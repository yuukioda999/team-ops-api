<?php

namespace App\Http\Controllers\Teams;

use App\Http\Controllers\Controller;
use App\Http\Requests\Players\ListPlayersRequest;
use App\Http\Requests\Players\PlayerRequest;
use App\Http\Requests\Players\ReplaceGuardiansRequest;
use App\Http\Resources\GuardianResource;
use App\Http\Resources\PlayerResource;
use App\Models\Team;
use App\Models\User;
use App\UseCases\Players\CreatePlayer;
use App\UseCases\Players\DeletePlayer;
use App\UseCases\Players\ListGuardianCandidates;
use App\UseCases\Players\ListPlayers;
use App\UseCases\Players\ReplaceGuardians;
use App\UseCases\Players\ShowPlayer;
use App\UseCases\Players\UpdatePlayer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * 名簿(003)。閲覧は役割に応じた範囲、変更は代表・役員だけ(TeamPolicy、ADR-0013)。
 * FormRequest を使う操作は、その authorize() で権限を判定する。
 */
final class PlayerController extends Controller
{
    public function index(ListPlayersRequest $request, Team $team, ListPlayers $list): JsonResponse
    {
        $result = $list($this->actor($request), $team, $request->grade(), $request->number());

        return new JsonResponse([
            'scope' => $result['scope'],
            'players' => PlayerResource::collection($result['players'])->resolve(),
        ]);
    }

    public function show(Request $request, Team $team, int $player, ShowPlayer $show): JsonResponse
    {
        Gate::authorize('viewPlayers', $team);

        return new JsonResponse(['player' => (new PlayerResource($show($this->actor($request), $team, $player)))->resolve()]);
    }

    public function store(PlayerRequest $request, Team $team, CreatePlayer $create): JsonResponse
    {
        $result = $create($this->actor($request), $team, $request->player());

        return new JsonResponse([
            'player' => (new PlayerResource($result['player']))->resolve(),
            'possible_duplicate' => $result['possible_duplicate'],
        ], 201);
    }

    public function update(PlayerRequest $request, Team $team, int $player, UpdatePlayer $update): JsonResponse
    {

        return new JsonResponse(['player' => (new PlayerResource($update($this->actor($request), $team, $player, $request->changes())))->resolve()]);
    }

    public function destroy(Request $request, Team $team, int $player, DeletePlayer $delete): Response
    {
        Gate::authorize('managePlayers', $team);
        $delete($this->actor($request), $team, $player);

        return response()->noContent();
    }

    public function guardians(ReplaceGuardiansRequest $request, Team $team, int $player, ReplaceGuardians $replace): JsonResponse
    {

        return new JsonResponse(['player' => (new PlayerResource($replace($this->actor($request), $team, $player, $request->membershipIds())))->resolve()]);
    }

    public function candidates(Team $team, ListGuardianCandidates $candidates): JsonResponse
    {
        Gate::authorize('managePlayers', $team);

        return new JsonResponse([
            'candidates' => GuardianResource::collection($candidates($team))->resolve(),
        ]);
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
