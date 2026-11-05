<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * メールのリンクに付いたトークンを受け取る入力。形の誤りは期限切れと同じ扱いにするため、
 * ここでは「文字列であること」だけを確かめる。
 */
final class ConsumeTokenRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:128'],
        ];
    }

    public function token(): string
    {
        return (string) $this->validated('token');
    }
}
