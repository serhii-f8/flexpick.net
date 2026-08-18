#!/usr/bin/env bash
set -euo pipefail

cd /home/ploi/audit.flexpick.net
git pull origin growth-retention

cd backend
composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
npm ci
npm run build

cd ../frontend
npm ci
PUBLIC_APP_URL=https://audit.flexpick.net npm run build

cd ..
echo "" | sudo -S service php8.4-fpm reload

if backend/artisan horizon:status 2>/dev/null | grep -q running; then
    php backend/artisan horizon:terminate
fi

echo "🚀 Application deployed!"
