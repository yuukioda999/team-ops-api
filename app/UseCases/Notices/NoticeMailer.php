<?php

namespace App\UseCases\Notices;

use App\Enums\DeliveryStatus;
use App\Models\Notice;
use App\Models\NoticeRecipient;
use App\Models\Team;
use App\Models\User;
use App\Support\NotifyRelay\EmailOutbox;
use App\Support\NotifyRelay\NoticeDeliveryKey;
use App\Support\NotifyRelay\RecipientRestriction;
use Illuminate\Support\Carbon;

/**
 * お知らせのメールをアウトボックスに入れる(送信と再通知で共通、ADR-0018・0019・0020)。
 * ユーザーIDの順に1,000人ずつ分ける。送る人の宛先の行は送信待ちに、送らない人は「送らなかった」にする。
 * 送らないのは、メールが届かないアドレスの人と、宛先を .invalid に限る環境でそれ以外のアドレスの人。
 */
final class NoticeMailer
{
    public const int PER_DELIVERY = 1000;

    public function __construct(private readonly EmailOutbox $outbox) {}

    /**
     * @param  list<int>  $userIds
     * @param  int|null  $reminder  再通知の回数。送信なら null
     * @return array{sent: int, excluded: int}
     */
    public function __invoke(Team $team, Notice $notice, array $userIds, ?int $reminder, Carbon $now): array
    {
        sort($userIds);
        $sent = 0;
        $excluded = ['undeliverable' => [], 'restricted' => []];
        foreach (array_chunk($userIds, self::PER_DELIVERY) as $i => $chunk) {
            $recipients = [];
            foreach (User::whereIn('id', $chunk)->orderBy('id')->get(['id', 'email', 'email_undeliverable_at']) as $user) {
                if ($user->email_undeliverable_at !== null) {
                    $excluded['undeliverable'][] = $user->id;

                    continue;
                }
                if (RecipientRestriction::invalidOnly() && ! str_ends_with(strtolower($user->email), '.invalid')) {
                    $excluded['restricted'][] = $user->id;

                    continue;
                }
                $recipients['user_'.$user->id] = $user->email;
            }
            if ($recipients === []) {
                continue;
            }
            $part = $i + 1;
            $this->outbox->queue(
                kind: $reminder === null ? 'notice' : 'notice_reminder',
                dedupeKey: $reminder === null ? NoticeDeliveryKey::publish($team->id, $notice->id, $part) : NoticeDeliveryKey::reminder($team->id, $notice->id, $reminder, $part),
                subject: ($reminder === null ? '' : '【再通知】')."【{$team->name}】{$notice->title}",
                text: $notice->body,
                recipients: $recipients,
                url: config('team_ops.web_url')."/teams/{$team->id}/notices/{$notice->id}",
                tenantRef: "team:{$team->id}",
            );
            $sentIds = array_map(fn (string $ref) => (int) substr($ref, 5), array_keys($recipients));
            $this->mark($notice, $sentIds, DeliveryStatus::Pending, null, $reminder ?? 0, $now);
            $sent += count($sentIds);
        }
        $this->mark($notice, $excluded['undeliverable'], DeliveryStatus::Skipped, NoticeRecipient::SKIPPED_UNDELIVERABLE, $reminder ?? 0, $now);
        $this->mark($notice, $excluded['restricted'], DeliveryStatus::Skipped, NoticeRecipient::SKIPPED_RESTRICTED, $reminder ?? 0, $now);

        return ['sent' => $sent, 'excluded' => count($excluded['undeliverable']) + count($excluded['restricted'])];
    }

    /**
     * 宛先の行を、この送信(round)の状態にする。前の送信の結果はこのあと採らない(ADR-0019)。
     *
     * @param  list<int>  $userIds
     */
    private function mark(Notice $notice, array $userIds, DeliveryStatus $status, ?string $error, int $round, Carbon $now): void
    {
        foreach (array_chunk($userIds, 500) as $chunk) {
            NoticeRecipient::where('notice_id', $notice->id)->whereIn('user_id', $chunk)->update([
                'delivery_status' => $status,
                'delivery_error' => $error,
                'delivery_round' => $round,
                'delivery_status_at' => null,
                'updated_at' => $now,
            ]);
        }
    }
}
