<?php

namespace App\Http\Controllers\Teams;

use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\CreateTeamRequest;
use App\Http\Resources\TeamResource;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use App\UseCases\Teams\CreateTeam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TeamController extends Controller
{
    /**
     * チームを作成する(AC-001-11・12)。
     */
    public function store(CreateTeamRequest $request, CreateTeam $createTeam): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $team = $createTeam($user, $request->name(), $request->sport());

        return (new TeamResource($team, $this->membership($team, $user)))->response()->setStatusCode(201);
    }

    /**
     * チームの情報。{team} は所属しているチームからだけ探す(ADR-0009、AC-001-13)。
     */
    public function show(Request $request, Team $team): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return (new TeamResource($team, $this->membership($team, $user)))->response()->setStatusCode(200);
    }

    private function membership(Team $team, User $user): TeamMembership
    {
        return TeamMembership::where('team_id', $team->id)->where('user_id', $user->id)->firstOrFail();
    }
}
