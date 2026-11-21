#!/usr/bin/env bash
#
# Deployment script for a Hostinger VPS.
#
# It pulls the latest revision, installs the dependencies, rebuilds the SPA
# and restarts the background workers. The TLS certificate is managed by
# certbot and is not touched here.
#
# Usage: ./deploy/deploy.sh

set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/chat}"
BACKEND_DIR="${APP_DIR}/backend"
FRONTEND_DIR="${APP_DIR}/frontend"

echo "==> Deploying to ${APP_DIR}"

cd "${APP_DIR}"

if [ -d .git ]; then
    git pull --ff-only
else
    echo "No git repository found, skipping the pull."
fi

echo "==> Installing the backend dependencies"
cd "${BACKEND_DIR}"
composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

echo "==> Installing the frontend dependencies"
cd "${FRONTEND_DIR}"
npm ci

echo "==> Building the SPA"
npm run build

echo "==> Preparing the caches"
cd "${BACKEND_DIR}"
php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan view:cache

echo "==> Running the migrations"
php artisan migrate --force

echo "==> Cleaning the expired files"
php artisan auth:clear-resets
php artisan queue:prune-batches --hours=48

echo "==> Restarting the workers"
php artisan reverb:restart
sudo supervisorctl restart chat-queue chat-reverb || true

echo "==> Done"
