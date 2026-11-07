<?php

namespace App\UseCases\Notices;

use App\Enums\NoticeStatus;
use App\Models\Notice;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * 自分が宛先に含まれる公開済みのお知らせ(AC-005-14・15)。
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
     * @return Collection<int, Notice>
     */
    public function __invoke(User $user, Team $team): Collection
    {
        return self::visibleTo($user, $team)->with('author')->orderByDesc('published_at')->orderByDesc('id')->limit(self::PER_PAGE)->get();
    }
}
