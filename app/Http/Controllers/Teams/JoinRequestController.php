<?php

namespace App\Http\Controllers\Teams;

use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\ApproveJoinRequestRequest;
use App\Http\Resources\JoinRequestResource;
use App\Models\Team;
use App\Models\User;
use App\UseCases\JoinRequests\ApproveJoinRequest;
use App\UseCases\JoinRequests\ListPendingJoinRequests;
use App\UseCases\JoinRequests\RejectJoinRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * 承認待ちの申請の一覧・承認・却下(AC-002-11〜14)。代表・役員だけが扱える(TeamPolicy)。
 */
final class JoinRequestController extends Controller
{
    public function index(Team $team, ListPendingJoinRequests $listPending): JsonResponse
    {
        Gate::authorize('reviewJoinRequests', $team);

        return new JsonResponse(['join_requests' => JoinRequestResource::collection($listPending($team))->resolve()]);
    }

    public function approve(ApproveJoinRequestRequest $request, Team $team, int $joinRequest, ApproveJoinRequest $approve): Response
    {
        Gate::authorize('reviewJoinRequests', $team);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $approve($user, $team, $joinRequest, $request->roles());

        return response()->noContent();
    }

    public function reject(Request $request, Team $team, int $joinRequest, RejectJoinRequest $reject): Response
    {
        Gate::authorize('reviewJoinRequests', $team);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $reject($user, $team, $joinRequest);

        return response()->noContent();
    }
}
