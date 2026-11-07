<?php

namespace App\Support\Roster;

/**
 * CSV のエラーまたは警告の1件。行番号は見出しを1行目として数える(AC-003-15)。
 */
final readonly class CsvProblem
{
    public function __construct(
        public ?int $line,
        public ?string $column,
        public string $message,
    ) {}

    /**
     * @return array{line: int|null, column: string|null, message: string}
     */
    public function toArray(): array
    {
        return ['line' => $this->line, 'column' => $this->column, 'message' => $this->message];
    }
}
