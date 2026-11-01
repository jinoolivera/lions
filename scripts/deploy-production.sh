#!/usr/bin/env bash
set -Eeuo pipefail

cd /root/lions
test -f .env.prod || { echo "Missing /root/lions/.env.prod" >&2; exit 1; }

if [ -f .deployment-pending ]; then
    if remote_main=$(git ls-remote origin refs/heads/main); then
        if [ -n "$remote_main" ]; then
            git fetch --prune origin main
            git reset --hard origin/main
            rm .deployment-pending
        else
            echo "GitHub main is empty; using the bootstrapped production checkout."
        fi
    else
        echo "Could not query origin/main; refusing to deploy without a verified remote." >&2
        exit 1
    fi
else
    git fetch --prune origin main
    git reset --hard origin/main
fi

if [ "${LIONS_SKIP_BUILD:-0}" = "1" ]; then
    docker image inspect lions-app:production >/dev/null
else
    docker compose --env-file .env.prod -f docker-compose.prod.yml build app
fi
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d db
docker compose --env-file .env.prod -f docker-compose.prod.yml run --rm --no-deps public-sync
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d --remove-orphans
docker compose --env-file .env.prod -f docker-compose.prod.yml exec -T app php artisan migrate --force
docker compose --env-file .env.prod -f docker-compose.prod.yml exec -T app php artisan optimize
docker compose --env-file .env.prod -f docker-compose.prod.yml restart app queue nginx

for attempt in {1..30}; do
    if curl --connect-timeout 5 --max-time 10 --fail --silent --show-error https://lions.devicesperu.com/up >/dev/null; then
        echo "Lions production is healthy."
        exit 0
    fi
    sleep 5
done

docker compose --env-file .env.prod -f docker-compose.prod.yml logs --tail=100 app nginx
exit 1
