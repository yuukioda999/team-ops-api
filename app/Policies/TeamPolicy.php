<?php

namespace App\Policies;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;

/**
 * チームの操作の権限(004 の権限表、ADR-0011)。持っている役割の和で判定する。
 * 所属していないチームは、ここに来る前にルートの {team} で404になる(ADR-0009)。
 */
final class TeamPolicy
{
    /**
     * 招待リンクの表示・発行・無効化(AC-002-01〜04)。
     */
    public function manageInvitation(User $user, Team $team): bool
    {
        return $this->hasAny($user, $team, [TeamRole::Representative, TeamRole::Officer]);
    }

    /**
     * 参加申請の一覧・承認・却下(AC-002-11〜14)。
     */
    public function reviewJoinRequests(User $user, Team $team): bool
    {
        return $this->hasAny($user, $team, [TeamRole::Representative, TeamRole::Officer]);
    }

    /**
     * @param  list<TeamRole>  $roles
     */
    private function hasAny(User $user, Team $team, array $roles): bool
    {
        return array_intersect(array_map(fn (TeamRole $r) => $r->value, $user->rolesIn($team)), array_map(fn (TeamRole $r) => $r->value, $roles)) !== [];
    }
}
