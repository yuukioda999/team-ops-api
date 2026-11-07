<?php

namespace App\UseCases\Players;

use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 選手を登録する(AC-003-01・03・04・20)。同じ表示名・同じ学年の選手がいても登録し、警告を返す。
 */
final class CreatePlayer
{
    public function __construct(private readonly DuplicateFinder $duplicates) {}

    /**
     * @return array{player: Player, possible_duplicate: bool}
     */
    public function __invoke(User $actor, Team $team, PlayerInput $input): array
    {
        return DB::transaction(function () use ($actor, $team, $input): array {
            // 同時に登録されても上限を超えないよう、チームの行をロックしてから数える
            Team::whereKey($team->id)->lockForUpdate()->first();
            self::ensureRoom($team, 1);

            $duplicate = $this->duplicates->exists($team, $input->displayName, $input->grade);
            $player = Player::create([
                'team_id' => $team->id,
                'display_name' => $input->displayName,
                'grade' => $input->grade,
                'number' => $input->number,
            ]);

            Audit::record($team, $actor, 'player.created', $player, ['possible_duplicate' => $duplicate]);

            return ['player' => $player->load('guardians.membership.user'), 'possible_duplicate' => $duplicate];
        });
    }

    /**
     * あと $adding 人を登録できるか。できなければ 422(AC-003-04)。チームの行をロックしてから呼ぶ。
     */
    public static function ensureRoom(Team $team, int $adding): void
    {
        $current = Player::where('team_id', $team->id)->count();
        if ($current + $adding > Player::MAX_PER_TEAM) {
            throw ValidationException::withMessages(['players' => self::limitMessage($current)]);
        }
    }

    public static function limitMessage(int $current): string
    {
        $room = max(0, Player::MAX_PER_TEAM - $current);

        return '選手は1チーム'.Player::MAX_PER_TEAM."人までです。今は{$current}人なので、登録できるのはあと{$room}人です。";
    }
}
