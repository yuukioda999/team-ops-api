<?php

namespace App\UseCases\Players;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamMembership;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * 選手に紐づけられるメンバー(保護者の役割を持つ人)。役割のないメンバーは選択肢に出さない(AC-003-07)。
 */
final class ListGuardianCandidates
{
    /**
     * @return Collection<int, TeamMembership>
     */
    public function __invoke(Team $team): Collection
    {
        return TeamMembership::where('team_id', $team->id)
            ->whereHas('roles', fn (Builder $q) => $q->where('role', TeamRole::Parent))
            ->with('user')
            ->orderBy('id')
            ->get();
    }
}
