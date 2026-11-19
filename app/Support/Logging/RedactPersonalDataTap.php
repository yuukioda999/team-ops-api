<?php

namespace App\Support\Logging;

use Illuminate\Log\Logger;
use Monolog\Logger as Monolog;

/**
 * config/logging.php の各チャンネルに 'tap' で付け、個人情報を伏せる加工処理を入れる。
 * あわせて、トレースIDを足す処理も入れる(AC-010-13)。
 */
final class RedactPersonalDataTap
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();
        if ($monolog instanceof Monolog) {
            $monolog->pushProcessor(new RedactPersonalData);
            $monolog->pushProcessor(new AddTraceContext);
        }
    }
}
