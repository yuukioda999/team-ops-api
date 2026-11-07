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

    protected $description = '予約日時を過ぎたお知らせを公開し、メールを送り始める';

    public function handle(PublishDueNotices $publish): int
    {
        $count = $publish();
        if ($count > 0) {
            $this->info("Published {$count} scheduled notice(s).");
        }

        return self::SUCCESS;
    }
}
