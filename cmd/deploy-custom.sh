#!/usr/bin/env bash
# Build & deploy vlastního MyInvoice image z forku (multicorecz).
# Vše custom (PDF design + případné FE změny) je zapečené v image — žádné bind-mounty.
#
# Workflow při nové upstream verzi:
#   (lokálně)  git fetch upstream && git merge upstream/master && git push origin custom-faktura-design
#   (server)   ssh hostinger 'cmd/deploy-custom.sh'
set -euo pipefail

BRANCH="${BRANCH:-custom-faktura-design}"
FORK="${FORK:-https://github.com/multicorecz/myinvoice.git}"
BUILD="${BUILD:-$HOME/myinvoice-build}"
DEPLOY="${DEPLOY:-/opt/myinvoice}"
IMAGE="${IMAGE:-myinvoice:custom}"
COMPOSE="${COMPOSE:-docker-compose.production.yml}"
CONTAINER="${CONTAINER:-myinvoice-app-1}"

echo "==> 1/4 Sync fork zdroje ($BRANCH)"
if [ -d "$BUILD/.git" ]; then
  git -C "$BUILD" fetch origin
  git -C "$BUILD" reset --hard "origin/$BRANCH"
else
  git clone -b "$BRANCH" "$FORK" "$BUILD"
fi
echo "    commit: $(git -C "$BUILD" rev-parse --short HEAD)"

echo "==> 2/4 Build image $IMAGE (~5-10 min)"
docker build -t "$IMAGE" "$BUILD"

echo "==> 3/4 Deploy (recreate + migrace)"
cd "$DEPLOY"
docker compose -f "$COMPOSE" up -d
sleep 4
docker exec "$CONTAINER" php api/bin/migrate.php

# CUSTOM(fork): zapiš „upgrade result" pro stránku Aktualizace. Náš deploy = vlastní build
# forku (ne appí self-upgrade), takže VersionService by tam jinak ukazoval starý výsledek.
# Píše {DATA_DIR}/storage/upgrade-result.json (VersionService::upgradeResultPath) + maže
# případný zaseknutý „upgrade probíhá" flag.
docker exec "$CONTAINER" sh -c '
  DIR="${MYINVOICE_DATA_DIR:-/var/www/html}"
  mkdir -p "$DIR/storage"
  VER="$(cat /var/www/html/VERSION 2>/dev/null | tr -d "[:space:]")"
  NOW="$(php -r "echo date(DATE_ATOM);")"
  printf "{\n    \"status\": \"applied\",\n    \"target_version\": \"%s\",\n    \"applied_at\": \"%s\",\n    \"message\": \"Nasazeno vlastním buildem forku (cmd/deploy-custom.sh).\"\n}\n" "$VER" "$NOW" > "$DIR/storage/upgrade-result.json"
  rm -f "$DIR/storage/upgrade-requested.json"
'

echo "==> 4/4 Hotovo. Verze: $(docker exec "$CONTAINER" cat /var/www/html/VERSION 2>/dev/null)"
docker ps --filter "name=$CONTAINER" --format "    {{.Names}}  {{.Status}}  ({{.Image}})"
