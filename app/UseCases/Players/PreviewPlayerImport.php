<?php

namespace App\UseCases\Players;

use App\Models\Team;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Roster\CsvProblem;
use App\Support\Roster\CsvRow;

/**
 * CSV の確認(AC-003-14・15・21)。登録はしない。
 * 確認は既存の名簿と照らした結果(重複の警告)を返すので、閲覧と同じく監査ログに残す(ADR-0013)。
 */
final class PreviewPlayerImport
{
    public function __construct(private readonly CheckPlayerImport $check) {}

    /**
     * @return array{hash: string, rows: list<CsvRow>, errors: list<CsvProblem>, warnings: list<CsvProblem>}
     */
    public function __invoke(User $actor, Team $team, string $bytes): array
    {
        $result = ($this->check)($team, $bytes);

        Audit::record($team, $actor, 'player.import_checked', null, [
            'count' => count($result['rows']),
            'errors' => count($result['errors']),
            'warnings' => count($result['warnings']),
        ]);

        return $result;
    }
}
