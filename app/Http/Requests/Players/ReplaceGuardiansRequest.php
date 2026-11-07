<?php

namespace App\Http\Requests\Players;

use App\Models\Player;
use App\Models\Team;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 選手に紐づける保護者(AC-003-05・06)。空の配列なら、すべて外す。
 */
final class ReplaceGuardiansRequest extends FormRequest
{
    /**
     * 入力の検査より先に権限を判定する。権限のない人には、内容によらず403を返す(TeamPolicy)。
     */
    public function authorize(): bool
    {
        $team = $this->route('team');

        return $team instanceof Team && $this->user()?->can('managePlayers', $team) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'membership_ids' => ['present', 'array', 'max:'.Player::MAX_GUARDIANS],
            'membership_ids.*' => ['integer', 'min:1', 'max:999999999999999999', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['membership_ids' => '保護者', 'membership_ids.*' => '保護者'];
    }

    /**
     * @return list<int>
     */
    public function membershipIds(): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->validated('membership_ids');

        return array_map(fn (int|string $id) => (int) $id, $ids);
    }
}
