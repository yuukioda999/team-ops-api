<?php

namespace App\Http\Requests\Auth;

final class RequestEmailChangeRequest extends EmailAddressRequest
{
    protected function field(): string
    {
        return 'new_email';
    }
}
