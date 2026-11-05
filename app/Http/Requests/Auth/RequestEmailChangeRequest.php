<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Support\Crypto\BlindIndex;
use Illuminate\Validation\Validator;

final class RequestEmailChangeRequest extends EmailAddressRequest
{
    protected function field(): string
    {
        return 'new_email';
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $user = $this->user();
                $value = $this->input('new_email');
                if ($validator->errors()->isEmpty() && $user instanceof User && is_string($value)
                    && BlindIndex::normalizeEmail($value) === BlindIndex::normalizeEmail($user->email)) {
                    $validator->errors()->add('new_email', '現在のメールアドレスと同じです。');
                }
            },
        ];
    }
}
