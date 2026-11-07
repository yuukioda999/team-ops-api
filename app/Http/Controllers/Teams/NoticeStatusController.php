<?php

namespace App\Http\Controllers\Teams;

use App\Http\Controllers\Controller;
use App\Http\Resources\Notices\NoticeDeliveryStatusResource;
use App\Http\Resources\Notices\NoticeRecipientResource;
use App\Http\Resources\Notices\NoticeReminderResource;
use App\Models\NoticeRecipient;
use App\Models\Team;
use App\Models\User;
use App\UseCases\Notices\ListNoticeRecipients;
use App\UseCases\Notices\NoticeStatusSummary;
use App\UseCases\Notices\SendReminder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 既読・配信状況と再通知(006)。代表・役員、または作成者のコーチだけ(ADR-0020)。
 */
final class NoticeStatusController extends Controller
{
    public function show(Request $request, Team $team, int $notice, NoticeStatusSummary $summary): JsonResponse
    {
        return new JsonResponse(['status' => (new NoticeDeliveryStatusResource($summary($this->actor($request), $team, $notice)))->resolve()]);
    }

    public function unread(Request $request, Team $team, int $notice, ListNoticeRecipients $list): JsonResponse
    {
        return $this->recipients($list->unread($this->actor($request), $team, $notice), false);
    }

    public function failures(Request $request, Team $team, int $notice, ListNoticeRecipients $list): JsonResponse
    {
        return $this->recipients($list->failures($this->actor($request), $team, $notice), true);
    }

    public function remind(Request $request, Team $team, int $notice, SendReminder $send): JsonResponse
    {
        return new JsonResponse(['reminder' => (new NoticeReminderResource($send($this->actor($request), $team, $notice)))->resolve()], 201);
    }

    /**
     * @param  array{members: list<NoticeRecipient>, former_members: int}  $list
     */
    private function recipients(array $list, bool $failures): JsonResponse
    {
        return new JsonResponse([
            'members' => array_map(function (NoticeRecipient $r) use ($failures): array {
                $resource = new NoticeRecipientResource($r);
                $resource->failures = $failures;

                return $resource->resolve();
            }, $list['members']),
            'former_members' => $list['former_members'],
        ]);
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
