<?php

namespace App\Http\Controllers\Teams;

use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\CreateTeamRequest;
use App\Http\Resources\TeamResource;
use App\Models\Team;
use App\Models\User;
use App\UseCases\Teams\CreateTeam;
use Illuminate\Http\JsonResponse;

final class TeamController extends Controller
{
    /**
     * チームを作成する(AC-001-11・12)。
     */
    public function store(CreateTeamRequest $request, CreateTeam $createTeam): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return (new TeamResource($createTeam($user, $request->name(), $request->sport())))->response()->setStatusCode(201);
    }

    /**
     * チームの情報。{team} は所属しているチームからだけ、役割付きで探してある(ADR-0009、AC-001-13)。
     */
    public function show(Team $team): JsonResponse
    {
        return (new TeamResource($team))->response()->setStatusCode(200);
    }
}
