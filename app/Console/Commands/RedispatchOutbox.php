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
 *
 * --now:notify-relay を起動し直したあとに、運用する人が手で打つ(spec 009 の AC-009-12)。送り直しの間隔は
 * 最大30分まで延びるので、まだ時刻の来ていない送信待ちも、すぐ送り直す。送ったもの・失敗したもの(gave_up を
 * 含む)は対象にしない。このときだけは、送っている途中の件をもう一度送ることがある。notify-relay が重複防止キーで
 * 受け付けを1回にまとめ(ADR-0002)、アウトボックスの結果は、予約し直したジョブだけが書く(ADR-0018 の
 * 2026-11-16 追記)。
 */
#[Signature('outbox:redispatch {--limit=500 : 1回に積む最大件数} {--now : まだ時刻の来ていない送信待ちも、すぐ送り直す}')]
#[Description('送信待ちのまま時刻が来たアウトボックスに、送信ジョブを積み直す')]
final class RedispatchOutbox extends Command
{
    /** 最初のジョブが動くのを待つ時間。これを過ぎても一度も送っていなければ積み直す */
    private const int FIRST_ATTEMPT_GRACE_SECONDS = 60;

    public function handle(): int
    {
        $now = now();
        if ($this->option('now')) {
            $moved = OutboxMessage::query()
                ->where('status', OutboxStatus::Pending)
                ->where('next_attempt_at', '>', $now)
                ->update(['next_attempt_at' => $now]);
            $this->info("Moved {$moved} waiting outbox message(s) to now. Up to --limit are queued below; the rest are queued by the scheduled run every minute.");
        }
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
