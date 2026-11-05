<?php

namespace App\Models;

use App\Enums\LoginTokenPurpose;
use App\Support\Crypto\BlindIndex;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * ログイン用・メール変更用のワンタイムトークン(ADR-0008)。
 * トークン自体は保存せず、SHA-256 のハッシュ値だけを持つ。
 *
 * @property int $id
 * @property LoginTokenPurpose $purpose
 * @property string $email
 * @property string $email_index
 * @property int|null $user_id
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 */
#[Fillable(['purpose', 'email', 'user_id', 'token_hash', 'expires_at'])]
#[Hidden(['email', 'email_index', 'token_hash'])]
class LoginToken extends Model
{
    protected static function booted(): void
    {
        static::saving(function (LoginToken $token): void {
            if ($token->isDirty('email') || ! $token->exists) {
                $token->email_index = app(BlindIndex::class)->email($token->email);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => LoginTokenPurpose::class,
            'email' => 'encrypted',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
