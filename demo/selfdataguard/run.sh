#!/usr/bin/env bash
# SelfDataGuard standalone demo launcher.
# Spawns PHP's built-in web server on http://127.0.0.1:8081

set -euo pipefail

cd "$(dirname "$0")"

PORT="${PORT:-8081}"
HOST="${HOST:-127.0.0.1}"

mkdir -p storage

cat <<EOF
SelfDataGuard demo starting at http://${HOST}:${PORT}
  - Open the URL in a browser
  - Press Ctrl+C to stop
  - Reset the demo with: rm -f storage/demo.sqlite storage/blindkey.bin
EOF

exec php -S "${HOST}:${PORT}" -t .
