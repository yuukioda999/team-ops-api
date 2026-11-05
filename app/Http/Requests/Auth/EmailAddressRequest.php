<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * メールアドレスを1つ受け取る入力(ログイン用リンクの要求、メール変更の申請)。
 */
abstract class EmailAddressRequest extends FormRequest
{
    abstract protected function field(): string;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            $this->field() => ['required', 'string', 'max:254', 'email:rfc'],
        ];
    }

    public function address(): string
    {
        return trim((string) $this->validated($this->field()));
    }
}
