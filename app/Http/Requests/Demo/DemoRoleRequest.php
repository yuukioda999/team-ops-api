<?php

namespace App\Http\Requests\Demo;

use App\Enums\TeamRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * デモでログインする役割(AC-007-01・05)。
 */
final class DemoRoleRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['role' => ['required', 'string', Rule::enum(TeamRole::class)]];
    }

    public function role(): TeamRole
    {
        return TeamRole::from((string) $this->validated('role'));
    }
}
