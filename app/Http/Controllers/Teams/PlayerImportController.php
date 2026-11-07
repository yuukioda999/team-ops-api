<?php

namespace App\Http\Controllers\Teams;

use App\Http\Controllers\Controller;
use App\Http\Requests\Players\PlayerCsvRequest;
use App\Models\Team;
use App\Models\User;
use App\Support\Roster\CsvProblem;
use App\Support\Roster\CsvRow;
use App\Support\Roster\PlayerCsv;
use App\UseCases\Players\CheckPlayerImport;
use App\UseCases\Players\ImportPlayers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * 名簿の CSV 取り込み(AC-003-13〜19・21、ADR-0014)。代表・役員だけ。
 */
final class PlayerImportController extends Controller
{
    /** 確認で見せる先頭の行数(AC-003-14) */
    private const int PREVIEW_ROWS = 10;

    public function template(Team $team): Response
    {
        Gate::authorize('managePlayers', $team);

        return response(PlayerCsv::TEMPLATE, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="players-template.csv"',
        ]);
    }

    public function preview(PlayerCsvRequest $request, Team $team, CheckPlayerImport $check): JsonResponse
    {
        Gate::authorize('managePlayers', $team);
        $result = $check($team, $request->bytes());

        return new JsonResponse([
            'file_hash' => $result['hash'],
            'count' => count($result['rows']),
            'rows' => array_map(fn (CsvRow $row) => [
                'line' => $row->line,
                'display_name' => $row->player->displayName,
                'grade' => $row->player->grade->value,
                'number' => $row->player->number,
            ], array_slice($result['rows'], 0, self::PREVIEW_ROWS)),
            'errors' => array_map(fn (CsvProblem $p) => $p->toArray(), $result['errors']),
            'warnings' => array_map(fn (CsvProblem $p) => $p->toArray(), $result['warnings']),
        ]);
    }

    public function store(PlayerCsvRequest $request, Team $team, ImportPlayers $import): JsonResponse
    {
        Gate::authorize('managePlayers', $team);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return new JsonResponse(['imported' => $import($user, $team, $request->bytes(), $request->confirmedHash())], 201);
    }
}
