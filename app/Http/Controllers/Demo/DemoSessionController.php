<?php

namespace App\Http\Controllers\Demo;

use App\Http\ApiErrorResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Demo\DemoRoleRequest;
use App\Http\Resources\MeResource;
use App\Models\User;
use App\UseCases\Demo\CreateDemoTeam;
use App\UseCases\Demo\DemoTeamsFull;
use App\UseCases\Demo\FindDemoUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * デモとしてログイン・役割の切り替え(AC-007-01・02・05、ADR-0021)。デモモードのときだけ(EnsureDemoMode)。
 */
final class DemoSessionController extends Controller
{
    public const string SESSION_KEY = 'demo_team_id';

    /** デモモードが有効か(画面がログインのボタンを出すか決める) */
    public function show(): JsonResponse
    {
        return new JsonResponse(['enabled' => true, 'lifetime_hours' => CreateDemoTeam::LIFETIME_HOURS]);
    }

    public function store(DemoRoleRequest $request, CreateDemoTeam $create, FindDemoUser $find): JsonResponse
    {
        if (! $request->hasSession()) {
            return new JsonResponse(['message' => ApiErrorResponse::message(419)], 419);
        }
        try {
            $team = $create()['team'];
        } catch (DemoTeamsFull) {
            return new JsonResponse(['message' => 'デモが混み合っています。時間をおいてもう一度お試しください。'], 503);
        }
        $user = $find($team->id, $request->role());
        abort_if($user === null, 500);

        return $this->login($request, $user, $team->id, 201);
    }

    public function switch(DemoRoleRequest $request, FindDemoUser $find): JsonResponse
    {
        // デモモードでなければ、ログインより先に 404 にする(EnsureDemoMode)ため、ここでログインを確かめる
        $current = $request->user('sanctum');
        if (! $current instanceof User || ! $request->hasSession()) {
            return new JsonResponse(['message' => ApiErrorResponse::message(401)], 401);
        }
        $teamId = $request->session()->get(self::SESSION_KEY);
        // 自分が作ったデモチームの中でだけ切り替えられる
        if (! is_string($teamId) || $current->demo_team_id !== $teamId) {
            return new JsonResponse(['message' => ApiErrorResponse::message(403)], 403);
        }
        $user = $find($teamId, $request->role());
        if ($user === null) {
            return new JsonResponse(['message' => ApiErrorResponse::message(404)], 404);
        }

        return $this->login($request, $user, $teamId, 200);
    }

    private function login(Request $request, User $user, string $teamId, int $status): JsonResponse
    {
        Auth::guard('web')->login($user);
        // セッション固定攻撃を防ぐため、ログインのたびにセッションIDを作り直す(ADR-0008)
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, $teamId);

        return new JsonResponse([...(new MeResource($user))->resolve($request), 'demo_team_id' => $teamId], $status);
    }
}
