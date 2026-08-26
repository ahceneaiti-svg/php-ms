<?php

namespace App\Otel;

use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Time\ClockFactory;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\ResourceAttributes;

/**
 * Bootstrap manuel du SDK OpenTelemetry (traces).
 * Exporte en OTLP/HTTP vers l'OpenTelemetry Collector (cf. docker-compose).
 */
final class Tracing
{
    private static ?TracerProvider $tracerProvider = null;

    public static function init(string $serviceName, string $otlpEndpoint): TracerProvider
    {
        if (self::$tracerProvider !== null) {
            return self::$tracerProvider;
        }

        $resource = ResourceInfo::create(Attributes::create([
            ResourceAttributes::SERVICE_NAME => $serviceName,
        ]));

        $transport = (new OtlpHttpTransportFactory())->create(
            rtrim($otlpEndpoint, '/').'/v1/traces',
            'application/x-protobuf'
        );

        $exporter = new SpanExporter($transport);
        $processor = new BatchSpanProcessor($exporter, ClockFactory::getDefault());

        self::$tracerProvider = TracerProvider::builder()
            ->addSpanProcessor($processor)
            ->setResource($resource)
            ->build();

        return self::$tracerProvider;
    }

    public static function tracer(string $name = 'app'): ?TracerInterface
    {
        return self::$tracerProvider?->getTracer($name);
    }

    public static function flush(): void
    {
        self::$tracerProvider?->forceFlush();
    }

    public static function currentTraceId(): ?string
    {
        $span = \OpenTelemetry\API\Trace\Span::getCurrent();
        $context = $span->getContext();

        return $context->isValid() ? $context->getTraceId() : null;
    }

    public static function currentSpanId(): ?string
    {
        $span = \OpenTelemetry\API\Trace\Span::getCurrent();
        $context = $span->getContext();

        return $context->isValid() ? $context->getSpanId() : null;
    }
}
