<?php

namespace Database\Factories;

use App\Enums\Sport;
use App\Enums\TeamRole;
use App\Models\Team;
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
     * 作成者を代表として所属させる。
     */
    public function withRepresentative(): static
    {
        return $this->afterCreating(function (Team $team): void {
            if ($team->created_by !== null) {
                $team->members()->attach($team->created_by, ['role' => TeamRole::Representative]);
            }
        });
    }
}
