<?php

namespace App\UseCases\Players;

use App\Enums\Grade;

/**
 * 選手の入力値。表示名は整えたもの(前後の空白を除いた1〜30文字)。
 */
final readonly class PlayerInput
{
    public function __construct(
        public string $displayName,
        public Grade $grade,
        public ?int $number,
    ) {}
}
