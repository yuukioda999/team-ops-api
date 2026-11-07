<?php

namespace App\UseCases\Members;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamMembership;
use Illuminate\Database\Eloquent\Collection;

/**
 * メンバー一覧(表示名と役割)。代表を先頭に、参加した順(ADR-0015)。
 */
final class ListMembers
{
    /**
     * @return Collection<int, TeamMembership>
     */
    public function __invoke(Team $team): Collection
    {
        $members = TeamMembership::where('team_id', $team->id)->with(['user', 'roles'])->orderBy('id')->get();

        return $members->sortBy(fn (TeamMembership $m) => $m->roles->contains('role', TeamRole::Representative) ? 0 : 1, SORT_REGULAR)->values();
    }
}
