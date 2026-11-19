<?php

namespace Tests\Feature\Observability;

use App\Models\OutboxMessage;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\Data\Gauge;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use Tests\TestCase;

/**
 * AC-010-05・06:アウトボックスとデモチームの件数(spec 010 の判断9)。
 */
class ReportGaugesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, Metric>
     */
    private function report(): array
    {
        $exporter = new InMemoryExporter;
        $provider = MeterProvider::builder()->addReader(new ExportingReader($exporter))->build();
        $this->app->instance(MeterProviderInterface::class, $provider);

        $this->assertSame(0, Artisan::call('telemetry:report-gauges'));

        $metrics = [];
        foreach ($exporter->collect() as $metric) {
            $metrics[$metric->name] = $metric;
        }

        return $metrics;
    }

    private function valueOf(Metric $metric): int|float
    {
        $this->assertInstanceOf(Gauge::class, $metric->data);
        $points = is_array($metric->data->dataPoints) ? $metric->data->dataPoints : iterator_to_array($metric->data->dataPoints);
        $this->assertCount(1, $points);
        $this->assertSame([], $points[0]->attributes->toArray(), 'AC-010-06 no labels');

        return $points[0]->value;
    }

    public function test_AC_010_05_pending_outbox_its_oldest_age_and_demo_teams_are_reported(): void
    {
        $this->travelTo(now()->startOfMinute());
        foreach ([300, 60] as $i => $ago) {
            $m = OutboxMessage::create(['kind' => 'notice', 'dedupe_key' => "team:1:notice:{$i}:1", 'give_up_at' => now()->addDay(), 'payload' => []]);
            $m->forceFill(['created_at' => now()->subSeconds($ago)])->save();
        }
        OutboxMessage::create(['kind' => 'notice', 'dedupe_key' => 'team:1:notice:9:1', 'status' => 'sent', 'payload' => null])
            ->forceFill(['created_at' => now()->subHour()])->save();
        Team::factory()->count(2)->create(['demo_expires_at' => now()->addDay()]);
        Team::factory()->create();

        $metrics = $this->report();

        $this->assertSame(2, $this->valueOf($metrics['team_ops.outbox.pending']));
        $this->assertSame(300, $this->valueOf($metrics['team_ops.outbox.oldest_pending_age']));
        $this->assertSame(2, $this->valueOf($metrics['team_ops.demo.teams']));
    }

    public function test_AC_010_05_with_nothing_pending_the_age_is_zero(): void
    {
        $metrics = $this->report();

        $this->assertSame(0, $this->valueOf($metrics['team_ops.outbox.pending']));
        $this->assertSame(0, $this->valueOf($metrics['team_ops.outbox.oldest_pending_age']));
    }
}
