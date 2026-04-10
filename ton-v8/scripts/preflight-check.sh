#!/usr/bin/env bash
set -e

ROOT="/var/www/html/public/rwa/ton-v8"

echo "========================================"
echo "V8 PRE-FLIGHT CHECK (FINAL)"
echo "========================================"

# 1. stdlib global
if [ ! -f "/opt/ton-stdlib/stdlib.fc" ]; then
  echo "❌ Missing global stdlib: /opt/ton-stdlib/stdlib.fc"
  exit 1
fi
echo "✔ Global stdlib exists"

# 2. stdlib local
if [ ! -f "$ROOT/contracts/imports/stdlib.fc" ]; then
  echo "❌ Missing local stdlib mirror"
  exit 1
fi
echo "✔ Local stdlib mirror exists"

# 3. include correctness
BAD=$(grep -R '/opt/ton-stdlib' "$ROOT/contracts" || true)
if [ ! -z "$BAD" ]; then
  echo "❌ Found absolute stdlib include (not allowed in build)"
  exit 1
fi
echo "✔ Includes are relative"

# 4. contracts exist
for f in v8_nft_item.fc v8_nft_collection.fc v8_mint_router.fc; do
  if [ ! -f "$ROOT/contracts/$f" ]; then
    echo "❌ Missing contract: $f"
    exit 1
  fi
done
echo "✔ Contracts present"

# 5. wrappers exist
for f in V8NftCollection.ts V8MintRouter.ts; do
  if [ ! -f "$ROOT/wrappers/$f" ]; then
    echo "❌ Missing wrapper: $f"
    exit 1
  fi
done
echo "✔ Wrappers present"

# 6. env
if [ ! -f "$ROOT/.env" ]; then
  echo "❌ Missing .env"
  exit 1
fi
echo "✔ .env exists"

# 7. node_modules
if [ ! -d "$ROOT/node_modules" ]; then
  echo "❌ node_modules missing"
  exit 1
fi
echo "✔ node_modules installed"

echo "========================================"
echo "✅ PRE-FLIGHT OK — SAFE TO BUILD"
echo "========================================"
