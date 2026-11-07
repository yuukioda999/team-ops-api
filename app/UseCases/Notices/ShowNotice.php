<?php

namespace App\UseCases\Notices;

use App\Models\Notice;
use App\Models\Team;
use App\Models\User;

/**
 * お知らせ1件。自分が宛先に含まれない(予約中・取り消しを含む)なら、存在しない場合と同じ404(AC-005-15)。
 */
final class ShowNotice
{
    public function __invoke(User $user, Team $team, int $noticeId): Notice
    {
        return ListMyNotices::visibleTo($user, $team)->with('author')->findOrFail($noticeId);
    }
}
