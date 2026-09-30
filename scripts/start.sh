#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
php artisan rally:setup
exec php artisan serve --host=0.0.0.0 --port=8000
