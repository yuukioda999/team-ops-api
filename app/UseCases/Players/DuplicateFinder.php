<?php

namespace App\UseCases\Players;

use App\Enums\Grade;
use App\Models\Player;
use App\Models\Team;
use App\Support\Text\NameKey;

/**
 * 同じ表示名・同じ学年の選手を探す(AC-003-03・21、ADR-0013)。
 * 表示名は暗号化しているので、同じ学年の選手を復号してアプリの中で比べる。
 */
final class DuplicateFinder
{
    /**
     * チームの選手の「学年:名前の比較用の形」の一覧。
     *
     * @return array<string, true>
     */
    public function keysIn(Team $team, ?Grade $grade = null): array
    {
        $query = Player::where('team_id', $team->id);
        if ($grade !== null) {
            $query->where('grade', $grade);
        }

        $keys = [];
        foreach ($query->get(['id', 'display_name', 'grade']) as $player) {
            $keys[self::key($player->display_name, $player->grade)] = true;
        }

        return $keys;
    }

    public function exists(Team $team, string $displayName, Grade $grade): bool
    {
        return isset($this->keysIn($team, $grade)[self::key($displayName, $grade)]);
    }

    public static function key(string $displayName, Grade $grade): string
    {
        return $grade->value.':'.NameKey::of($displayName);
    }
}
