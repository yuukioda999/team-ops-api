<?php

namespace App\UseCases\Auth;

use App\Enums\LoginTokenPurpose;
use App\Models\User;
use App\Support\Auth\OneTimeToken;
use App\Support\Crypto\BlindIndex;
use Illuminate\Support\Facades\DB;

/**
 * ログイン用リンクのトークンを使い、ログインする利用者を返す(AC-001-03・04)。
 * 未登録のメールアドレスなら、ここでアカウントを作る。
 */
final class ConsumeLoginLink
{
    /**
     * @return array{User, string|null}|null ログインする利用者と、ログイン後に戻る先(AC-001-19)。
     *                                       使えないトークン(期限切れ・使用済み・不正)のときは null
     */
    public function __invoke(string $plainToken): ?array
    {
        return DB::transaction(function () use ($plainToken): ?array {
            $token = OneTimeToken::consume(LoginTokenPurpose::Login, $plainToken);
            if ($token === null) {
                return null;
            }

            // リンクを要求してから使うまでの間に、同じアドレスのアカウントができている場合もある。
            // 同じアドレス宛ての2本のリンクが同時に使われても、アカウントは1つだけ作る
            $user = User::whereEmail($token->email)->first()
                ?? User::createOrFirst(['email_index' => app(BlindIndex::class)->email($token->email)], ['email' => $token->email]);

            return [$user, $token->return_to];
        });
    }
}
