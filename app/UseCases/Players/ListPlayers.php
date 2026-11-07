<?php

namespace App\UseCases\Players;

use App\Enums\Grade;
use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Database\Eloquent\Collection;

/**
 * 名簿を、見られる範囲と絞り込みの条件で返す。閲覧したことを監査ログに残す(AC-003-08〜12)。
 */
final class ListPlayers
{
    /**
     * @return array{scope: string, players: Collection<int, Player>}
     */
    public function __invoke(User $actor, Team $team, ?Grade $grade, ?int $number): array
    {
        $scope = RosterScope::for($actor, $team);
        $query = $scope->query()->with('guardians.membership.user');
        if ($grade !== null) {
            $query->where('grade', $grade);
        }
        if ($number !== null) {
            $query->where('number', $number);
        }

        // 学年の順(未就学 → 中3)、背番号の順。表示名は暗号化しているので並べ替えに使わない
        $order = array_map(fn (Grade $g) => $g->value, Grade::cases());
        $players = $query->get()
            ->sortBy([
                fn (Player $a, Player $b) => array_search($a->grade->value, $order, true) <=> array_search($b->grade->value, $order, true),
                fn (Player $a, Player $b) => [$a->number === null, $a->number, $a->id] <=> [$b->number === null, $b->number, $b->id],
            ])
            ->values();

        Audit::record($team, $actor, 'player.viewed_list', null, [
            'scope' => $scope->name,
            'grade' => $grade?->value,
            'number' => $number,
            'count' => $players->count(),
        ]);

        return ['scope' => $scope->name, 'players' => $players];
    }
}
