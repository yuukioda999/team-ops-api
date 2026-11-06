<?php

namespace App\UseCases\Invitations;

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * 招待リンクを無効にする。以後そのリンクからは申請できない(AC-002-03)。
 */
final class RevokeInvitation
{
    public function __invoke(User $actor, Team $team): void
    {
        DB::transaction(function () use ($actor, $team): void {
            $invitation = TeamInvitation::where('team_id', $team->id)->active()->lockForUpdate()->first();
            if ($invitation === null) {
                return; // すでに無効。何度押しても同じ結果
            }

            $invitation->forceFill(['revoked_at' => now()])->save();
            Audit::record($team, $actor, 'invitation.revoked', $invitation);
        });
    }
}
