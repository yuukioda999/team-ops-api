<?php

namespace App\Support\Roster;

use App\UseCases\Players\PlayerInput;

/**
 * 検査を通った CSV の1行。
 */
final readonly class CsvRow
{
    public function __construct(
        public int $line,
        public PlayerInput $player,
    ) {}
}
