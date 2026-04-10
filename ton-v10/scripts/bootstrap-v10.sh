#!/usr/bin/env bash
set -euo pipefail

ROOT="/var/www/html/public/rwa/ton-v10"

cd "$ROOT"

echo "== V10 BOOTSTRAP =="

if [ ! -f package.json ]; then
  echo "package.json missing"
  exit 1
fi

npm install

if [ ! -f contracts/imports/stdlib.fc ]; then
  echo "stdlib.fc missing under contracts/imports/"
  echo "Copy Blueprint/FunC stdlib there before build."
  exit 1
fi

bash ./scripts/check-v10-imports.sh
bash ./scripts/check-v10-metadata.sh

echo "BOOTSTRAP_OK"
