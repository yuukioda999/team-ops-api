<?php

use App\Models\LoginToken;
use App\Models\RelayEvent;
use App\Models\TeamJoinRequest;
use Illuminate\Support\Facades\Schedule;

// 送信待ちのまま時刻が来たアウトボックスに、送信ジョブを積み直す(ADR-0002)
// 重複起動の防止のロックは5分で切れる(プロセスが落ちてロックが残っても、長く止まらない。ADR-0018)
Schedule::command('outbox:redispatch')->everyMinute()->withoutOverlapping(5);

// 予約日時を過ぎたお知らせを公開する(AC-005-11、ADR-0018)
Schedule::command('notices:publish-due')->everyMinute()->withoutOverlapping(5);

// 期限切れから24時間を過ぎたログイン用・メール変更用のトークンを消す(AC-001-18、ADR-0003)
Schedule::command('model:prune', ['--model' => [LoginToken::class]])->daily();

// 14日間そのままの参加申請を取り消し(AC-002-15)、最終状態から30日を過ぎた申請の記録を消す(AC-002-17)
Schedule::command('join-requests:expire')->daily();
Schedule::command('model:prune', ['--model' => [TeamJoinRequest::class]])->daily();

// 完了の通知が届いていないお知らせのメールを notify-relay と照合する(AC-006-09、ADR-0019)
Schedule::command('notices:reconcile-deliveries')->hourly()->withoutOverlapping(30);

// 受け取った webhook の event_id は8日で消す(ADR-0019)
Schedule::command('model:prune', ['--model' => [RelayEvent::class]])->daily();
