<?php

namespace Database\Seeders;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * ローカル開発用のデータ。宛先は .invalid ドメインだけを使う。
     * email_index はモデルのイベントで作るため、WithoutModelEvents は使わない。
     */
    public function run(): void
    {
        $user = User::create([
            'email' => 'coach@example.invalid',
            'display_name' => 'テスト 代表',
        ]);

        Team::factory()->withRepresentative()->create([
            'name' => 'つくばFC U-10',
            'created_by' => $user->id,
        ]);
    }
}
