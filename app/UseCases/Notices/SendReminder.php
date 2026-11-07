<?php

namespace App\UseCases\Notices;

use App\Models\NoticeReminder;
use App\Models\Team;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 未読の人に再通知する(AC-006-11〜15、ADR-0020)。お知らせの行をロックして、二重に実行しない。
 * 前回から1時間以内・3回済み・未読の人がいないときは 409。
 * 実行時点で未読の、今もチームにいる人に送る。メールが届かないアドレスの人などは除き、その人数を返す。
 */
final class SendReminder
{
    public function __construct(private readonly NoticeMailer $mail) {}

    /**
     * @return array{number: int, sent: int, excluded: int}
     */
    public function __invoke(User $actor, Team $team, int $noticeId): array
    {
        return DB::transaction(function () use ($actor, $team, $noticeId): array {
            $notice = NoticeStatusFinder::find($actor, $team, $noticeId, lock: true);
            $now = now();
            $availability = NoticeStatusSummary::reminderAvailability($team, $notice, $now);
            if (! $availability['available']) {
                $error = ValidationException::withMessages(['reminder' => match ($availability['reason']) {
                    'max' => '再通知は1つのお知らせにつき3回までです。',
                    'interval' => '再通知は1時間に1回までです。'.self::nextAt($availability['next_available_at']),
                    default => '未読の人がいません。',
                }])->status(409);
                throw $error;
            }

            $number = $availability['sent'] + 1;
            $userIds = NoticeStatusSummary::unreadMemberIds($team, $notice);
            $result = ($this->mail)($team, $notice, $userIds, $number, $now);
            NoticeReminder::create([
                'notice_id' => $notice->id,
                'number' => $number,
                'sent_by_user_id' => $actor->id,
                'sent_count' => $result['sent'],
                'excluded_count' => $result['excluded'],
                'created_at' => $now,
            ]);
            Audit::record($team, $actor, 'notice.reminded', $notice, ['number' => $number, 'recipients' => $result['sent'], 'excluded' => $result['excluded']]);

            return ['number' => $number, 'sent' => $result['sent'], 'excluded' => $result['excluded']];
        });
    }

    private static function nextAt(?string $iso): string
    {
        return $iso === null ? '' : Carbon::parse($iso)->setTimezone('Asia/Tokyo')->format('n月j日 H:i').'から実行できます。';
    }
}
