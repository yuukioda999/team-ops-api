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
 * @property array<string, mixed> $payload
 * @property OutboxStatus $status
 * @property int $attempts
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $sent_at
 * @property string|null $last_error
 */
#[Fillable(['kind', 'dedupe_key', 'payload', 'status', 'next_attempt_at'])]
#[Hidden(['payload'])]
class OutboxMessage extends Model
{
    protected $attributes = [
        'status' => 'pending',
        'attempts' => 0,
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
            'sent_at' => 'datetime',
        ];
    }
}
