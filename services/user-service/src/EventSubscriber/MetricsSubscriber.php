<?php

namespace App\EventSubscriber;

use Prometheus\CollectorRegistry;
use Prometheus\Storage\APC;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Alimente un registre Prometheus (stockage APCu, partage entre workers PHP)
 * avec un compteur de requetes et un histogramme de latence.
 * Le registre est expose via GET /metrics (cf. MetricsController) pour scrape Prometheus.
 */
final class MetricsSubscriber implements EventSubscriberInterface
{
    /** @var array<int, float> */
    private array $startTimes = [];

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onRequest',
            KernelEvents::TERMINATE => 'onTerminate',
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->startTimes[spl_object_id($event->getRequest())] = microtime(true);
    }

    public function onTerminate(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        $id = spl_object_id($request);

        if (!isset($this->startTimes[$id])) {
            return;
        }

        $duration = microtime(true) - $this->startTimes[$id];
        unset($this->startTimes[$id]);

        $route = $request->attributes->get('_route') ?? 'unknown';
        if ($route === 'metrics') {
            return;
        }

        $registry = new CollectorRegistry(new APC());

        $registry->getOrRegisterCounter(
            'app',
            'http_requests_total',
            'Nombre total de requetes HTTP',
            ['method', 'route', 'status']
        )->inc([
            $request->getMethod(),
            $route,
            (string) $event->getResponse()->getStatusCode(),
        ]);

        $registry->getOrRegisterHistogram(
            'app',
            'http_request_duration_seconds',
            'Duree des requetes HTTP',
            ['method', 'route'],
            [0.01, 0.05, 0.1, 0.3, 0.5, 1, 3, 5]
        )->observe($duration, [
            $request->getMethod(),
            $route,
        ]);
    }
}
