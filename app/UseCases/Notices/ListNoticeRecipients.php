<?php

namespace App\UseCases\Notices;

use App\Enums\DeliveryStatus;
use App\Models\NoticeRecipient;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * 配信状況の一覧:未読のメンバー(AC-006-05)と、メールが届かなかった・送らなかったメンバー(AC-006-06)。
 * 今もチームにいる人だけを表示名で返す。チームを抜けた人は人数だけ返す(ADR-0003、レビュー S2)。
 * 表示名は暗号化しているので、並べ替えは読み出してから行う。
 */
final class ListNoticeRecipients
{
    /**
     * @return array{members: list<NoticeRecipient>, former_members: int}
     */
    public function unread(User $actor, Team $team, int $noticeId): array
    {
        $notice = NoticeStatusFinder::find($actor, $team, $noticeId);

        return $this->split($team, NoticeRecipient::where('notice_id', $notice->id)->whereNull('read_at'));
    }

    /**
     * @return array{members: list<NoticeRecipient>, former_members: int}
     */
    public function failures(User $actor, Team $team, int $noticeId): array
    {
        $notice = NoticeStatusFinder::find($actor, $team, $noticeId);

        return $this->split($team, NoticeRecipient::where('notice_id', $notice->id)->whereIn('delivery_status', [DeliveryStatus::Failed, DeliveryStatus::Skipped]));
    }

    /**
     * @param  Builder<NoticeRecipient>  $query
     * @return array{members: list<NoticeRecipient>, former_members: int}
     */
    private function split(Team $team, Builder $query): array
    {
        $members = TeamMembership::where('team_id', $team->id)->select('user_id');
        $former = (clone $query)->whereNotIn('user_id', $members)->count();
        $rows = $query->whereIn('user_id', $members)->with('user:id,display_name')->get();

        return [
            'members' => array_values($rows->sortBy(fn (NoticeRecipient $r) => (string) $r->user?->display_name)->all()),
            'former_members' => $former,
        ];
    }
}
