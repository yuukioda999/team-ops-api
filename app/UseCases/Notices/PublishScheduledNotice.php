<?php

namespace App\UseCases\Notices;

use App\Enums\NoticeStatus;
use App\Models\Notice;
use App\Models\Team;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * 予約日時を過ぎた予約中のお知らせを1件公開する(AC-005-11、ADR-0018、ADR-0024)。
 * 行をロックして状態を確かめ直してから公開するので、同じお知らせに対して同時に動いても二重に公開しない。
 * 作成者が今は作成できない(除名・役割の変更・アカウントの削除)なら、公開せずに取り消す(AC-005-17)。
 */
final class PublishScheduledNotice
{
    public function __construct(private readonly PublishNotice $publish) {}

    /**
     * @return bool 公開したら true(取り消した、すでに公開された、まだ時刻でないなら false)
     */
    public function __invoke(int $noticeId): bool
    {
        return DB::transaction(function () use ($noticeId): bool {
            $notice = Notice::whereKey($noticeId)->lockForUpdate()->first();
            if ($notice === null || $notice->status !== NoticeStatus::Scheduled || $notice->scheduled_at === null || $notice->scheduled_at->isFuture()) {
                return false; // 取り消された、または別の処理が先に公開した
            }
            $team = Team::findOrFail($notice->team_id);

            $author = $notice->author_user_id === null ? null : User::find($notice->author_user_id);
            if ($author === null || ! Gate::forUser($author)->allows('createNotices', $team)) {
                $notice->forceFill(['status' => NoticeStatus::Cancelled])->save();
                Audit::record($team, null, 'notice.cancelled', $notice, ['reason' => 'author_not_permitted']);

                return false;
            }

            ($this->publish)($team, $notice, null);

            return true;
        });
    }
}
