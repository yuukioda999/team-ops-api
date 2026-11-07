<?php

namespace App\Http\Requests\Notices;

use App\Models\Notice;
use App\Models\Team;
use App\Support\Text\SingleLineText;
use App\UseCases\Notices\NoticeAudience;
use App\UseCases\Notices\NoticeInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * お知らせの作成(POST)と、予約中のお知らせの編集(PATCH、送った項目だけ)。AC-005-01・09・10。
 * 権限:作成は作成できる役割。編集の相手ごとの判定は UseCase で行う(作成者か代表・役員)。
 */
final class NoticeRequest extends FormRequest
{
    /** 本文に使えない制御文字(改行・タブは使える) */
    private const string BODY_FORBIDDEN = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u';

    public function authorize(): bool
    {
        $team = $this->route('team');

        return $team instanceof Team && $this->user()?->can('createNotices', $team) === true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('title'))) {
            $this->merge(['title' => SingleLineText::trim($this->input('title'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('PATCH') ? 'sometimes' : 'required';

        return [
            'title' => [$required, 'string', 'between:1,'.Notice::TITLE_MAX, 'not_regex:'.SingleLineText::FORBIDDEN_PATTERN],
            'body' => [$required, 'string', 'between:1,'.Notice::BODY_MAX, 'not_regex:'.self::BODY_FORBIDDEN],
            ...AudienceRules::rules($required),
            'scheduled_at' => [$this->isMethod('PATCH') ? 'sometimes' : 'nullable', 'date'],
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
        return ['title' => 'タイトル', 'body' => '本文', 'target' => '宛先', 'target.type' => '宛先', 'target.values' => '宛先', 'scheduled_at' => '予約日時'];
    }

    public function notice(): NoticeInput
    {
        $scheduled = $this->validated('scheduled_at');

        return new NoticeInput(
            (string) $this->validated('title'),
            (string) $this->validated('body'),
            $this->audience(),
            is_string($scheduled) ? Carbon::parse($scheduled) : null,
        );
    }

    /**
     * 編集で送られた項目だけ。
     *
     * @return array{title?: string, body?: string, audience?: NoticeAudience, scheduled_at?: Carbon}
     */
    public function changes(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();
        $changes = [];
        foreach (['title', 'body'] as $field) {
            if (array_key_exists($field, $validated)) {
                $changes[$field] = (string) $validated[$field];
            }
        }
        if (array_key_exists('target', $validated)) {
            $changes['audience'] = $this->audience();
        }
        if (is_string($validated['scheduled_at'] ?? null)) {
            $changes['scheduled_at'] = Carbon::parse($validated['scheduled_at']);
        }

        return $changes;
    }

    private function audience(): NoticeAudience
    {
        /** @var array<string, mixed> $target */
        $target = $this->validated('target');

        return AudienceRules::audience($target);
    }
}
