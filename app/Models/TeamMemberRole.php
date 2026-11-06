<?php

namespace App\Models;

use App\Enums\TeamRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * メンバーの役割1つ(ADR-0011)。
 *
 * @property int $id
 * @property int $membership_id
 * @property string $team_id
 * @property TeamRole $role
 * @property string|null $representative_of
 */
#[Fillable(['membership_id', 'team_id', 'role', 'representative_of'])]
class TeamMemberRole extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => TeamRole::class,
        ];
    }

    /**
     * @return BelongsTo<TeamMembership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(TeamMembership::class, 'membership_id');
    }
}
