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
     * 選手の登録・編集・削除、保護者の紐づけ、CSV の取り込み(AC-003-01〜07・13〜19)。
     */
    public function managePlayers(User $user, Team $team): bool
    {
        return $this->hasAny($user, $team, [TeamRole::Representative, TeamRole::Officer]);
    }

    /**
     * 全選手の名簿の閲覧(AC-003-08・12)。
     */
    public function viewAllPlayers(User $user, Team $team): bool
    {
        return $this->hasAny($user, $team, [TeamRole::Representative, TeamRole::Officer, TeamRole::Coach]);
    }

    /**
     * 名簿を開けるか。保護者は自分に紐づく選手だけを見る(AC-003-09)。絞り込みは UseCase で行う。
     */
    public function viewPlayers(User $user, Team $team): bool
    {
        return $this->viewAllPlayers($user, $team) || $this->hasAny($user, $team, [TeamRole::Parent]);
    }

    /**
     * @param  list<TeamRole>  $roles
     */
    private function hasAny(User $user, Team $team, array $roles): bool
    {
        return array_intersect(array_map(fn (TeamRole $r) => $r->value, $user->rolesIn($team)), array_map(fn (TeamRole $r) => $r->value, $roles)) !== [];
    }
}
