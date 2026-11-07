<?php

namespace App\UseCases\AuditLogs;

use App\Models\AuditLog;
use App\Models\Team;
use App\Models\TeamMembership;
use Illuminate\Database\Eloquent\Collection;

/**
 * 監査ログを新しい順に100件ずつ(AC-004-12)。$before を渡すと、その ID より古いものを返す。
 * 操作した人がまだチームにいるかも返す(いなければ画面で「退会したメンバー」と表示する)。
 */
final class ListAuditLogs
{
    public const int PER_PAGE = 100;

    /**
     * @return array{logs: Collection<int, AuditLog>, member_user_ids: array<int, true>, next_before: int|null}
     */
    public function __invoke(Team $team, ?int $before): array
    {
        $query = AuditLog::where('team_id', $team->id)->with('actor')->orderByDesc('id')->limit(self::PER_PAGE + 1);
        if ($before !== null) {
            $query->where('id', '<', $before);
        }
        $logs = $query->get();
        $hasMore = $logs->count() > self::PER_PAGE;
        $logs = $logs->take(self::PER_PAGE)->values();

        $stillMembers = TeamMembership::where('team_id', $team->id)
            ->whereIn('user_id', $logs->pluck('actor_user_id')->filter()->unique()->all())
            ->pluck('user_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $memberUserIds = array_fill_keys($stillMembers, true);

        return [
            'logs' => $logs,
            'member_user_ids' => $memberUserIds,
            'next_before' => $hasMore ? $logs->last()?->id : null,
        ];
    }
}
