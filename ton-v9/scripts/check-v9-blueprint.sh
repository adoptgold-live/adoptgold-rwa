#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html/public/rwa/ton-v9

echo "== wrappers =="
ls -la wrappers

echo
echo "== compile files =="
ls -la wrappers/*.compile.ts

echo
echo "== grep @ton/core vs ton-core =="
grep -RIn "@ton/core\|ton-core" wrappers scripts package.json || true
