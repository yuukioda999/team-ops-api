<?php

namespace App\Jobs;

use App\Enums\OutboxStatus;
use App\Models\OutboxMessage;
use App\Support\NotifyRelay\RelayClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * アウトボックスの1件を notify-relay に送る。
 *
 * 送る前に、次に送り直す時刻を書き込んで1件を予約する(条件付きの UPDATE)。このため、
 * - 同じメッセージのジョブが同時に動いても、送るのは1つだけ
 * - 想定外の例外で止まっても、次の時刻まで送り直さない(間をあけずに繰り返さない)
 * 送り直しは、定期実行の outbox:redispatch が時刻の来たものに改めてジョブを積んで行う。
 *
 * ジョブが持つのはIDだけで、宛先や本文は持たない(failed_jobs に残さないため)。
 * 同じ dedupe_key で送るので、届いていたのに応答だけ失われた場合も二重送信にならない。
 */
final class DeliverOutboxMessage implements ShouldQueue
{
    use Queueable;

    /** 失敗した回数ごとの、次に送るまでの秒数。使い切ったら最後の値を繰り返す */
    public const array BACKOFF = [30, 60, 120, 300, 600, 1800];

    /** 送り直しは outbox:redispatch が行うので、キューでは再実行しない */
    public int $tries = 1;

    public function __construct(public readonly int $outboxMessageId) {}

    public static function delayAfter(int $attempts): int
    {
        return self::BACKOFF[min(max($attempts, 1), count(self::BACKOFF)) - 1];
    }

    public function handle(RelayClient $relay): void
    {
        $message = $this->reserve();
        if ($message === null) {
            return; // 送信済み・失敗済み・まだ時刻が来ていない、または別のジョブが予約した
        }
        $context = ['outbox_message_id' => $message->id, 'kind' => $message->kind, 'attempts' => $message->attempts];

        if ($message->give_up_at !== null && $message->give_up_at->isPast()) {
            $this->finish($message, OutboxStatus::Failed, 'gave_up');
            Log::error('Outbox message expired before notify-relay accepted it', $context);

            return;
        }

        $payload = $message->payload;
        if ($payload === null) {
            $this->finish($message, OutboxStatus::Failed, 'payload_missing');
            Log::error('Outbox message has no payload', $context);

            return;
        }

        $result = $relay->createDelivery($payload);
        $context['reason'] = $result->reason;

        if ($result->isAccepted()) {
            $this->finish($message, OutboxStatus::Sent, null);
            Log::info('Outbox message handed to notify-relay', $context + ['delivery_id' => $result->deliveryId]);

            return;
        }

        if ($result->shouldRetry()) {
            // 予約したときに書いた next_attempt_at のまま残す。outbox:redispatch が拾う
            $message->forceFill(['last_error' => $result->reason])->save();
            Log::warning('notify-relay did not accept the outbox message; will retry', $context + ['next_attempt_at' => $message->next_attempt_at?->toIso8601String()]);

            return;
        }

        $this->finish($message, OutboxStatus::Failed, $result->reason);
        Log::error('notify-relay rejected an outbox message', $context);
    }

    /**
     * 想定外の例外で止まった。予約したときの next_attempt_at になったら outbox:redispatch が送り直す。
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('Outbox delivery job failed; it will be retried later', [
            'outbox_message_id' => $this->outboxMessageId,
            'exception' => $exception,
        ]);
    }

    /**
     * 送る権利を1件だけ取る。取れたら attempts を1つ増やし、次に送り直す時刻を先に書いておく。
     */
    private function reserve(): ?OutboxMessage
    {
        $current = OutboxMessage::find($this->outboxMessageId);
        if ($current === null || $current->status !== OutboxStatus::Pending) {
            return null;
        }

        $now = Carbon::now();
        $attempts = $current->attempts + 1;
        $reserved = OutboxMessage::whereKey($current->id)
            ->where('status', OutboxStatus::Pending)
            ->where('attempts', $current->attempts)
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now))
            ->update([
                'attempts' => $attempts,
                'next_attempt_at' => $now->copy()->addSeconds(self::delayAfter($attempts)),
                'updated_at' => $now,
            ]);

        return $reserved === 1 ? $current->refresh() : null;
    }

    /**
     * 最終状態にする。宛先と本文(ログイン用リンクを含みうる)は、もう使わないので消す(ADR-0003)。
     */
    private function finish(OutboxMessage $message, OutboxStatus $status, ?string $error): void
    {
        $message->forceFill([
            'status' => $status,
            'sent_at' => $status === OutboxStatus::Sent ? now() : null,
            'next_attempt_at' => null,
            'last_error' => $error,
            'payload' => null,
        ])->save();
    }
}
