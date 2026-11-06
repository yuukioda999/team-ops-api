<?php

namespace App\Models;

use App\Enums\JoinRequestStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 参加申請(002)。ひとことは暗号化し、最終状態になったら消す。最終状態の行は30日後に消す(AC-002-17)。
 *
 * @property int $id
 * @property string $team_id
 * @property int|null $invitation_id
 * @property int $user_id
 * @property string|null $message
 * @property JoinRequestStatus $status
 * @property int|null $pending_user_id
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property Carbon $created_at
 */
#[Fillable(['team_id', 'invitation_id', 'user_id', 'message', 'status', 'pending_user_id', 'decided_by', 'decided_at'])]
#[Hidden(['message'])]
class TeamJoinRequest extends Model
{
    use Prunable;

    /** 承認も却下もされないまま、この日数で自動的に取り消す(AC-002-15) */
    public const int EXPIRE_AFTER_DAYS = 14;

    /** 最終状態の行を残す日数(AC-002-17) */
    public const int KEEP_DAYS_AFTER_DECISION = 30;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'message' => 'encrypted',
            'status' => JoinRequestStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()
            ->where('status', '!=', JoinRequestStatus::Pending)
            ->where('decided_at', '<', now()->subDays(self::KEEP_DAYS_AFTER_DECISION));
    }

    /**
     * 最終状態にする。ひとことを消し、承認待ちの一意制約から外す。
     */
    public function finish(JoinRequestStatus $status, ?User $decidedBy): void
    {
        $this->forceFill([
            'status' => $status,
            'message' => null,
            'pending_user_id' => null,
            'decided_by' => $decidedBy?->id,
            'decided_at' => now(),
        ])->save();
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
