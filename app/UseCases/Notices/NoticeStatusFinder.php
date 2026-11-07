<?php

namespace App\UseCases\Notices;

use App\Enums\NoticeStatus;
use App\Models\Notice;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * 配信状況・再通知の対象のお知らせを探す。チームの送信済みのお知らせでなければ404。
 * 代表・役員か、作成者のコーチでなければ 403(AC-006-10、ADR-0020)。
 */
final class NoticeStatusFinder
{
    public static function find(User $actor, Team $team, int $noticeId, bool $lock = false): Notice
    {
        $query = Notice::where('team_id', $team->id)->where('status', NoticeStatus::Published);
        if ($lock) {
            $query->lockForUpdate();
        }
        $notice = $query->findOrFail($noticeId);
        Gate::forUser($actor)->authorize('viewNoticeStatus', [$team, $notice]);

        return $notice;
    }
}
