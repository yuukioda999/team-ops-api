<?php

namespace App\Http\Requests\Auth;

final class RequestLoginLinkRequest extends EmailAddressRequest
{
    /**
     * ログイン後に戻ってよい先(AC-001-19、ADR-0010)。決まった形のパスだけを受け付ける。
     * 招待リンクと、メール変更の確認リンク。どちらも同じ形の64桁のトークンを持つ。
     */
    private const string RETURN_TO_PATTERN = '#\A/(join|settings/email/verify)\?token=[0-9a-f]{64}\z#';

    protected function field(): string
    {
        return 'email';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [...parent::rules(), 'return_to' => ['nullable', 'string', 'max:512']];
    }

    /**
     * 受け付けない形の戻り先は、エラーにせず無視する(ログイン自体は続けられるように)。
     */
    public function returnTo(): ?string
    {
        $value = $this->validated('return_to');

        return is_string($value) && preg_match(self::RETURN_TO_PATTERN, $value) === 1 ? $value : null;
    }
}
