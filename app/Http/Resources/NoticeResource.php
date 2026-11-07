<?php

namespace App\Http\Resources;

use App\Models\Notice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * お知らせ(AC-005-14)。author を読み込んでから渡す。
 *
 * @property Notice $resource
 */
final class NoticeResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'title' => $this->resource->title,
            'body' => $this->resource->body,
            'target' => ['type' => $this->resource->target_type->value, 'values' => $this->resource->target_values],
            'status' => $this->resource->status->value,
            'scheduled_at' => $this->resource->scheduled_at?->toIso8601String(),
            'published_at' => $this->resource->published_at?->toIso8601String(),
            'recipient_count' => $this->resource->recipient_count,
            'author_display_name' => $this->resource->author->display_name ?? null,
            // 自分宛ての一覧で、まだ開いていない(AC-006-03)。それ以外は false
            'unread' => (bool) $this->resource->getAttribute('unread'),
        ];
    }
}
