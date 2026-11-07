<?php

namespace Tests\Support;

use App\Models\Team;
use Tests\TestCase;

/**
 * 権限表のテストで、API を呼ぶのに要るもの。
 */
final readonly class PermissionContext
{
    public function __construct(
        public TestCase $test,
        public Team $team,
        /** 役割の変更・除名の相手(保護者) */
        public int $targetMembershipId,
        /** 代表の交代の相手(役員) */
        public int $nextRepresentativeMembershipId,
    ) {}

    public function url(string $path): string
    {
        return "/api/teams/{$this->team->id}{$path}";
    }
}
