<?php

namespace App\UseCases\Notices;

use App\Enums\NoticeStatus;
use App\Models\Notice;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * 予約中のお知らせ。代表・役員はチームの全部、それ以外(コーチ)は自分が作ったものだけ(ADR-0017)。
 */
final class ListScheduledNotices
{
    /**
     * @return Collection<int, Notice>
     */
    public function __invoke(User $user, Team $team): Collection
    {
        $query = Notice::where('team_id', $team->id)->where('status', NoticeStatus::Scheduled)->with('author');
        if (! Gate::forUser($user)->allows('manageAllNotices', $team)) {
            $query->where('author_user_id', $user->id);
        }

        return $query->orderBy('scheduled_at')->get();
    }
}
