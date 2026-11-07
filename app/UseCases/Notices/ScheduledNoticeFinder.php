<?php

namespace App\UseCases\Notices;

use App\Enums\NoticeStatus;
use App\Models\Notice;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * 編集・取り消しの対象のお知らせを、行をロックして探す。チームのお知らせでなければ404。
 * 送信済み・取り消し済みは 409(AC-005-06)。作成者か代表・役員でなければ 403(AC-005-10)。
 */
final class ScheduledNoticeFinder
{
    public static function lockForChange(User $actor, Team $team, int $noticeId): Notice
    {
        $notice = Notice::where('team_id', $team->id)->lockForUpdate()->findOrFail($noticeId);
        Gate::forUser($actor)->authorize('manageNotice', [$team, $notice]);

        if ($notice->status !== NoticeStatus::Scheduled) {
            throw ValidationException::withMessages([
                'notice' => $notice->status === NoticeStatus::Published
                    ? '送信済みのお知らせは編集・取り消しできません。訂正は新しいお知らせで送ってください。'
                    : 'このお知らせは取り消されています。',
            ])->status(409);
        }

        return $notice;
    }
}
