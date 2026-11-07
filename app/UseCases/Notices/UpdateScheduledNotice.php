<?php

namespace App\UseCases\Notices;

use App\Models\Notice;
use App\Models\Team;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 予約中のお知らせを編集する(AC-005-10)。送信済みは 409(AC-005-06)。
 */
final class UpdateScheduledNotice
{
    /**
     * @param  array{title?: string, body?: string, audience?: NoticeAudience, scheduled_at?: Carbon}  $changes
     */
    public function __invoke(User $actor, Team $team, int $noticeId, array $changes): Notice
    {
        if (isset($changes['scheduled_at'])) {
            ScheduleWindow::ensure($changes['scheduled_at']);
        }

        return DB::transaction(function () use ($actor, $team, $noticeId, $changes): Notice {
            $notice = ScheduledNoticeFinder::lockForChange($actor, $team, $noticeId);
            $fill = [];
            foreach (['title', 'body', 'scheduled_at'] as $field) {
                if (array_key_exists($field, $changes)) {
                    $fill[$field] = $changes[$field];
                }
            }
            if (isset($changes['audience'])) {
                $fill['target_type'] = $changes['audience']->type;
                $fill['target_values'] = $changes['audience']->values;
            }
            $notice->fill($fill);
            $changed = array_keys($notice->getDirty());
            $notice->save();

            if ($changed !== []) {
                sort($changed);
                Audit::record($team, $actor, 'notice.updated', $notice, ['fields' => $changed]);
            }

            return $notice;
        });
    }
}
