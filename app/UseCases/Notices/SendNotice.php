<?php

namespace App\UseCases\Notices;

use App\Enums\NoticeStatus;
use App\Models\Notice;
use App\Models\Team;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * お知らせを作る。今すぐ送信なら公開し、予約なら指定日時まで公開しない(AC-005-01・09)。
 * 作れるのは1人1時間に10件まで(AC-005-18)。入力の誤りで作れなかったものは数えない。
 */
final class SendNotice
{
    public const int PER_HOUR = 10;

    public function __construct(private readonly PublishNotice $publish) {}

    public function __invoke(User $actor, Team $team, NoticeInput $input): Notice
    {
        $key = 'notice-create:user:'.$actor->id;
        if (RateLimiter::tooManyAttempts($key, self::PER_HOUR)) {
            throw new ThrottleRequestsException(headers: ['Retry-After' => RateLimiter::availableIn($key)]);
        }
        if ($input->scheduledAt !== null) {
            ScheduleWindow::ensure($input->scheduledAt);
        }
        // 届く人数が0人にはならない(送信者本人が必ず含まれる、AC-005-02)

        $notice = DB::transaction(function () use ($actor, $team, $input): Notice {
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
        RateLimiter::hit($key, 3600);

        return $notice;
    }
}
