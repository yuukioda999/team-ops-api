<?php

namespace App\UseCases\Notices;

use App\Enums\NoticeStatus;
use App\Models\Notice;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

/**
 * 予約日時を過ぎた予約中のお知らせを公開する(AC-005-11、ADR-0018)。スケジューラが毎分呼ぶ。
 * 1件ずつ、行をロックして状態を確かめ直してから公開するので、同時に動いても二重に公開しない。
 */
final class PublishDueNotices
{
    public function __construct(private readonly PublishNotice $publish) {}

    /**
     * @return int 公開した件数
     */
    public function __invoke(): int
    {
        $ids = Notice::where('status', NoticeStatus::Scheduled)->where('scheduled_at', '<=', now())->orderBy('scheduled_at')->pluck('id');
        $published = 0;
        foreach ($ids as $id) {
            $published += DB::transaction(function () use ($id): int {
                $notice = Notice::whereKey($id)->lockForUpdate()->first();
                if ($notice === null || $notice->status !== NoticeStatus::Scheduled || $notice->scheduled_at === null || $notice->scheduled_at->isFuture()) {
                    return 0; // 取り消された、または別の処理が先に公開した
                }
                ($this->publish)(Team::findOrFail($notice->team_id), $notice, null);

                return 1;
            });
        }

        return $published;
    }
}
