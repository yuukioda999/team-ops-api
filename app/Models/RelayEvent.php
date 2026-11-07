<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Carbon;

/**
 * 受け取った notify-relay の webhook の event_id(重複の除外、ADR-0019)。
 * notify-relay は24時間まで送り直すので、8日を過ぎたものは消す。
 *
 * @property string $event_id
 * @property Carbon $received_at
 */
class RelayEvent extends Model
{
    use Prunable;

    public const int KEEP_DAYS = 8;

    protected $primaryKey = 'event_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::where('received_at', '<', now()->subDays(self::KEEP_DAYS));
    }
}
