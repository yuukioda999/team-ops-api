<?php

namespace App\Support\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use OpenTelemetry\API\Trace\Span;

/**
 * ログにトレースIDとスパンIDを入れ、ログからトレースへたどれるようにする(AC-010-13)。
 * スパンがないときは何も足さない。
 */
final class AddTraceContext implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $context = Span::getCurrent()->getContext();
        if (! $context->isValid()) {
            return $record;
        }

        return $record->with(extra: $record->extra + [
            'trace_id' => $context->getTraceId(),
            'span_id' => $context->getSpanId(),
        ]);
    }
}
