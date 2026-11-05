<?php

namespace App\UseCases\Auth;

use App\Enums\LoginTokenPurpose;
use App\Models\User;
use App\Support\Auth\OneTimeToken;
use App\Support\NotifyRelay\EmailOutbox;
use Illuminate\Support\Facades\DB;

/**
 * ログイン用リンクをメールで送る(AC-001-01・02)。
 *
 * 登録済みかどうかで、応答もここでの処理(トークンの作成と配信依頼)も変えない。
 * アカウントはリンクを使った時点で作る(AC-001-03)。
 * 登録済みなら、宛先はアカウントに保存してあるアドレスにする(入力された文字列には送らない)。
 */
final class RequestLoginLink
{
    public function __construct(private readonly EmailOutbox $outbox) {}

    /**
     * @param  string  $email  正規化済みのメールアドレス(EmailAddressRequest::address)
     */
    public function __invoke(string $email): void
    {
        DB::transaction(function () use ($email): void {
            $user = User::whereEmail($email)->first();
            $email = $user !== null ? $user->email : $email;
            [$token, $plain] = OneTimeToken::issue(LoginTokenPurpose::Login, $email, $user?->id);

            $this->outbox->queue(
                kind: 'login_link',
                dedupeKey: 'login-link:'.$token->id,
                subject: '【チーム運営】ログイン用のリンク',
                text: implode("\n", [
                    '次のリンクを開き、表示された画面で「ログインする」を押してください。',
                    'リンクの有効期限は'.config('team_ops.login_link.ttl_minutes').'分です。',
                    '',
                    'このメールに心当たりがない場合は、何もせずに削除してください。',
                ]),
                recipients: ['login_token_'.$token->id => $email],
                url: OneTimeToken::link('/login/verify', $plain),
                expiresAt: $token->expires_at,
            );
        });
    }
}
