<?php

namespace App\UseCases\Teams;

use App\Enums\Sport;
use App\Models\Team;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * チーム名・競技を変える(AC-004-11)。監査ログには変わった項目の名前だけを残す。
 */
final class UpdateTeam
{
    /**
     * @param  array{name?: string, sport?: Sport}  $changes
     */
    public function __invoke(User $actor, Team $team, array $changes): Team
    {
        return DB::transaction(function () use ($actor, $team, $changes): Team {
            $team->fill($changes);
            $changed = array_keys($team->getDirty());
            $team->save();

            if ($changed !== []) {
                sort($changed);
                // 対象はチーム自身なので team_id で分かる。subject_id は数値の ID の列なので、ULID のチームは入れない
                Audit::record($team, $actor, 'team.updated', null, ['fields' => $changed]);
            }

            return $team;
        });
    }
}
