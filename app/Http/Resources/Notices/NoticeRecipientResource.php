<?php

namespace App\Http\Resources\Notices;

use App\Models\NoticeRecipient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 配信状況の一覧の1人(AC-006-05・06)。user を読み込んでから渡す。
 * failures が true のとき、メールの状態と理由のコードも返す。
 *
 * @property NoticeRecipient $resource
 */
final class NoticeRecipientResource extends JsonResource
{
    public static $wrap = null;

    public bool $failures = false;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = ['display_name' => $this->resource->user?->display_name];
        if ($this->failures) {
            $item['status'] = $this->resource->delivery_status->value;
            $item['reason'] = $this->resource->delivery_error;
        }

        return $item;
    }
}
