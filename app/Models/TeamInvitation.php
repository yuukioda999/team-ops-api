<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * チームの招待リンク(002、ADR-0010)。チームで有効なリンクは常に1つ(AC-002-02)。
 *
 * @property int $id
 * @property string $team_id
 * @property string $token_hash
 * @property string $token 平文のトークン(保存は暗号化)
 * @property int|null $created_by
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 */
#[Fillable(['team_id', 'token_hash', 'token', 'created_by', 'expires_at', 'revoked_at'])]
#[Hidden(['token', 'token_hash'])]
class TeamInvitation extends Model
{
    public const int TTL_DAYS = 7;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public static function hashToken(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function url(): string
    {
        return config('team_ops.web_url').'/join?token='.$this->token;
    }
}
