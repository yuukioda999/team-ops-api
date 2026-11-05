<?php

use Illuminate\Support\Facades\Schedule;

// 送信待ちのまま時刻が来たアウトボックスに、送信ジョブを積み直す(ADR-0002)
Schedule::command('outbox:redispatch')->everyMinute()->withoutOverlapping();
