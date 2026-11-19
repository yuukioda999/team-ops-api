<?php

namespace App\Support\Telemetry;

use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeInterface;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\EventInterface;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\StatusDataInterface;

/**
 * 個人情報を伏せたスパン。属性・イベント・状態の説明だけを差し替え、ほかは元のスパンのまま。
 */
final class RedactedSpanData implements SpanDataInterface
{
    /** @var AttributesInterface<string, mixed> */
    private readonly AttributesInterface $attributes;

    /** @var list<EventInterface> */
    private readonly array $events;

    private readonly StatusDataInterface $status;

    public function __construct(private readonly SpanDataInterface $span)
    {
        $this->attributes = SpanRedactor::attributes($span->getAttributes());
        $this->events = SpanRedactor::events($span->getEvents());
        $this->status = SpanRedactor::status($span->getStatus());
    }

    public function getName(): string
    {
        return $this->span->getName();
    }

    public function getKind(): int
    {
        return $this->span->getKind();
    }

    public function getContext(): SpanContextInterface
    {
        return $this->span->getContext();
    }

    public function getParentContext(): SpanContextInterface
    {
        return $this->span->getParentContext();
    }

    public function getTraceId(): string
    {
        return $this->span->getTraceId();
    }

    public function getSpanId(): string
    {
        return $this->span->getSpanId();
    }

    public function getParentSpanId(): string
    {
        return $this->span->getParentSpanId();
    }

    public function getStatus(): StatusDataInterface
    {
        return $this->status;
    }

    public function getStartEpochNanos(): int
    {
        return $this->span->getStartEpochNanos();
    }

    /**
     * @return AttributesInterface<string, mixed>
     */
    public function getAttributes(): AttributesInterface
    {
        return $this->attributes;
    }

    public function getEvents(): array
    {
        return $this->events;
    }

    public function getLinks(): array
    {
        return $this->span->getLinks();
    }

    public function getEndEpochNanos(): int
    {
        return $this->span->getEndEpochNanos();
    }

    public function hasEnded(): bool
    {
        return $this->span->hasEnded();
    }

    public function getInstrumentationScope(): InstrumentationScopeInterface
    {
        return $this->span->getInstrumentationScope();
    }

    public function getResource(): ResourceInfo
    {
        return $this->span->getResource();
    }

    public function getTotalDroppedEvents(): int
    {
        return $this->span->getTotalDroppedEvents();
    }

    public function getTotalDroppedLinks(): int
    {
        return $this->span->getTotalDroppedLinks();
    }
}
