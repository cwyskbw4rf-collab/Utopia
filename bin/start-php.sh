#!/bin/sh
set -eu

# Default PORT to 3000 if not provided by the environment
PORT="${PORT:-3000}"

echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] Starting PHP built-in server on 0.0.0.0:${PORT}"

exec php -S "0.0.0.0:${PORT}" -t public
