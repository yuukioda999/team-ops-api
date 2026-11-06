<?php

namespace App\UseCases\Invitations;

use App\Models\Team;
use App\Models\TeamJoinRequest;
use App\Models\TeamMembership;
use App\Models\User;

/**
 * 招待リンクのチームと、開いた人とそのチームとの関係を返す(AC-002-05・07・08・09)。
 * 返すのはチーム名と競技だけ。チームIDは、そのチームのメンバーにだけ返す。
 */
final class PreviewInvitation
{
    public function __construct(private readonly FindInvitation $findInvitation) {}

    /**
     * @return array{team: Team, relation: 'member'|'pending'|'none'|null}|null 無効なリンクのときは null
     */
    public function __invoke(string $plainToken, ?User $viewer): ?array
    {
        $invitation = ($this->findInvitation)($plainToken);
        if ($invitation === null) {
            return null;
        }
        $team = $invitation->team()->firstOrFail();

        if ($viewer === null) {
            return ['team' => $team, 'relation' => null];
        }
        if (TeamMembership::where('team_id', $team->id)->where('user_id', $viewer->id)->exists()) {
            return ['team' => $team, 'relation' => 'member'];
        }
        if (TeamJoinRequest::where('team_id', $team->id)->where('pending_user_id', $viewer->id)->exists()) {
            return ['team' => $team, 'relation' => 'pending'];
        }

        return ['team' => $team, 'relation' => 'none'];
    }
}
