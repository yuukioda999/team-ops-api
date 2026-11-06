<?php

namespace App\Enums;

enum LoginTokenPurpose: string
{
    case Login = 'login';
    case EmailChange = 'email_change';
}
