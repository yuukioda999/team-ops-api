<?php

namespace App\UseCases\Notices;

use App\Enums\NoticeStatus;
use App\Models\Notice;
use App\Models\NoticeRecipient;
use App\Models\Team;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\NotifyRelay\EmailOutbox;
use App\Support\NotifyRelay\RecipientRestriction;

/**
 * お知らせを公開する(今すぐ送信と予約の公開で共通、ADR-0018)。呼び出し側のトランザクションの中で、
 * お知らせの行をロックしてから呼ぶ。宛先は公開の時点の名簿で決めて保存し(AC-005-12)、
 * ユーザーIDの順に1,000人ずつアウトボックスに入れる(AC-005-08)。
 */
final class PublishNotice
{
    public const int PER_DELIVERY = 1000;

    public function __construct(
        private readonly ResolveRecipients $resolve,
        private readonly EmailOutbox $outbox,
    ) {}

    public function __invoke(Team $team, Notice $notice, ?User $actor): void
    {
        $userIds = ($this->resolve)($team, new NoticeAudience($notice->target_type, $notice->target_values), $notice->author_user_id);

        $notice->forceFill([
            'status' => NoticeStatus::Published,
            'published_at' => now(),
            'recipient_count' => count($userIds),
        ])->save();

        $now = now();
        foreach (array_chunk($userIds, 500) as $chunk) {
            NoticeRecipient::insert(array_map(fn (int $id) => ['notice_id' => $notice->id, 'user_id' => $id, 'created_at' => $now, 'updated_at' => $now], $chunk));
        }

        foreach (array_chunk($userIds, self::PER_DELIVERY) as $i => $chunk) {
            $recipients = [];
            foreach (User::whereIn('id', $chunk)->orderBy('id')->get(['id', 'email']) as $user) {
                // 宛先を .invalid に限る環境(作業環境・デモ)では、それ以外の宛先はメールだけ送らない
                if (RecipientRestriction::invalidOnly() && ! str_ends_with(strtolower($user->email), '.invalid')) {
                    continue;
                }
                $recipients['user_'.$user->id] = $user->email;
            }
            if ($recipients === []) {
                continue;
            }
            $part = $i + 1;
            $this->outbox->queue(
                kind: 'notice',
                dedupeKey: "team:{$team->id}:notice:{$notice->id}:part:{$part}",
                subject: "【{$team->name}】{$notice->title}",
                text: $notice->body,
                recipients: $recipients,
                url: config('team_ops.web_url')."/teams/{$team->id}/notices/{$notice->id}",
                tenantRef: "team:{$team->id}",
            );
        }

        Audit::record($team, $actor, 'notice.published', $notice, [
            'target' => $notice->target_type->value,
            'values' => $notice->target_values,
            'recipients' => count($userIds),
            'scheduled' => $notice->scheduled_at !== null,
        ]);
    }
}
