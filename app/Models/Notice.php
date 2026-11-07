<?php

namespace App\Models;

use App\Enums\NoticeStatus;
use App\Enums\NoticeTarget;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * お知らせ(005)。タイトルと本文は暗号化して保存する(ADR-0017)。
 *
 * @property int $id
 * @property string $team_id
 * @property int|null $author_user_id
 * @property string $title
 * @property string $body
 * @property NoticeTarget $target_type
 * @property list<string> $target_values
 * @property NoticeStatus $status
 * @property Carbon|null $scheduled_at
 * @property Carbon|null $published_at
 * @property int $recipient_count
 */
#[Fillable(['team_id', 'author_user_id', 'title', 'body', 'target_type', 'target_values', 'status', 'scheduled_at', 'published_at', 'recipient_count'])]
#[Hidden(['title', 'body'])]
class Notice extends Model
{
    public const int TITLE_MAX = 100;

    public const int BODY_MAX = 2000;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'title' => 'encrypted',
            'body' => 'encrypted',
            'target_type' => NoticeTarget::class,
            'target_values' => 'array',
            'status' => NoticeStatus::class,
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
            'recipient_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /**
     * @return HasMany<NoticeRecipient, $this>
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(NoticeRecipient::class);
    }
}
