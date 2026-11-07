<?php

namespace App\UseCases\Notices;

use App\Models\Notice;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * 届く人数(AC-005-02)。予約の編集では、そのお知らせの作成者を送信者として数える(公開のときと同じ)。
 */
final class CountRecipients
{
    public function __construct(private readonly ResolveRecipients $resolve) {}

    public function __invoke(User $actor, Team $team, NoticeAudience $audience, ?int $noticeId): int
    {
        $sender = $actor->id;
        if ($noticeId !== null) {
            $notice = Notice::where('team_id', $team->id)->findOrFail($noticeId);
            Gate::forUser($actor)->authorize('manageNotice', [$team, $notice]);
            $sender = $notice->author_user_id;
        }

        return count(($this->resolve)($team, $audience, $sender));
    }
}
