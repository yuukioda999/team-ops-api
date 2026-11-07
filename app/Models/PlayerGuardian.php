<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 選手と保護者(保護者の役割を持つメンバー)の紐づけ(AC-003-05・06)。
 *
 * @property int $id
 * @property int $player_id
 * @property int $membership_id
 */
#[Fillable(['player_id', 'membership_id'])]
class PlayerGuardian extends Model
{
    /**
     * @return BelongsTo<TeamMembership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(TeamMembership::class, 'membership_id');
    }
}
