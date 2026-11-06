<?php

namespace App\UseCases\Invitations;

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * 招待リンクを発行する。チームで有効なリンクは常に1つなので、古いリンクは無効にする(AC-002-01・02)。
 */
final class IssueInvitation
{
    public function __invoke(User $actor, Team $team): TeamInvitation
    {
        return DB::transaction(function () use ($actor, $team): TeamInvitation {
            // 同時に発行されても有効なリンクが2つにならないよう、チームの行をロックしてから入れ替える
            Team::whereKey($team->id)->lockForUpdate()->first();

            $revoked = TeamInvitation::where('team_id', $team->id)->active()->update(['revoked_at' => now()]);

            $plain = bin2hex(random_bytes(32)); // 256ビット(AC-002-01:128ビット以上)
            $invitation = TeamInvitation::create([
                'team_id' => $team->id,
                'token_hash' => TeamInvitation::hashToken($plain),
                'token' => $plain,
                'created_by' => $actor->id,
                'expires_at' => now()->addDays(TeamInvitation::TTL_DAYS),
            ]);

            Audit::record($team, $actor, 'invitation.issued', $invitation, ['replaced_previous' => $revoked > 0]);

            return $invitation;
        });
    }
}
