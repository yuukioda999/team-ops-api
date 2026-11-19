<?php

namespace App\Console\Commands;

use App\Enums\OutboxStatus;
use App\Models\OutboxMessage;
use App\Models\Team;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\ObserverInterface;

/**
 * 送っていないアウトボックスの数、いちばん古いものの待ち時間、デモチームの数を、DB で数えて
 * OTLP で送る(AC-010-05、spec 010 の判断9)。scheduler が毎分動かす。
 *
 * PHP はリクエストごとにプロセスの状態が消えるため、アプリの中で数え続けず、毎回 DB を数える。
 * ラベルは付けない(チームや宛先で分けない、AC-010-06)。
 */
#[Signature('telemetry:report-gauges')]
#[Description('アウトボックスとデモチームの件数を、メトリクスとして送る')]
final class ReportGauges extends Command
{
    public function handle(MeterProviderInterface $meters): int
    {
        $meter = $meters->getMeter('team-ops-api');
        $meter->createObservableGauge('team_ops.outbox.pending', '{message}', '送っていないアウトボックスの数',
            [], fn (ObserverInterface $o) => $o->observe(self::pendingOutbox()));
        $meter->createObservableGauge('team_ops.outbox.oldest_pending_age', 's', 'いちばん古い、送っていないアウトボックスの待ち時間',
            [], fn (ObserverInterface $o) => $o->observe(self::oldestPendingAge()));
        $meter->createObservableGauge('team_ops.demo.teams', '{team}', 'デモチームの数(ADR-0021)',
            [], fn (ObserverInterface $o) => $o->observe(self::demoTeams()));

        // 毎分の短いプロセスなので、終わる前に送り切る
        if (method_exists($meters, 'forceFlush')) {
            $meters->forceFlush();
        }

        return self::SUCCESS;
    }

    public static function pendingOutbox(): int
    {
        return OutboxMessage::query()->where('status', OutboxStatus::Pending)->count();
    }

    public static function oldestPendingAge(): int
    {
        $oldest = OutboxMessage::query()->where('status', OutboxStatus::Pending)->min('created_at');
        if ($oldest === null) {
            return 0;
        }

        return max(0, (int) now()->diffInSeconds(Carbon::parse((string) $oldest), true));
    }

    public static function demoTeams(): int
    {
        return Team::query()->whereNotNull('demo_expires_at')->count();
    }
}
