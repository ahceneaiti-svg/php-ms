<?php

namespace App\Monolog;

use App\Otel\Tracing;
use Monolog\LogRecord;

/**
 * Injecte trace_id / span_id dans chaque log, pour correlation Loki <-> Tempo
 * (voir derivedFields dans la datasource Grafana Loki).
 */
final class TraceProcessor
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $traceId = Tracing::currentTraceId();
        $spanId = Tracing::currentSpanId();

        if ($traceId) {
            $record->extra['trace_id'] = $traceId;
        }
        if ($spanId) {
            $record->extra['span_id'] = $spanId;
        }

        return $record;
    }
}
