<?php

namespace App\EventSubscriber;

use App\Otel\Tracing;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Propagation\PropagationGetterInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Cree un span SERVER pour chaque requete HTTP entrante (ici : /health et
 * /metrics uniquement, ce service n'expose pas d'API metier), et flush le
 * TracerProvider a la fin de la requete.
 */
final class TracingSubscriber implements EventSubscriberInterface
{
    /** @var array<int, array{0: \OpenTelemetry\API\Trace\SpanInterface, 1: \OpenTelemetry\Context\ScopeInterface}> */
    private array $spans = [];

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 10000],
            KernelEvents::EXCEPTION => ['onException', 10000],
            KernelEvents::TERMINATE => ['onTerminate', -10000],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $tracer = Tracing::tracer();
        if (!$tracer) {
            return;
        }

        $request = $event->getRequest();
        $carrier = $request->headers->all();

        $parentContext = TraceContextPropagator::getInstance()->extract($carrier, new class implements PropagationGetterInterface {
            public function keys($carrier): array
            {
                return array_keys($carrier);
            }

            public function get($carrier, string $key): ?string
            {
                return $carrier[strtolower($key)][0] ?? null;
            }
        });

        $span = $tracer->spanBuilder($request->getMethod().' '.$request->getPathInfo())
            ->setParent($parentContext)
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setAttribute('http.method', $request->getMethod())
            ->setAttribute('http.target', $request->getPathInfo())
            ->setAttribute('http.scheme', $request->getScheme())
            ->startSpan();

        $scope = $span->activate();

        $this->spans[spl_object_id($request)] = [$span, $scope];
    }

    public function onException(ExceptionEvent $event): void
    {
        $entry = $this->spans[spl_object_id($event->getRequest())] ?? null;
        if ($entry) {
            [$span] = $entry;
            $span->recordException($event->getThrowable());
            $span->setStatus(StatusCode::STATUS_ERROR, $event->getThrowable()->getMessage());
        }
    }

    public function onTerminate(TerminateEvent $event): void
    {
        $requestId = spl_object_id($event->getRequest());
        $entry = $this->spans[$requestId] ?? null;

        if ($entry) {
            [$span, $scope] = $entry;
            $span->setAttribute('http.status_code', $event->getResponse()->getStatusCode());
            $span->end();
            $scope->detach();
            unset($this->spans[$requestId]);
        }

        Tracing::flush();
    }
}
