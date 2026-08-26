# INSTALL — Guide technique de mise en route

Ce document explique comment installer, démarrer, vérifier et déboguer la
stack (`user-service`, `client-service`, `notification-service`, PostgreSQL,
RabbitMQ, Mailpit, OpenTelemetry Collector, Tempo, Prometheus, Loki,
Promtail, Grafana).

Pour la vue d'ensemble architecture / choix techniques, voir [README.md](README.md).

## 1. Prérequis

- Docker Engine ≥ 24 et Docker Compose plugin (`docker compose version`)
- Accès réseau sortant lors du premier build (téléchargement des paquets
  Composer via Packagist et des images Docker)
- Ports libres sur la machine hôte : `8081`, `8082`, `8083`, `3000`, `9090`,
  `3100`, `3200`, `4317`, `4318`, `5672`, `15672`, `1025`, `8025`

Vérifier :

```bash
docker --version
docker compose version
```

## 2. Structure du dépôt

```
microservices/
├── docker-compose.yml
├── scripts/postgres/init-multiple-dbs.sh   # crée user_db + client_db au 1er démarrage de postgres
├── observability/
│   ├── otel-collector/config.yaml          # reçoit OTLP, exporte vers Tempo
│   ├── prometheus/prometheus.yml           # scrape /metrics des 2 services
│   ├── tempo/tempo.yaml
│   ├── loki/loki-config.yaml
│   ├── promtail/promtail-config.yaml       # lit les logs Docker, push vers Loki
│   └── grafana/provisioning/datasources/   # datasources Prometheus/Tempo/Loki préconfigurées
└── services/
    ├── user-service/             # Symfony + FrankenPHP — publie sur RabbitMQ
    ├── client-service/           # Symfony + FrankenPHP
    └── notification-service/     # Symfony + FrankenPHP + supervisord (web + consumer RabbitMQ)
```

## 3. Build des images

Depuis la racine `microservices/` :

```bash
docker compose build
```

Ce que fait le build de chaque service (`services/*/Dockerfile`) :

1. part de l'image `dunglas/frankenphp:php8.3` ;
2. installe les extensions PHP requises : `pdo_pgsql` (user/client),
   `intl`, `zip`, `opcache`, `apcu`, `sockets` (user-service et
   notification-service, requis par `php-amqplib`), `pcntl`
   (notification-service, arrêt propre du consumer) ;
3. installe `curl` (healthchecks), et `supervisor` pour
   notification-service (voir plus bas) ;
4. copie `composer.json`, lance `composer install` (télécharge les
   dépendances : Symfony, Doctrine, Monolog, SDK OpenTelemetry PHP,
   `promphp/prometheus_client_php`, `php-amqplib/php-amqplib`) ;
5. copie le code applicatif, régénère l'autoload optimisé.

`notification-service` fait tourner **2 process dans le même conteneur**,
supervisés par `supervisord` (`services/notification-service/docker/`) :
le serveur HTTP FrankenPHP (`/health`, `/metrics`) et le consumer RabbitMQ
(`php bin/console app:consume-user-events`, boucle infinie). Ce n'est pas
2 conteneurs séparés — un seul déploiement logique "notification-service".

Si le build échoue sur une résolution de version Composer (le SDK
`open-telemetry/*` évolue vite), voir la section [9. Dépannage](#9-dépannage).

## 4. Démarrage

```bash
docker compose up -d
docker compose ps
```

Attendre que `postgres` et `rabbitmq` soient `healthy` (healthchecks
`pg_isready` / `rabbitmq-diagnostics -q ping`) — les services applicatifs
en dépendent au démarrage. RabbitMQ met parfois 15-20s à devenir healthy
au tout premier démarrage.

Au premier démarrage, `scripts/postgres/init-multiple-dbs.sh` s'exécute
automatiquement et crée les bases `user_db` et `client_db` (variable
`POSTGRES_MULTIPLE_DATABASES` dans `docker-compose.yml`). Ce script ne
tourne qu'une fois : si vous le modifiez après coup, il faut recréer le
volume (`docker compose down -v` puis `up -d`).

## 5. Initialiser le schéma de base de données

Ce scaffold n'utilise pas de migrations Doctrine (volontairement simple) :
le schéma est généré directement depuis les entités.

```bash
docker compose exec user-service php bin/console doctrine:schema:create
docker compose exec client-service php bin/console doctrine:schema:create
```

Vérifier que ça a fonctionné :

```bash
docker compose exec postgres psql -U app -d user_db -c '\dt'
docker compose exec postgres psql -U app -d client_db -c '\dt'
```

Si vous modifiez une entité plus tard, `doctrine:schema:update --force`
(dev uniquement — en prod, passer par de vraies migrations).

## 6. Charger des données de démo (fixtures)

`doctrine/doctrine-fixtures-bundle` + `fakerphp/faker` sont installés en
dépendances dev (bundle actif uniquement en `dev`/`test`, jamais en `prod`).
Les fixtures `client-service` appellent l'API `user-service` (via
`UserServiceClient`) pour rattacher chaque client généré à un utilisateur
réellement existant — **l'ordre compte** :

```bash
# 1. d'abord user-service (genere 20 utilisateurs)
docker compose exec user-service php bin/console doctrine:fixtures:load --no-interaction

# 2. puis client-service (genere 30 clients, rattaches a des userId reels
#    recuperes via GET /api/users sur user-service)
docker compose exec client-service php bin/console doctrine:fixtures:load --no-interaction
```

`doctrine:fixtures:load` purge la table avant de recharger (rejouable sans
conflit d'unicité sur `email`). Si vous rechargez `client-service` sans
avoir de `user-service` accessible ou vide, la commande échoue avec un
message explicite plutôt que de créer des `userId` orphelins.

Sans `--no-interaction`, la commande demande confirmation avant la purge —
utile en session interactive, à éviter en script/CI.

## 7. Vérifier que tout tourne

### Healthchecks applicatifs

```bash
curl http://localhost:8081/health   # user-service
curl http://localhost:8082/health   # client-service
curl http://localhost:8083/health   # notification-service
```

### Scénario fonctionnel complet

```bash
# 1. créer un utilisateur dans user-service
curl -s -X POST http://localhost:8081/api/users \
  -H 'Content-Type: application/json' \
  -d '{"email":"alice@example.com","firstName":"Alice","lastName":"Martin"}' | tee /tmp/user.json

# 2. créer un client rattaché à cet utilisateur (userId=1)
curl -s -X POST http://localhost:8082/api/clients \
  -H 'Content-Type: application/json' \
  -d '{"companyName":"Acme","userId":1}'

# 3. lire le client : la réponse doit contenir la clé "user"
#    remplie par l'appel interne client-service -> user-service
curl -s http://localhost:8082/api/clients/1 | python3 -m json.tool
```

Résultat attendu à l'étape 3 : un objet avec `companyName`, `userId`, et un
sous-objet `user` contenant `email`/`firstName`/`lastName` — preuve que
client-service a bien appelé user-service en interne.

### Email de bienvenue asynchrone (RabbitMQ)

La création d'un utilisateur (étape 1 ci-dessus) publie un événement
`user.registered` que `notification-service` consomme pour envoyer l'email :

```bash
# l'email doit apparaitre dans Mailpit en quelques secondes
curl -s http://localhost:8025/api/v1/messages | python3 -m json.tool

# suivre la queue en direct
docker compose exec rabbitmq rabbitmqctl list_queues name messages consumers
```

`notification_service.user_registered` doit afficher `1` sous `consumers`
et retomber à `0` message peu après chaque création d'utilisateur. Si des
messages s'accumulent dans `notification_service.user_registered.dead`,
voir [Dépannage](#9-dépannage).

### Observabilité

| Vérification | Commande / URL |
|---|---|
| Métriques Prometheus exposées par le service | `curl http://localhost:8081/metrics` |
| Prometheus a bien scrapé les 3 services | http://localhost:9090/targets (les trois jobs doivent être `UP`) |
| Traces reçues par le collector | `docker compose logs otel-collector \| grep -i span` (l'exporter `debug` loggue chaque span) |
| Traces visibles dans Tempo | Grafana → Explore → datasource Tempo → "Search" |
| Trace unique HTTP + RabbitMQ | Chercher une trace `POST /api/users` : elle doit contenir les spans de `user-service` **et** de `notification-service` (span `user_events user.registered process`) |
| Logs applicatifs dans Loki | Grafana → Explore → datasource Loki → requête `{service="client-service"}` |
| Corrélation trace → logs | Dans Grafana Explore/Tempo, ouvrir une trace, bouton "Logs for this span" |

Note : `notification-service` fait tourner 2 process (serveur HTTP +
consumer RabbitMQ, voir section 3) dans des processus PHP distincts —
`/metrics` ne reflète donc que le trafic HTTP de ce service (`/health`),
pas l'activité du consumer (emails envoyés). Le consumer reste pleinement
observable via ses logs (Loki) et ses traces (Tempo, span CONSUMER par
message) — voir [CLAUDE.md](CLAUDE.md) pour le detail de ce choix.

Grafana : http://localhost:3000 (auth anonyme activée en dev, voir
[README.md](README.md#a-adapter-avant-prod) pour la prod).

## 8. Logs et arrêt

```bash
docker compose logs -f user-service client-service notification-service
docker compose logs -f otel-collector

docker compose down          # arrête les conteneurs, garde les volumes (données Postgres/Grafana/Prometheus/RabbitMQ)
docker compose down -v       # arrête et supprime aussi les volumes (repart de zéro)
```

## 9. Dépannage

**`composer install` échoue pendant le build (conflit de versions)**
Le SDK PHP `open-telemetry/*` change vite. Entrer dans un conteneur
temporaire pour ajuster les contraintes :

```bash
docker compose run --rm user-service composer update --with-all-dependencies
```

Puis reporter les versions résolues dans `services/user-service/composer.json`
(et pareil côté `client-service`) avant de relancer `docker compose build`.

**`composer install` échoue avec `... affected by security advisories (PKSA-...)`**
Composer ≥ 2.8 bloque par défaut l'installation d'une version connue comme
vulnérable (audit intégré). Ça arrive typiquement quand une contrainte
`7.1.*` figée tombe entièrement dans une plage couverte par une CVE. Deux
solutions :

1. (recommandé) Monter la contrainte du paquet concerné vers une branche
   plus récente non affectée, par exemple `"symfony/yaml": "^7.2"` au lieu
   de `"7.1.*"`, dans le `composer.json` du service concerné, puis
   relancer `docker compose build`.
2. Si aucune version corrigée n'est disponible dans la contrainte voulue,
   ignorer explicitement l'avis (à ne faire qu'en connaissance de cause) :
   ```json
   "config": {
       "audit": { "abandoned": "report" },
       "allow-plugins": { "php-http/discovery": true }
   }
   ```
   ou ajouter l'ID `PKSA-...` à `policy.advisories.ignore-id` dans la
   configuration Composer globale du conteneur de build.

**`client-service` renvoie `"user": null`**
Vérifier que l'utilisateur `userId` existe bien côté user-service
(`curl http://localhost:8081/api/users/{id}`), et que la variable
d'environnement `USER_SERVICE_URL` (dans `docker-compose.yml`) pointe
bien vers `http://user-service` (nom du service Docker, pas `localhost`).

**Pas de traces dans Tempo**
1. Vérifier que le collector reçoit bien des spans :
   `docker compose logs otel-collector | tail -50`
2. Vérifier `OTEL_EXPORTER_OTLP_ENDPOINT=http://otel-collector:4318` dans
   l'environnement du service concerné (`docker compose exec user-service env | grep OTEL`).
3. Vérifier que `otel-collector` exporte bien vers `tempo:4317` dans
   `observability/otel-collector/config.yaml`.

**Pas de logs dans Loki**
Promtail lit `/var/run/docker.sock` et `/var/lib/docker/containers` — sur
certains environnements (Docker Desktop macOS/Windows, rootless Docker),
ces chemins hôtes diffèrent ou ne sont pas montables tels quels. Vérifier :

```bash
docker compose logs promtail
```

**Erreur Postgres "database already exists" ou bases manquantes**
Le script d'init ne tourne qu'à la création du volume. Si les bases sont
absentes après une modification du script :

```bash
docker compose down -v
docker compose up -d postgres
# attendre healthy, puis relancer schema:create (section 5)
```

**Tempo ne démarre pas : `field ingester not found in type app.Config`**
Le schéma de config de Tempo a changé en v3.x (l'image `grafana/tempo:latest`
suit la dernière version). La config fournie dans ce dépôt
(`observability/tempo/tempo.yaml`) est déjà compatible v3 (sections
`ingester`/`compactor`/`metrics_generator` retirées, laissées aux valeurs
par défaut). Si vous épinglez une image Tempo plus ancienne (v2.x), il
faudra réintroduire ces sections.

**otel-collector n'arrive plus à exporter vers Tempo : `no children to pick from`**
Erreur gRPC côté client : le collector a résolu et mis en cache l'adresse
IP de `tempo` au démarrage ; si le conteneur `tempo` est recréé seul
(`docker compose up -d tempo` ou `restart`) sans redémarrer le collector,
son adresse change et la connexion gRPC ne se rétablit pas toute seule.
Fix : `docker compose restart otel-collector` après tout redémarrage de
`tempo` isolé. Un `docker compose up -d` global (qui démarre tout dans
l'ordre) n'est pas concerné.

**`docker compose build notification-service` reste bloqué indéfiniment, 0% CPU**
Classique piège Debian : `apt-get install supervisor` tire `tzdata` en
dépendance, qui essaie d'ouvrir un prompt interactif de sélection de fuseau
horaire — ça bloque indéfiniment dans un `docker build` sans TTY. Le
`Dockerfile` de `notification-service` fixe `ENV DEBIAN_FRONTEND=noninteractive`
juste avant le `apt-get install` pour cette raison précise ; si vous ajoutez
un autre paquet Debian dans un Dockerfile de ce dépôt, gardez cette ligne
avant tout `apt-get install`.

**`notification-service` : le consumer boucle en `FATAL state, too many start retries`**
Vérifier `docker compose logs notification-service`. Si l'erreur est
`APCu is not enabled` ou `apc_mmap: mkstemp on ... failed` : ne pas essayer
de partager un registre `CollectorRegistry` (APCu) entre le process web
(FrankenPHP) et le process consumer (`bin/console`) de ce même conteneur —
ce sont deux processus PHP distincts, et APCu ne peut pas être partagé
entre eux via un chemin de fichier mmap fixe (`mkstemp()` exige un template
unique de type `XXXXXX`, donc chaque processus obtient toujours son propre
segment). C'est pour ça que `ConsumeUserEventsCommand` n'utilise pas le
`CollectorRegistry` Prometheus : ses métriques métier (emails envoyés) ne
sont volontairement pas exposées via `/metrics`, seulement via logs/traces.

**`doctrine:fixtures:load` sur client-service échoue avec "Aucun utilisateur disponible"**
Les fixtures `client-service` interrogent `GET {USER_SERVICE_URL}/api/users`
en direct — chargez d'abord les fixtures `user-service` (voir section 6),
et vérifiez qu'il répond : `curl http://localhost:8081/api/users`.

**Messages accumulés dans `notification_service.user_registered.dead`**
Le consumer a `nack`é sans requeue (voir `ConsumeUserEventsCommand::handleMessage`) —
la cause exacte est dans les logs `notification-service` au moment de
l'échec (`docker compose logs notification-service | grep -i "Echec de traitement"`).
Causes typiques : Mailpit indisponible (`docker compose ps mailpit`), ou
payload JSON malformé. Pas de rejeu automatique de la DLQ dans ce scaffold —
consulter/rejouer manuellement via la console RabbitMQ (http://localhost:15672).

**Prometheus target `DOWN`**
Vérifier que le conteneur écoute bien sur le port 80 en interne (pas de
mapping de port à changer côté `prometheus.yml`, qui utilise le réseau
Docker interne `user-service:80` / `client-service:80`, pas les ports
publiés `8081`/`8082`).

## 10. Aller plus loin

- Ajouter des migrations Doctrine (`doctrine/doctrine-migrations-bundle`)
  plutôt que `schema:create`.
- Ajouter des tests (`symfony/test-pack`) et un pipeline CI qui lance
  `docker compose build` + un smoke test du scénario de la section 7.
- Passer les credentials Postgres et `APP_SECRET` par des secrets Docker
  ou un vault plutôt que les `.env` committés (actuellement acceptable
  uniquement parce que ce sont des valeurs de dev factices).
- Désactiver `GF_AUTH_ANONYMOUS_ENABLED` sur Grafana avant tout usage non
  local.
- Idempotency key sur `notification-service` pour éviter les doublons
  d'email en cas de redélivraison AMQP.
- Remplacer Mailpit par un vrai transport SMTP/API (`MAILER_DSN`) en prod.
- Séparer le process web et le process consumer de `notification-service`
  en 2 déploiements distincts si le trafic justifie un scaling indépendant
  (aujourd'hui volontairement un seul conteneur, voir section 3).
