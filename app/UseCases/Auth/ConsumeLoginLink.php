<?php

namespace App\UseCases\Auth;

use App\Enums\LoginTokenPurpose;
use App\Models\User;
use App\Support\Auth\OneTimeToken;
use Illuminate\Support\Facades\DB;

/**
 * ログイン用リンクのトークンを使い、ログインする利用者を返す(AC-001-03・04)。
 * 未登録のメールアドレスなら、ここでアカウントを作る。
 */
final class ConsumeLoginLink
{
    /**
     * @return User|null 使えないトークン(期限切れ・使用済み・不正)のときは null
     */
    public function __invoke(string $plainToken): ?User
    {
        return DB::transaction(function () use ($plainToken): ?User {
            $token = OneTimeToken::consume(LoginTokenPurpose::Login, $plainToken);
            if ($token === null) {
                return null;
            }

            // リンクを要求してから使うまでの間に、同じアドレスのアカウントができている場合もある
            return User::whereEmail($token->email)->first()
                ?? User::create(['email' => $token->email]);
        });
    }
}
