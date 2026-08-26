<?php

namespace App\Controller;

use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\APC;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class MetricsController
{
    #[Route('/metrics', name: 'metrics', methods: ['GET'])]
    public function __invoke(): Response
    {
        $registry = new CollectorRegistry(new APC());
        $renderer = new RenderTextFormat();
        $body = $renderer->render($registry->getMetricFamilySamples());

        return new Response($body, 200, ['Content-Type' => RenderTextFormat::MIME_TYPE]);
    }
}
