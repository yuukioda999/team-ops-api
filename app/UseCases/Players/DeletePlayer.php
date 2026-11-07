<?php

namespace App\UseCases\Players;

use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * 選手を削除する。行ごと消し、保護者との紐づけも外れる(AC-003-02・20、ADR-0013)。
 */
final class DeletePlayer
{
    public function __invoke(User $actor, Team $team, int $playerId): void
    {
        DB::transaction(function () use ($actor, $team, $playerId): void {
            $player = Player::where('team_id', $team->id)->lockForUpdate()->findOrFail($playerId);
            $guardians = $player->guardians()->count();
            $player->delete(); // player_guardians は外部キーで一緒に消える

            Audit::record($team, $actor, 'player.deleted', $player, ['guardians_unlinked' => $guardians]);
        });
    }
}
