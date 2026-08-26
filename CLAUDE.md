# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Two PHP/Symfony microservices (`user-service`, `client-service`) plus a full
observability stack (OpenTelemetry Collector, Tempo, Prometheus, Loki,
Promtail, Grafana), orchestrated by a single root `docker-compose.yml`.
`user-service` owns users; `client-service` owns clients and calls
`user-service` over HTTP to enrich a client with its associated user's data.
There is no code shared between the two services — each is a fully
independent Symfony app under `services/<name>/`, deliberately duplicated
(Kernel, Tracing bootstrap, subscribers) rather than factored into a shared
package.

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

# logs
docker compose logs -f user-service client-service
docker compose logs -f otel-collector

# stop (keep volumes) / stop + wipe volumes (Postgres/Grafana/Prometheus data)
docker compose down
docker compose down -v

# rebuild + recreate a single service after code/composer.json changes
docker compose build user-service client-service
docker compose up -d user-service client-service

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
```

Health: `GET /health` on 8081/8082. Metrics: `GET /metrics` on 8081/8082
(Prometheus text format). Observability UIs: Grafana `:3000` (anonymous
auth enabled in dev), Prometheus `:9090`, Tempo query API `:3200`, Loki
`:3100`.

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

### Observability data flow

- **Traces**: app → OTLP/HTTP → `otel-collector:4318` → OTLP/gRPC →
  `tempo:4317`. Config: `observability/otel-collector/config.yaml`,
  `observability/tempo/tempo.yaml`.
- **Metrics**: Prometheus scrapes `user-service:80/metrics` and
  `client-service:80/metrics` directly (`observability/prometheus/prometheus.yml`)
  — metrics do **not** go through the OTel Collector in this setup.
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
