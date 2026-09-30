#!/usr/bin/env bash
set -euo pipefail
bash "$(dirname "${BASH_SOURCE[0]}")/start-server.sh"
tail -f storage/logs/server.log
