<?php

namespace App\Http\Resources;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamMembership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use LogicException;

/**
 * チームの情報と、ログイン中の利用者のそのチームでの役割。
 * チームは User::teams() から取り出したもの(membership が付いている)を渡す。
 *
 * @property Team $resource
 */
final class TeamResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $membership = $this->resource->getRelation('membership');
        if (! $membership instanceof TeamMembership) {
            throw new LogicException('TeamResource needs a team loaded through User::teams().');
        }

        $user = $request->user();

        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'sport' => $this->resource->sport->value,
            'roles' => array_map(fn (TeamRole $role) => $role->value, $membership->roleList()),
            // 画面がボタンや導線を出し分けるため。判定は TeamPolicy と同じもの(ADR-0011)
            'permissions' => [
                'manage_invitation' => $user !== null && Gate::forUser($user)->allows('manageInvitation', $this->resource),
                'review_join_requests' => $user !== null && Gate::forUser($user)->allows('reviewJoinRequests', $this->resource),
                'manage_players' => $user !== null && Gate::forUser($user)->allows('managePlayers', $this->resource),
                'update_team' => $user !== null && Gate::forUser($user)->allows('updateTeam', $this->resource),
                'view_members' => $user !== null && Gate::forUser($user)->allows('viewMembers', $this->resource),
                'manage_roles' => $user !== null && Gate::forUser($user)->allows('manageRoles', $this->resource),
                'remove_members' => $user !== null && Gate::forUser($user)->allows('removeMembers', $this->resource),
                'view_audit_logs' => $user !== null && Gate::forUser($user)->allows('viewAuditLogs', $this->resource),
                'view_players' => $user !== null && Gate::forUser($user)->allows('viewPlayers', $this->resource),
                'view_all_players' => $user !== null && Gate::forUser($user)->allows('viewAllPlayers', $this->resource),
            ],
        ];
    }
}
