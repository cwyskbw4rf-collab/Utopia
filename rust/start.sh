#!/bin/sh
set -eu

# Map Render's $PORT to HTTP_PORT expected by the binary
if [ -n "${PORT:-}" ]; then
  export HTTP_PORT="${PORT}"
fi

# Default protocol to http for Render web service
protocol="${PROXY_PROTOCOL:-http}"

# If arguments given to the container, use them; otherwise use the default protocol
if [ "$#" -gt 0 ]; then
  exec /opt/proxy/proxy "$@"
else
  exec /opt/proxy/proxy "$protocol"
fi
