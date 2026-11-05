<?php

namespace App\Http\Requests\Auth;

final class RequestLoginLinkRequest extends EmailAddressRequest
{
    protected function field(): string
    {
        return 'email';
    }
}
