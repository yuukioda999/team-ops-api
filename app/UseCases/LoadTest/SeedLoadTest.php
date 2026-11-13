<?php

namespace App\UseCases\LoadTest;

use App\Enums\NoticeStatus;
use App\Enums\NoticeTarget;
use App\Enums\Sport;
use App\Enums\TeamRole;
use App\Support\Crypto\BlindIndex;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * 負荷試験のデータを作る(AC-008-02、ADR-0023)。チームごとに、代表・役員・コーチ・保護者の
 * $members 人と、全員宛ての予約のお知らせ1件を作る。宛先はすべて loadtest.invalid で外には出ない。
 *
 * 1万チーム × 30人を短い時間で作るため、Eloquent で1行ずつ保存せず、行をまとめて insert する。
 * 暗号化とブラインドインデックスは、モデルの保存と同じ処理(Crypt::encryptString と BlindIndex)を使う。
 * 試験の環境でしか動かない(AC-008-03、allowed())。
 */
final class SeedLoadTest
{
    /** 試験のデータを作ってよい環境 */
    public const array ALLOWED_ENVIRONMENTS = ['local', 'testing', 'loadtest'];

    public const string DOMAIN = 'loadtest.invalid';

    /** 一度に作るチームの数(insert の行数と、メモリの使い方の釣り合い) */
    private const int CHUNK = 100;

    public function __construct(private readonly BlindIndex $blindIndex) {}

    /**
     * 試験のデータを作ってよいか(AC-008-03)。次の3つがすべてそろうときだけ。
     * APP_ENV は artisan の --env で上書きできるので、設定(LOADTEST_SEED_ENABLED)も見る。
     * デモの公開環境(DEMO_MODE)では、設定に関係なく作らない。
     */
    public static function allowed(): bool
    {
        return app()->environment(self::ALLOWED_ENVIRONMENTS)
            && config('team_ops.loadtest_seed_enabled') === true
            && config('team_ops.demo_mode') !== true;
    }

    public const string NOT_ALLOWED = 'loadtest:seed runs only when APP_ENV is local, testing or loadtest, LOADTEST_SEED_ENABLED is true and DEMO_MODE is off.';

    /**
     * @param  callable(int): void|null  $progress  作ったチームの数を受け取る
     * @return list<string> 作ったチームのID
     */
    public function __invoke(int $teams, int $members, CarbonInterface $scheduledAt, ?callable $progress = null): array
    {
        if (! self::allowed()) {
            throw new RuntimeException(self::NOT_ALLOWED);
        }
        if ($members < 4) {
            throw new RuntimeException('A team needs at least 4 members (representative, officer, coach and a parent).');
        }
        $run = strtolower(Str::random(6)); // 何度実行してもメールアドレスが重ならないように
        $ids = [];
        for ($start = 0; $start < $teams; $start += self::CHUNK) {
            $count = min(self::CHUNK, $teams - $start);
            $ids = [...$ids, ...DB::transaction(fn (): array => $this->chunk($run, $start, $count, $members, $scheduledAt))];
            if ($progress !== null) {
                $progress(count($ids));
            }
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function chunk(string $run, int $start, int $count, int $members, CarbonInterface $scheduledAt): array
    {
        $now = now();
        $teamIds = [];
        $teamRows = [];
        $userRows = [];
        for ($t = 0; $t < $count; $t++) {
            $no = $start + $t + 1;
            $id = strtolower((string) Str::ulid()); // HasUlids と同じく小文字で保存する
            $teamIds[] = $id;
            $teamRows[] = ['id' => $id, 'name' => "負荷試験チーム {$no}", 'sport' => Sport::Soccer->value, 'created_by' => null, 'created_at' => $now, 'updated_at' => $now];
            for ($m = 1; $m <= $members; $m++) {
                $email = "t{$no}-m{$m}-{$run}@".self::DOMAIN;
                $userRows[] = [
                    'email' => Crypt::encryptString($email),
                    'email_index' => $this->blindIndex->email($email),
                    'display_name' => Crypt::encryptString("試験 {$no}-{$m}"),
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }
        foreach (array_chunk($userRows, 1000) as $rows) {
            DB::table('users')->insert($rows);
        }
        /** @var array<string, int> $userIdByIndex */
        $userIdByIndex = DB::table('users')->whereIn('email_index', array_column($userRows, 'email_index'))->pluck('id', 'email_index')->all();
        // 作成者は各チームの先頭(代表)。チームの行に入れてから insert する(チームごとの UPDATE をしない)
        foreach ($teamRows as $t => $row) {
            $teamRows[$t]['created_by'] = $userIdByIndex[$userRows[$t * $members]['email_index']];
        }
        DB::table('teams')->insert($teamRows);

        $membershipRows = [];
        foreach ($teamIds as $t => $teamId) {
            for ($m = 0; $m < $members; $m++) {
                $membershipRows[] = ['team_id' => $teamId, 'user_id' => $userIdByIndex[$userRows[$t * $members + $m]['email_index']], 'created_at' => $now, 'updated_at' => $now];
            }
        }
        foreach (array_chunk($membershipRows, 1000) as $rows) {
            DB::table('team_memberships')->insert($rows);
        }
        $membershipIds = DB::table('team_memberships')->whereIn('team_id', $teamIds)->get(['id', 'team_id', 'user_id'])
            ->mapWithKeys(fn (object $row): array => [$row->team_id.':'.$row->user_id => (int) $row->id])->all();

        $roleRows = [];
        $noticeRows = [];
        foreach ($teamIds as $t => $teamId) {
            $repId = null;
            for ($m = 0; $m < $members; $m++) {
                $userId = $userIdByIndex[$userRows[$t * $members + $m]['email_index']];
                $role = match ($m) {
                    0 => TeamRole::Representative,
                    1 => TeamRole::Officer,
                    2 => TeamRole::Coach,
                    default => TeamRole::Parent,
                };
                $repId ??= $userId;
                $roleRows[] = [
                    'membership_id' => $membershipIds[$teamId.':'.$userId], 'team_id' => $teamId, 'role' => $role->value,
                    'representative_of' => $role === TeamRole::Representative ? $teamId : null, 'created_at' => $now, 'updated_at' => $now,
                ];
            }
            $noticeRows[] = [
                'team_id' => $teamId, 'author_user_id' => $repId,
                'title' => Crypt::encryptString('負荷試験のお知らせ'), 'body' => Crypt::encryptString("負荷試験のために送るお知らせです。\n実在の人には届きません。"),
                'target_type' => NoticeTarget::All->value, 'target_values' => '[]', 'status' => NoticeStatus::Scheduled->value,
                'scheduled_at' => $scheduledAt, 'recipient_count' => 0, 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        foreach (array_chunk($roleRows, 1000) as $rows) {
            DB::table('team_member_roles')->insert($rows);
        }
        DB::table('notices')->insert($noticeRows);

        return $teamIds;
    }
}
