#!/usr/bin/env bash
set -euo pipefail

ROOT="/var/www/html/public/rwa/ton-v10"

echo "== CHECK IMPORTS =="

if grep -R "ton-core" "$ROOT" --exclude-dir=node_modules; then
  echo "FAIL: forbidden ton-core import found"
  exit 1
fi

if ! grep -R "@ton/core" "$ROOT/wrappers" "$ROOT/scripts" >/dev/null 2>&1; then
  echo "FAIL: @ton/core imports not found"
  exit 1
fi

echo "IMPORTS_OK"
