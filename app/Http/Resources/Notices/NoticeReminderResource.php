<?php

namespace App\Http\Resources\Notices;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 実行した再通知(AC-006-11・14)。SendReminder の結果を渡す。
 *
 * @property array{number: int, sent: int, excluded: int} $resource
 */
final class NoticeReminderResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'number' => $this->resource['number'],
            'sent' => $this->resource['sent'],
            'excluded' => $this->resource['excluded'],
        ];
    }
}
