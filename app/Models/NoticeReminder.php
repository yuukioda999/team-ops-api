<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 未読の人への再通知1回(AC-006-11〜13、ADR-0020)。
 *
 * @property int $id
 * @property int $notice_id
 * @property int $number
 * @property int|null $sent_by_user_id
 * @property int $sent_count
 * @property int $excluded_count
 * @property Carbon $created_at
 */
#[Fillable(['notice_id', 'number', 'sent_by_user_id', 'sent_count', 'excluded_count', 'created_at'])]
class NoticeReminder extends Model
{
    public const int MAX = 3;

    public const int INTERVAL_MINUTES = 60;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'number' => 'integer'];
    }
}
