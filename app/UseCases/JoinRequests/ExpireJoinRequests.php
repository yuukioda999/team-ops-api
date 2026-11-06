<?php

namespace App\UseCases\JoinRequests;

use App\Enums\JoinRequestStatus;
use App\Models\TeamJoinRequest;
use Illuminate\Support\Facades\DB;

/**
 * 申請から14日間、承認も却下もされない申請を取り消す(AC-002-15)。ひとことも消す(AC-002-17)。
 */
final class ExpireJoinRequests
{
    /**
     * @return int 取り消した件数
     */
    public function __invoke(): int
    {
        $count = 0;
        TeamJoinRequest::where('status', JoinRequestStatus::Pending)
            ->where('created_at', '<=', now()->subDays(TeamJoinRequest::EXPIRE_AFTER_DAYS))
            ->orderBy('id')
            ->chunkById(200, function ($requests) use (&$count): void {
                foreach ($requests as $request) {
                    DB::transaction(function () use ($request, &$count): void {
                        // 取り消す直前に承認・却下されていたら触らない
                        $fresh = TeamJoinRequest::whereKey($request->id)->where('status', JoinRequestStatus::Pending)->lockForUpdate()->first();
                        if ($fresh !== null) {
                            $fresh->finish(JoinRequestStatus::Cancelled, null);
                            $count++;
                        }
                    });
                }
            });

        return $count;
    }
}
