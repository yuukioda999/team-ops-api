<?php

namespace Database\Factories;

use App\Enums\Sport;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Team>
 */
class TeamFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->city().'FC',
            'sport' => Sport::Soccer,
            'created_by' => User::factory(),
        ];
    }

    /**
     * 利用者を、指定した役割でメンバーにする。
     *
     * @param  list<TeamRole>  $roles
     */
    public static function addMember(Team $team, User $user, array $roles): TeamMembership
    {
        $team->members()->attach($user->id);
        $membership = TeamMembership::where('team_id', $team->id)->where('user_id', $user->id)->sole();
        $membership->grant($roles);

        return $membership;
    }

    /**
     * 作成者を代表として所属させる。
     */
    public function withRepresentative(): static
    {
        return $this->afterCreating(function (Team $team): void {
            if ($team->created_by !== null) {
                $team->members()->attach($team->created_by);
                TeamMembership::where('team_id', $team->id)->where('user_id', $team->created_by)->sole()->grant([TeamRole::Representative]);
            }
        });
    }
}
