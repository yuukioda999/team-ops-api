<?php

namespace App\Http\Requests\Notices;

use Illuminate\Foundation\Http\FormRequest;

/**
 * お知らせ一覧の続き(AC-005-19)。before を渡すと、そのお知らせより古いものを返す。
 */
final class ListNoticesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['before' => ['nullable', 'integer', 'min:1', 'max:999999999999999999']];
    }

    public function before(): ?int
    {
        $value = $this->validated('before');

        return $value === null ? null : (int) $value;
    }
}
