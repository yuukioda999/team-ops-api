<?php

namespace App\UseCases\LoadTest;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * 試験データのチームの宛先の、試験用のアドレスと利用者IDの対応(AC-008-02)。障害訓練が、受け取り役が受け取った
 * 宛先を、API と notify-relay の配信状況と宛先ごとにつなぐのに使う(spec 009 の AC-009-02)。
 * 試験の環境の外では読まない(AC-008-03)。試験用のアドレス(loadtest.invalid)だけを返す。
 */
final class ListLoadTestRecipients
{
    /**
     * @param  list<string>  $teamIds
     * @return array<string, int> 小文字のアドレス => 利用者ID
     */
    public function __invoke(array $teamIds): array
    {
        if (! SeedLoadTest::allowed()) {
            throw new RuntimeException(SeedLoadTest::NOT_ALLOWED);
        }
        $map = [];
        foreach (array_chunk($teamIds, 500) as $chunk) {
            $userIds = DB::table('team_memberships')->whereIn('team_id', $chunk)->pluck('user_id')->all();
            foreach (array_chunk($userIds, 1000) as $ids) {
                foreach (DB::table('users')->whereIn('id', $ids)->get(['id', 'email']) as $row) {
                    $email = strtolower(Crypt::decryptString((string) $row->email));
                    if (str_ends_with($email, '@'.SeedLoadTest::DOMAIN)) {
                        $map[$email] = (int) $row->id;
                    }
                }
            }
        }

        return $map;
    }
}
