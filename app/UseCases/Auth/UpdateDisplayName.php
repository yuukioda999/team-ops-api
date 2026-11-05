<?php

namespace App\UseCases\Auth;

use App\Models\User;

/**
 * 表示名を登録・変更する(AC-001-08)。初回の登録で、アカウントの登録が完了する。
 */
final class UpdateDisplayName
{
    public function __invoke(User $user, string $displayName): User
    {
        $user->update(['display_name' => $displayName]);

        return $user;
    }
}
