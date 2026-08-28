# Microservices Symfony : user-service / client-service / notification-service

Architecture 3 microservices PHP/Symfony, instrumentes OpenTelemetry (traces
vers Tempo), metriques Prometheus, logs vers Loki. user-service et
client-service communiquent en synchrone (HTTP) ; user-service et
notification-service communiquent en asynchrone (RabbitMQ).

## Architecture

```
client (HTTP) ──▶ client-service ──▶ user-service ──▶ RabbitMQ ──▶ notification-service ──▶ Mailpit (SMTP)
                       │                  │           (exchange       │
                       └────► PostgreSQL ◀┘             topic         └─ /health, /metrics
                              (bases separees :          "user_events")
                               client_db / user_db)

Chaque service HTTP (user/client/notification) :
  - expose /metrics (format Prometheus, scrape par Prometheus)
  - expose /health
  - exporte ses traces en OTLP/HTTP vers otel-collector:4318 → Tempo
  - logge en JSON sur stdout (trace_id/span_id inclus) → Promtail → Loki

Une trace unique traverse HTTP (client-service → user-service) et RabbitMQ
(user-service → notification-service) : le contexte W3C traceparent est
propage dans les en-tetes HTTP et dans les en-tetes du message AMQP.

Grafana provisionne les 3 datasources (Prometheus, Tempo, Loki) avec
correlation trace_id ↔ logs.
```

## Services

- **user-service** — gere les utilisateurs. API REST :
  - `GET /api/users`
  - `GET /api/users/{id}`
  - `POST /api/users` `{ "email", "firstName", "lastName" }` — publie un
    evenement `user.registered` sur RabbitMQ apres creation (best effort,
    ne fait jamais echouer la requete)

- **client-service** — gere les clients, chaque client reference un
  `userId`. Lors de la lecture d'un client, il appelle user-service pour
  recuperer et injecter les infos de l'utilisateur associe. API REST :
  - `GET /api/clients`
  - `GET /api/clients/{id}` → renvoie le client + `user` (recupere via appel a user-service)
  - `POST /api/clients` `{ "companyName", "userId" }` (verifie que l'utilisateur existe)

- **notification-service** — consomme l'evenement `user.registered` sur
  RabbitMQ et envoie un email de bienvenue (Symfony Mailer, via Mailpit en
  dev). Pas d'API metier : uniquement `/health` et `/metrics`. Tourne 2
  process dans le meme conteneur (serveur HTTP + consumer RabbitMQ, voir
  [CLAUDE.md](CLAUDE.md) pour le detail).

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

# l'email de bienvenue arrive de facon asynchrone (RabbitMQ) : verifier dans Mailpit
curl http://localhost:8025/api/v1/messages
```

## Observabilite

| Outil | URL | Usage |
|---|---|---|
| Grafana | http://localhost:3000 | dashboards, exploration traces/logs/metrics (auth anonyme activee, a durcir en prod) |
| Prometheus | http://localhost:9090 | metriques brutes |
| Tempo (query API) | http://localhost:3200 | traces brutes |
| Loki | http://localhost:3100 | logs bruts |
| RabbitMQ management | http://localhost:15672 (guest/guest) | queues, exchanges, messages en attente |
| Mailpit | http://localhost:8025 | emails envoyes par notification-service |
| /metrics de chaque service | http://localhost:8081/metrics, http://localhost:8082/metrics, http://localhost:8083/metrics | format Prometheus |

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
- **Messaging asynchrone** : `php-amqplib/php-amqplib` (pas Symfony
  Messenger — evite de coupler user-service et notification-service au
  meme nom de classe PHP pour le message ; le contrat est un schema JSON
  simple, plus adapte a de vrais microservices). Exchange topic durable
  `user_events`, routing key `user.registered`, queue durable
  `notification_service.user_registered` avec dead-letter exchange
  `user_events.dlx` → queue `notification_service.user_registered.dead`
  en cas d'echec de traitement (email invalide, mailer down...).

## Déploiement Kubernetes (minikube)

Un dossier [`k8s/`](k8s/README.md) fournit les manifestes pour déployer la
même stack sur un cluster minikube (namespace dédié, Deployments/StatefulSets,
ConfigMap/Secret, HPA, Ingress optionnel, Jobs de bootstrap schema/fixtures).
C'est une cible de déploiement complémentaire à `docker-compose.yml`, pas un
remplacement — voir [k8s/README.md](k8s/README.md) pour le détail (build des
images dans le daemon Docker de minikube, ordre d'application, accès aux UIs).

## A adapter avant prod

- Versions exactes des paquets `open-telemetry/*` a verifier/ajuster
  (`composer update`) : l'API du SDK PHP OTel evolue vite.
- Secrets (`APP_SECRET`, creds Postgres, creds RabbitMQ) : a sortir des
  fichiers `.env` committes vers un gestionnaire de secrets.
- Grafana : desactiver l'auth anonyme (`GF_AUTH_ANONYMOUS_ENABLED`).
- Migrations Doctrine (`doctrine/doctrine-migrations-bundle`) plutot que
  `schema:create`.
- notification-service n'a pas de deduplication : une redelivraison AMQP
  (crash du consumer avant l'ack) peut renvoyer un email en double —
  ajouter une idempotency key si ca devient un probleme reel.
- Mailpit est un outil de dev (capture les emails sans les envoyer
  reellement) : a remplacer par un vrai transport SMTP/API en prod
  (`MAILER_DSN`).
