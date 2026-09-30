#!/usr/bin/env bash
set -euo pipefail

# One-command bootstrap for a fresh JEV Rally Codespace.
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

if ! command -v php >/dev/null 2>&1; then
  echo "PHP is missing. In Codespaces, run: Ctrl+Shift+P > Codespaces: Rebuild Container"
  echo "Recovery Mode cannot install PHP into the running workspace; rebuild the dev container first."
  exit 1
fi

mkdir -p bootstrap/cache storage/app/data/catalog storage/app/data/races storage/app/data/locks \
  storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs

if ! command -v composer >/dev/null 2>&1; then
  echo "Installing Composer..."
  php -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');"
  php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
  rm -f /tmp/composer-setup.php
fi

if [[ ! -f .env ]]; then
  cp .env.example .env
fi

echo "Installing PHP dependencies..."
composer install --no-interaction --prefer-dist

if ! grep -q '^APP_KEY=base64:' .env; then
  php artisan key:generate --force
fi

echo "Initializing JSON catalogs..."
php artisan rally:setup

if command -v node >/dev/null 2>&1; then
  echo "Checking JavaScript..."
  node --check public/js/rally.js
fi

if [[ -f vendor/bin/phpunit ]]; then
  echo "Running tests..."
  php artisan test
fi

echo
echo "JEV Rally dependencies are installed. Start the game with:"
echo "php artisan serve --host=0.0.0.0 --port=8000"
