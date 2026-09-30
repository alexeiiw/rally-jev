#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

mkdir -p bootstrap/cache storage/app/data/catalog storage/app/data/races storage/app/data/locks \
  storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs
composer install --no-interaction --prefer-dist

if [[ ! -f .env ]]; then
  cp .env.example .env
fi

if ! grep -q '^APP_KEY=base64:' .env; then
  php artisan key:generate --force
fi

touch storage/app/data/.gitkeep
php artisan rally:setup
if command -v node >/dev/null 2>&1; then
  node --check public/js/rally.js
fi
if [[ -f vendor/bin/phpunit ]]; then
  php artisan test
fi

echo "JEV Rally is prepared. Start it with: php artisan serve --host=0.0.0.0 --port=8000"
