<?php

namespace App\Http\Requests\Invitations;

use App\Support\Text\SingleLineText;

/**
 * 参加申請の入力(AC-002-06)。表示名はアカウントと同じ規則、ひとことは任意で100文字以内。
 */
final class SubmitJoinRequestRequest extends InvitationTokenRequest
{
    public const int MESSAGE_MAX = 100;

    protected function prepareForValidation(): void
    {
        foreach (['display_name', 'message'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => SingleLineText::trim($this->input($field))]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'display_name' => ['required', 'string', 'between:1,30', 'not_regex:'.SingleLineText::FORBIDDEN_PATTERN],
            'message' => ['nullable', 'string', 'max:'.self::MESSAGE_MAX, 'not_regex:'.SingleLineText::FORBIDDEN_PATTERN],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['display_name' => '表示名', 'message' => 'ひとこと'];
    }

    public function displayName(): string
    {
        return (string) $this->validated('display_name');
    }

    public function message(): ?string
    {
        $value = $this->validated('message');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
