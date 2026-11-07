<?php

namespace App\Http\Requests\Players;

use App\Enums\Grade;
use App\Models\Team;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 名簿の絞り込み(AC-003-12)。
 */
final class ListPlayersRequest extends FormRequest
{
    /**
     * 入力の検査より先に権限を判定する。権限のない人には、内容によらず403を返す(TeamPolicy)。
     */
    public function authorize(): bool
    {
        $team = $this->route('team');

        return $team instanceof Team && $this->user()?->can('viewPlayers', $team) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'grade' => ['nullable', Rule::enum(Grade::class)],
            'number' => ['nullable', 'integer', 'between:0,999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['grade' => '学年', 'number' => '背番号'];
    }

    public function grade(): ?Grade
    {
        $value = $this->validated('grade');

        return is_string($value) ? Grade::from($value) : null;
    }

    public function number(): ?int
    {
        $value = $this->validated('number');

        return $value === null ? null : (int) $value;
    }
}
