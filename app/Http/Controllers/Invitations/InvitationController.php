<?php

namespace App\Http\Controllers\Invitations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Invitations\InvitationTokenRequest;
use App\Http\Requests\Invitations\SubmitJoinRequestRequest;
use App\Models\TeamJoinRequest;
use App\Models\TeamMembership;
use App\Models\User;
use App\UseCases\Invitations\FindInvitation;
use App\UseCases\JoinRequests\JoinRequestOutcome;
use App\UseCases\JoinRequests\SubmitJoinRequest;
use Illuminate\Http\JsonResponse;

/**
 * 招待リンクを開いた人の操作(AC-002-05〜10・16)。チームの外に置く(チームのデータは返さない)。
 */
final class InvitationController extends Controller
{
    public const string INVALID_LINK = 'リンクが無効です。チームの役員に新しいリンクを依頼してください';

    /**
     * リンクのチーム名を返す(ログイン不要)。ログイン中なら、そのチームとの関係も返す(AC-002-05・07・08・09)。
     */
    public function preview(InvitationTokenRequest $request, FindInvitation $find): JsonResponse
    {
        $invitation = $find($request->token());
        if ($invitation === null) {
            return new JsonResponse(['message' => self::INVALID_LINK], 410);
        }
        $team = $invitation->team()->firstOrFail();

        $user = $request->user();
        $relation = null;
        $teamId = null;
        if ($user instanceof User) {
            if (TeamMembership::where('team_id', $team->id)->where('user_id', $user->id)->exists()) {
                $relation = 'member';
                $teamId = $team->id; // メンバーにだけ、チームの画面へ進むためのIDを返す
            } elseif (TeamJoinRequest::where('team_id', $team->id)->where('pending_user_id', $user->id)->exists()) {
                $relation = 'pending';
            } else {
                $relation = 'none';
            }
        }

        return new JsonResponse([
            'team' => ['name' => $team->name, 'sport' => $team->sport->value],
            'relation' => $relation,
            'team_id' => $teamId,
        ]);
    }

    /**
     * 参加を申請する(AC-002-06・08・09・10・16)。
     */
    public function apply(SubmitJoinRequestRequest $request, SubmitJoinRequest $submit): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        [$outcome, $team] = $submit($user, $request->token(), $request->displayName(), $request->message());

        return match ($outcome) {
            JoinRequestOutcome::Submitted => new JsonResponse(['status' => 'pending', 'team' => ['name' => $team?->name]], 201),
            JoinRequestOutcome::AlreadyPending => new JsonResponse(['status' => 'pending', 'team' => ['name' => $team?->name]], 200),
            JoinRequestOutcome::AlreadyMember => new JsonResponse(['message' => 'すでにこのチームのメンバーです', 'team_id' => $team?->id], 409),
            JoinRequestOutcome::InvalidLink => new JsonResponse(['message' => self::INVALID_LINK], 410),
            JoinRequestOutcome::TooMany => new JsonResponse(['message' => 'このリンクへの申請が多すぎるため、受け付けを止めています。チームの役員にお問い合わせください'], 429),
        };
    }
}
