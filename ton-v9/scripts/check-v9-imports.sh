#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html/public/rwa/ton-v9

echo "== @ton/core / ton-core scan =="
grep -RIn "ton-core\|@ton/core" wrappers scripts package.json || true
