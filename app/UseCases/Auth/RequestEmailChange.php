<?php

namespace App\UseCases\Auth;

use App\Enums\LoginTokenPurpose;
use App\Models\User;
use App\Support\Auth\OneTimeToken;
use App\Support\NotifyRelay\EmailOutbox;
use Illuminate\Support\Facades\DB;

/**
 * メールアドレスの変更を申請する(AC-001-09)。新しいアドレスに確認メールを送り、
 * リンクを開くまでは旧アドレスのまま。
 *
 * 新しいアドレスが別のアカウントで使われている場合も、応答は変えない(登録の有無を推測させない)。
 * 代わりに新しいアドレスへ「変更できない」ことだけを知らせる。届くのはそのアドレスの持ち主だけ。
 */
final class RequestEmailChange
{
    public function __construct(private readonly EmailOutbox $outbox) {}

    public function __invoke(User $user, string $newEmail): void
    {
        $newEmail = trim($newEmail);

        DB::transaction(function () use ($user, $newEmail): void {
            $owner = User::whereEmail($newEmail)->first();
            [$token, $plain] = OneTimeToken::issue(LoginTokenPurpose::EmailChange, $newEmail, $user->id);

            if ($owner !== null) {
                $this->outbox->queue(
                    kind: 'email_change_unavailable',
                    dedupeKey: 'email-change-unavailable:'.$token->id,
                    subject: '【チーム運営】メールアドレスの変更について',
                    text: implode("\n", [
                        'このメールアドレスへの変更が申請されましたが、すでに別のアカウントで使われているため変更できません。',
                        '',
                        'このメールに心当たりがない場合は、何もせずに削除してください。',
                    ]),
                    recipients: ['login_token_'.$token->id => $newEmail],
                );
                $token->forceFill(['used_at' => now()])->save(); // 使えないトークンにしておく

                return;
            }

            $this->outbox->queue(
                kind: 'email_change',
                dedupeKey: 'email-change:'.$token->id,
                subject: '【チーム運営】メールアドレスの確認',
                text: implode("\n", [
                    'メールアドレスの変更を確定するには、次のリンクを開き、表示された画面で「変更する」を押してください。',
                    'リンクの有効期限は'.config('team_ops.login_link.ttl_minutes').'分です。確定するまでは、これまでのメールアドレスのままです。',
                    '',
                    'このメールに心当たりがない場合は、何もせずに削除してください。',
                ]),
                recipients: ['login_token_'.$token->id => $newEmail],
                url: OneTimeToken::link('/settings/email/verify', $plain),
                expiresAt: $token->expires_at,
            );
        });
    }
}
