<?php

namespace App\Http\Requests\Members;

use App\Enums\TeamRole;
use App\Models\Team;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 役割の変更(AC-004-03)。代表は含められない(交代は別の操作、ADR-0015)。
 */
final class ChangeRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $team = $this->route('team');

        return $team instanceof Team && $this->user()?->can('manageRoles', $team) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'roles' => ['present', 'array', 'max:3'],
            // 代表を送ったときだけ、交代の操作を案内する。それ以外の不正な値は標準の文言
            'roles.*' => ['bail', 'string', 'distinct', 'not_in:'.TeamRole::Representative->value, Rule::in(array_map(fn (TeamRole $r) => $r->value, TeamRole::changeable()))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['roles.*.not_in' => '代表は役割の変更では付けられません。代表を交代する操作を使ってください。'];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['roles' => '役割', 'roles.*' => '役割'];
    }

    /**
     * @return list<TeamRole>
     */
    public function roles(): array
    {
        /** @var list<string> $values */
        $values = $this->validated('roles');

        return array_map(fn (string $v) => TeamRole::from($v), $values);
    }
}
