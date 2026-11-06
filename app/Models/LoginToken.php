<?php

namespace App\Models;

use App\Enums\LoginTokenPurpose;
use App\Models\Concerns\HasEmailBlindIndex;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * ログイン用・メール変更用のワンタイムトークン(ADR-0008)。
 * トークン自体は保存せず、SHA-256 のハッシュ値だけを持つ。
 * 期限切れから24時間で行ごと消す(AC-001-18)。リンクを要求しただけの人のアドレスを残し続けないため。
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
    use HasEmailBlindIndex;
    use Prunable;

    /** 期限切れのあと、行を残す時間(AC-001-18) */
    public const int KEEP_HOURS_AFTER_EXPIRY = 24;

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<', now()->subHours(self::KEEP_HOURS_AFTER_EXPIRY));
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

    /**
     * notify-relay の重複防止キー。行のIDだけだと、DBを作り直したとき(デモ環境のリセットなど)に
     * 同じ値が別の依頼に使われ、notify-relay が 409 で拒否する。トークンのハッシュの先頭を加えて、
     * 依頼ごとに一意にする(ハッシュの先頭からトークンは復元できない)。
     */
    public function dedupeKey(string $kind): string
    {
        return $kind.':'.$this->id.'-'.substr($this->token_hash, 0, 16);
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
