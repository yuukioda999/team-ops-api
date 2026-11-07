<?php

namespace App\Enums;

enum NoticeStatus: string
{
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Cancelled = 'cancelled';
}
