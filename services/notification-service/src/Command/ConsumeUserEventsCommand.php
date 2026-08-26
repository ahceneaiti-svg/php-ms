<?php

namespace App\Command;

use App\Mail\WelcomeMailer;
use App\Otel\Tracing;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\Propagation\PropagationGetterInterface;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Consomme l'exchange topic "user_events" (routing key "user.registered",
 * publie par user-service) et envoie l'email de bienvenue correspondant.
 *
 * Contrat du message (JSON) :
 *   { "eventId": "...", "eventType": "user.registered", "occurredAt": "...",
 *     "data": { "id": int, "email": string, "firstName": string, "lastName": string } }
 *
 * Fiabilite : ack manuel apres envoi reussi. En cas d'echec (mailer down,
 * payload invalide...), le message est nack sans requeue et part vers la
 * dead-letter queue "notification_service.user_registered.dead" plutot que
 * de boucler indefiniment. Pas de deduplication : une redelivraison AMQP
 * (crash du consumer avant l'ack) peut renvoyer un email en double — a
 * ajouter (idempotency key + store) si ca devient un probleme reel.
 *
 * Observabilite : ce process tourne separement du serveur HTTP de ce
 * service (voir docker/supervisord.conf) et n'alimente donc PAS le
 * registre Prometheus expose par /metrics (APCu n'est pas partageable
 * entre deux processus PHP distincts). L'activite du consumer se suit via
 * les logs structures (Loki) et les traces (Tempo, span CONSUMER par
 * message, rattache a la trace de la requete HTTP qui a cree l'utilisateur).
 */
#[AsCommand(
    name: 'app:consume-user-events',
    description: "Consomme les evenements user.registered sur RabbitMQ et envoie l'email de bienvenue",
)]
class ConsumeUserEventsCommand extends Command
{
    private const EXCHANGE = 'user_events';
    private const DEAD_LETTER_EXCHANGE = 'user_events.dlx';
    private const QUEUE = 'notification_service.user_registered';
    private const DEAD_LETTER_QUEUE = 'notification_service.user_registered.dead';
    private const ROUTING_KEY = 'user.registered';

    private bool $shouldStop = false;

    public function __construct(
        private readonly WelcomeMailer $welcomeMailer,
        private readonly LoggerInterface $logger,
        private readonly string $host,
        private readonly int $port,
        private readonly string $user,
        private readonly string $password,
        private readonly string $vhost,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (\function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function (): void { $this->shouldStop = true; });
            pcntl_signal(SIGINT, function (): void { $this->shouldStop = true; });
        }

        $connection = new AMQPStreamConnection($this->host, $this->port, $this->user, $this->password, $this->vhost);
        $channel = $connection->channel();

        $channel->exchange_declare(self::EXCHANGE, 'topic', false, true, false);
        $channel->exchange_declare(self::DEAD_LETTER_EXCHANGE, 'fanout', false, true, false);

        $channel->queue_declare(self::DEAD_LETTER_QUEUE, false, true, false, false);
        $channel->queue_bind(self::DEAD_LETTER_QUEUE, self::DEAD_LETTER_EXCHANGE);

        $channel->queue_declare(self::QUEUE, false, true, false, false, false, new AMQPTable([
            'x-dead-letter-exchange' => self::DEAD_LETTER_EXCHANGE,
        ]));
        $channel->queue_bind(self::QUEUE, self::EXCHANGE, self::ROUTING_KEY);

        // Un message a la fois : evite qu'un consumer lent monopolise toute la queue.
        $channel->basic_qos(0, 1, false);

        $channel->basic_consume(self::QUEUE, '', false, false, false, false, function (AMQPMessage $message) use ($channel): void {
            $this->handleMessage($channel, $message);
        });

        $output->writeln(sprintf('En attente des messages sur "%s" (Ctrl+C pour arreter)', self::QUEUE));
        $this->logger->info('Consumer demarre', ['queue' => self::QUEUE]);

        while ($channel->is_consuming() && !$this->shouldStop) {
            try {
                $channel->wait(null, false, 5);
            } catch (AMQPTimeoutException) {
                // Pas de message recu dans le delai imparti : on reboucle,
                // ce qui laisse la main au signal handler entre deux attentes.
            }
        }

        $channel->close();
        $connection->close();

        $this->logger->info('Consumer arrete proprement');

        return Command::SUCCESS;
    }

    private function handleMessage(AMQPChannel $channel, AMQPMessage $message): void
    {
        $parentContext = $this->extractTraceContext($message);
        $tracer = Tracing::tracer();
        $span = $tracer?->spanBuilder(self::EXCHANGE.' '.self::ROUTING_KEY.' process')
            ->setParent($parentContext)
            ->setSpanKind(SpanKind::KIND_CONSUMER)
            ->setAttribute('messaging.system', 'rabbitmq')
            ->setAttribute('messaging.destination', self::EXCHANGE)
            ->setAttribute('messaging.rabbitmq.routing_key', self::ROUTING_KEY)
            ->startSpan();
        $scope = $span?->activate();

        try {
            $payload = json_decode($message->getBody(), true, flags: JSON_THROW_ON_ERROR);
            $data = $payload['data'] ?? null;

            if (!\is_array($data) || empty($data['email']) || empty($data['firstName'])) {
                throw new \RuntimeException('Message user.registered invalide (champ "data" manquant ou incomplet)');
            }

            $span?->setAttribute('user.id', $data['id'] ?? null);

            $this->welcomeMailer->sendWelcomeEmail($data['email'], $data['firstName']);

            $this->logger->info('Email de bienvenue envoye', [
                'user_id' => $data['id'] ?? null,
                'email' => $data['email'],
            ]);

            $channel->basic_ack($message->getDeliveryTag());
        } catch (\Throwable $e) {
            $this->logger->error('Echec de traitement du message user.registered', [
                'exception' => $e->getMessage(),
            ]);
            $span?->recordException($e);
            $span?->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());

            // requeue=false : part vers la dead-letter queue plutot que de boucler indefiniment.
            $channel->basic_nack($message->getDeliveryTag(), false, false);
        } finally {
            $scope?->detach();
            $span?->end();
            Tracing::flush();
        }
    }

    private function extractTraceContext(AMQPMessage $message): ContextInterface
    {
        $headers = [];

        if ($message->has('application_headers')) {
            /** @var AMQPTable $table */
            $table = $message->get('application_headers');
            foreach ($table->getNativeData() as $key => $value) {
                $headers[strtolower($key)] = [$value];
            }
        }

        return TraceContextPropagator::getInstance()->extract($headers, new class implements PropagationGetterInterface {
            public function keys($carrier): array
            {
                return array_keys($carrier);
            }

            public function get($carrier, string $key): ?string
            {
                return $carrier[strtolower($key)][0] ?? null;
            }
        });
    }
}
