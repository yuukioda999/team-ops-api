<?php

namespace App\Http\Resources;

use App\Models\TeamJoinRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 承認待ちの申請。チームの代表・役員だけに返す(AC-002-11)。
 *
 * @property TeamJoinRequest $resource
 */
final class JoinRequestResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $applicant */
        $applicant = $this->resource->user;

        return [
            'id' => $this->resource->id,
            'display_name' => $applicant->display_name,
            'message' => $this->resource->message,
            'created_at' => $this->resource->created_at->toIso8601String(),
        ];
    }
}
