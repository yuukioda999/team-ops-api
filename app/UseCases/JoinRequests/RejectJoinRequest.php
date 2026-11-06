<?php

namespace App\UseCases\JoinRequests;

use App\Enums\JoinRequestStatus;
use App\Models\Team;
use App\Models\TeamJoinRequest;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * 承認待ちの申請を却下する。申請者には通知しない(AC-002-13)。
 */
final class RejectJoinRequest
{
    public function __invoke(User $actor, Team $team, int $requestId): TeamJoinRequest
    {
        return DB::transaction(function () use ($actor, $team, $requestId): TeamJoinRequest {
            $request = TeamJoinRequest::where('team_id', $team->id)
                ->where('status', JoinRequestStatus::Pending)
                ->lockForUpdate()
                ->findOrFail($requestId);

            $request->finish(JoinRequestStatus::Rejected, $actor);
            Audit::record($team, $actor, 'join_request.rejected', $request, ['user_id' => $request->user_id]);

            return $request;
        });
    }
}
