<?php

namespace App\Models;

use App\Enums\TeamRole;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * チームへの所属。役割は team_member_roles に持つ(ADR-0011)。
 *
 * @property int $id
 * @property string $team_id
 * @property int $user_id
 */
class TeamMembership extends Pivot
{
    protected $table = 'team_memberships';

    public $incrementing = true;

    /**
     * @return HasMany<TeamMemberRole, $this>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(TeamMemberRole::class, 'membership_id');
    }

    /**
     * 役割の値を、決まった順(代表・役員・コーチ・保護者)で返す。
     *
     * @return list<TeamRole>
     */
    public function roleList(): array
    {
        $held = $this->roles()->pluck('role')->all();

        return array_values(array_filter(TeamRole::cases(), fn (TeamRole $role) => in_array($role, $held, true)));
    }

    /**
     * 役割を足す。すでに持っている役割は足さない。
     *
     * @param  list<TeamRole>  $roles
     */
    public function grant(array $roles): void
    {
        foreach ($roles as $role) {
            TeamMemberRole::firstOrCreate(
                ['membership_id' => $this->id, 'role' => $role],
                ['team_id' => $this->team_id, 'representative_of' => $role === TeamRole::Representative ? $this->team_id : null],
            );
        }
    }
}
