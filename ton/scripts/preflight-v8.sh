#!/usr/bin/env bash
# /var/www/html/public/rwa/ton/scripts/preflight-v8.sh
# Version: v8.0.0-20260330-preflight
set -euo pipefail

ROOT="/var/www/html/public/rwa/ton"
ENV_FILE="$ROOT/.env"

echo "========================================"
echo "V8 PRE-FLIGHT CHECK"
echo "========================================"

# -------- 1. ENV EXIST --------
if [ ! -f "$ENV_FILE" ]; then
  echo "❌ .env missing at $ENV_FILE"
  exit 1
fi

echo "✔ .env exists"

# -------- 2. LOAD ENV --------
set -a
source "$ENV_FILE"
set +a

# -------- 3. REQUIRED VARS --------
REQUIRED=(
  V8_OWNER
  V8_TREASURY_ADDRESS
  V8_ROYALTY_ADDRESS
  V8_COLLECTION_CONTENT_URI
  V8_PUBLIC_MIN_ATTACH_TON
  V8_COLLECTION_FORWARD_TON
  V8_TREASURY_CONTRIBUTION_TON
)

for v in "${REQUIRED[@]}"; do
  if [ -z "${!v:-}" ]; then
    echo "❌ Missing env: $v"
    exit 1
  fi
done

echo "✔ Required env variables present"

# -------- 4. OWNER LOCK CHECK --------
if [ "$V8_OWNER" != "UQDRA7wHzvXPhnIq0tP1aF36Y0I4H3alYWZKj_LFldB1XzCL" ]; then
  echo "❌ OWNER mismatch (must use locked address)"
  exit 1
fi

echo "✔ Owner address locked and correct"

# -------- 5. ADDRESS FORMAT --------
check_addr () {
  if [[ "$1" != UQ* && "$1" != EQ* ]]; then
    echo "❌ Invalid address format: $1"
    exit 1
  fi
}

check_addr "$V8_OWNER"
check_addr "$V8_TREASURY_ADDRESS"
check_addr "$V8_ROYALTY_ADDRESS"

echo "✔ Address formats look valid"

# -------- 6. ECONOMIC VALIDATION --------
TOTAL=$(printf "%.2f" "$V8_PUBLIC_MIN_ATTACH_TON")
TREASURY=$(printf "%.2f" "$V8_TREASURY_CONTRIBUTION_TON")
COLLECTION=$(printf "%.2f" "$V8_COLLECTION_FORWARD_TON")

SUM=$(awk "BEGIN {print $TREASURY + $COLLECTION}")

if (( $(echo "$SUM > $TOTAL" | bc -l) )); then
  echo "❌ Economics invalid: treasury + collection > user attach"
  exit 1
fi

echo "✔ Mint economics valid"

# -------- 7. COLLECTION JSON --------
JSON_PATH=$(echo "$V8_COLLECTION_CONTENT_URI" | sed 's|https://adoptgold.app||')
FULL_JSON="/var/www/html/public$JSON_PATH"

if [ ! -f "$FULL_JSON" ]; then
  echo "❌ Collection JSON missing: $FULL_JSON"
  exit 1
fi

echo "✔ Collection JSON found"

# -------- 8. BUILD ARTIFACTS --------
ARTIFACTS=(
  "$ROOT/build/v8_nft_item.compiled.json"
  "$ROOT/build/v8_nft_collection.compiled.json"
  "$ROOT/build/v8_mint_router.compiled.json"
)

for f in "${ARTIFACTS[@]}"; do
  if [ ! -f "$f" ]; then
    echo "❌ Missing compiled artifact: $f"
    echo "Run: npx blueprint build"
    exit 1
  fi
done

echo "✔ Compiled artifacts exist"

# -------- 9. EMPTY NFT ITEM CODE CHECK --------
if grep -q '"hex":""' "$ROOT/build/v8_nft_item.compiled.json"; then
  echo "❌ nftItemCode is EMPTY (critical error)"
  exit 1
fi

echo "✔ NFT item code present (NOT EMPTY)"

# -------- 10. TON RPC CHECK --------
RPC="${TON_RPC_URL:-https://mainnet-v4.tonhubapi.com}"

echo "Checking TON RPC..."

if ! curl -s "$RPC" > /dev/null; then
  echo "❌ TON RPC not reachable: $RPC"
  exit 1
fi

echo "✔ TON RPC reachable"

# -------- 11. NODE / TOOLING --------
if ! command -v node >/dev/null 2>&1; then
  echo "❌ node not installed"
  exit 1
fi

if ! command -v npx >/dev/null 2>&1; then
  echo "❌ npx not available"
  exit 1
fi

echo "✔ Node environment OK"

# -------- FINAL --------
echo "========================================"
echo "✅ PRE-FLIGHT PASSED — READY TO DEPLOY"
echo "========================================"

