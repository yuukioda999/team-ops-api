<?php

namespace App\UseCases\Demo;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamMemberRole;
use App\Models\User;

/**
 * デモチームの、指定した役割でログインするデモユーザー(AC-007-05)。
 * 保護者は1人目の保護者、コーチは1人目のコーチ。チームがない(期限切れで消えた)なら null。
 */
final class FindDemoUser
{
    public function __invoke(string $teamId, TeamRole $role): ?User
    {
        $team = Team::whereKey($teamId)->whereNotNull('demo_expires_at')->first();
        if ($team === null) {
            return null;
        }
        $userIds = TeamMemberRole::query()
            ->join('team_memberships', 'team_memberships.id', '=', 'team_member_roles.membership_id')
            ->where('team_member_roles.team_id', $team->id)
            ->where('team_member_roles.role', $role)
            ->orderBy('team_memberships.id')
            ->pluck('team_memberships.user_id');

        return User::where('demo_team_id', $team->id)->whereIn('id', $userIds)->orderBy('id')->first();
    }
}
