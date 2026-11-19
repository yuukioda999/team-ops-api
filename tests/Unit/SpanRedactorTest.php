<?php

namespace Tests\Unit;

use App\Support\Telemetry\RedactingSpanExporter;
use App\Support\Telemetry\SpanRedactor;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * AC-010-01:送るスパンに個人情報を入れない(ADR-0026)。
 */
class SpanRedactorTest extends TestCase
{
    /**
     * @param  array<string, string|int>  $attributes
     */
    private function exported(array $attributes, ?callable $more = null): SpanDataInterface
    {
        $memory = new InMemoryExporter;
        $provider = new TracerProvider(new SimpleSpanProcessor(new RedactingSpanExporter($memory)));
        $span = $provider->getTracer('test')->spanBuilder('GET /api/teams/{team}')->setAttributes($attributes)->startSpan();
        if ($more !== null) {
            $more($span);
        }
        $span->end();
        $spans = $memory->getSpans();
        $this->assertCount(1, $spans);

        return $spans[0];
    }

    /**
     * @return array<string, array{string, string|int}>
     */
    public static function droppedAttributes(): array
    {
        return [
            'URL with the query' => ['url.full', 'http://localhost:8000/api/x?email=coach%40example.invalid'],
            'query' => ['url.query', 'token=secret-token'],
            'path' => ['url.path', '/api/teams/1'],
            'client IP address' => ['client.address', '192.0.2.10'],
            'peer IP address' => ['network.peer.address', '192.0.2.10'],
            'user agent' => ['user_agent.original', 'Mozilla/5.0'],
            'request header' => ['http.request.header.cookie', 'team_ops_session=abc'],
            'response header' => ['http.response.header.set-cookie', 'team_ops_session=abc'],
            'user id' => ['enduser.id', '42'],
        ];
    }

    #[DataProvider('droppedAttributes')]
    public function test_AC_010_01_personal_attributes_are_not_exported(string $key, string|int $value): void
    {
        $span = $this->exported([$key => $value, 'http.route' => 'api/teams/{team}']);

        $attributes = $span->getAttributes()->toArray();
        $this->assertArrayNotHasKey($key, $attributes);
        $this->assertSame('api/teams/{team}', $attributes['http.route'], 'other attributes are kept');
    }

    public function test_AC_010_01_values_written_into_sql_are_masked(): void
    {
        $span = $this->exported(['db.query.text' => "select * from users where email_index = 'abc' and id = ? and x = 0xDEADBEEF"]);

        $this->assertSame('select * from users where email_index = ? and id = ? and x = ?', $span->getAttributes()->get('db.query.text'));
    }

    public function test_AC_010_01_an_email_address_in_any_attribute_is_masked(): void
    {
        $span = $this->exported(['app.note' => 'sent to coach@example.invalid']);

        $this->assertSame(SpanRedactor::MASK, $span->getAttributes()->get('app.note'));
    }

    public function test_AC_010_01_exception_messages_and_status_descriptions_are_not_exported(): void
    {
        $span = $this->exported([], function ($span): void {
            $span->recordException(new RuntimeException('no user for coach@example.invalid (山田 太郎)'));
            $span->setStatus(StatusCode::STATUS_ERROR, 'no user for coach@example.invalid');
        });

        $events = $span->getEvents();
        $this->assertCount(1, $events);
        $attributes = $events[0]->getAttributes()->toArray();
        $this->assertArrayNotHasKey('exception.message', $attributes);
        $this->assertSame(RuntimeException::class, $attributes['exception.type'], 'the type is kept to find the cause');
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame('', $span->getStatus()->getDescription());
    }

    public function test_the_trace_and_parent_are_kept(): void
    {
        $memory = new InMemoryExporter;
        $provider = new TracerProvider(new SimpleSpanProcessor(new RedactingSpanExporter($memory)));
        $tracer = $provider->getTracer('test');
        $parent = $tracer->spanBuilder('parent')->startSpan();
        $scope = $parent->activate();
        $tracer->spanBuilder('child')->startSpan()->end();
        $scope->detach();
        $parent->end();

        [$child, $exportedParent] = $memory->getSpans();
        $this->assertSame($exportedParent->getTraceId(), $child->getTraceId());
        $this->assertSame($exportedParent->getSpanId(), $child->getParentSpanId());
    }
}
