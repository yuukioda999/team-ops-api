<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdateMeRequest;
use App\Http\Resources\MeResource;
use App\Models\User;
use App\UseCases\Auth\UpdateDisplayName;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return (new MeResource($this->user($request)))->response()->setStatusCode(200);
    }

    public function update(UpdateMeRequest $request, UpdateDisplayName $updateDisplayName): JsonResponse
    {
        $user = $updateDisplayName($this->user($request), $request->displayName());

        return (new MeResource($user))->response()->setStatusCode(200);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
