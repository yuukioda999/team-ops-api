<?php

namespace App\Http\Resources;

use App\Support\Roster\CsvProblem;
use App\Support\Roster\CsvRow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * CSV の確認の結果(AC-003-14・15・21)。先頭10行だけを返す。
 *
 * @property array{hash: string, rows: list<CsvRow>, errors: list<CsvProblem>, warnings: list<CsvProblem>} $resource
 */
final class PlayerImportPreviewResource extends JsonResource
{
    /** 確認で見せる先頭の行数(AC-003-14) */
    public const int PREVIEW_ROWS = 10;

    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'file_hash' => $this->resource['hash'],
            'count' => count($this->resource['rows']),
            'rows' => array_map(fn (CsvRow $row) => [
                'line' => $row->line,
                'display_name' => $row->player->displayName,
                'grade' => $row->player->grade->value,
                'number' => $row->player->number,
            ], array_slice($this->resource['rows'], 0, self::PREVIEW_ROWS)),
            'errors' => array_map(fn (CsvProblem $p) => $p->toArray(), $this->resource['errors']),
            'warnings' => array_map(fn (CsvProblem $p) => $p->toArray(), $this->resource['warnings']),
        ];
    }
}
