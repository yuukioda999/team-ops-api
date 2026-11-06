<?php

namespace App\Http\Resources;

use App\Models\TeamInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 招待リンク。代表・役員だけに返す(ADR-0010)。QR コードは画面がこの URL から作る。
 *
 * @property TeamInvitation $resource
 */
final class InvitationResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'url' => $this->resource->url(),
            'expires_at' => $this->resource->expires_at->toIso8601String(),
        ];
    }
}
