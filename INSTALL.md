# INSTALL — Guide technique de mise en route

Ce document explique comment installer, démarrer, vérifier et déboguer la
stack (`user-service`, `client-service`, PostgreSQL, OpenTelemetry Collector,
Tempo, Prometheus, Loki, Promtail, Grafana).

Pour la vue d'ensemble architecture / choix techniques, voir [README.md](README.md).

## 1. Prérequis

- Docker Engine ≥ 24 et Docker Compose plugin (`docker compose version`)
- Accès réseau sortant lors du premier build (téléchargement des paquets
  Composer via Packagist et des images Docker)
- Ports libres sur la machine hôte : `8081`, `8082`, `3000`, `9090`, `3100`,
  `3200`, `4317`, `4318`

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
    ├── user-service/     # Symfony + FrankenPHP
    └── client-service/   # Symfony + FrankenPHP
```

## 3. Build des images

Depuis la racine `microservices/` :

```bash
docker compose build
```

Ce que fait le build de chaque service (`services/*/Dockerfile`) :

1. part de l'image `dunglas/frankenphp:php8.3` ;
2. installe les extensions PHP requises : `pdo_pgsql`, `intl`, `zip`,
   `opcache`, `apcu` ;
3. installe `curl` (healthchecks) ;
4. copie `composer.json`, lance `composer install` (télécharge les
   dépendances : Symfony, Doctrine, Monolog, SDK OpenTelemetry PHP,
   `promphp/prometheus_client_php`) ;
5. copie le code applicatif, régénère l'autoload optimisé.

Si le build échoue sur une résolution de version Composer (le SDK
`open-telemetry/*` évolue vite), voir la section [8. Dépannage](#8-dépannage).

## 4. Démarrage

```bash
docker compose up -d
docker compose ps
```

Attendre que `postgres` soit `healthy` (healthcheck `pg_isready`) — les
services applicatifs en dépendent au démarrage.

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

## 6. Vérifier que tout tourne

### Healthchecks applicatifs

```bash
curl http://localhost:8081/health   # user-service
curl http://localhost:8082/health   # client-service
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

### Observabilité

| Vérification | Commande / URL |
|---|---|
| Métriques Prometheus exposées par le service | `curl http://localhost:8081/metrics` |
| Prometheus a bien scrapé les 2 services | http://localhost:9090/targets (les deux jobs doivent être `UP`) |
| Traces reçues par le collector | `docker compose logs otel-collector \| grep -i span` (l'exporter `debug` loggue chaque span) |
| Traces visibles dans Tempo | Grafana → Explore → datasource Tempo → "Search" |
| Logs applicatifs dans Loki | Grafana → Explore → datasource Loki → requête `{service="client-service"}` |
| Corrélation trace → logs | Dans Grafana Explore/Tempo, ouvrir une trace, bouton "Logs for this span" |

Grafana : http://localhost:3000 (auth anonyme activée en dev, voir
[README.md](README.md#a-adapter-avant-prod) pour la prod).

## 7. Logs et arrêt

```bash
docker compose logs -f user-service client-service
docker compose logs -f otel-collector

docker compose down          # arrête les conteneurs, garde les volumes (données Postgres/Grafana/Prometheus)
docker compose down -v       # arrête et supprime aussi les volumes (repart de zéro)
```

## 8. Dépannage

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

**Prometheus target `DOWN`**
Vérifier que le conteneur écoute bien sur le port 80 en interne (pas de
mapping de port à changer côté `prometheus.yml`, qui utilise le réseau
Docker interne `user-service:80` / `client-service:80`, pas les ports
publiés `8081`/`8082`).

## 9. Aller plus loin

- Ajouter des migrations Doctrine (`doctrine/doctrine-migrations-bundle`)
  plutôt que `schema:create`.
- Ajouter des tests (`symfony/test-pack`) et un pipeline CI qui lance
  `docker compose build` + un smoke test du scénario de la section 6.
- Passer les credentials Postgres et `APP_SECRET` par des secrets Docker
  ou un vault plutôt que les `.env` committés (actuellement acceptable
  uniquement parce que ce sont des valeurs de dev factices).
- Désactiver `GF_AUTH_ANONYMOUS_ENABLED` sur Grafana avant tout usage non
  local.
