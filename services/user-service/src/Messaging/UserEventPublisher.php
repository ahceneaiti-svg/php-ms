<?php

namespace App\Messaging;

use App\Otel\Tracing;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPExceptionInterface;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Psr\Log\LoggerInterface;

/**
 * Publie les evenements domaine de user-service sur RabbitMQ, sur
 * l'exchange topic "user_events". Consomme par notification-service pour
 * envoyer l'email de bienvenue (voir INSTALL.md).
 *
 * Une connexion AMQP est ouverte et fermee a chaque publication : simple
 * et sans etat partage entre requetes HTTP, au prix d'un aller-retour TCP
 * supplementaire par evenement. Acceptable pour ce volume ; a revoir
 * (pool de connexions, mode worker) si le trafic devient significatif.
 *
 * La publication est "best effort" : un echec est logue et trace, mais
 * ne fait jamais echouer la creation de l'utilisateur (l'email de
 * bienvenue n'est pas une garantie transactionnelle).
 */
final class UserEventPublisher
{
    private const EXCHANGE = 'user_events';
    private const ROUTING_KEY = 'user.registered';

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $host,
        private readonly int $port,
        private readonly string $user,
        private readonly string $password,
        private readonly string $vhost,
    ) {
    }

    public function publishUserRegistered(int $userId, string $email, string $firstName, string $lastName): void
    {
        $tracer = Tracing::tracer();
        $span = $tracer?->spanBuilder(self::EXCHANGE.' -> '.self::ROUTING_KEY)
            ->setSpanKind(SpanKind::KIND_PRODUCER)
            ->setAttribute('messaging.system', 'rabbitmq')
            ->setAttribute('messaging.destination', self::EXCHANGE)
            ->setAttribute('messaging.destination_kind', 'topic')
            ->setAttribute('messaging.rabbitmq.routing_key', self::ROUTING_KEY)
            ->setAttribute('user.id', $userId)
            ->startSpan();

        $scope = $span?->activate();

        $headers = [];
        if ($span) {
            TraceContextPropagator::getInstance()->inject($headers);
        }

        $connection = null;

        try {
            $connection = new AMQPStreamConnection($this->host, $this->port, $this->user, $this->password, $this->vhost);
            $channel = $connection->channel();

            $channel->exchange_declare(self::EXCHANGE, 'topic', false, true, false);

            $payload = [
                'eventId' => bin2hex(random_bytes(16)),
                'eventType' => self::ROUTING_KEY,
                'occurredAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
                'data' => [
                    'id' => $userId,
                    'email' => $email,
                    'firstName' => $firstName,
                    'lastName' => $lastName,
                ],
            ];

            $message = new AMQPMessage(json_encode($payload, JSON_THROW_ON_ERROR), [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'application_headers' => new AMQPTable($headers),
            ]);

            $channel->basic_publish($message, self::EXCHANGE, self::ROUTING_KEY);

            $channel->close();
            $connection->close();

            $this->logger->info('Evenement user.registered publie', ['user_id' => $userId]);
        } catch (AMQPExceptionInterface|\Throwable $e) {
            $this->logger->error('Publication de user.registered sur RabbitMQ en echec', [
                'user_id' => $userId,
                'exception' => $e->getMessage(),
            ]);
            $span?->recordException($e);
            $span?->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            $connection?->close();
        } finally {
            $scope?->detach();
            $span?->end();
        }
    }
}
