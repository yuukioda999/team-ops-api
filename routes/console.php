<?php

use App\Models\LoginToken;
use Illuminate\Support\Facades\Schedule;

// 送信待ちのまま時刻が来たアウトボックスに、送信ジョブを積み直す(ADR-0002)
Schedule::command('outbox:redispatch')->everyMinute()->withoutOverlapping();

// 期限切れから24時間を過ぎたログイン用・メール変更用のトークンを消す(AC-001-18、ADR-0003)
Schedule::command('model:prune', ['--model' => [LoginToken::class]])->daily();
