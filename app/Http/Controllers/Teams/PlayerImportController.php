<?php

namespace App\Http\Controllers\Teams;

use App\Http\Controllers\Controller;
use App\Http\Requests\Players\PlayerCsvRequest;
use App\Http\Resources\PlayerImportPreviewResource;
use App\Models\Team;
use App\Models\User;
use App\Support\Roster\PlayerCsv;
use App\UseCases\Players\ImportPlayers;
use App\UseCases\Players\PreviewPlayerImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * 名簿の CSV 取り込み(AC-003-13〜19・21、ADR-0014)。代表・役員だけ(確認と確定は PlayerCsvRequest で判定)。
 */
final class PlayerImportController extends Controller
{
    public function template(Team $team): Response
    {
        Gate::authorize('managePlayers', $team);

        return response(PlayerCsv::TEMPLATE, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="players-template.csv"',
        ]);
    }

    public function preview(PlayerCsvRequest $request, Team $team, PreviewPlayerImport $preview): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return new JsonResponse((new PlayerImportPreviewResource($preview($user, $team, $request->bytes())))->resolve());
    }

    public function store(PlayerCsvRequest $request, Team $team, ImportPlayers $import): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return new JsonResponse(['imported' => $import($user, $team, $request->bytes(), $request->confirmedHash())], 201);
    }
}
