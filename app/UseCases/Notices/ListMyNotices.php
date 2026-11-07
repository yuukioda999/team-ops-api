<?php

namespace App\UseCases\Notices;

use App\Enums\NoticeStatus;
use App\Models\Notice;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * 自分が宛先に含まれる公開済みのお知らせを、新しい順に50件ずつ(AC-005-14・15・19)。
 * $before を渡すと、そのお知らせより古いものを返す。
 */
final class ListMyNotices
{
    public const int PER_PAGE = 50;

    /**
     * @return Builder<Notice>
     */
    public static function visibleTo(User $user, Team $team): Builder
    {
        return Notice::where('team_id', $team->id)
            ->where('status', NoticeStatus::Published)
            ->whereHas('recipients', fn (Builder $q) => $q->where('user_id', $user->id));
    }

    /**
     * @return array{notices: Collection<int, Notice>, next_before: int|null}
     */
    public function __invoke(User $user, Team $team, ?int $before): array
    {
        $query = self::visibleTo($user, $team)->with('author');
        if ($before !== null) {
            $cursor = self::visibleTo($user, $team)->find($before);
            if ($cursor === null) {
                return ['notices' => new Collection, 'next_before' => null];
            }
            // 公開日時が同じなら ID で順を決める(新しい順)
            $query->where(fn (Builder $q) => $q->where('published_at', '<', $cursor->published_at)
                ->orWhere(fn (Builder $q) => $q->where('published_at', $cursor->published_at)->where('id', '<', $cursor->id)));
        }
        $notices = $query->orderByDesc('published_at')->orderByDesc('id')->limit(self::PER_PAGE + 1)->get();
        $hasMore = $notices->count() > self::PER_PAGE;
        $notices = $notices->take(self::PER_PAGE)->values();

        return ['notices' => $notices, 'next_before' => $hasMore ? $notices->last()?->id : null];
    }
}
