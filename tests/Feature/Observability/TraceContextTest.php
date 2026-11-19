<?php

namespace Tests\Feature\Observability;

use App\Jobs\DeliverOutboxMessage;
use App\Models\OutboxMessage;
use App\Support\Logging\AddTraceContext;
use App\Support\NotifyRelay\EmailOutbox;
use App\Support\NotifyRelay\RelayClient;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Monolog\Level;
use Monolog\LogRecord;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Tests\TestCase;

/**
 * AC-010-02・03・13:トレースの受け渡し(ADR-0026)。
 */
class TraceContextTest extends TestCase
{
    use RefreshDatabase;

    private const string RELAY_URL = 'http://notify-relay.test/v1/deliveries';

    private function startSpan(): SpanInterface
    {
        $provider = new TracerProvider(new SimpleSpanProcessor(new InMemoryExporter));

        return $provider->getTracer('test')->spanBuilder('POST /api/teams/{team}/notices/{notice}/publish')->startSpan();
    }

    /**
     * traceparent の末尾(フラグ)を除いた部分。フラグは SDK の版で変わりうる
     *
     * @return non-empty-string
     */
    private function traceparentPrefixOf(SpanInterface $span): string
    {
        $c = $span->getContext();

        return '00-'.$c->getTraceId().'-'.$c->getSpanId().'-';
    }

    private function queueMessage(): OutboxMessage
    {
        return (new EmailOutbox(true))->queue('notice', 'team:1:notice:1:1', '件名', '本文', ['user_1' => 'coach@example.invalid']);
    }

    public function test_AC_010_03_the_outbox_keeps_the_trace_it_was_made_in(): void
    {
        Queue::fake();
        $span = $this->startSpan();
        $scope = $span->activate();
        try {
            $message = $this->queueMessage();
        } finally {
            $scope->detach();
            $span->end();
        }

        $this->assertStringStartsWith($this->traceparentPrefixOf($span), (string) $message->refresh()->traceparent);
    }

    public function test_AC_010_03_every_attempt_including_a_resend_continues_the_same_trace(): void
    {
        Queue::fake();
        $stored = '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01';
        $message = $this->queueMessage();
        $message->forceFill(['traceparent' => $stored])->save();
        Http::fake([self::RELAY_URL => Http::sequence()->push(null, 503)->push(['delivery_id' => 'dlv_X'], 202)]);

        // 1回目は受け付けられず、outbox:redispatch で送り直す
        (new DeliverOutboxMessage($message->id))->handle(app(RelayClient::class));
        $message->refresh()->forceFill(['next_attempt_at' => now()->subSecond()])->save();
        (new DeliverOutboxMessage($message->id))->handle(app(RelayClient::class));

        $sent = Http::recorded();
        $this->assertCount(2, $sent);
        foreach ($sent as [$request]) {
            /** @var Request $request */
            $this->assertStringContainsString('0af7651916cd43dd8448eb211c80319c', $request->header('traceparent')[0] ?? '', 'AC-010-02 the request to notify-relay carries the trace');
        }
    }

    public function test_without_a_trace_no_traceparent_is_stored_or_sent(): void
    {
        Queue::fake();
        $message = $this->queueMessage();
        Http::fake([self::RELAY_URL => Http::response(['delivery_id' => 'dlv_X'], 202)]);

        (new DeliverOutboxMessage($message->id))->handle(app(RelayClient::class));

        $this->assertNull($message->refresh()->traceparent);
        Http::assertSent(fn (Request $request) => ! $request->hasHeader('traceparent'));
    }

    public function test_AC_010_13_a_log_written_inside_a_span_carries_its_trace_id(): void
    {
        $record = new LogRecord(new DateTimeImmutable, 'test', Level::Info, 'Outbox message handed to notify-relay');
        $span = $this->startSpan();
        $scope = $span->activate();
        try {
            $withTrace = (new AddTraceContext)($record);
        } finally {
            $scope->detach();
            $span->end();
        }

        $this->assertSame($span->getContext()->getTraceId(), $withTrace->extra['trace_id']);
        $this->assertSame($span->getContext()->getSpanId(), $withTrace->extra['span_id']);
        $this->assertArrayNotHasKey('trace_id', (new AddTraceContext)($record)->extra, 'outside a span nothing is added');
    }
}
