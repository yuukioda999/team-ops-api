<?php

namespace App\Models;

use App\Enums\TeamRole;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property string $team_id
 * @property int $user_id
 * @property TeamRole $role
 */
class TeamMembership extends Pivot
{
    protected $table = 'team_memberships';

    public $incrementing = true;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => TeamRole::class,
        ];
    }
}
