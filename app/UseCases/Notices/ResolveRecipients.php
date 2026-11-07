<?php

namespace App\UseCases\Notices;

use App\Enums\NoticeTarget;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamMemberRole;
use App\Models\TeamMembership;
use Illuminate\Database\Eloquent\Builder;

/**
 * 宛先の指定から、実際に届くユーザーを決める(spec の「宛先の決め方」、AC-005-03・12、ADR-0017)。
 * - 全員:チームの全メンバー
 * - 学年:その学年の選手に紐づく保護者 + 代表・役員・コーチ全員
 * - 役割:選んだ役割を持つメンバー
 * どれでも送信者本人(チームにいれば)を足し、同じ人は1回だけ。ユーザーIDの順に返す。
 */
final class ResolveRecipients
{
    /**
     * @return list<int>
     */
    public function __invoke(Team $team, NoticeAudience $audience, ?int $senderUserId): array
    {
        $members = TeamMembership::where('team_id', $team->id);

        $userIds = match ($audience->type) {
            NoticeTarget::All => $members->pluck('user_id')->all(),
            NoticeTarget::Roles => $members
                ->whereHas('roles', fn (Builder $q) => $q->whereIn('role', $audience->values))
                ->pluck('user_id')->all(),
            NoticeTarget::Grades => array_merge(
                // その学年の選手に紐づく保護者(選手との紐づけは保護者の役割を持つメンバーだけ、AC-003-07)
                TeamMembership::where('team_id', $team->id)
                    ->whereIn('id', fn ($q) => $q->select('player_guardians.membership_id')
                        ->from('player_guardians')
                        ->join('players', 'players.id', '=', 'player_guardians.player_id')
                        ->where('players.team_id', $team->id)
                        ->whereIn('players.grade', $audience->values))
                    ->pluck('user_id')->all(),
                // 代表・役員・コーチ全員
                TeamMembership::where('team_id', $team->id)
                    ->whereIn('id', TeamMemberRole::where('team_id', $team->id)
                        ->whereIn('role', [TeamRole::Representative->value, TeamRole::Officer->value, TeamRole::Coach->value])
                        ->select('membership_id'))
                    ->pluck('user_id')->all(),
            ),
        };

        if ($senderUserId !== null && TeamMembership::where('team_id', $team->id)->where('user_id', $senderUserId)->exists()) {
            $userIds[] = $senderUserId;
        }

        $unique = array_values(array_unique(array_map(fn (mixed $id): int => (int) $id, $userIds)));
        sort($unique);

        return $unique;
    }
}
