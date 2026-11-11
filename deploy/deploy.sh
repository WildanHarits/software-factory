#!/usr/bin/env bash
# Deploy the latest code. Run on the server as the "factory" user, from /srv/software-factory:
#   ./deploy/deploy.sh
set -euo pipefail

cd "$(dirname "$0")/.."

echo "→ Pulling the latest code"
git pull --ff-only

echo "→ Installing PHP dependencies"
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

echo "→ Building the frontend"
npm ci --no-audit --no-fund
npm run build

php artisan down --retry=15 || true

echo "→ Migrating the database"
php artisan migrate --force

echo "→ Caching config, routes, views and events"
php artisan optimize

php artisan up

# Horizon finishes the jobs it's running (agent runs included), then supervisor restarts it with the new code.
echo "→ Restarting Horizon"
php artisan horizon:terminate

echo "→ Checking the installation"
php artisan factory:doctor
