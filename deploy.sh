#!/usr/bin/env bash
#
# Deploy the latest code from the main branch to this server.
#
# Intended to be run on the VPS after a checkpoint commit has been pushed.
# Assumes the app already lives in $APP_DIR and a production .env exists there
# (the .env is provisioned once and never touched by this script).
#
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/finance-tracker-bot}"
BRANCH="${BRANCH:-main}"

cd "$APP_DIR"

echo "==> [1/6] Pulling latest ${BRANCH}"
git fetch origin
git checkout "${BRANCH}"
git pull --ff-only origin "${BRANCH}"

echo "==> [2/6] Installing PHP dependencies"
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> [3/6] Building frontend assets"
npm install --no-audit --no-fund
npm run build

echo "==> [4/6] Running database migrations"
php artisan migrate --force

echo "==> [5/6] Refreshing application caches"
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "==> [6/6] Fixing storage permissions"
chown -R www-data:www-data storage bootstrap/cache || true

echo ""
echo "Deployed ${BRANCH} @ $(git rev-parse --short HEAD) at $(date '+%Y-%m-%d %H:%M:%S')"
