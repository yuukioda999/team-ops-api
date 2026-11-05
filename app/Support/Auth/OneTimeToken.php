<?php

namespace App\Support\Auth;

use App\Enums\LoginTokenPurpose;
use App\Models\LoginToken;
use Illuminate\Support\Carbon;

/**
 * ログイン用・メール変更用のワンタイムトークン(ADR-0008)。
 *
 * トークンは32バイトの乱数で、DBには SHA-256 だけを保存する。
 * 使うときは条件付きの UPDATE で「未使用・期限内」のものを1回だけ使用済みにする。
 */
final class OneTimeToken
{
    /**
     * @return array{LoginToken, string} 保存した行と、メールに書く平文のトークン
     */
    public static function issue(LoginTokenPurpose $purpose, string $email, ?int $userId): array
    {
        $plain = bin2hex(random_bytes(32));
        $token = LoginToken::create([
            'purpose' => $purpose,
            'email' => $email,
            'user_id' => $userId,
            'token_hash' => LoginToken::hashToken($plain),
            'expires_at' => now()->addMinutes((int) config('team_ops.login_link.ttl_minutes')),
        ]);

        return [$token, $plain];
    }

    /**
     * 未使用で期限内のトークンを使用済みにして返す。使えないときは null。
     * 期限切れ・使用済み・存在しないを区別しない(どれも同じ表示にする)。
     * 同時に2回使われても、成功するのは1回だけ。
     *
     * @param  int|null  $userId  指定すると、その利用者のトークンだけを使う(他人のトークンは使用済みにしない)
     */
    public static function consume(LoginTokenPurpose $purpose, string $plain, ?int $userId = null): ?LoginToken
    {
        if (preg_match('/^[0-9a-f]{64}$/', $plain) !== 1) {
            return null;
        }
        $hash = LoginToken::hashToken($plain);
        $now = Carbon::now();

        $used = LoginToken::where('token_hash', $hash)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->where('expires_at', '>', $now)
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->update(['used_at' => $now, 'updated_at' => $now]);

        return $used === 1 ? LoginToken::where('token_hash', $hash)->first() : null;
    }

    public static function link(string $path, string $plain): string
    {
        return config('team_ops.web_url').$path.'?token='.$plain;
    }
}
