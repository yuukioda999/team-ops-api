<?php

namespace App\Http\Resources\Demo;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * デモチームから送られたメール1通(AC-007-12)。
 *
 * @property array{subject: string, to: list<string>, sent_at: string, text: string} $resource
 */
final class DemoMailResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'subject' => $this->resource['subject'],
            'to' => $this->resource['to'],
            'sent_at' => $this->resource['sent_at'],
            'text' => $this->resource['text'],
        ];
    }
}
