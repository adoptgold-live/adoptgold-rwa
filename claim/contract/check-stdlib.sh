#!/usr/bin/env bash
set -euo pipefail

echo "Checking canonical stdlib path..."
if [ -f /opt/ton-stdlib/stdlib.fc ]; then
  echo "OK: /opt/ton-stdlib/stdlib.fc exists"
  ls -lh /opt/ton-stdlib/stdlib.fc
  exit 0
fi

echo "MISSING: /opt/ton-stdlib/stdlib.fc"
echo
echo "Searching likely locations..."
find /opt /usr/local /usr -type f -name stdlib.fc 2>/dev/null | head -n 20 || true
