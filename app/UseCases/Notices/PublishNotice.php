<?php

namespace App\UseCases\Notices;

use App\Enums\NoticeStatus;
use App\Models\Notice;
use App\Models\NoticeRecipient;
use App\Models\Team;
use App\Models\User;
use App\Support\Audit\Audit;

/**
 * お知らせを公開する(今すぐ送信と予約の公開で共通、ADR-0018)。呼び出し側のトランザクションの中で、
 * お知らせの行をロックしてから呼ぶ。宛先は公開の時点の名簿で決めて保存し(AC-005-12)、
 * ユーザーIDの順に1,000人ずつアウトボックスに入れる(AC-005-08、NoticeMailer)。
 */
final class PublishNotice
{
    public function __construct(
        private readonly ResolveRecipients $resolve,
        private readonly NoticeMailer $mail,
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
            NoticeRecipient::insert(array_map(fn (int $id) => ['notice_id' => $notice->id, 'user_id' => $id, 'delivery_status' => 'pending', 'created_at' => $now, 'updated_at' => $now], $chunk));
        }

        ($this->mail)($team, $notice, $userIds, null, $now);

        Audit::record($team, $actor, 'notice.published', $notice, [
            'target' => $notice->target_type->value,
            'values' => $notice->target_values,
            'recipients' => count($userIds),
            'scheduled' => $notice->scheduled_at !== null,
        ]);
    }
}
