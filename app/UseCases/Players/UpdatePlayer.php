<?php

namespace App\UseCases\Players;

use App\Enums\Grade;
use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * 選手の情報を編集する(AC-003-02・20)。監査ログには変わった項目の名前だけを残す。
 */
final class UpdatePlayer
{
    /**
     * @param  array{display_name?: string, grade?: Grade, number?: int|null}  $changes
     */
    public function __invoke(User $actor, Team $team, int $playerId, array $changes): Player
    {
        return DB::transaction(function () use ($actor, $team, $playerId, $changes): Player {
            $player = Player::where('team_id', $team->id)->lockForUpdate()->findOrFail($playerId);
            $player->fill($changes);
            $changed = array_keys($player->getDirty());
            $player->save();

            if ($changed !== []) {
                sort($changed);
                Audit::record($team, $actor, 'player.updated', $player, ['fields' => $changed]);
            }

            return $player->load('guardians.membership.user');
        });
    }
}
