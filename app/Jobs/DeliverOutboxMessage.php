<?php

namespace App\Jobs;

use App\Enums\OutboxStatus;
use App\Models\OutboxMessage;
use App\Support\NotifyRelay\RelayClient;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * アウトボックスの1件を notify-relay に送る。
 *
 * ジョブが持つのはIDだけで、宛先や本文は持たない(failed_jobs に残さないため)。
 * notify-relay が止まっていても、24時間は間隔をあけて送り直す。同じ dedupe_key で送るので、
 * 届いていたのに応答だけ失われた場合も二重送信にはならない(AC-R001-03)。
 */
final class DeliverOutboxMessage implements ShouldQueue
{
    use Queueable;

    /** 送り直しの間隔(秒)。使い切ったら最後の値を繰り返す */
    private const array BACKOFF = [10, 30, 60, 300, 900, 1800];

    /** 回数ではなく retryUntil の期限で打ち切る */
    public int $tries = 0;

    public function __construct(public readonly int $outboxMessageId) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addDay();
    }

    public function handle(RelayClient $relay): void
    {
        $message = OutboxMessage::find($this->outboxMessageId);
        if ($message === null || $message->status !== OutboxStatus::Pending) {
            return; // 送信済み・失敗済み。重複して積まれたジョブは何もしない
        }

        $result = $relay->createDelivery($message->payload);
        $attempts = $message->attempts + 1;
        $context = ['outbox_message_id' => $message->id, 'kind' => $message->kind, 'attempts' => $attempts, 'reason' => $result->reason];

        if ($result->isAccepted()) {
            $message->forceFill([
                'status' => OutboxStatus::Sent,
                'attempts' => $attempts,
                'sent_at' => now(),
                'next_attempt_at' => null,
                'last_error' => null,
            ])->save();
            Log::info('Outbox message handed to notify-relay', $context + ['delivery_id' => $result->deliveryId]);

            return;
        }

        if ($result->shouldRetry()) {
            $delay = self::BACKOFF[min($attempts, count(self::BACKOFF)) - 1];
            $message->forceFill([
                'attempts' => $attempts,
                'next_attempt_at' => now()->addSeconds($delay),
                'last_error' => $result->reason,
            ])->save();
            Log::warning('notify-relay is unavailable; will retry', $context + ['retry_in_seconds' => $delay]);
            $this->release($delay);

            return;
        }

        $this->markFailed($message, $attempts, $result->reason);
        Log::error('notify-relay rejected an outbox message', $context);
    }

    /**
     * 期限(24時間)を過ぎても送れなかった、または想定外の例外で止まった。
     */
    public function failed(?Throwable $exception): void
    {
        DB::transaction(function (): void {
            $message = OutboxMessage::lockForUpdate()->find($this->outboxMessageId);
            if ($message !== null && $message->status === OutboxStatus::Pending) {
                $this->markFailed($message, $message->attempts, 'gave_up');
            }
        });
        Log::error('Outbox message could not be delivered', [
            'outbox_message_id' => $this->outboxMessageId,
            'exception' => $exception,
        ]);
    }

    private function markFailed(OutboxMessage $message, int $attempts, string $reason): void
    {
        $message->forceFill([
            'status' => OutboxStatus::Failed,
            'attempts' => $attempts,
            'next_attempt_at' => null,
            'last_error' => $reason,
        ])->save();
    }
}
