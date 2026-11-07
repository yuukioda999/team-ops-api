<?php

namespace App\UseCases\Players;

use App\Enums\TeamRole;
use App\Models\Player;
use App\Models\PlayerGuardian;
use App\Models\Team;
use App\Models\TeamMemberRole;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 選手に紐づける保護者を、指定したメンバーに置き換える(AC-003-05〜07・20)。
 * 選べるのは、このチームで保護者の役割を持つメンバーだけ。外したメンバー自体は残る。
 */
final class ReplaceGuardians
{
    /**
     * @param  list<int>  $membershipIds
     */
    public function __invoke(User $actor, Team $team, int $playerId, array $membershipIds): Player
    {
        $membershipIds = array_values(array_unique($membershipIds));
        if (count($membershipIds) > Player::MAX_GUARDIANS) {
            throw ValidationException::withMessages(['membership_ids' => '保護者は1人の選手に'.Player::MAX_GUARDIANS.'人まで紐づけられます。']);
        }

        return DB::transaction(function () use ($actor, $team, $playerId, $membershipIds): Player {
            $player = Player::where('team_id', $team->id)->lockForUpdate()->findOrFail($playerId);

            // 他チームのメンバーや、保護者の役割を持たないメンバーの ID は、区別せずに同じ 422 にする
            $parents = TeamMemberRole::where('team_id', $team->id)
                ->where('role', TeamRole::Parent)
                ->whereIn('membership_id', $membershipIds)
                ->pluck('membership_id')
                ->all();
            if (count($parents) !== count($membershipIds)) {
                throw ValidationException::withMessages(['membership_ids' => '保護者の役割を持つメンバーだけを紐づけられます。']);
            }

            $current = $player->guardians()->pluck('membership_id')->all();
            $added = array_values(array_diff($membershipIds, $current));
            $removed = array_values(array_diff($current, $membershipIds));

            $player->guardians()->whereIn('membership_id', $removed)->delete();
            foreach ($added as $membershipId) {
                PlayerGuardian::create(['player_id' => $player->id, 'membership_id' => $membershipId]);
            }

            if ($added !== [] || $removed !== []) {
                Audit::record($team, $actor, 'player.guardians_changed', $player, [
                    'added' => array_map('strval', $added),
                    'removed' => array_map('strval', $removed),
                ]);
            }

            return $player->load('guardians.membership.user');
        });
    }
}
