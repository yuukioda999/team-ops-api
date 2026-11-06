<?php

use App\Models\LoginToken;
use App\Models\TeamJoinRequest;
use Illuminate\Support\Facades\Schedule;

// 送信待ちのまま時刻が来たアウトボックスに、送信ジョブを積み直す(ADR-0002)
Schedule::command('outbox:redispatch')->everyMinute()->withoutOverlapping();

// 期限切れから24時間を過ぎたログイン用・メール変更用のトークンを消す(AC-001-18、ADR-0003)
Schedule::command('model:prune', ['--model' => [LoginToken::class]])->daily();

// 14日間そのままの参加申請を取り消し(AC-002-15)、最終状態から30日を過ぎた申請の記録を消す(AC-002-17)
Schedule::command('join-requests:expire')->daily();
Schedule::command('model:prune', ['--model' => [TeamJoinRequest::class]])->daily();
