<?php

namespace App\UseCases\Notices;

use App\Enums\NoticeStatus;
use App\Models\Notice;
use App\Models\Team;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * お知らせを作る。今すぐ送信なら公開し、予約なら指定日時まで公開しない(AC-005-01・09)。
 * 届く人が0人なら作らない(AC-005-02)。
 */
final class SendNotice
{
    public function __construct(
        private readonly ResolveRecipients $resolve,
        private readonly PublishNotice $publish,
    ) {}

    public function __invoke(User $actor, Team $team, NoticeInput $input): Notice
    {
        if ($input->scheduledAt !== null) {
            ScheduleWindow::ensure($input->scheduledAt);
        }
        // 送信者本人が必ず含まれるので、チームにいる限り0人にはならない。念のため送る前に確かめる
        if (($this->resolve)($team, $input->audience, $actor->id) === []) {
            throw ValidationException::withMessages(['target' => '届く人がいません。宛先を選び直してください。']);
        }

        return DB::transaction(function () use ($actor, $team, $input): Notice {
            $notice = Notice::create([
                'team_id' => $team->id,
                'author_user_id' => $actor->id,
                'title' => $input->title,
                'body' => $input->body,
                'target_type' => $input->audience->type,
                'target_values' => $input->audience->values,
                'status' => NoticeStatus::Scheduled,
                'scheduled_at' => $input->scheduledAt,
                'recipient_count' => 0, // 公開のときに決まる
            ]);

            if ($input->scheduledAt === null) {
                ($this->publish)($team, $notice, $actor);
            } else {
                Audit::record($team, $actor, 'notice.scheduled', $notice, [
                    'target' => $notice->target_type->value,
                    'values' => $notice->target_values,
                ]);
            }

            return $notice;
        });
    }
}
