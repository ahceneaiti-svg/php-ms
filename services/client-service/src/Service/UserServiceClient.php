<?php

namespace App\Service;

use App\Otel\Tracing;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;

/**
 * Client HTTP vers user-service. Cree un span CLIENT et propage le contexte
 * de trace (header traceparent, format W3C) pour que user-service rattache
 * son span SERVER a la meme trace.
 */
final class UserServiceClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $userServiceBaseUrl,
    ) {
    }

    /**
     * @return array<string, mixed>|null null si l'utilisateur n'existe pas
     */
    public function getUser(int $userId): ?array
    {
        $tracer = Tracing::tracer();
        $span = $tracer?->spanBuilder('GET /api/users/{id}')
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute('http.method', 'GET')
            ->setAttribute('peer.service', 'user-service')
            ->setAttribute('user.id', $userId)
            ->startSpan();

        $scope = $span?->activate();

        $headers = [];
        if ($span) {
            TraceContextPropagator::getInstance()->inject($headers);
        }

        try {
            $response = $this->httpClient->request(
                'GET',
                rtrim($this->userServiceBaseUrl, '/')."/api/users/{$userId}",
                [
                    'headers' => $headers,
                    'timeout' => 5,
                ]
            );

            $status = $response->getStatusCode();
            $span?->setAttribute('http.status_code', $status);

            if ($status === 404) {
                return null;
            }

            return $response->toArray();
        } catch (HttpExceptionInterface $e) {
            $this->logger->error('Appel a user-service en echec', [
                'user_id' => $userId,
                'exception' => $e->getMessage(),
            ]);
            $span?->recordException($e);
            $span?->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());

            throw $e;
        } finally {
            $scope?->detach();
            $span?->end();
        }
    }
}
