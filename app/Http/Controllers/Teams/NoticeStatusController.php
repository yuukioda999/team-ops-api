<?php

namespace App\Http\Controllers\Teams;

use App\Http\Controllers\Controller;
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
        return new JsonResponse(['status' => $summary($this->actor($request), $team, $notice)]);
    }

    public function unread(Request $request, Team $team, int $notice, ListNoticeRecipients $list): JsonResponse
    {
        return new JsonResponse(['members' => $list->unread($this->actor($request), $team, $notice)]);
    }

    public function failures(Request $request, Team $team, int $notice, ListNoticeRecipients $list): JsonResponse
    {
        return new JsonResponse(['members' => $list->failures($this->actor($request), $team, $notice)]);
    }

    public function remind(Request $request, Team $team, int $notice, SendReminder $send): JsonResponse
    {
        return new JsonResponse(['reminder' => $send($this->actor($request), $team, $notice)], 201);
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
