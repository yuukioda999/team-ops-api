<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use App\Support\Audit\AuditActionLabel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 監査ログの1件(AC-004-12)。details(ID など)は返さない。
 * 操作した人がチームにいないときは、表示名を返さず actor を former にする。
 *
 * @property AuditLog $resource
 */
final class AuditLogResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @param  array<int, true>  $memberUserIds
     */
    public function __construct(AuditLog $resource, private readonly array $memberUserIds)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $actorId = $this->resource->actor_user_id;
        $actor = match (true) {
            $actorId === null => 'system',
            isset($this->memberUserIds[$actorId]) => 'member',
            default => 'former',
        };

        return [
            'id' => $this->resource->id,
            'created_at' => $this->resource->created_at->toIso8601String(),
            'action' => $this->resource->action,
            'action_label' => AuditActionLabel::of($this->resource->action),
            'actor' => $actor,
            'actor_display_name' => $actor === 'member' ? $this->resource->actor?->display_name : null,
        ];
    }
}
