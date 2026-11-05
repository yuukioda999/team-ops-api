<?php

namespace App\Http\Controllers\Auth;

use App\Http\ApiErrorResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ConsumeTokenRequest;
use App\Http\Requests\Auth\RequestEmailChangeRequest;
use App\Http\Resources\MeResource;
use App\Models\User;
use App\UseCases\Auth\ConfirmEmailChange;
use App\UseCases\Auth\RequestEmailChange;
use Illuminate\Http\JsonResponse;

final class EmailChangeController extends Controller
{
    /**
     * メールアドレスの変更を申請する(AC-001-09)。
     */
    public function store(RequestEmailChangeRequest $request, RequestEmailChange $requestEmailChange): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $requestEmailChange($user, $request->address());

        return new JsonResponse(['message' => '確認メールを送信しました'], 202);
    }

    /**
     * 確認メールのリンクで変更を確定する(AC-001-09・10)。別の端末で開いても確定できるよう、
     * ログインは求めない(トークンが本人の確認になる)。
     */
    public function consume(ConsumeTokenRequest $request, ConfirmEmailChange $confirmEmailChange): JsonResponse
    {
        $result = $confirmEmailChange($request->token());

        return match ($result) {
            ConfirmEmailChange::INVALID => new JsonResponse(['message' => ApiErrorResponse::message(410)], 410),
            ConfirmEmailChange::TAKEN => new JsonResponse(['message' => 'このメールアドレスはすでに使われているため、変更できません'], 409),
            default => (new MeResource($result))->response()->setStatusCode(200),
        };
    }
}
