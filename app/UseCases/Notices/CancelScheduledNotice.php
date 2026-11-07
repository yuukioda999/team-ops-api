<?php

namespace App\UseCases\Notices;

use App\Enums\NoticeStatus;
use App\Models\Team;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * 予約を取り消す。何も送られない(AC-005-10)。
 */
final class CancelScheduledNotice
{
    public function __invoke(User $actor, Team $team, int $noticeId): void
    {
        DB::transaction(function () use ($actor, $team, $noticeId): void {
            $notice = ScheduledNoticeFinder::lockForChange($actor, $team, $noticeId);
            $notice->forceFill(['status' => NoticeStatus::Cancelled])->save();

            Audit::record($team, $actor, 'notice.cancelled', $notice);
        });
    }
}
