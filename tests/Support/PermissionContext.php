<?php

namespace Tests\Support;

use App\Models\Team;
use Tests\TestCase;

/**
 * 権限表のテストで、API を呼ぶのに要るもの。
 */
final readonly class PermissionContext
{
    /**
     * @param  array{int, int}  $joinRequestIds  承認待ちの参加申請(承認用、却下用)
     */
    public function __construct(
        public TestCase $test,
        public Team $team,
        /** 役割の変更・除名の相手(保護者) */
        public int $targetMembershipId,
        /** 代表の交代の相手(役員) */
        public int $nextRepresentativeMembershipId,
        /** 名簿の操作に使う選手(誰にも紐づいていない) */
        public int $playerId,
        public array $joinRequestIds,
    ) {}

    public function url(string $path): string
    {
        return "/api/teams/{$this->team->id}{$path}";
    }
}
