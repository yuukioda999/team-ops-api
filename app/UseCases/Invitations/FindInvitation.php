<?php

namespace App\UseCases\Invitations;

use App\Models\TeamInvitation;

/**
 * 招待リンクのトークンから、有効なリンクを探す。
 * 期限切れ・無効化・存在しないを区別しない(AC-002-07、推測させない)。
 */
final class FindInvitation
{
    public function __invoke(string $plainToken, bool $lock = false): ?TeamInvitation
    {
        if (preg_match('/^[0-9a-f]{64}$/', $plainToken) !== 1) {
            return null;
        }

        return TeamInvitation::where('token_hash', TeamInvitation::hashToken($plainToken))
            ->active()
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->first();
    }
}
