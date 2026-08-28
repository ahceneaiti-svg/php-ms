# k8s/ — déploiement sur minikube

Manifestes Kubernetes pour la stack complète décrite dans le `docker-compose.yml`
racine : 3 microservices Symfony (`user-service`, `client-service`,
`notification-service`), Postgres, RabbitMQ, Mailpit, et la stack
observabilité (OpenTelemetry Collector, Tempo, Prometheus, Loki, Promtail,
Grafana). Voir le `CLAUDE.md` racine pour le contexte applicatif complet.

Tout est déployé dans le namespace `microservices`.

## Choix retenu : images buildées localement (pas de registry)

Pour un cluster **minikube local**, la solution la plus simple est de builder
les 3 images applicatives directement dans le daemon Docker interne de
minikube (`eval $(minikube docker-env)`), puis de les référencer par leur tag
local avec `imagePullPolicy: IfNotPresent` — c'est ce que font
`user-service.yaml`, `client-service.yaml` et `notification-service.yaml`
(images `user-service:local`, `client-service:local`,
`notification-service:local`).

Alternative pour un cluster distant / CI : pousser les images vers un
registry (Docker Hub, GHCR, ECR…) et remplacer les champs `image:` par
`<registry>/<repo>/<service>:<tag>` (`imagePullPolicy: Always` recommandé si
le tag n'est pas immuable), typiquement via un overlay Kustomize
(`images:` transformer) plutôt qu'en éditant les fichiers de base.

Les images tierces (Postgres, RabbitMQ, Mailpit, otel-collector, Tempo,
Prometheus, Loki, Promtail, Grafana) sont tirées telles quelles depuis
Docker Hub / GHCR, comme en docker-compose.

## Pré-requis

```bash
minikube start --cpus=4 --memory=8192          # ajuster selon la machine
minikube addons enable ingress                  # uniquement si vous utilisez ingress.yaml
```

## 1. Builder les images dans le daemon Docker de minikube

```bash
eval $(minikube docker-env)

docker build -t user-service:local          services/user-service
docker build -t client-service:local        services/client-service
docker build -t notification-service:local  services/notification-service

# revenir au daemon Docker de la machine hôte quand vous avez fini
eval $(minikube docker-env -u)
```

À refaire après chaque changement de code/`composer.json` dans un service
(pas de rebuild automatique côté cluster).

## 2. Déployer la stack

```bash
kubectl apply -k k8s/
kubectl -n microservices get pods -w
```

Ordre de démarrage (dépendances gérées par les probes readiness/liveness,
pas besoin d'appliquer dans un ordre précis — `kubectl apply -k` applique
tout, les Pods qui dépendent d'un autre service redémarrent/retenteront
jusqu'à ce que leurs dépendances soient Ready) :

- `postgres` (StatefulSet + PVC), `rabbitmq` (StatefulSet + PVC), `mailpit`,
  `otel-collector` → infrastructure de base
- `tempo`, `prometheus`, `loki`, `promtail`, `grafana` → observabilité
- `user-service`, `client-service`, `notification-service` → applicatif

`ingress.yaml` est inclus dans la kustomization ; si vous n'avez pas activé
l'addon `ingress` de minikube, `kubectl apply -k k8s/` échouera sur cette
ressource — soit activez l'addon, soit retirez `ingress.yaml` de
`kustomization.yaml` avant d'appliquer.

## 3. Bootstrap base de données et données de démo

Équivalent Kubernetes des commandes manuelles listées dans le `CLAUDE.md`
racine (`doctrine:schema:create`, `doctrine:fixtures:load`), fournies comme
`Job`s dans `jobs-bootstrap.yaml`. **Ce fichier n'est pas dans
`kustomization.yaml`** : ce sont des étapes one-shot à ordonner manuellement,
pas une ressource de la stack "vivante".

L'ordre compte, en particulier `client-service`'s fixtures fait un vrai
`GET /api/users` sur `user-service` (pas d'IDs en dur) — donc `user-service`
doit être `Ready` (Deployment, pas juste son Job schema/fixtures) avant de
lancer les fixtures de `client-service` :

```bash
# 1. schémas (parallélisables)
kubectl apply -f k8s/jobs-bootstrap.yaml -l bootstrap-step=schema
kubectl -n microservices wait --for=condition=complete --timeout=120s \
  job -l bootstrap-step=schema

# 2. fixtures user-service, puis attendre sa complétion
kubectl apply -f k8s/jobs-bootstrap.yaml \
  -l bootstrap-step=fixtures,app.kubernetes.io/name=user-service
kubectl -n microservices wait --for=condition=complete --timeout=120s \
  job/user-service-fixtures

# 3. s'assurer que le Deployment user-service est bien Ready
kubectl -n microservices rollout status deployment/user-service

# 4. fixtures client-service (dépend du user-service Ready ci-dessus)
kubectl apply -f k8s/jobs-bootstrap.yaml \
  -l bootstrap-step=fixtures,app.kubernetes.io/name=client-service
kubectl -n microservices wait --for=condition=complete --timeout=120s \
  job/client-service-fixtures
```

Pour rejouer un Job (ex. après un `docker:schema:update`), le supprimer
d'abord — les Jobs sont immuables une fois créés :

```bash
kubectl -n microservices delete job user-service-fixtures
kubectl apply -f k8s/jobs-bootstrap.yaml \
  -l bootstrap-step=fixtures,app.kubernetes.io/name=user-service
```

Alternative sans Job, en one-off exec directement dans un Pod applicatif
existant (équivalent de `docker compose exec <service> php bin/console ...`) :

```bash
kubectl -n microservices exec deploy/user-service   -- php bin/console doctrine:schema:create
kubectl -n microservices exec deploy/client-service -- php bin/console doctrine:schema:create
kubectl -n microservices exec deploy/user-service   -- php bin/console doctrine:fixtures:load --no-interaction
kubectl -n microservices exec deploy/client-service -- php bin/console doctrine:fixtures:load --no-interaction
```

## Accès aux services et aux UIs

### Option A — `kubectl port-forward` (le plus simple, pas de dépendance à l'addon ingress)

```bash
kubectl -n microservices port-forward svc/user-service         8081:80
kubectl -n microservices port-forward svc/client-service       8082:80
kubectl -n microservices port-forward svc/notification-service 8083:80
kubectl -n microservices port-forward svc/grafana              3000:3000
kubectl -n microservices port-forward svc/prometheus            9090:9090
kubectl -n microservices port-forward svc/rabbitmq-management  15672:15672
kubectl -n microservices port-forward svc/mailpit               8025:8025
kubectl -n microservices port-forward svc/tempo                 3200:3200
kubectl -n microservices port-forward svc/loki                  3100:3100
```

Puis exactement les mêmes URLs/commandes que le smoke test du `CLAUDE.md`
racine (`http://localhost:8081`, `:8082`, `:8025`, etc.).

### Option B — `minikube service`

```bash
minikube service -n microservices user-service
minikube service -n microservices grafana
minikube service -n microservices rabbitmq-management
minikube service -n microservices mailpit
# ... etc, ouvre directement le navigateur / affiche l'URL
```

### Option C — Ingress (si l'addon `ingress` est activé)

```bash
echo "$(minikube ip)  user-service.microservices.local client-service.microservices.local \
  notification-service.microservices.local grafana.microservices.local \
  rabbitmq.microservices.local mailpit.microservices.local \
  prometheus.microservices.local" | sudo tee -a /etc/hosts

curl http://user-service.microservices.local/health
open http://grafana.microservices.local        # ou xdg-open sur Linux
```

## Smoke test end-to-end

Une fois `port-forward` (ou l'Ingress) en place sur `user-service`/`client-service`
et les fixtures/schema chargés, reprendre exactement le smoke test décrit
dans le `CLAUDE.md` racine (`POST /api/users`, `POST /api/clients`,
vérification Mailpit).

## Observabilité

- Grafana (`:3000`, auth anonyme Admin activée comme en dev docker-compose)
  a les 3 datasources pré-provisionnées (Prometheus/Tempo/Loki), avec les
  mêmes liens croisés traces↔logs qu'en local.
- Prometheus scrape directement `/metrics` des 3 Services applicatifs
  (`user-service:80`, `client-service:80`, `notification-service:80`) — pas
  via l'OTel Collector, voir CLAUDE.md § Observability data flow.
- `notification-service` fait tourner 2 process dans le même Pod
  (`supervisord` : serveur HTTP + consumer RabbitMQ). readiness/liveness ne
  portent que sur le process HTTP (`/health`) ; le consumer n'a pas de
  métrique Prometheus exposée (même limitation qu'en docker-compose, voir
  CLAUDE.md) — son activité n'est visible que via les logs (Loki) et les
  traces (Tempo).
- **Promtail est adapté pour Kubernetes** : la config docker-compose utilise
  `docker_sd_configs` contre `/var/run/docker.sock`, qui n'a pas de sens sur
  un cluster. `promtail.yaml` utilise `kubernetes_sd_configs` (découverte des
  Pods du namespace `microservices`) et lit les logs via les symlinks
  standard `/var/log/pods` du node — ça fonctionne quel que soit le runtime
  de conteneurs du node minikube (containerd ou docker), avec le même
  pipeline JSON (`level`/`message`/`trace_id`/`span_id`) et les mêmes
  correlations trace_id↔logs déjà configurées côté Grafana.
- Contrairement au docker-compose (où redémarrer `tempo` seul casse
  `otel-collector` — DNS embarqué de Docker qui garde une IP de conteneur
  périmée, voir CLAUDE.md § gotchas), ce problème **ne se reproduit pas** en
  k8s : `otel-collector` résout `tempo` via le Service ClusterIP, stable
  quel que soit le redémarrage/la ré-IP du Pod `tempo` derrière.

## Secrets

`secrets.yaml` contient des **placeholders** (`CHANGE_ME`) repris des
identifiants de dev du `docker-compose.yml` (`app`/`app`, `guest`/`guest`) —
suffisants pour faire tourner la démo sur un minikube local, mais **à ne
jamais utiliser tels quels au-delà d'un usage local/jetable**. Pour injecter
de vraies valeurs sans les committer :

```bash
kubectl -n microservices create secret generic postgres-credentials \
  --from-literal=POSTGRES_USER=app \
  --from-literal=POSTGRES_PASSWORD='<vraie valeur>' \
  --from-literal=POSTGRES_DB=app \
  --from-literal=POSTGRES_MULTIPLE_DATABASES=user_db,client_db \
  --from-literal=USER_DATABASE_URL='postgresql://app:<vraie valeur>@postgres:5432/user_db?serverVersion=16&charset=utf8' \
  --from-literal=CLIENT_DATABASE_URL='postgresql://app:<vraie valeur>@postgres:5432/client_db?serverVersion=16&charset=utf8' \
  --dry-run=client -o yaml | kubectl apply -f -
```

ou déléguer à un gestionnaire de secrets externe (Sealed Secrets, External
Secrets Operator + Vault/SSM, SOPS) plutôt qu'à `kubectl apply -f
secrets.yaml`.

## Scaling

Un `HorizontalPodAutoscaler` (CPU 75% / mémoire 80%) est fourni pour les 3
services applicatifs :

```bash
kubectl -n microservices get hpa
kubectl -n microservices scale deployment/user-service --replicas=3   # scale manuel
```

Nécessite le `metrics-server` (déjà présent sur minikube via
`minikube addons enable metrics-server` si ce n'est pas déjà actif).

Postgres et RabbitMQ sont des `StatefulSet` à **1 réplique** volontairement
(pas de clustering configuré) — ne pas les scaler sans revoir la
configuration applicative (réplication Postgres, cluster RabbitMQ).

## Logs / debug

```bash
kubectl -n microservices logs -f deploy/user-service
kubectl -n microservices logs -f deploy/notification-service -c notification-service   # web + consumer, 1 seul conteneur (supervisord)
kubectl -n microservices logs -f deploy/otel-collector
kubectl -n microservices get events --sort-by=.lastTimestamp
kubectl -n microservices describe pod <pod>
```

## Nettoyage

```bash
kubectl delete -k k8s/
kubectl -n microservices delete -f k8s/jobs-bootstrap.yaml   # si appliqué
kubectl delete namespace microservices                        # supprime aussi les PVC/PV associés
```
