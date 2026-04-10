#!/usr/bin/env bash
set -euo pipefail

SRC="/var/www/html/public/rwa/ton-v8/contracts/imports"
DST="/var/www/html/public/rwa/ton-v9/contracts/imports"

if [ ! -d "$SRC" ]; then
  echo "SOURCE_IMPORTS_NOT_FOUND: $SRC"
  exit 1
fi

rm -rf "$DST"
mkdir -p "$(dirname "$DST")"
cp -a "$SRC" "$DST"

echo "COPIED_IMPORTS_TO: $DST"
find "$DST" -maxdepth 2 -type f | sort
