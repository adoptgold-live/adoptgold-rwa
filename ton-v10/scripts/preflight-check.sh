#!/usr/bin/env bash
set -euo pipefail

ROOT="/var/www/html/public/rwa/ton-v10"
COLLECTION_JSON_URL="https://adoptgold.app/rwa/metadata/collection/rwa-collection-v10.json"
COLLECTION_IMG_URL="https://adoptgold.app/rwa/metadata/collection/rwa-collection.png"

echo "== V10 PREFLIGHT =="

echo "[1/6] workspace"
test -d "$ROOT/contracts" && echo "OK contracts/"
test -d "$ROOT/wrappers" && echo "OK wrappers/"
test -d "$ROOT/scripts" && echo "OK scripts/"

echo "[2/6] imports"
test -f "$ROOT/contracts/imports/stdlib.fc" && echo "OK stdlib.fc" || { echo "MISSING stdlib.fc"; exit 1; }

echo "[3/6] core library hygiene"
if grep -R "ton-core" "$ROOT" --exclude-dir=node_modules; then
  echo "FOUND forbidden ton-core references"
  exit 1
fi
echo "OK only @ton/core expected"

echo "[4/6] metadata urls"
curl -fsSI "$COLLECTION_JSON_URL" >/dev/null && echo "OK collection json 200" || { echo "BAD collection json"; exit 1; }
curl -fsSI "$COLLECTION_IMG_URL" >/dev/null && echo "OK collection image 200" || { echo "BAD collection image"; exit 1; }

echo "[5/6] json syntax"
php -r '$j=file_get_contents("'"$ROOT"'/../metadata/collection/rwa-collection-v10.json"); json_decode($j,true); if (json_last_error()!==JSON_ERROR_NONE) { fwrite(STDERR, "JSON_BAD\n"); exit(1);} echo "JSON_OK\n";'

echo "[6/6] blueprint targets"
npx blueprint build >/tmp/v10-blueprint-build.log 2>&1 || true
if ! grep -q "V10NftItem" /tmp/v10-blueprint-build.log; then
  echo "V10NftItem target not visible"
  cat /tmp/v10-blueprint-build.log
  exit 1
fi
if ! grep -q "V10NftCollection" /tmp/v10-blueprint-build.log; then
  echo "V10NftCollection target not visible"
  cat /tmp/v10-blueprint-build.log
  exit 1
fi
echo "OK blueprint targets visible"

echo "PREFLIGHT_OK"
