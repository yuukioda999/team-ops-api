<?php

namespace App\Console\Commands;

use App\UseCases\Deliveries\ReconcileDeliveries;
use Illuminate\Console\Command;

/**
 * 結果の通知が届いていないお知らせのメールを notify-relay と照合する(AC-006-09)。スケジューラが1時間ごとに実行する。
 */
final class ReconcileDeliveriesCommand extends Command
{
    protected $signature = 'notices:reconcile-deliveries';

    protected $description = '完了の通知が届いていないお知らせのメールを notify-relay と照合する';

    public function handle(ReconcileDeliveries $reconcile): int
    {
        $result = $reconcile();
        if ($result['checked'] > 0) {
            $this->info("Checked {$result['checked']} delivery(ies), {$result['mismatched']} mismatched.");
        }

        return self::SUCCESS;
    }
}
