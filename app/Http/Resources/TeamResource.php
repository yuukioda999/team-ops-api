<?php

namespace App\Http\Resources;

use App\Models\Team;
use App\Models\TeamMembership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
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

        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'sport' => $this->resource->sport->value,
            'role' => $membership->role->value,
        ];
    }
}
