<?php

namespace App\UseCases\JoinRequests;

use App\Enums\JoinRequestStatus;
use App\Models\Team;
use App\Models\TeamJoinRequest;
use Illuminate\Database\Eloquent\Collection;

/**
 * チームの承認待ちの申請を、申請の古い順に返す(AC-002-11)。
 */
final class ListPendingJoinRequests
{
    /**
     * @return Collection<int, TeamJoinRequest>
     */
    public function __invoke(Team $team): Collection
    {
        return TeamJoinRequest::where('team_id', $team->id)
            ->where('status', JoinRequestStatus::Pending)
            ->with('user')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }
}
