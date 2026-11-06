<?php

namespace App\Http\Requests\Teams;

use App\Enums\TeamRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 承認で付ける役割。選べるのは保護者・コーチだけ(複数可)。役員・代表は 422(AC-002-12・14)。
 */
final class ApproveJoinRequestRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'roles' => ['required', 'array', 'min:1', 'max:2'],
            'roles.*' => ['distinct', Rule::in(array_map(fn (TeamRole $r) => $r->value, TeamRole::grantableOnApproval()))],
        ];
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
