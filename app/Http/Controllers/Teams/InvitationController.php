<?php

namespace App\Http\Controllers\Teams;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvitationResource;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\UseCases\Invitations\IssueInvitation;
use App\UseCases\Invitations\RevokeInvitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * チームの招待リンク(AC-002-01〜04)。代表・役員だけが扱える(TeamPolicy)。
 */
final class InvitationController extends Controller
{
    public function show(Team $team): JsonResponse
    {
        Gate::authorize('manageInvitation', $team);
        $invitation = TeamInvitation::where('team_id', $team->id)->active()->first();

        return new JsonResponse(['invitation' => $invitation !== null ? (new InvitationResource($invitation))->resolve() : null]);
    }

    public function store(Request $request, Team $team, IssueInvitation $issue): JsonResponse
    {
        Gate::authorize('manageInvitation', $team);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return new JsonResponse(['invitation' => (new InvitationResource($issue($user, $team)))->resolve()], 201);
    }

    public function destroy(Request $request, Team $team, RevokeInvitation $revoke): Response
    {
        Gate::authorize('manageInvitation', $team);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $revoke($user, $team);

        return response()->noContent();
    }
}
