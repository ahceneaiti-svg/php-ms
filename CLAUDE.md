# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Three PHP/Symfony microservices (`user-service`, `client-service`,
`notification-service`) plus a full observability stack (OpenTelemetry
Collector, Tempo, Prometheus, Loki, Promtail, Grafana) and messaging
(RabbitMQ, Mailpit), orchestrated by a single root `docker-compose.yml`.

- `user-service` owns users; on `POST /api/users` it also publishes a
  `user.registered` event to RabbitMQ (best effort — never fails the request).
- `client-service` owns clients and calls `user-service` over HTTP
  (synchronous) to enrich a client with its associated user's data.
- `notification-service` consumes `user.registered` from RabbitMQ
  (asynchronous) and sends a welcome email (Symfony Mailer → Mailpit in dev).
  It has no business HTTP API, only `/health` and `/metrics`.

There is no code shared between the services — each is a fully independent
Symfony app under `services/<name>/`, deliberately duplicated (Kernel,
Tracing bootstrap, subscribers) rather than factored into a shared package.
The messaging contract between user-service and notification-service is a
plain JSON schema on the wire (not a shared PHP class), specifically to
avoid coupling two independently-deployable services to the same class —
see "Messaging contract" below.

## Commands

All commands run from the repository root unless noted.

```bash
# build / start everything
docker compose build
docker compose up -d
docker compose ps

# one-time DB schema setup (no migrations bundle in this scaffold — plain schema:create)
docker compose exec user-service php bin/console doctrine:schema:create
docker compose exec client-service php bin/console doctrine:schema:create

# after changing an entity
docker compose exec <service> php bin/console doctrine:schema:update --force   # dev only

# demo data (fixtures bundle is dev/test-only, see bundles.php) — ORDER MATTERS:
# client-service's fixtures call GET /api/users on user-service to pick real userIds
docker compose exec user-service php bin/console doctrine:fixtures:load --no-interaction
docker compose exec client-service php bin/console doctrine:fixtures:load --no-interaction

# inspect RabbitMQ / consumed messages / dead-letters
docker compose exec rabbitmq rabbitmqctl list_queues name messages consumers
curl -s http://localhost:8025/api/v1/messages | python3 -m json.tool   # Mailpit inbox

# logs
docker compose logs -f user-service client-service notification-service
docker compose logs -f otel-collector

# stop (keep volumes) / stop + wipe volumes (Postgres/Grafana/Prometheus/RabbitMQ data)
docker compose down
docker compose down -v

# rebuild + recreate a single service after code/composer.json changes
docker compose build user-service client-service notification-service
docker compose up -d user-service client-service notification-service

# resolve/update PHP deps for one service (run inside a throwaway container)
docker compose run --rm user-service composer update --with-all-dependencies
# then hand-copy the resolved versions back into services/<name>/composer.json

# inspect the DI container / routes when a controller/service isn't wiring up
docker compose exec user-service php bin/console debug:container --all
docker compose exec user-service php bin/console debug:router
```

There is no test suite, linter, or `composer.json` scripts section in this
repo yet — `php -l` isn't available outside the containers either (no local
PHP). Validate PHP syntax via `docker compose exec <service> php -l <file>`
or by rebuilding.

### End-to-end smoke test

```bash
curl -s -X POST http://localhost:8081/api/users \
  -H 'Content-Type: application/json' \
  -d '{"email":"alice@example.com","firstName":"Alice","lastName":"Martin"}'

curl -s -X POST http://localhost:8082/api/clients \
  -H 'Content-Type: application/json' \
  -d '{"companyName":"Acme","userId":1}'

curl -s http://localhost:8082/api/clients/1   # must include a populated "user" object

# the POST /api/users call above also triggers an async welcome email —
# check it landed in Mailpit within a few seconds:
curl -s http://localhost:8025/api/v1/messages | python3 -m json.tool
```

Health: `GET /health` on 8081/8082/8083. Metrics: `GET /metrics` on
8081/8082/8083 (Prometheus text format — notification-service's only
covers its own HTTP traffic, see "Messaging contract" below for why).
Observability UIs: Grafana `:3000` (anonymous auth enabled in dev),
Prometheus `:9090`, Tempo query API `:3200`, Loki `:3100`, RabbitMQ
management `:15672` (guest/guest), Mailpit `:8025`.

## Architecture

### Per-service layout (identical shape in `user-service` and `client-service`)

```
public/index.php        # boots Dotenv, then Otel\Tracing::init(), then the Kernel — in that order
src/Kernel.php           # classic MicroKernelTrait kernel (no symfony/runtime)
src/Otel/Tracing.php     # manual OpenTelemetry SDK bootstrap (OTLP/HTTP exporter, BatchSpanProcessor)
src/EventSubscriber/
  TracingSubscriber.php  # SERVER span per HTTP request; extracts W3C traceparent; flushes Tracing on kernel.terminate
  MetricsSubscriber.php  # Prometheus counter + histogram per request, stored in APCu
src/Monolog/TraceProcessor.php  # injects trace_id/span_id into every log record
src/Controller/
  HealthController.php  # GET /health
  MetricsController.php # GET /metrics — renders the APCu-backed CollectorRegistry
src/DataFixtures/        # doctrine/doctrine-fixtures-bundle, loaded only in dev/test (see bundles.php)
```

`client-service`'s `ClientFixtures` depends on `UserServiceClient::listUsers()`
(a real HTTP call to `user-service`), not on hardcoded IDs — so it fails
loudly if `user-service` fixtures haven't been loaded first, instead of
silently creating clients pointing at nonexistent users.

**`Kernel::configureContainer` must explicitly `import('../config/services.yaml')`** in
addition to the `config/packages/*.yaml` glob — this is not the stock
Symfony Flex skeleton (built by hand, no `symfony/runtime`), and omitting
that import silently drops every service with constructor arguments from
the container (controllers with no constructor args still resolve, via
Symfony's fallback direct-instantiation path, which is what made an earlier
regression here half-work and pass a naive smoke test). If a controller
error says "has required constructor arguments and does not exist in the
container", check this import first, then `debug:container --all`.

### Cross-service call path (client-service → user-service)

`client-service`'s `src/Service/UserServiceClient.php` is the only place
that talks to `user-service`. It:
1. starts a CLIENT span,
2. injects the current trace context into the outgoing request headers via
   `TraceContextPropagator::inject()` (W3C `traceparent`),
3. calls `{USER_SERVICE_URL}/api/users/{id}` with Symfony HttpClient,
4. returns `null` on 404 (caller treats this as "no such user"), lets other
   HTTP errors bubble up as exceptions (recorded on the span).

`ClientController` uses this both to enrich `GET /api/clients/{id}` and to
validate `userId` exists before `POST /api/clients`. `user-service`'s
`TracingSubscriber` picks up the propagated `traceparent` on the inbound
request, so a single trace spans both services end-to-end in Tempo.

### Messaging contract (user-service → RabbitMQ → notification-service)

Deliberately built on raw `php-amqplib/php-amqplib`, not Symfony Messenger —
Messenger's AMQP transport ties producer and consumer to the *same PHP
class* for (de)serialization, which is fine in a monorepo but wrong for
two independently-deployable services with no shared package. The contract
here is a plain JSON schema instead:

```
exchange:      user_events            (topic, durable)
routing key:   user.registered
queue:         notification_service.user_registered   (durable)
dead-letter:   user_events.dlx (fanout) → notification_service.user_registered.dead
payload:       { eventId, eventType, occurredAt, data: { id, email, firstName, lastName } }
```

- **Publisher**: `user-service`'s `src/Messaging/UserEventPublisher.php`,
  called from `UserController::create()` after the user is persisted.
  Opens a new AMQP connection per publish (no pooling — acceptable at this
  volume, see file comment). Failures are logged/traced but never fail the
  HTTP request — the event is best-effort.
- **Consumer**: `notification-service`'s
  `src/Command/ConsumeUserEventsCommand.php` (`app:consume-user-events`),
  a long-running loop, not a web request. Manually acks on success; on any
  failure it `nack`s without requeue, routing the message to the dead-letter
  queue instead of retrying forever. No deduplication — a redelivery after
  a mid-processing crash can send a duplicate email.
- **Trace propagation over AMQP**: the publisher injects the W3C
  `traceparent` into the message's `application_headers`; the consumer
  extracts it (`ConsumeUserEventsCommand::extractTraceContext`) and starts
  its CONSUMER span as a child of it. The result: a single Tempo trace
  spans `POST /api/users` (user-service) → the AMQP hop → the consumer's
  `user_events user.registered process` span (notification-service) — the
  same pattern as the HTTP propagation above, just over a different
  transport. Log correlation (`trace_id` in Monolog) works the same way
  too, since `Otel\Tracing::init()` also runs from `bin/console` (all
  three services), not just `public/index.php`.

### `notification-service` runs two processes in one container

Its `Dockerfile` installs `supervisor` and runs both under it (config:
`services/notification-service/docker/supervisord.conf`):
1. `frankenphp run ...` — serves `/health` and `/metrics` only.
2. `php bin/console app:consume-user-events` — the RabbitMQ consumer loop.

**These are two separate PHP processes, and APCu is *not* shared between
them.** An earlier attempt to force-share the Prometheus `CollectorRegistry`
(APCu-backed) across both via a fixed `apc.mmap_file_mask` path failed:
APCu's mmap backend calls `mkstemp()`, which requires a unique `XXXXXX`
template and therefore always creates a *new* file/segment per process,
regardless of the configured mask. There is no cheap way to share APCu
across genuinely separate PHP processes here. Consequence: the consumer's
business activity (emails sent/failed) is **not** in `/metrics` — it's
only visible via logs (Loki) and traces (Tempo, per the propagation above).
If you need those as Prometheus metrics, look at a push-based approach
(Pushgateway) or an in-process embedded metrics server in the consumer,
not APCu sharing.

Also note: `apt-get install supervisor` pulls in `tzdata`, which prompts
interactively unless `DEBIAN_FRONTEND=noninteractive` is set before the
`apt-get install` line — omitting it hangs the `docker build` indefinitely
with no error (this bit us once; the env var is in the Dockerfile for
exactly this reason).

### Observability data flow

- **Traces**: app → OTLP/HTTP → `otel-collector:4318` → OTLP/gRPC →
  `tempo:4317`. Config: `observability/otel-collector/config.yaml`,
  `observability/tempo/tempo.yaml`.
- **Metrics**: Prometheus scrapes `user-service:80/metrics`,
  `client-service:80/metrics` and `notification-service:80/metrics`
  directly (`observability/prometheus/prometheus.yml`) — metrics do
  **not** go through the OTel Collector in this setup. See above for why
  `notification-service`'s consumer activity isn't in there.
- **Logs**: Monolog writes JSON to stdout (with `trace_id`/`span_id`) →
  Promtail reads Docker container logs → Loki. No app-side dependency on
  Loki. Config: `observability/promtail/promtail-config.yaml`,
  `observability/loki/loki-config.yaml`.
- **Grafana** (`observability/grafana/provisioning/datasources/`) has all
  three datasources pre-wired, including Loki→Tempo derived-field linking
  on `trace_id` and Tempo→Loki `tracesToLogsV2`.

### Known operational gotchas (see `INSTALL.md` § Dépannage for full detail)

- `grafana/tempo:latest` currently resolves to Tempo v3, whose config
  schema dropped the old top-level `ingester`/`compactor` blocks used in
  v2 — the checked-in `tempo.yaml` is already v3-shaped.
- If `tempo` is restarted in isolation (not the whole stack), `otel-collector`
  keeps a stale gRPC connection to its old IP and every export fails with
  `no children to pick from` until `otel-collector` is restarted too.
- `symfony/*` packages are pinned to `^7.2`, not `7.1.*` — the entire 7.1
  branch was rejected by Composer's built-in security-advisory audit
  (Composer ≥ 2.8) at build time.
- Postgres holds two databases (`user_db`, `client_db`) created once by
  `scripts/postgres/init-multiple-dbs.sh` via `POSTGRES_MULTIPLE_DATABASES`
  — this only runs on first volume creation; changing the script later
  requires `docker compose down -v`.
- `php-amqplib/php-amqplib` needs `ext-sockets`, which isn't installed by
  default on `dunglas/frankenphp` — it's in the `install-php-extensions`
  list on `user-service` and `notification-service` only (`client-service`
  doesn't talk to RabbitMQ, so it doesn't need it).
- If `otel-collector` was restarted right after `rabbitmq`/`tempo` came
  back up, give it a `docker compose restart otel-collector` too — see the
  gRPC stale-DNS gotcha above; it applies to any container it exports to.
