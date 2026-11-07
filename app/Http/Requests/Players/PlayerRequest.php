<?php

namespace App\Http\Requests\Players;

use App\Enums\Grade;
use App\Support\Text\SingleLineText;
use App\UseCases\Players\PlayerInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 選手の登録(AC-003-01)。編集(PATCH)では、送った項目だけを変える。
 */
final class PlayerRequest extends FormRequest
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
        $required = $this->isMethod('PATCH') ? 'sometimes' : 'required';

        return [
            'display_name' => [$required, 'string', 'between:1,30', 'not_regex:'.SingleLineText::FORBIDDEN_PATTERN],
            'grade' => [$required, Rule::enum(Grade::class)],
            'number' => ['sometimes', 'nullable', 'integer', 'between:0,999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['display_name' => '表示名', 'grade' => '学年', 'number' => '背番号'];
    }

    public function player(): PlayerInput
    {
        return new PlayerInput(
            (string) $this->validated('display_name'),
            Grade::from((string) $this->validated('grade')),
            $this->validated('number') === null ? null : (int) $this->validated('number'),
        );
    }

    /**
     * 編集で送られた項目だけ。
     *
     * @return array{display_name?: string, grade?: Grade, number?: int|null}
     */
    public function changes(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();
        $changes = [];
        if (array_key_exists('display_name', $validated)) {
            $changes['display_name'] = (string) $validated['display_name'];
        }
        if (array_key_exists('grade', $validated)) {
            $changes['grade'] = Grade::from((string) $validated['grade']);
        }
        if (array_key_exists('number', $validated)) {
            $changes['number'] = $validated['number'] === null ? null : (int) $validated['number'];
        }

        return $changes;
    }
}
