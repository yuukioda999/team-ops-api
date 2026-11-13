<?php

namespace App\UseCases\Notices;

use App\Enums\NoticeStatus;
use App\Jobs\PublishScheduledNoticeJob;
use App\Models\Notice;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 予約日時を過ぎた予約中のお知らせを、1件ずつ公開のジョブにしてキューに積む(AC-005-11、ADR-0018、ADR-0024)。
 * スケジューラが毎分呼ぶ。公開そのものは worker が並列に行う(PublishScheduledNotice)。
 * 1件が失敗しても、ほかの予約の公開は止めない。失敗したものは次の分にまた積む。
 */
final class PublishDueNotices
{
    /**
     * @return int 時刻の来た予約の件数(同じお知らせのジョブがまだ残っていれば、キューには積まれない)
     */
    public function __invoke(): int
    {
        $queued = 0;
        Notice::where('status', NoticeStatus::Scheduled)->where('scheduled_at', '<=', now())
            ->select('id')->lazyById(1000)
            ->each(function (Notice $notice) use (&$queued): void {
                $queued++;
                try {
                    PublishScheduledNoticeJob::dispatch((int) $notice->id);
                } catch (Throwable $e) {
                    // 積めなくても残りは続ける。次の分にまた積む(本文や宛先は出さない)
                    Log::error('Queueing a scheduled notice failed; will retry next minute', ['notice_id' => $notice->id, 'exception' => $e::class]);
                }
            });

        return $queued;
    }
}
