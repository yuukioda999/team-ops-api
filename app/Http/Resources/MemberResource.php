<?php

namespace App\Http\Resources;

use App\Enums\TeamRole;
use App\Models\TeamMembership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * メンバーの表示名と役割(ADR-0015)。メールアドレスは返さない。user と roles を読み込んでから渡す。
 *
 * @property TeamMembership $resource
 */
final class MemberResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $held = $this->resource->roles->pluck('role')->all();

        return [
            'membership_id' => $this->resource->id,
            'display_name' => $this->resource->user->display_name ?? null,
            'roles' => array_values(array_map(
                fn (TeamRole $r) => $r->value,
                array_filter(TeamRole::cases(), fn (TeamRole $r) => in_array($r, $held, true)),
            )),
            'is_me' => $this->resource->user_id === $request->user()?->getAuthIdentifier(),
        ];
    }
}
