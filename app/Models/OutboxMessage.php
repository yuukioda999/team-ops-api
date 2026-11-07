<?php

namespace App\Models;

use App\Enums\OutboxStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * notify-relay への配信依頼(ADR-0002)。payload は宛先を含むため暗号化する。
 *
 * @property int $id
 * @property string $kind
 * @property string $dedupe_key
 * @property array<string, mixed>|null $payload 送信済み・失敗で消す
 * @property OutboxStatus $status
 * @property int $attempts
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $give_up_at
 * @property Carbon|null $sent_at
 * @property string|null $last_error
 * @property Carbon|null $created_at
 * @property string|null $delivery_id notify-relay が受け付けたときの ID(ADR-0019)
 * @property int $results_received
 * @property Carbon|null $relay_completed_at
 * @property Carbon|null $reconcile_stopped_at
 * @property Carbon|null $last_reconciled_at
 * @property string|null $last_mismatch
 */
#[Fillable(['kind', 'dedupe_key', 'payload', 'status', 'next_attempt_at', 'give_up_at'])]
#[Hidden(['payload'])]
class OutboxMessage extends Model
{
    protected $attributes = [
        'status' => 'pending',
        'attempts' => 0,
        'results_received' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'status' => OutboxStatus::class,
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'give_up_at' => 'datetime',
            'sent_at' => 'datetime',
            'results_received' => 'integer',
            'relay_completed_at' => 'datetime',
            'reconcile_stopped_at' => 'datetime',
            'last_reconciled_at' => 'datetime',
        ];
    }
}
