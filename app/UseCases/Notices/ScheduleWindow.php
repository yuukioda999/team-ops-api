<?php

namespace App\UseCases\Notices;

use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * 予約できる日時の範囲(5分後〜30日後、AC-005-09・13)。
 */
final class ScheduleWindow
{
    public const int MIN_MINUTES = 5;

    public const int MAX_DAYS = 30;

    public static function ensure(Carbon $at): void
    {
        if ($at->lt(now()->addMinutes(self::MIN_MINUTES)) || $at->gt(now()->addDays(self::MAX_DAYS))) {
            throw ValidationException::withMessages(['scheduled_at' => '予約できるのは、'.self::MIN_MINUTES.'分後から'.self::MAX_DAYS.'日後までです。']);
        }
    }
}
