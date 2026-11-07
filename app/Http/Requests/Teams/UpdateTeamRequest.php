<?php

namespace App\Http\Requests\Teams;

use App\Enums\Sport;
use App\Models\Team;
use App\Support\Text\SingleLineText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * チーム名・競技の変更(AC-004-11)。チーム作成(AC-001-11)と同じ検査。送った項目だけを変える。
 */
final class UpdateTeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        $team = $this->route('team');

        return $team instanceof Team && $this->user()?->can('updateTeam', $team) === true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => SingleLineText::trim($this->input('name'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'between:1,50', 'not_regex:'.SingleLineText::FORBIDDEN_PATTERN],
            'sport' => ['sometimes', Rule::enum(Sport::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => 'チーム名', 'sport' => '競技'];
    }

    /**
     * @return array{name?: string, sport?: Sport}
     */
    public function changes(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();
        $changes = [];
        if (array_key_exists('name', $validated)) {
            $changes['name'] = (string) $validated['name'];
        }
        if (array_key_exists('sport', $validated)) {
            $changes['sport'] = Sport::from((string) $validated['sport']);
        }

        return $changes;
    }
}
