<?php

namespace App\UseCases\Auth;

use App\Enums\LoginTokenPurpose;
use App\Models\LoginToken;
use App\Models\User;
use App\Support\Auth\OneTimeToken;
use App\Support\Crypto\BlindIndex;
use App\Support\NotifyRelay\EmailOutbox;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * メールアドレスの変更を確定し、旧アドレスに知らせる(AC-001-09・10)。
 *
 * 確定できるのは、申請した本人がログインしているときだけ(plan.md D5)。
 * 新しいアドレスを打ち間違えても、その宛先の持ち主がリンクを押すだけで乗っ取ることはできない。
 */
final class ConfirmEmailChange
{
    public const string INVALID = 'invalid';

    public const string TAKEN = 'taken';

    public function __construct(private readonly EmailOutbox $outbox) {}

    /**
     * @param  string|null  $currentSessionId  確定した端末のセッション。これ以外のセッションは終了させる
     * @return User|string 変更後の利用者。失敗したら INVALID(期限切れ・別の人の申請など)か TAKEN(すでに使われている)
     */
    public function __invoke(User $user, string $plainToken, ?string $currentSessionId): User|string
    {
        return DB::transaction(function () use ($user, $plainToken, $currentSessionId): User|string {
            // 他人の申請のリンクは使わず、存在しないリンクと同じ扱いにする
            $token = OneTimeToken::consume(LoginTokenPurpose::EmailChange, $plainToken, $user->id);
            if ($token === null) {
                return self::INVALID;
            }

            $user = User::lockForUpdate()->findOrFail($user->id);
            if (User::whereEmail($token->email)->whereKeyNot($user->id)->exists()) {
                return self::TAKEN;
            }

            $oldEmail = $user->email;
            try {
                DB::transaction(fn () => $user->update(['email' => $token->email]));
            } catch (UniqueConstraintViolationException) {
                return self::TAKEN; // 同時に別の人が同じアドレスに変更した
            }

            $this->revokeOutstandingLinks($user, $oldEmail);
            $this->endOtherSessions($user, $currentSessionId);

            $this->outbox->queue(
                kind: 'email_changed',
                dedupeKey: $token->dedupeKey('email-changed'),
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

    /**
     * まだ使われていないメール変更のリンクと、旧アドレス宛てのログイン用リンクを使えなくする。
     */
    private function revokeOutstandingLinks(User $user, string $oldEmail): void
    {
        LoginToken::whereNull('used_at')
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('user_id', $user->id)->where('purpose', LoginTokenPurpose::EmailChange))
                ->orWhere(fn ($q) => $q->where('email_index', app(BlindIndex::class)->email($oldEmail))->where('purpose', LoginTokenPurpose::Login)))
            ->update(['used_at' => now()]);
    }

    /**
     * 確定した端末以外のセッションを終了させる(旧アドレスでログインしていた別の端末を残さない)。
     */
    private function endOtherSessions(User $user, ?string $currentSessionId): void
    {
        $driver = (string) config('session.driver');
        if ($driver === 'array') {
            return; // テスト用。リクエストをまたいで残るセッションがない
        }
        if ($driver !== 'database') {
            // 黙って抜けると、他の端末のログインが残ったまま変更が終わってしまう
            throw new \LogicException("Ending other sessions is not implemented for the {$driver} session driver.");
        }
        DB::table((string) config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->when($currentSessionId !== null, fn ($q) => $q->where('id', '!=', $currentSessionId))
            ->delete();
    }
}
