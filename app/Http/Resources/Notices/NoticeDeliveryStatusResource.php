<?php

namespace App\Http\Resources\Notices;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * お知らせの配信状況(AC-006-04・07)と、再通知できるか(AC-006-12・13・15)。NoticeStatusSummary の結果を渡す。
 *
 * @property array{
 *     title: string, state: string, recipients: int, read: int, unread: int, unread_members: int,
 *     delivered: int, pending: int, failed: int, skipped: int,
 *     reminders: array{sent: int, max: int, available: bool, reason: string|null, next_available_at: string|null}
 * } $resource
 */
final class NoticeDeliveryStatusResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $s = $this->resource;

        return [
            'title' => $s['title'],
            'state' => $s['state'],
            'recipients' => $s['recipients'],
            'read' => $s['read'],
            'unread' => $s['unread'],
            'unread_members' => $s['unread_members'],
            'delivered' => $s['delivered'],
            'pending' => $s['pending'],
            'failed' => $s['failed'],
            'skipped' => $s['skipped'],
            'reminders' => [
                'sent' => $s['reminders']['sent'],
                'max' => $s['reminders']['max'],
                'available' => $s['reminders']['available'],
                'reason' => $s['reminders']['reason'],
                'next_available_at' => $s['reminders']['next_available_at'],
            ],
        ];
    }
}
