<?php

namespace App\Support\Telemetry;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\ArrayAccessGetterSetter;
use Throwable;

/**
 * W3C traceparent の受け渡しと、手で作るスパン(ADR-0026)。
 * 送る先が設定されていないときも、受け取った traceparent はそのまま引き継ぐ。
 */
final class Tracing
{
    /**
     * いまのスパンの traceparent。スパンがなければ null。
     */
    public static function currentTraceparent(): ?string
    {
        $carrier = [];
        TraceContextPropagator::getInstance()->inject($carrier, ArrayAccessGetterSetter::getInstance(), Context::getCurrent());

        return isset($carrier['traceparent']) && is_string($carrier['traceparent']) ? $carrier['traceparent'] : null;
    }

    /**
     * 保存しておいた traceparent の下でスパンを作り、$work を動かす。traceparent がなければいまの文脈の下に作る。
     * スパンに入れる属性は、ID・件数・決まったコードだけにする(個人情報を入れない、AC-010-01)。
     *
     * @template T
     *
     * @param  non-empty-string  $name
     * @param  SpanKind::KIND_*  $kind
     * @param  array<string, string|int|bool|null>  $attributes
     * @param  callable(SpanInterface): T  $work
     * @return T
     */
    public static function inSpan(string $name, ?string $traceparent, array $attributes, callable $work, int $kind = SpanKind::KIND_INTERNAL): mixed
    {
        $parent = Context::getCurrent();
        if ($traceparent !== null && $traceparent !== '') {
            $parent = TraceContextPropagator::getInstance()->extract(['traceparent' => $traceparent], ArrayAccessGetterSetter::getInstance(), $parent);
        }
        $span = Globals::tracerProvider()->getTracer('team-ops-api')
            ->spanBuilder($name)
            ->setSpanKind($kind)
            ->setParent($parent)
            ->setAttributes(array_filter($attributes, fn ($v) => $v !== null))
            ->startSpan();
        $scope = $span->storeInContext($parent)->activate();
        try {
            return $work($span);
        } catch (Throwable $e) {
            // 例外のメッセージは送らない(個人情報を含みうる)。種類だけを残す
            $span->setStatus(StatusCode::STATUS_ERROR, $e::class);
            throw $e;
        } finally {
            $scope->detach();
            $span->end();
        }
    }
}
