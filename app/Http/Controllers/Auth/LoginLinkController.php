<?php

namespace App\Http\Controllers\Auth;

use App\Http\ApiErrorResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ConsumeTokenRequest;
use App\Http\Requests\Auth\RequestLoginLinkRequest;
use App\Http\Resources\MeResource;
use App\UseCases\Auth\ConsumeLoginLink;
use App\UseCases\Auth\RequestLoginLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

final class LoginLinkController extends Controller
{
    /**
     * ログイン用リンクを要求する。登録の有無にかかわらず同じ応答を返す(AC-001-01・02)。
     */
    public function store(RequestLoginLinkRequest $request, RequestLoginLink $requestLoginLink): JsonResponse
    {
        $requestLoginLink($request->address());

        return new JsonResponse(['message' => 'メールを送信しました'], 202);
    }

    /**
     * リンクのトークンでログインする(AC-001-03・04)。
     */
    public function consume(ConsumeTokenRequest $request, ConsumeLoginLink $consumeLoginLink): JsonResponse
    {
        $user = $consumeLoginLink($request->token());
        if ($user === null) {
            return new JsonResponse(['message' => ApiErrorResponse::message(410)], 410);
        }

        Auth::guard('web')->login($user);
        // セッション固定攻撃を防ぐため、ログインのたびにセッションIDを作り直す(ADR-0008)
        $request->session()->regenerate();

        return (new MeResource($user))->response()->setStatusCode(200);
    }
}
