<?php

namespace App\UseCases\JoinRequests;

use App\Enums\JoinRequestStatus;
use App\Models\TeamJoinRequest;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * 自分の参加申請(AC-002-09・13・15)。承認されたものはチーム一覧に入るので除く。
 * 同じチームに何度か申請していたら、最新の1件だけを返す(却下のあとの再申請で、
 * 「承認待ち」と「承認されませんでした」が並ばないように)。
 */
final class ListMyJoinRequests
{
    /**
     * @return Collection<int, TeamJoinRequest>
     */
    public function __invoke(User $user): Collection
    {
        return $user->joinRequests()
            ->with('team')
            ->latest('id')
            ->get()
            ->unique('team_id')
            ->reject(fn (TeamJoinRequest $request) => $request->status === JoinRequestStatus::Approved || $request->team === null)
            ->values();
    }
}
