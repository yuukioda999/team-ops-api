<?php

namespace App\UseCases\Auth;

use App\Enums\LoginTokenPurpose;
use App\Models\User;
use App\Support\Auth\OneTimeToken;
use App\Support\NotifyRelay\EmailOutbox;
use Illuminate\Support\Facades\DB;

/**
 * メールアドレスの変更を確定し、旧アドレスに知らせる(AC-001-09・10)。
 */
final class ConfirmEmailChange
{
    public const string INVALID = 'invalid';

    public const string TAKEN = 'taken';

    public function __construct(private readonly EmailOutbox $outbox) {}

    /**
     * @return User|string 変更後の利用者。失敗したら INVALID(期限切れなど)か TAKEN(すでに使われている)
     */
    public function __invoke(string $plainToken): User|string
    {
        return DB::transaction(function () use ($plainToken): User|string {
            $token = OneTimeToken::consume(LoginTokenPurpose::EmailChange, $plainToken);
            $user = $token?->user_id !== null ? User::lockForUpdate()->find($token->user_id) : null;
            if ($token === null || $user === null) {
                return self::INVALID;
            }

            $taken = User::whereEmail($token->email)->whereKeyNot($user->id)->exists();
            if ($taken) {
                return self::TAKEN;
            }

            $oldEmail = $user->email;
            $user->update(['email' => $token->email]);

            $this->outbox->queue(
                kind: 'email_changed',
                dedupeKey: 'email-changed:'.$token->id,
                subject: '【チーム運営】メールアドレスが変更されました',
                text: implode("\n", [
                    'アカウントのメールアドレスが変更されました。今後のお知らせは新しいメールアドレスに届きます。',
                    '',
                    '心当たりがない場合は、チームの代表に連絡してください。',
                ]),
                recipients: ['user_'.$user->id => $oldEmail],
            );

            return $user;
        });
    }
}
