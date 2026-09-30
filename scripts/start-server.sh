#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

if pgrep -f 'artisan serve --host=0.0.0.0 --port=8000' >/dev/null 2>&1; then
  exit 0
fi

mkdir -p storage/logs
nohup php artisan serve --host=0.0.0.0 --port=8000 > storage/logs/server.log 2>&1 &
