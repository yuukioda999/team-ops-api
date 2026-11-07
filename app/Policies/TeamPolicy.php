<?php

namespace App\Policies;

use App\Enums\TeamRole;
use App\Models\Notice;
use App\Models\Team;
use App\Models\TeamMembership;
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
     * チーム名・競技の変更(AC-004-11)。
     */
    public function updateTeam(User $user, Team $team): bool
    {
        return $this->hasAny($user, $team, [TeamRole::Representative]);
    }

    /**
     * メンバー一覧(表示名と役割)の閲覧(004 の権限表、ADR-0015)。
     */
    public function viewMembers(User $user, Team $team): bool
    {
        return $this->hasAny($user, $team, [TeamRole::Representative, TeamRole::Officer]);
    }

    /**
     * 役割の変更(役員の任命・解任を含む、AC-004-03)。
     */
    public function manageRoles(User $user, Team $team): bool
    {
        return $this->hasAny($user, $team, [TeamRole::Representative]);
    }

    /**
     * 代表の交代(AC-004-04)。
     */
    public function transferRepresentative(User $user, Team $team): bool
    {
        return $this->hasAny($user, $team, [TeamRole::Representative]);
    }

    /**
     * メンバーを除名できる立場か(画面の表示用)。相手ごとの判定は removeMember。
     */
    public function removeMembers(User $user, Team $team): bool
    {
        return $this->hasAny($user, $team, [TeamRole::Representative, TeamRole::Officer]);
    }

    /**
     * このメンバーを除名できるか。役員は、代表と役員の役割を持つメンバーを除名できない(AC-004-07)。
     * 代表が自分を除名する場合は、UseCase で「先に代表を交代して」と返す(AC-004-06)。
     */
    public function removeMember(User $user, Team $team, TeamMembership $target): bool
    {
        if ($this->hasAny($user, $team, [TeamRole::Representative])) {
            return true;
        }
        if (! $this->hasAny($user, $team, [TeamRole::Officer])) {
            return false;
        }
        $targetRoles = $target->roleList();

        return ! in_array(TeamRole::Representative, $targetRoles, true) && ! in_array(TeamRole::Officer, $targetRoles, true);
    }

    /**
     * 監査ログの閲覧(AC-004-12)。
     */
    public function viewAuditLogs(User $user, Team $team): bool
    {
        return $this->hasAny($user, $team, [TeamRole::Representative]);
    }

    /**
     * お知らせの作成・送信・予約(AC-005-01・04・09)。
     */
    public function createNotices(User $user, Team $team): bool
    {
        return $this->hasAny($user, $team, [TeamRole::Representative, TeamRole::Officer, TeamRole::Coach]);
    }

    /**
     * 自分が宛先のお知らせの閲覧(AC-005-14)。絞り込みは UseCase で行う。
     */
    public function viewNotices(User $user, Team $team): bool
    {
        return $user->rolesIn($team) !== [];
    }

    /**
     * チームのすべての予約中のお知らせを見て、編集・取り消しできるか(AC-005-10、ADR-0017)。
     */
    public function manageAllNotices(User $user, Team $team): bool
    {
        return $this->hasAny($user, $team, [TeamRole::Representative, TeamRole::Officer]);
    }

    /**
     * 既読・配信状況の確認と再通知(006)。代表・役員はすべて、コーチは自分が作成したお知らせだけ(004 の権限表 ※2)。
     */
    public function viewNoticeStatus(User $user, Team $team, Notice $notice): bool
    {
        if ($this->manageAllNotices($user, $team)) {
            return true;
        }

        return $notice->author_user_id === $user->id && in_array(TeamRole::Coach, $user->rolesIn($team), true);
    }

    /**
     * この予約中のお知らせを編集・取り消しできるか。作成者(今も作成できる役割を持つ)か、代表・役員(AC-005-10)。
     */
    public function manageNotice(User $user, Team $team, Notice $notice): bool
    {
        if ($this->manageAllNotices($user, $team)) {
            return true;
        }

        return $notice->author_user_id === $user->id && $this->createNotices($user, $team);
    }

    /**
     * @param  list<TeamRole>  $roles
     */
    private function hasAny(User $user, Team $team, array $roles): bool
    {
        return array_intersect(array_map(fn (TeamRole $r) => $r->value, $user->rolesIn($team)), array_map(fn (TeamRole $r) => $r->value, $roles)) !== [];
    }
}
