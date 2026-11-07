<?php

namespace App\UseCases\Players;

use App\Models\Player;
use App\Models\Team;
use App\Support\Roster\CsvProblem;
use App\Support\Roster\CsvRow;
use App\Support\Roster\PlayerCsv;

/**
 * CSV を読み、エラー(1件でもあれば取り込まない)と、重複の警告(取り込める)を調べる(AC-003-14〜18・21)。
 * 確認と確定の両方で使う。確定のときも、最新の名簿でもう一度調べる(ADR-0014)。
 */
final class CheckPlayerImport
{
    public function __construct(private readonly DuplicateFinder $duplicates) {}

    /**
     * @return array{hash: string, rows: list<CsvRow>, errors: list<CsvProblem>, warnings: list<CsvProblem>}
     */
    public function __invoke(Team $team, string $bytes): array
    {
        $csv = PlayerCsv::parse($bytes);
        $errors = $csv->errors;

        $current = Player::where('team_id', $team->id)->count();
        if ($current + count($csv->rows) + $this->failedRows($errors) > Player::MAX_PER_TEAM) {
            $errors[] = new CsvProblem(null, null, CreatePlayer::limitMessage($current));
        }

        $existing = $this->duplicates->keysIn($team);
        $seen = [];
        $warnings = [];
        foreach ($csv->rows as $row) {
            $key = DuplicateFinder::key($row->player->displayName, $row->player->grade);
            if (isset($seen[$key])) {
                $warnings[] = new CsvProblem($row->line, PlayerCsv::COLUMN_NAME, "{$row->line}行目:{$seen[$key]}行目と同じ表示名・学年です");
            } elseif (isset($existing[$key])) {
                $warnings[] = new CsvProblem($row->line, PlayerCsv::COLUMN_NAME, "{$row->line}行目:同じ表示名・学年の選手がすでに名簿にいます");
            }
            $seen[$key] ??= $row->line;
        }

        return ['hash' => hash('sha256', $bytes), 'rows' => $csv->rows, 'errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * エラーのある行の数(上限の判定には、直したあとに取り込む行として数える)。
     *
     * @param  list<CsvProblem>  $errors
     */
    private function failedRows(array $errors): int
    {
        return count(array_unique(array_filter(array_map(fn (CsvProblem $p) => $p->line, $errors))));
    }
}
