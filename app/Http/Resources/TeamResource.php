<?php

namespace App\Http\Resources;

use App\Models\Team;
use App\Models\TeamMembership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * チームの情報と、ログイン中の利用者のそのチームでの役割。
 *
 * @property Team $resource
 */
final class TeamResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(Team $team, private readonly TeamMembership $membership)
    {
        parent::__construct($team);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'sport' => $this->resource->sport->value,
            'role' => $this->membership->role->value,
        ];
    }
}
