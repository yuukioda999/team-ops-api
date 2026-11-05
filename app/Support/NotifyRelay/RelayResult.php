<?php

namespace App\Support\NotifyRelay;

/**
 * notify-relay の応答を、アウトボックスの次の扱いに直したもの。
 * 理由(reason)は固定の文字列だけにし、応答の本文(宛先を含みうる)は持たない。
 */
final class RelayResult
{
    private function __construct(
        public readonly string $outcome, // accepted / retry / rejected
        public readonly string $reason,
        public readonly ?string $deliveryId = null,
    ) {}

    public static function retry(string $reason): self
    {
        return new self('retry', $reason);
    }

    public static function fromStatus(int $status, mixed $deliveryId): self
    {
        $id = is_string($deliveryId) ? $deliveryId : null;

        return match (true) {
            // 202:新しく受け付けた / 200:同じ依頼を受付済み(二重送信にならない)
            $status === 202, $status === 200 => new self('accepted', 'http_'.$status, $id),
            // 一時的な失敗。アウトボックスに残したまま、あとで送り直す(ADR-0002)
            $status === 429, $status === 503, $status >= 500 => new self('retry', 'http_'.$status),
            // 署名の鍵の設定ミスは、送り直しても直らない。記録して止める
            $status === 401 => new self('rejected', 'unauthorized'),
            // 409:同じ dedupe_key で内容が違う / 400・413・422:依頼の形が仕様に合わない
            default => new self('rejected', 'http_'.$status),
        };
    }

    public function isAccepted(): bool
    {
        return $this->outcome === 'accepted';
    }

    public function shouldRetry(): bool
    {
        return $this->outcome === 'retry';
    }
}
