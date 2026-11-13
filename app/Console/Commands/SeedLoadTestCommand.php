<?php

namespace App\Console\Commands;

use App\UseCases\LoadTest\CreateLoadTestSessions;
use App\UseCases\LoadTest\SeedLoadTest;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * 負荷試験のデータを作る(AC-008-02・03、ADR-0023)。本番の環境では何も作らずに止まる。
 *
 *   php artisan loadtest:seed --teams=10000 --at=2026-11-13T06:00:00Z --sessions=50 --sessions-out=/tmp/sessions.json
 */
final class SeedLoadTestCommand extends Command
{
    protected $signature = 'loadtest:seed
        {--teams=1000 : 作るチームの数}
        {--members=30 : 1チームの人数(代表・役員・コーチ・保護者)}
        {--at= : 予約のお知らせを公開する日時(ISO 8601)。省略すると5分後}
        {--sessions=0 : k6 が使うログイン済みのセッションの数}
        {--sessions-out= : セッションの Cookie を書き出す JSON のパス}';

    protected $description = '負荷試験のチーム・利用者・予約のお知らせを作る(試験の環境だけ。LOADTEST_SEED_ENABLED=true が要る)';

    public function handle(SeedLoadTest $seed, CreateLoadTestSessions $createSessions): int
    {
        if (! SeedLoadTest::allowed()) {
            $this->error(SeedLoadTest::NOT_ALLOWED.' Nothing was created.');

            return self::FAILURE;
        }
        $teams = (int) $this->option('teams');
        $members = (int) $this->option('members');
        $at = is_string($this->option('at')) ? Carbon::parse($this->option('at')) : now()->addMinutes(5);
        $sessions = (int) $this->option('sessions');
        $out = $this->option('sessions-out');
        if ($sessions > 0 && ! is_string($out)) {
            $this->error('--sessions-out is required with --sessions.');

            return self::FAILURE;
        }

        $started = microtime(true);
        try {
            $ids = $seed($teams, $members, $at, function (int $done) use ($teams): void {
                if ($done % 1000 === 0 || $done === $teams) {
                    $this->line("teams: {$done}/{$teams}");
                }
            });
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $seconds = microtime(true) - $started;
        $this->info(sprintf('Created %d teams x %d members and their notices scheduled at %s in %.1f s.', count($ids), $members, $at->toIso8601String(), $seconds));
        // collect.py が結果に入れる(AC-008-02 の作成時間)
        $this->line(sprintf('seed_seconds=%.1f', $seconds));

        if ($sessions > 0) {
            $cookies = $createSessions($ids, $sessions);
            file_put_contents($out, json_encode($cookies, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $this->info('Wrote '.count($cookies)." sessions to {$out}.");
        }

        return self::SUCCESS;
    }
}
