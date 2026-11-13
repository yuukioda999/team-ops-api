<?php

namespace App\UseCases\LoadTest;

use App\Enums\TeamRole;
use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * k6 が使うログイン済みのセッションを作る(spec 008 AC-008-07、ADR-0023 の判断3)。
 * チームの先頭から、代表と保護者を1人ずつ選ぶ。セッションはふつうのログインと同じ保存先に書き、
 * Cookie の値はブラウザに渡すものと同じ形で返す。試験の環境でしか動かない(SeedLoadTest::allowed())。
 */
final class CreateLoadTestSessions
{
    /**
     * @param  list<string>  $teamIds
     * @return list<array{team_id: string, role: string, cookie_name: string, cookie_value: string}>
     */
    public function __invoke(array $teamIds, int $count): array
    {
        if (! SeedLoadTest::allowed()) {
            throw new RuntimeException(SeedLoadTest::NOT_ALLOWED);
        }
        $store = app('session')->driver();
        $cookieName = $store->getName();
        $out = [];
        foreach (array_slice($teamIds, 0, (int) ceil($count / 2)) as $teamId) {
            $users = User::query()
                ->join('team_memberships', 'team_memberships.user_id', '=', 'users.id')
                ->join('team_member_roles', 'team_member_roles.membership_id', '=', 'team_memberships.id')
                ->where('team_memberships.team_id', $teamId)
                ->whereIn('team_member_roles.role', [TeamRole::Representative->value, TeamRole::Parent->value])
                ->orderBy('users.id')
                ->get(['users.id', 'team_member_roles.role']);
            foreach ([TeamRole::Representative->value, TeamRole::Parent->value] as $role) {
                $user = $users->firstWhere('role', $role);
                if ($user === null || count($out) >= $count) {
                    continue;
                }
                $store->flush();
                $store->setId(null);
                // データベースの保存先は、一度書くと以後は UPDATE だけになる。新しいセッションとして INSERT させる
                $handler = $store->getHandler();
                if ($handler instanceof DatabaseSessionHandler) {
                    $handler->setExists(false);
                }
                $store->put('login_web_'.sha1(SessionGuard::class), $user->id);
                $store->put('_token', Str::random(40));
                $store->save();
                $value = Crypt::encrypt(CookieValuePrefix::create($cookieName, Crypt::getKey()).$store->getId(), false);
                $out[] = ['team_id' => $teamId, 'role' => $role, 'cookie_name' => $cookieName, 'cookie_value' => $value];
            }
        }

        return $out;
    }
}
