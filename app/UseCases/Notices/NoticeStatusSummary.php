<?php

namespace App\UseCases\Notices;

use App\Enums\DeliveryStatus;
use App\Models\Notice;
use App\Models\NoticeRecipient;
use App\Models\NoticeReminder;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * お知らせの配信状況(AC-006-04・07)と、再通知できるか(AC-006-12・13・15)。
 */
final class NoticeStatusSummary
{
    /**
     * @return array{
     *     state: 'sending'|'done',
     *     recipients: int, read: int, unread: int,
     *     delivered: int, pending: int, failed: int, skipped: int,
     *     reminders: array{sent: int, max: int, available: bool, reason: 'interval'|'max'|'no_unread'|null, next_available_at: string|null}
     * }
     */
    public function __invoke(User $actor, Team $team, int $noticeId): array
    {
        $notice = NoticeStatusFinder::find($actor, $team, $noticeId);
        $rows = NoticeRecipient::where('notice_id', $notice->id)
            ->selectRaw('delivery_status, COUNT(*) AS total, SUM(CASE WHEN read_at IS NULL THEN 0 ELSE 1 END) AS read_count')
            ->groupBy('delivery_status')
            ->toBase()
            ->get();
        $by = ['pending' => 0, 'delivered' => 0, 'failed' => 0, 'skipped' => 0];
        $total = 0;
        $read = 0;
        foreach ($rows as $row) {
            $by[(string) $row->delivery_status] = (int) $row->total;
            $total += (int) $row->total;
            $read += (int) $row->read_count;
        }

        return [
            'state' => $by[DeliveryStatus::Pending->value] > 0 ? 'sending' : 'done',
            'recipients' => $total,
            'read' => $read,
            'unread' => $total - $read,
            'delivered' => $by['delivered'],
            'pending' => $by['pending'],
            'failed' => $by['failed'],
            'skipped' => $by['skipped'],
            'reminders' => self::reminderAvailability($team, $notice, now()),
        ];
    }

    /**
     * @return array{sent: int, max: int, available: bool, reason: 'interval'|'max'|'no_unread'|null, next_available_at: string|null}
     */
    public static function reminderAvailability(Team $team, Notice $notice, Carbon $now): array
    {
        $sent = NoticeReminder::where('notice_id', $notice->id)->count();
        $last = NoticeReminder::where('notice_id', $notice->id)->orderByDesc('number')->first();
        $next = $last?->created_at->copy()->addMinutes(NoticeReminder::INTERVAL_MINUTES);
        $reason = match (true) {
            $sent >= NoticeReminder::MAX => 'max',
            $next !== null && $next->isAfter($now) => 'interval',
            self::unreadMemberIds($team, $notice) === [] => 'no_unread',
            default => null,
        };

        return [
            'sent' => $sent,
            'max' => NoticeReminder::MAX,
            'available' => $reason === null,
            'reason' => $reason,
            'next_available_at' => $reason === 'interval' ? $next->toIso8601String() : null,
        ];
    }

    /**
     * まだ開いていない宛先のうち、今もチームにいる人(除名された人はお知らせを開けない)。
     *
     * @return list<int>
     */
    public static function unreadMemberIds(Team $team, Notice $notice): array
    {
        return array_values(array_map('intval', NoticeRecipient::where('notice_id', $notice->id)
            ->whereNull('read_at')
            ->whereIn('user_id', TeamMembership::where('team_id', $team->id)->select('user_id'))
            ->orderBy('user_id')
            ->pluck('user_id')
            ->all()));
    }
}
