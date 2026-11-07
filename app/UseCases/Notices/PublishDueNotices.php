<?php

namespace App\UseCases\Notices;

use App\Enums\NoticeStatus;
use App\Models\Notice;
use App\Models\Team;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 予約日時を過ぎた予約中のお知らせを公開する(AC-005-11、ADR-0018)。スケジューラが毎分呼ぶ。
 * 1件ずつ、行をロックして状態を確かめ直してから公開するので、同時に動いても二重に公開しない。
 * 作成者が今は作成できない(除名・役割の変更・アカウントの削除)なら、公開せずに取り消す(AC-005-17)。
 * 1件が失敗しても、ほかの予約の公開は止めない。
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
            try {
                $published += DB::transaction(fn (): int => $this->publishOne((int) $id));
            } catch (Throwable $e) {
                // 本文や宛先は出さない。次の分にやり直す
                Log::error('Publishing a scheduled notice failed; will retry next minute', ['notice_id' => $id, 'exception' => $e::class]);
            }
        }

        return $published;
    }

    private function publishOne(int $id): int
    {
        $notice = Notice::whereKey($id)->lockForUpdate()->first();
        if ($notice === null || $notice->status !== NoticeStatus::Scheduled || $notice->scheduled_at === null || $notice->scheduled_at->isFuture()) {
            return 0; // 取り消された、または別の処理が先に公開した
        }
        $team = Team::findOrFail($notice->team_id);

        $author = $notice->author_user_id === null ? null : User::find($notice->author_user_id);
        if ($author === null || ! Gate::forUser($author)->allows('createNotices', $team)) {
            $notice->forceFill(['status' => NoticeStatus::Cancelled])->save();
            Audit::record($team, null, 'notice.cancelled', $notice, ['reason' => 'author_not_permitted']);

            return 0;
        }

        ($this->publish)($team, $notice, null);

        return 1;
    }
}
