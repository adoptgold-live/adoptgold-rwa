#!/usr/bin/env bash
# /var/www/html/public/rwa/ton/scripts/bootstrap-v8.sh
# Version: v8.0.0-20260330-auto-build-deploy
set -euo pipefail

ROOT="/var/www/html/public/rwa/ton"
cd "$ROOT"

if [ ! -f package.json ]; then
  echo "package.json missing in $ROOT"
  exit 1
fi

if [ ! -f .env ]; then
  cat > .env <<'ENVEOF'
V8_OWNER=
V8_TREASURY_ADDRESS=
V8_ROYALTY_ADDRESS=
V8_ROUTER_PLACEHOLDER=EQAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAM9c
V8_ROYALTY_FACTOR=2500
V8_ROYALTY_BASE=10000
V8_COLLECTION_CONTENT_URI=https://adoptgold.app/rwa/metadata/collection/v8.json
V8_COLLECTION_DEPLOY_VALUE_TON=0.25
V8_ROUTER_DEPLOY_VALUE_TON=0.25
V8_TREASURY_CONTRIBUTION_TON=0.33
V8_PUBLIC_MIN_ATTACH_TON=0.50
V8_COLLECTION_FORWARD_TON=0.12
V8_FIRST_ITEM_INDEX=0
TON_RPC_URL=https://mainnet-v4.tonhubapi.com
ENVEOF
  echo "Created $ROOT/.env - fill it first and rerun."
  exit 1
fi

echo "==> installing deps if needed"
npm install

echo "==> building contracts"
npx blueprint build v8_nft_item
npx blueprint build v8_nft_collection
npx blueprint build v8_mint_router

echo "==> deploy V8 collection first"
npx blueprint run deployV8Collection --mainnet

echo
echo "Copy the printed V8_COLLECTION_ADDRESS into $ROOT/.env"
echo "Then rerun only the router deploy:"
echo "npx blueprint run deployV8Router --mainnet"
echo
echo "After router deploy, set collection router address by owner/admin wallet."
echo "Suggested next helper command:"
echo "npx tsx scripts/postDeployV8SetRouter.ts --collection <V8_COLLECTION_ADDRESS> --router <V8_ROUTER_ADDRESS>"
