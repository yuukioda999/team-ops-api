<?php

namespace App\Console\Commands;

use App\UseCases\Notices\PublishDueNotices;
use Illuminate\Console\Command;

/**
 * 予約日時を過ぎたお知らせを公開する(AC-005-11)。スケジューラが毎分実行する。
 */
final class PublishDueNoticesCommand extends Command
{
    protected $signature = 'notices:publish-due';

    protected $description = '予約日時を過ぎたお知らせを、公開のジョブにしてキューに積む(公開は worker が行う、ADR-0024)';

    public function handle(PublishDueNotices $publish): int
    {
        $count = $publish();
        if ($count > 0) {
            $this->info("Queued {$count} scheduled notice(s) for publishing.");
        }

        return self::SUCCESS;
    }
}
