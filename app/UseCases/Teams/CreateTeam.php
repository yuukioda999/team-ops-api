<?php

namespace App\UseCases\Teams;

use App\Enums\Sport;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * チームを作成し、作成者をそのチームの代表にする(AC-001-11・12)。
 */
final class CreateTeam
{
    public const int MAX_TEAMS_PER_REPRESENTATIVE = 5;

    /**
     * @return Team 作成者の役割(membership)付きのチーム
     */
    public function __invoke(User $creator, string $name, Sport $sport): Team
    {
        return DB::transaction(function () use ($creator, $name, $sport): Team {
            // 同時に作成されても上限を超えないよう、作成者の行をロックしてから数える
            User::whereKey($creator->id)->lockForUpdate()->first();

            if ($creator->createdTeams()->count() >= self::MAX_TEAMS_PER_REPRESENTATIVE) {
                throw ValidationException::withMessages([
                    'team' => '代表として作成できるチームは'.self::MAX_TEAMS_PER_REPRESENTATIVE.'つまでです。',
                ]);
            }

            $team = Team::create(['name' => $name, 'sport' => $sport, 'created_by' => $creator->id]);
            $team->members()->attach($creator->id);
            TeamMembership::where('team_id', $team->id)->where('user_id', $creator->id)->sole()->grant([TeamRole::Representative]);

            return $creator->teams()->whereKey($team->id)->firstOrFail();
        });
    }
}
