<?php

namespace App\Support\NotifyRelay;

/**
 * 宛先を .invalid ドメインだけに限るかどうか。
 * RECIPIENTS_INVALID_ONLY か、デモモード(spec 007、AC-007-13)のどちらかが有効なら限る。
 */
final class RecipientRestriction
{
    public static function invalidOnly(): bool
    {
        return (bool) config('team_ops.invalid_recipients_only') || (bool) config('team_ops.demo_mode');
    }
}
