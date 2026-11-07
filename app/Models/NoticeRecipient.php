<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * お知らせの送信時点の宛先1人(ADR-0017)。既読と配信状態を持つ(ADR-0019・0020)。
 *
 * @property int $id
 * @property int $notice_id
 * @property int $user_id
 * @property Carbon|null $read_at
 * @property DeliveryStatus $delivery_status
 * @property string|null $delivery_error
 * @property Carbon|null $delivery_status_at
 */
#[Fillable(['notice_id', 'user_id'])]
class NoticeRecipient extends Model
{
    /** 送らなかった理由:メールが届かないアドレス(ADR-0020) */
    public const string SKIPPED_UNDELIVERABLE = 'undeliverable_address';

    /** 送らなかった理由:宛先を .invalid に限る環境(作業環境・デモ) */
    public const string SKIPPED_RESTRICTED = 'restricted';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'delivery_status' => DeliveryStatus::class,
            'delivery_status_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
