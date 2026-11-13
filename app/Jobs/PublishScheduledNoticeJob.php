<?php

namespace App\Jobs;

use App\UseCases\Notices\PublishScheduledNotice;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 予約のお知らせ1件の公開(ADR-0024)。worker が並列に処理する。お知らせの ID だけを持ち、本文や宛先は持たない。
 * 同じお知らせのジョブは、処理が終わるまで1つしか積まない(キューが混んでいて、次の分の scheduler が
 * また積もうとしても重ならない)。重なっても、公開は行のロックと状態の確認で1回だけになる。
 */
final class PublishScheduledNoticeJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** 失敗したら、このジョブではやり直さず、次の分の scheduler がもう一度積む */
    public int $tries = 1;

    /** 公開されないまま worker が止まっても、この時間がたてば次の分の scheduler がまた積める */
    public int $uniqueFor = 600;

    public function __construct(public readonly int $noticeId) {}

    public function uniqueId(): string
    {
        return (string) $this->noticeId;
    }

    public function handle(PublishScheduledNotice $publish): void
    {
        try {
            $publish($this->noticeId);
        } catch (Throwable $e) {
            // 本文や宛先は出さない。次の分にやり直す
            Log::error('Publishing a scheduled notice failed; will retry next minute', ['notice_id' => $this->noticeId, 'exception' => $e::class]);
        }
    }
}
