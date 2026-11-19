<?php

namespace App\Support\Telemetry;

use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;

/**
 * スパンを送る前に個人情報を伏せてから、本来の送り口(OTLP)に渡す(ADR-0026)。
 */
final class RedactingSpanExporter implements SpanExporterInterface
{
    public function __construct(private readonly SpanExporterInterface $inner) {}

    /**
     * @param  iterable<SpanDataInterface>  $batch
     */
    public function export(iterable $batch, ?CancellationInterface $cancellation = null): FutureInterface
    {
        $redacted = [];
        foreach ($batch as $span) {
            $redacted[] = new RedactedSpanData($span);
        }

        return $this->inner->export($redacted, $cancellation);
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return $this->inner->shutdown($cancellation);
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return $this->inner->forceFlush($cancellation);
    }
}
