<?php

namespace App\Http\Requests\Teams;

use App\Enums\Sport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateTeamRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'between:1,50'],
            'sport' => ['required', Rule::enum(Sport::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => 'チーム名', 'sport' => '競技'];
    }

    public function name(): string
    {
        return (string) $this->validated('name');
    }

    public function sport(): Sport
    {
        return Sport::from((string) $this->validated('sport'));
    }
}
