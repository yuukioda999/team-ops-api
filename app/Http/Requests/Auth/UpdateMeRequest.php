<?php

namespace App\Http\Requests\Auth;

use App\Support\Text\SingleLineText;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateMeRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('display_name'))) {
            $this->merge(['display_name' => SingleLineText::trim($this->input('display_name'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'display_name' => ['required', 'string', 'between:1,30', 'not_regex:'.SingleLineText::FORBIDDEN_PATTERN],
        ];
    }

    public function displayName(): string
    {
        return (string) $this->validated('display_name');
    }
}
