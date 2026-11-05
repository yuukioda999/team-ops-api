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
    public static function fromConfig(HttpFactory $http, array $config): self
    {
        $secret = is_string($config['secret'] ?? null) ? base64_decode($config['secret'], true) : false;
        if (! is_string($config['key_id'] ?? null) || $config['key_id'] === '' || $secret === false || strlen($secret) < 32) {
            throw new RuntimeException('NOTIFY_RELAY_KEY_ID and NOTIFY_RELAY_SECRET (base64, 32 bytes or more) are required.');
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
                    'X-Relay-Signature' => self::sign($this->secret, $timestamp, $body),
                ])
                ->withBody($body, 'application/json')
                ->post($this->baseUrl.'/v1/deliveries');
        } catch (ConnectionException) {
            return RelayResult::retry('connection_failed');
        }

        return RelayResult::fromStatus($response->status(), $response->json('delivery_id'));
    }

    public static function sign(string $secret, int $timestamp, string $body): string
    {
        return 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }
}
