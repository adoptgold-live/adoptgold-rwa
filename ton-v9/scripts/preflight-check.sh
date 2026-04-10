#!/usr/bin/env bash
set -euo pipefail

BASE="/var/www/html/public/rwa/ton-v9"

echo "========================================"
echo "V9 PREFLIGHT CHECK"
echo "========================================"

test -d "$BASE/contracts"
test -d "$BASE/wrappers"
test -d "$BASE/scripts"
test -f "$BASE/contracts/v9_nft_item.fc"
test -f "$BASE/contracts/v9_nft_collection.fc"
test -f "$BASE/wrappers/V9NftCollection.ts"
test -f "$BASE/scripts/deployV9Collection.ts"
test -f "$BASE/scripts/testV9PublicMint.ts"
test -f "$BASE/.env"

echo "[OK] file layout present"

grep -q "V9_OWNER=" "$BASE/.env"
grep -q "V9_TREASURY_ADDRESS=" "$BASE/.env"
grep -q "V9_COLLECTION_CONTENT_URI=" "$BASE/.env"

echo "[OK] env baseline present"

echo
echo "Recommended next commands:"
echo "cd $BASE"
echo "npx blueprint build v9_nft_item"
echo "npx blueprint build v9_nft_collection"
echo "npx blueprint run deployV9Collection --mainnet"
