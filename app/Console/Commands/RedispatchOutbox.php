<?php

namespace App\Console\Commands;

use App\Enums\OutboxStatus;
use App\Jobs\DeliverOutboxMessage;
use App\Models\OutboxMessage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * 送信待ちのまま時刻が来たアウトボックスに、送信ジョブを積み直す(ADR-0002、plan.md D2)。
 *
 * 次の場合を拾う:
 * - notify-relay が一時的に受け付けなかった(next_attempt_at が来た)
 * - コミットした直後にプロセスが落ちるなどして、最初のジョブが積まれなかった
 * - ジョブが想定外の例外で止まった
 * 同じ件のジョブが重複しても、ジョブ側の予約で送るのは1つだけになる。
 */
#[Signature('outbox:redispatch {--limit=500 : 1回に積む最大件数}')]
#[Description('送信待ちのまま時刻が来たアウトボックスに、送信ジョブを積み直す')]
final class RedispatchOutbox extends Command
{
    /** 最初のジョブが動くのを待つ時間。これを過ぎても一度も送っていなければ積み直す */
    private const int FIRST_ATTEMPT_GRACE_SECONDS = 60;

    public function handle(): int
    {
        $now = now();
        $ids = OutboxMessage::query()
            ->where('status', OutboxStatus::Pending)
            ->where(function ($q) use ($now) {
                $q->where('next_attempt_at', '<=', $now)
                    ->orWhere(fn ($q) => $q->whereNull('next_attempt_at')
                        ->where('created_at', '<=', $now->copy()->subSeconds(self::FIRST_ATTEMPT_GRACE_SECONDS)));
            })
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->pluck('id');

        foreach ($ids as $id) {
            DeliverOutboxMessage::dispatch((int) $id);
        }

        if ($ids->isNotEmpty()) {
            $this->info("Redispatched {$ids->count()} outbox message(s).");
        }

        return self::SUCCESS;
    }
}
