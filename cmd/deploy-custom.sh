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
BUILD="${BUILD:-/opt/myinvoice-build}"
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

echo "==> 4/4 Hotovo. Verze: $(docker exec "$CONTAINER" cat /var/www/html/VERSION 2>/dev/null)"
docker ps --filter "name=$CONTAINER" --format "    {{.Names}}  {{.Status}}  ({{.Image}})"
