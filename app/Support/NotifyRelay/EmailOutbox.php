<?php

namespace App\Support\NotifyRelay;

use App\Jobs\DeliverOutboxMessage;
use App\Models\OutboxMessage;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * メールの配信依頼をアウトボックスに記録する(ADR-0002)。
 *
 * 呼び出し側のトランザクションの中で記録し、コミットされた後に送信ジョブを積む。
 * 業務データの保存と配信依頼が、片方だけ残ることはない。
 *
 * 依頼の制約は notify-relay の契約(api/openapi.yaml の DeliveryRequest)に合わせ、
 * 違反はコミット前にここで例外にする(コミット後に notify-relay から 422 が返って黙って失敗するのを防ぐ)。
 * 例外のメッセージには値を含めない。
 */
final class EmailOutbox
{
    /** 送り直す期限。メールに有効期限がある場合はそちらを使う(plan.md「送り直しの期限」) */
    private const int GIVE_UP_AFTER_HOURS = 24;

    private const int MAX_RECIPIENTS = 1000;

    public function __construct(private readonly bool $invalidRecipientsOnly) {}

    /**
     * @param  string  $kind  依頼の種類(例:login_link)。ログと集計に使う
     * @param  string  $dedupeKey  依頼ごとに一意な値。notify-relay の重複防止キーになる。
     *                             一意制約はチームを区別しないため、チームのデータに関わる依頼では
     *                             先頭にチームIDを含める(例:team:{チームID}:notice:{お知らせID}:1)
     * @param  array<string, string>  $recipients  recipient_ref => メールアドレス
     * @param  CarbonInterface|null  $expiresAt  この時刻を過ぎたら送らない。送り直しもこの時刻までにする
     */
    public function queue(
        string $kind,
        string $dedupeKey,
        string $subject,
        string $text,
        array $recipients,
        ?string $url = null,
        ?CarbonInterface $expiresAt = null,
        string $tenantRef = 'system',
    ): OutboxMessage {
        $this->validate($dedupeKey, $subject, $text, $recipients, $url, $tenantRef);

        $payload = [
            'dedupe_key' => $dedupeKey,
            'tenant_ref' => $tenantRef,
            'message' => array_filter(['subject' => $subject, 'text' => $text, 'url' => $url], fn ($v) => $v !== null),
            'recipients' => array_map(
                fn (string $ref, string $email) => ['recipient_ref' => $ref, 'email' => $email],
                array_map('strval', array_keys($recipients)),
                array_values($recipients),
            ),
        ];
        if ($expiresAt !== null) {
            $payload['expires_at'] = $expiresAt->toIso8601String();
        }
        $giveUpAt = $expiresAt ?? now()->addHours(self::GIVE_UP_AFTER_HOURS);

        return DB::transaction(function () use ($kind, $dedupeKey, $payload, $giveUpAt): OutboxMessage {
            // 同時に同じキーで呼ばれても、一意制約の例外を呼び出し側に上げない
            $message = OutboxMessage::createOrFirst(
                ['dedupe_key' => $dedupeKey],
                ['kind' => $kind, 'payload' => $payload, 'give_up_at' => $giveUpAt],
            );

            if (! $message->wasRecentlyCreated) {
                $this->assertSameRequest($message, $payload);

                return $message;
            }

            DeliverOutboxMessage::dispatch($message->id)->afterCommit();

            return $message;
        });
    }

    /**
     * @param  array<string, string>  $recipients
     */
    private function validate(string $dedupeKey, string $subject, string $text, array $recipients, ?string $url, string $tenantRef): void
    {
        $fail = fn (string $what) => throw new InvalidArgumentException("Delivery request is invalid: {$what}.");

        if (preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $dedupeKey) !== 1) {
            $fail('dedupe_key must be 8 to 128 characters of letters, digits and . _ : -');
        }
        if ($tenantRef === '' || mb_strlen($tenantRef) > 64) {
            $fail('tenant_ref must be 1 to 64 characters');
        }
        if (mb_strlen($subject) < 1 || mb_strlen($subject) > 200 || preg_match('/[\r\n]/', $subject) === 1) {
            $fail('subject must be a single line of 1 to 200 characters');
        }
        if (mb_strlen($text) < 1 || mb_strlen($text) > 2000) {
            $fail('text must be 1 to 2000 characters');
        }
        if ($url !== null && (strlen($url) > 2048 || preg_match('#^https?://[^\s/]+#', $url) !== 1)) {
            $fail('url must be an absolute http(s) URL of at most 2048 characters');
        }
        if ($recipients === [] || count($recipients) > self::MAX_RECIPIENTS) {
            $fail('recipients must contain 1 to 1000 addresses');
        }
        foreach ($recipients as $ref => $email) {
            if ((string) $ref === '' || mb_strlen((string) $ref) > 64) {
                $fail('recipient_ref must be 1 to 64 characters');
            }
            if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $fail('a recipient address is not valid');
            }
            if ($this->invalidRecipientsOnly && ! str_ends_with(strtolower($email), '.invalid')) {
                $fail('only .invalid recipients are accepted (RECIPIENTS_INVALID_ONLY or DEMO_MODE)');
            }
        }
    }

    /**
     * 同じキーで内容の違う依頼は、キーの付け方の誤り。黙って前の依頼を返さない(notify-relay の 409 と同じ扱い)。
     *
     * @param  array<string, mixed>  $payload
     */
    private function assertSameRequest(OutboxMessage $existing, array $payload): void
    {
        $stored = $existing->payload;
        if ($stored === null) {
            return; // 送信済みで中身を消してある。比べようがないので、受付済みとして扱う
        }
        unset($stored['expires_at'], $payload['expires_at']);
        if ($stored != $payload) {
            throw new LogicException('dedupe_key was already used for a different delivery request.');
        }
    }
}
