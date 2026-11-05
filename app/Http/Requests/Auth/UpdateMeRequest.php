<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateMeRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('display_name'))) {
            $this->merge(['display_name' => trim($this->input('display_name'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'display_name' => ['required', 'string', 'between:1,30'],
        ];
    }

    public function displayName(): string
    {
        return (string) $this->validated('display_name');
    }
}
