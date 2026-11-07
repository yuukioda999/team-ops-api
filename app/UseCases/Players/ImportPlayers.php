<?php

namespace App\UseCases\Players;

use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Roster\CsvProblem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 確認したファイルと同じものなら、もう一度調べてから一括で登録する(AC-003-14・15・19、ADR-0014)。
 * 1件でもエラーがあれば1件も登録しない。
 */
final class ImportPlayers
{
    public function __construct(private readonly CheckPlayerImport $check) {}

    public function __invoke(User $actor, Team $team, string $bytes, string $confirmedHash): int
    {
        if (! hash_equals($confirmedHash, hash('sha256', $bytes))) {
            throw ValidationException::withMessages(['file_hash' => '確認したファイルと違います。もう一度確認してから取り込んでください。']);
        }

        return DB::transaction(function () use ($actor, $team, $bytes): int {
            // 確認から確定までの間に名簿が変わっても上限を超えないよう、チームの行をロックしてから調べ直す
            Team::whereKey($team->id)->lockForUpdate()->first();
            $result = ($this->check)($team, $bytes);
            if ($result['errors'] !== []) {
                throw ValidationException::withMessages([
                    'file' => array_map(fn (CsvProblem $p) => $p->message, $result['errors']),
                ]);
            }

            foreach ($result['rows'] as $row) {
                Player::create([
                    'team_id' => $team->id,
                    'display_name' => $row->player->displayName,
                    'grade' => $row->player->grade,
                    'number' => $row->player->number,
                ]);
            }

            Audit::record($team, $actor, 'player.imported', null, [
                'count' => count($result['rows']),
                'warnings' => count($result['warnings']),
            ]);

            return count($result['rows']);
        });
    }
}
