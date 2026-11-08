<?php

namespace App\Console\Commands;

use App\UseCases\Demo\PruneDemoTeams;
use Illuminate\Console\Command;

/**
 * 期限を過ぎたデモチームを消す(AC-007-07)。スケジューラが1時間ごとに実行する。
 * --all はすべてのデモチームを消す(make demo-reset、AC-007-19)。
 */
final class PruneDemoTeamsCommand extends Command
{
    protected $signature = 'demo:prune {--all : 期限に関係なく、すべてのデモチームを消す}';

    protected $description = '期限を過ぎたデモチームを、デモユーザーと送ったメールの記録ごと消す';

    public function handle(PruneDemoTeams $prune): int
    {
        $deleted = $prune((bool) $this->option('all'));
        $this->info("Deleted {$deleted} demo team(s).");

        return self::SUCCESS;
    }
}
