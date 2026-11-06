<?php

namespace App\UseCases\Invitations;

use App\Models\Team;
use App\Models\TeamInvitation;

/**
 * チームの今の招待リンク(AC-002-01)。有効なリンクがなければ null。
 */
final class ShowInvitation
{
    public function __invoke(Team $team): ?TeamInvitation
    {
        return TeamInvitation::where('team_id', $team->id)->active()->first();
    }
}
