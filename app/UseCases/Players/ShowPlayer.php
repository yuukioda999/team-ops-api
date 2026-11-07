<?php

namespace App\UseCases\Players;

use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use App\Support\Audit\Audit;

/**
 * 1人の選手の詳細。見られる範囲にない選手は、存在しない場合と同じ404にする(AC-003-10)。
 */
final class ShowPlayer
{
    public function __invoke(User $actor, Team $team, int $playerId): Player
    {
        $scope = RosterScope::for($actor, $team);
        $player = $scope->query()->with('guardians.membership.user')->findOrFail($playerId);

        Audit::record($team, $actor, 'player.viewed', $player, ['scope' => $scope->name]);

        return $player;
    }
}
