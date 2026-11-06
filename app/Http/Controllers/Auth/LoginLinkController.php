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
        $requestLoginLink($request->address(), $request->returnTo());

        return new JsonResponse(['message' => 'メールを送信しました'], 202);
    }

    /**
     * リンクのトークンでログインする(AC-001-03・04)。
     */
    public function consume(ConsumeTokenRequest $request, ConsumeLoginLink $consumeLoginLink): JsonResponse
    {
        // Web と同じオリジンからでない(セッションがない)呼び出しでは、トークンを使い切る前に断る。
        // そうしないと、ログインできないのにリンクだけが使用済みになる
        if (! $request->hasSession()) {
            return new JsonResponse(['message' => ApiErrorResponse::message(419)], 419);
        }

        $result = $consumeLoginLink($request->token());
        if ($result === null) {
            return new JsonResponse(['message' => ApiErrorResponse::message(410)], 410);
        }
        [$user, $returnTo] = $result;

        Auth::guard('web')->login($user);
        // セッション固定攻撃を防ぐため、ログインのたびにセッションIDを作り直す(ADR-0008)
        $request->session()->regenerate();

        // ログイン後に戻る先(AC-001-19)。画面はここへ進む
        return new JsonResponse([...(new MeResource($user))->resolve($request), 'return_to' => $returnTo], 200);
    }
}
