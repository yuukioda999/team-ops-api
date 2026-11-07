<?php

namespace App\Http\Requests\Notices;

use App\Models\Team;
use App\UseCases\Notices\NoticeAudience;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * 届く人数の確認(AC-005-02)。
 */
final class RecipientCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        $team = $this->route('team');

        return $team instanceof Team && $this->user()?->can('createNotices', $team) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...AudienceRules::rules('required'),
            // 予約の編集のときは、そのお知らせの作成者を送信者として数える
            'notice_id' => ['nullable', 'integer', 'min:1', 'max:999999999999999999'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => AudienceRules::after($v));
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['target' => '宛先', 'target.type' => '宛先', 'target.values' => '宛先'];
    }

    public function noticeId(): ?int
    {
        $id = $this->validated('notice_id');

        return $id === null ? null : (int) $id;
    }

    public function audience(): NoticeAudience
    {
        /** @var array<string, mixed> $target */
        $target = $this->validated('target');

        return AudienceRules::audience($target);
    }
}
