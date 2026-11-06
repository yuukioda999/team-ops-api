<?php

namespace App\Http\Requests\Invitations;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 招待リンクのトークンを本文で受け取る(URL のクエリに載せず、アクセスログに残さない)。
 * 形の誤りは無効なリンクと同じ扱いにするため、ここでは文字列であることだけを確かめる。
 */
class InvitationTokenRequest extends FormRequest
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
