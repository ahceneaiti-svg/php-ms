#!/usr/bin/env bash
# Lance tous les port-forward vers les services du namespace en parallèle.
# Ctrl-C coupe tout d'un coup (trap sur les PID enfants).
set -euo pipefail

NS="${NS:-microservices}"

# svc:local:remote
FORWARDS=(
  "user-service:8081:80"
  "client-service:8082:80"
  "notification-service:8083:80"
  "grafana:3000:3000"
  "prometheus:9090:9090"
  "rabbitmq-management:15672:15672"
  "mailpit:8025:8025"
  "tempo:3200:3200"
  "loki:3100:3100"
)

PIDS=()

cleanup() {
  echo
  echo "Arrêt des port-forward..."
  for pid in "${PIDS[@]}"; do
    kill "$pid" 2>/dev/null || true
  done
  wait 2>/dev/null || true
  exit 0
}
trap cleanup INT TERM

for f in "${FORWARDS[@]}"; do
  IFS=':' read -r svc local remote <<< "$f"
  kubectl -n "$NS" port-forward "svc/$svc" "$local:$remote" >/dev/null 2>&1 &
  pid=$!
  PIDS+=("$pid")
  printf '  %-22s http://localhost:%s  (pid %s)\n' "$svc" "$local" "$pid"
done

echo
echo "${#PIDS[@]} port-forward actifs sur le namespace '$NS'. Ctrl-C pour tout arrêter."
wait
