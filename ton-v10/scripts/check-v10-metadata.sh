#!/usr/bin/env bash
set -euo pipefail

JSON_URL="https://adoptgold.app/rwa/metadata/collection/rwa-collection-v10.json"
IMG_URL="https://adoptgold.app/rwa/metadata/collection/rwa-collection.png"

echo "== CHECK METADATA =="

curl -fsSI "$JSON_URL" | head -n 5
curl -fsSI "$IMG_URL" | head -n 5

TMP_JSON="$(mktemp)"
curl -fsSL "$JSON_URL" -o "$TMP_JSON"

php -r '$j=file_get_contents("'"$TMP_JSON"'"); json_decode($j,true); if (json_last_error()!==JSON_ERROR_NONE) { fwrite(STDERR, "JSON_BAD\n"); exit(1);} echo "JSON_OK\n";'

rm -f "$TMP_JSON"

echo "METADATA_OK"
