<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * テスト用の宛先は .invalid ドメインにし、実在のアドレスに送らない。
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->userName().'@example.invalid',
            'display_name' => fake()->lastName().fake()->firstName(),
        ];
    }

    /**
     * 初回ログイン直後で、表示名をまだ登録していない状態。
     */
    public function withoutDisplayName(): static
    {
        return $this->state(fn () => ['display_name' => null]);
    }
}
