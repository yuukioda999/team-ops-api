<?php

namespace App\Http\Resources;

use App\Models\TeamMembership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 保護者(保護者の役割を持つメンバー)の ID と表示名。user を読み込んでから渡す。
 *
 * @property TeamMembership $resource
 */
final class GuardianResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{membership_id: int, display_name: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'membership_id' => $this->resource->id,
            'display_name' => $this->resource->user->display_name ?? null,
        ];
    }
}
