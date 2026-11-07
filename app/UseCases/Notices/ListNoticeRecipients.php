<?php

namespace App\UseCases\Notices;

use App\Enums\DeliveryStatus;
use App\Models\NoticeRecipient;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * 配信状況の一覧:未読の人(AC-006-05)と、メールが届かなかった・送らなかった人(AC-006-06)。
 * 表示名は暗号化しているので、並べ替えは読み出してから行う。除名された人は member が false。
 */
final class ListNoticeRecipients
{
    /**
     * @return list<array{display_name: string|null, member: bool}>
     */
    public function unread(User $actor, Team $team, int $noticeId): array
    {
        $notice = NoticeStatusFinder::find($actor, $team, $noticeId);
        $rows = $this->sorted(NoticeRecipient::where('notice_id', $notice->id)->whereNull('read_at')->with('user:id,display_name')->get());
        $members = $this->memberIds($team, $rows);

        return array_map(fn (NoticeRecipient $r) => [
            'display_name' => $r->user?->display_name,
            'member' => isset($members[$r->user_id]),
        ], $rows);
    }

    /**
     * @return list<array{display_name: string|null, member: bool, status: string, reason: string|null}>
     */
    public function failures(User $actor, Team $team, int $noticeId): array
    {
        $notice = NoticeStatusFinder::find($actor, $team, $noticeId);
        $rows = $this->sorted(NoticeRecipient::where('notice_id', $notice->id)
            ->whereIn('delivery_status', [DeliveryStatus::Failed, DeliveryStatus::Skipped])
            ->with('user:id,display_name')
            ->get());
        $members = $this->memberIds($team, $rows);

        return array_map(fn (NoticeRecipient $r) => [
            'display_name' => $r->user?->display_name,
            'member' => isset($members[$r->user_id]),
            'status' => $r->delivery_status->value,
            'reason' => $r->delivery_error,
        ], $rows);
    }

    /**
     * @param  Collection<int, NoticeRecipient>  $rows
     * @return list<NoticeRecipient>
     */
    private function sorted(Collection $rows): array
    {
        return array_values($rows->sortBy(fn (NoticeRecipient $r) => (string) $r->user?->display_name)->all());
    }

    /**
     * @param  list<NoticeRecipient>  $rows
     * @return array<int, true>
     */
    private function memberIds(Team $team, array $rows): array
    {
        $ids = TeamMembership::where('team_id', $team->id)->whereIn('user_id', array_map(fn (NoticeRecipient $r) => $r->user_id, $rows))->pluck('user_id')->all();

        return array_fill_keys(array_map('intval', $ids), true);
    }
}
