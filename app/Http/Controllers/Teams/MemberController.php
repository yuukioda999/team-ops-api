<?php

namespace App\Http\Controllers\Teams;

use App\Http\Controllers\Controller;
use App\Http\Requests\Members\ChangeRolesRequest;
use App\Http\Requests\Members\TransferRepresentativeRequest;
use App\Http\Resources\MemberResource;
use App\Models\Team;
use App\Models\User;
use App\UseCases\Members\ChangeMemberRoles;
use App\UseCases\Members\ListMembers;
use App\UseCases\Members\RemoveMember;
use App\UseCases\Members\TransferRepresentative;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * メンバーの管理(004)。権限は TeamPolicy で判定する(ADR-0015)。
 */
final class MemberController extends Controller
{
    public function index(Team $team, ListMembers $list): JsonResponse
    {
        Gate::authorize('viewMembers', $team);

        return new JsonResponse(['members' => MemberResource::collection($list($team))->resolve()]);
    }

    public function roles(ChangeRolesRequest $request, Team $team, int $membership, ChangeMemberRoles $change): JsonResponse
    {
        return new JsonResponse(['member' => (new MemberResource($change($this->actor($request), $team, $membership, $request->roles())))->resolve()]);
    }

    public function transfer(TransferRepresentativeRequest $request, Team $team, TransferRepresentative $transfer): JsonResponse
    {
        return new JsonResponse(['member' => (new MemberResource($transfer($this->actor($request), $team, $request->membershipId())))->resolve()]);
    }

    public function destroy(Request $request, Team $team, int $membership, RemoveMember $remove): Response
    {
        // 相手の役割による判定(AC-004-07)は、相手を見つけてから UseCase の中で Policy に聞く
        Gate::authorize('removeMembers', $team);
        $remove($this->actor($request), $team, $membership);

        return response()->noContent();
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
