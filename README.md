# Microservices Symfony : user-service / client-service

Architecture 2 microservices PHP/Symfony, instrumentes OpenTelemetry (traces
vers Tempo), metriques Prometheus, logs vers Loki.

## Architecture

```
client (HTTP) ──▶ client-service ──▶ user-service
                       │                  │
                       └────► PostgreSQL ◀┘ (bases separees : client_db / user_db)

Chaque service :
  - expose /metrics (format Prometheus, scrape par Prometheus)
  - expose /health
  - exporte ses traces en OTLP/HTTP vers otel-collector:4318 → Tempo
  - logge en JSON sur stdout (trace_id/span_id inclus) → Promtail → Loki

Grafana provisionne les 3 datasources (Prometheus, Tempo, Loki) avec
correlation trace_id ↔ logs.
```

## Services

- **user-service** — gere les utilisateurs. API REST :
  - `GET /api/users`
  - `GET /api/users/{id}`
  - `POST /api/users` `{ "email", "firstName", "lastName" }`

- **client-service** — gere les clients, chaque client reference un
  `userId`. Lors de la lecture d'un client, il appelle user-service pour
  recuperer et injecter les infos de l'utilisateur associe. API REST :
  - `GET /api/clients`
  - `GET /api/clients/{id}` → renvoie le client + `user` (recupere via appel a user-service)
  - `POST /api/clients` `{ "companyName", "userId" }` (verifie que l'utilisateur existe)

## Lancer la stack

```bash
docker compose build
docker compose up -d
```

Puis creer le schema en base (pas de migrations dans ce scaffold, juste
`schema:create` pour demarrer rapidement) :

```bash
docker compose exec user-service php bin/console doctrine:schema:create
docker compose exec client-service php bin/console doctrine:schema:create
```

## Donnees de demo (fixtures)

`doctrine/doctrine-fixtures-bundle` + `fakerphp/faker` (dev uniquement).
Les fixtures `client-service` recuperent les utilisateurs reels via l'API
`user-service` (`GET /api/users`) pour rattacher chaque client genere a un
`userId` existant — a charger dans cet ordre :

```bash
docker compose exec user-service php bin/console doctrine:fixtures:load --no-interaction
docker compose exec client-service php bin/console doctrine:fixtures:load --no-interaction
```

Voir [INSTALL.md](INSTALL.md#6-charger-des-données-de-démo-fixtures) pour le detail.

## Tester

```bash
# creer un utilisateur
curl -X POST http://localhost:8081/api/users \
  -H 'Content-Type: application/json' \
  -d '{"email":"a@b.com","firstName":"Alice","lastName":"Martin"}'

# creer un client rattache a cet utilisateur (id=1)
curl -X POST http://localhost:8082/api/clients \
  -H 'Content-Type: application/json' \
  -d '{"companyName":"Acme","userId":1}'

# lire le client -> doit contenir les infos user recuperees via user-service
curl http://localhost:8082/api/clients/1
```

## Observabilite

| Outil | URL | Usage |
|---|---|---|
| Grafana | http://localhost:3000 | dashboards, exploration traces/logs/metrics (auth anonyme activee, a durcir en prod) |
| Prometheus | http://localhost:9090 | metriques brutes |
| Tempo (query API) | http://localhost:3200 | traces brutes |
| Loki | http://localhost:3100 | logs bruts |
| /metrics de chaque service | http://localhost:8081/metrics, http://localhost:8082/metrics | format Prometheus |

Dans Grafana, l'exploration Tempo permet de sauter vers les logs Loki
correspondants (`derivedFields` configure sur `trace_id`), et inversement
depuis un log Loki contenant `trace_id` vers la trace Tempo.

## Choix techniques

- **Base image** : `dunglas/frankenphp` (Symfony officiel), un seul
  conteneur par service (pas de nginx+php-fpm separes).
- **Traces** : SDK `open-telemetry/sdk` + `open-telemetry/exporter-otlp`,
  bootstrap manuel dans `public/index.php` (`src/Otel/Tracing.php`), span
  SERVER par requete (`TracingSubscriber`), span CLIENT sur l'appel HTTP
  client-service → user-service (`UserServiceClient`) avec propagation
  W3C `traceparent`.
- **Metriques** : `promphp/prometheus_client_php`, stockage APCu (partage
  entre workers PHP d'un meme conteneur), expose sur `/metrics`, scrape
  direct par Prometheus (pas de pipeline OTLP metrics ici, volontairement
  simple).
- **Logs** : Monolog → JSON sur stdout, `trace_id`/`span_id` injectes par
  un processor Monolog. Promtail lit les logs Docker et les pousse vers
  Loki (pas de dependance applicative a Loki).

## A adapter avant prod

- Versions exactes des paquets `open-telemetry/*` a verifier/ajuster
  (`composer update`) : l'API du SDK PHP OTel evolue vite.
- Secrets (`APP_SECRET`, creds Postgres) : a sortir des fichiers `.env`
  committes vers un gestionnaire de secrets.
- Grafana : desactiver l'auth anonyme (`GF_AUTH_ANONYMOUS_ENABLED`).
- Migrations Doctrine (`doctrine/doctrine-migrations-bundle`) plutot que
  `schema:create`.
