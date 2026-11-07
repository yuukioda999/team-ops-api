<?php

namespace App\Http\Controllers\Teams;

use App\Http\Controllers\Controller;
use App\Http\Requests\AuditLogs\ListAuditLogsRequest;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\Team;
use App\UseCases\AuditLogs\ListAuditLogs;
use Illuminate\Http\JsonResponse;

/**
 * 監査ログの閲覧(AC-004-12)。代表だけ。
 */
final class AuditLogController extends Controller
{
    public function index(ListAuditLogsRequest $request, Team $team, ListAuditLogs $list): JsonResponse
    {
        $result = $list($team, $request->before());

        return new JsonResponse([
            'logs' => $result['logs']->map(fn (AuditLog $log) => (new AuditLogResource($log, $result['member_user_ids']))->resolve($request))->all(),
            'next_before' => $result['next_before'],
        ]);
    }
}
