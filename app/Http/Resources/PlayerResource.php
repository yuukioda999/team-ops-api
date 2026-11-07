<?php

namespace App\Http\Resources;

use App\Models\Player;
use App\Models\PlayerGuardian;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 選手と、紐づく保護者の表示名(AC-003-08・09)。guardians.membership.user を読み込んでから渡す。
 *
 * @property Player $resource
 */
final class PlayerResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'display_name' => $this->resource->display_name,
            'grade' => $this->resource->grade->value,
            'number' => $this->resource->number,
            'guardians' => $this->resource->guardians
                ->sortBy('membership_id')
                ->map(fn (PlayerGuardian $g) => (new GuardianResource($g->membership))->resolve($request))
                ->values()
                ->all(),
        ];
    }
}
