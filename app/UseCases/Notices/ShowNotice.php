<?php

namespace App\UseCases\Notices;

use App\Models\Notice;
use App\Models\NoticeRecipient;
use App\Models\Team;
use App\Models\User;

/**
 * お知らせ1件。自分が宛先に含まれない(予約中・取り消しを含む)なら、存在しない場合と同じ404(AC-005-15)。
 * 開いた本人の既読日時が空なら入れる(AC-006-01、ADR-0020)。
 */
final class ShowNotice
{
    public function __invoke(User $user, Team $team, int $noticeId): Notice
    {
        $notice = ListMyNotices::visibleTo($user, $team)->with('author')->findOrFail($noticeId);
        NoticeRecipient::where('notice_id', $notice->id)->where('user_id', $user->id)->whereNull('read_at')->update(['read_at' => now(), 'updated_at' => now()]);
        $notice->setAttribute('unread', false);

        return $notice;
    }
}
