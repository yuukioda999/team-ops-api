<?php

namespace App\Support\Demo;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * デモ環境・作業環境の Mailpit の API(ADR-0021)。デモの「送信されたメール」と、デモチームの削除に使う。
 * 本番相当の設定(デモ無効)では使わない。
 */
final class MailpitClient
{
    private const int TIMEOUT_SECONDS = 5;

    public function __construct(private readonly Http $http) {}

    /**
     * 宛先のドメインで探し、新しい順に最大 $limit 件を返す。宛先がすべてそのドメインのメールだけを返す。
     *
     * @return list<array{subject: string, to: list<string>, sent_at: string, text: string}>
     */
    public function messagesTo(string $domain, int $limit): array
    {
        $search = $this->get('/api/v1/search', ['query' => "to:\"@{$domain}\"", 'limit' => $limit]);
        $messages = is_array($search['messages'] ?? null) ? $search['messages'] : [];
        $out = [];
        foreach ($messages as $summary) {
            if (! is_array($summary) || ! is_string($summary['ID'] ?? null)) {
                continue;
            }
            $to = self::addresses($summary['To'] ?? null);
            // ほかのドメインの宛先が1つでも混ざっていれば出さない(他の訪問者のメールを見せない)
            if ($to === [] || array_filter($to, fn (string $a) => ! str_ends_with(strtolower($a), '@'.$domain)) !== []) {
                continue;
            }
            $message = $this->get('/api/v1/message/'.rawurlencode($summary['ID']));
            $out[] = [
                'subject' => is_string($message['Subject'] ?? null) ? $message['Subject'] : '',
                'to' => $to,
                'sent_at' => is_string($message['Date'] ?? null) ? $message['Date'] : (is_string($summary['Created'] ?? null) ? $summary['Created'] : ''),
                'text' => is_string($message['Text'] ?? null) ? $message['Text'] : '',
            ];
        }

        // 新しい順は Mailpit の返す順に任せず、ここで並べる
        usort($out, fn (array $a, array $b) => (strtotime($b['sent_at']) ?: 0) <=> (strtotime($a['sent_at']) ?: 0));

        return $out;
    }

    /**
     * 宛先のドメインのメールを消す(デモチームの削除、AC-007-07)。Mailpit につながらなければ false。
     */
    public function deleteTo(string $domain): bool
    {
        return $this->deleteWhere("to:\"@{$domain}\"");
    }

    /**
     * デモチーム宛て(@demo-)のメールのうち、Mailpit が受け取ったのが $before より前のものを消す。
     * チームの削除のあとに届いたメールや、消せなかったメールを後から消す(AC-007-07)
     */
    public function deleteDemoMailsReceivedBefore(\DateTimeInterface $before): bool
    {
        $utc = \DateTimeImmutable::createFromInterface($before)->setTimezone(new \DateTimeZone('UTC'));

        return $this->deleteWhere('to:"@'.DemoData::DOMAIN_PREFIX.'" before:"'.$utc->format('Y-m-d\\TH:i:s\\Z').'"');
    }

    private function deleteWhere(string $query): bool
    {
        $base = $this->baseUrl();
        if ($base === null) {
            return true;
        }
        try {
            $this->http->timeout(self::TIMEOUT_SECONDS)->delete($base.'/api/v1/search?'.http_build_query(['query' => $query]))->throw();

            return true;
        } catch (\Throwable $e) {
            // 消し残しは次の回の古いメールの削除で消える。検索条件はチームIDと日時だけで、個人情報を含まない
            report($e);

            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        $base = $this->baseUrl();
        if ($base === null) {
            throw new HttpException(503, 'メールの受け止め先が設定されていません');
        }
        try {
            $response = $this->http->timeout(self::TIMEOUT_SECONDS)->acceptJson()->get($base.$path, $query);
        } catch (ConnectionException) {
            throw new HttpException(503, 'メールの受け止め先につながりません');
        }
        $json = $response->json();
        if (! $response->successful() || ! is_array($json)) {
            throw new HttpException(503, 'メールの受け止め先につながりません');
        }

        /** @var array<string, mixed> $json */
        return $json;
    }

    private function baseUrl(): ?string
    {
        $url = config('team_ops.mailpit_url');

        return is_string($url) && $url !== '' ? rtrim($url, '/') : null;
    }

    /**
     * @return list<string>
     */
    private static function addresses(mixed $list): array
    {
        if (! is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $item) {
            if (is_array($item) && is_string($item['Address'] ?? null)) {
                $out[] = $item['Address'];
            }
        }

        return $out;
    }
}
