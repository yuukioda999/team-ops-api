<?php

namespace App\Support\NotifyRelay;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;

/**
 * notify-relay の配信 API を呼ぶ。リクエストには HMAC-SHA256 の署名を付ける
 * (notify-relay api/openapi.yaml の「認証(リクエスト署名)」)。
 */
final class RelayClient
{
    private const string PATH = '/v1/deliveries';

    /**
     * team-ops の compose.yaml に公開しているローカル開発用の鍵。誰でも読めるため、
     * ローカルとテスト以外では使わせない。
     */
    private const array PUBLISHED_LOCAL_SECRETS = ['local-dev-relay-signing-key-0001'];

    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $baseUrl,
        private readonly string $keyId,
        private readonly string $secret,
        private readonly int $timeoutSeconds = 10,
    ) {}

    /**
     * @param  array<string, mixed>  $config  config('team_ops.notify_relay')
     */
    public static function fromConfig(HttpFactory $http, array $config, string $environment): self
    {
        $secret = is_string($config['secret'] ?? null) ? base64_decode($config['secret'], true) : false;
        if (! is_string($config['key_id'] ?? null) || $config['key_id'] === '' || $secret === false || strlen($secret) < 32) {
            throw new RuntimeException('NOTIFY_RELAY_KEY_ID and NOTIFY_RELAY_SECRET (base64, 32 bytes or more) are required.');
        }
        if (! in_array($environment, ['local', 'testing'], true) && in_array($secret, self::PUBLISHED_LOCAL_SECRETS, true)) {
            throw new RuntimeException('NOTIFY_RELAY_SECRET is the published local development key. Set a real secret.');
        }

        return new self(
            $http,
            rtrim((string) ($config['url'] ?? ''), '/'),
            $config['key_id'],
            $secret,
            (int) ($config['timeout'] ?? 10),
        );
    }

    /**
     * 配信依頼を送る。接続できない場合は例外ではなく、再試行すべき結果として返す。
     *
     * @param  array<string, mixed>  $delivery  api/openapi.yaml の DeliveryRequest
     */
    public function createDelivery(array $delivery, ?int $timestamp = null): RelayResult
    {
        $body = json_encode($delivery, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp ??= time();

        try {
            $response = $this->http
                ->timeout($this->timeoutSeconds)
                ->withHeaders([
                    'X-Relay-Key-Id' => $this->keyId,
                    'X-Relay-Timestamp' => (string) $timestamp,
                    'X-Relay-Signature' => self::sign($this->secret, $timestamp, 'POST', self::PATH, $body),
                ])
                // リダイレクトをたどると、宛先とトークンを含む本文を別のホストに送ってしまう
                ->withoutRedirecting()
                ->withBody($body, 'application/json')
                ->post($this->baseUrl.self::PATH);
        } catch (ConnectionException) {
            return RelayResult::retry('connection_failed');
        }

        return RelayResult::fromStatus($response->status(), $response->json('delivery_id'));
    }

    /**
     * 配信依頼の状態(件数だけ)を問い合わせる(AC-R001-21、照合用)。
     * 7日を過ぎて消えた依頼は not_found。接続できない・想定外の応答は null(次の回にやり直す)。
     *
     * @return array{state: string, pending: int, finished: int}|'not_found'|null
     */
    public function getDelivery(string $deliveryId, ?int $timestamp = null): array|string|null
    {
        if (preg_match('/^dlv_[0-9A-HJKMNP-TV-Z]{26}$/', $deliveryId) !== 1) {
            return 'not_found';
        }
        $path = self::PATH.'/'.$deliveryId;
        $timestamp ??= time();
        try {
            $response = $this->http
                ->timeout($this->timeoutSeconds)
                ->withHeaders([
                    'X-Relay-Key-Id' => $this->keyId,
                    'X-Relay-Timestamp' => (string) $timestamp,
                    'X-Relay-Signature' => self::sign($this->secret, $timestamp, 'GET', $path, ''),
                ])
                ->withoutRedirecting()
                ->get($this->baseUrl.$path);
        } catch (ConnectionException) {
            return null;
        }
        if ($response->status() === 404) {
            return 'not_found';
        }
        $counts = $response->json('counts');
        $state = $response->json('state');
        if (! $response->successful() || ! is_array($counts) || ! is_string($state)) {
            return null;
        }
        $n = fn (string $k): int => is_int($counts[$k] ?? null) ? $counts[$k] : 0;

        return ['state' => $state, 'pending' => $n('pending'), 'finished' => $n('delivered') + $n('failed') + $n('skipped')];
    }

    /**
     * 署名の形式は notify-relay の api/openapi.yaml(1.0.0-draft.2)に従う。
     * メソッドとパスも含め、ある依頼の署名を別の操作に使い回せないようにする。
     */
    public static function sign(string $secret, int $timestamp, string $method, string $path, string $body): string
    {
        return 'v1='.hash_hmac('sha256', $timestamp.'.'.$method.'.'.$path.'.'.$body, $secret);
    }
}
