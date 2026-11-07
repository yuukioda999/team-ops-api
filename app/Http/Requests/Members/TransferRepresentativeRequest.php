<?php

namespace App\Http\Requests\Members;

use App\Models\Team;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 代表の交代(AC-004-04)。新しい代表にするメンバーを指定する。
 */
final class TransferRepresentativeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $team = $this->route('team');

        return $team instanceof Team && $this->user()?->can('transferRepresentative', $team) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['membership_id' => ['required', 'integer', 'min:1', 'max:999999999999999999']];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['membership_id' => '新しい代表'];
    }

    public function membershipId(): int
    {
        return (int) $this->validated('membership_id');
    }
}
