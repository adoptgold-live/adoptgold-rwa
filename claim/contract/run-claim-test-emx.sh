#!/usr/bin/env bash
set -euo pipefail

APP_DIR="/var/www/html/public/rwa/claim/contract"
cd "$APP_DIR"

if [ ! -f "$APP_DIR/.claim-distributor.deploy.env" ]; then
  echo "[FATAL] Missing env file: $APP_DIR/.claim-distributor.deploy.env"
  exit 1
fi

set -a
source "$APP_DIR/.claim-distributor.deploy.env"
set +a

need_var() {
  local k="$1"
  local v="${!k:-}"
  if [ -z "$v" ]; then
    echo "[FATAL] Missing env: $k"
    exit 1
  fi
}

need_cmd() {
  command -v "$1" >/dev/null 2>&1 || {
    echo "[FATAL] Missing command: $1"
    exit 1
  }
}

need_cmd npx
need_var CLAIM_DISTRIBUTOR

if [ ! -f "$APP_DIR/scripts/sendClaim.ts" ]; then
  echo "[FATAL] Missing script: $APP_DIR/scripts/sendClaim.ts"
  exit 1
fi

# -------------------------------------------------
# Locked tiny live test values
# -------------------------------------------------
export CLAIM_TEST_SYMBOL="${CLAIM_TEST_SYMBOL:-EMX}"
export CLAIM_TEST_TOKEN_ID="${CLAIM_TEST_TOKEN_ID:-2}"
export CLAIM_TEST_DECIMALS="${CLAIM_TEST_DECIMALS:-9}"
export CLAIM_TEST_AMOUNT="${CLAIM_TEST_AMOUNT:-0.1}"
export CLAIM_TEST_AMOUNT_UNITS="${CLAIM_TEST_AMOUNT_UNITS:-100000000}"

# Default recipient:
# 1) first CLI argument
# 2) CLAIM_TEST_RECIPIENT env if already exported
# 3) fail hard
export CLAIM_TEST_RECIPIENT="${1:-${CLAIM_TEST_RECIPIENT:-}}"

if [ -z "$CLAIM_TEST_RECIPIENT" ]; then
  echo "[FATAL] Missing recipient."
  echo "[HINT] Usage:"
  echo "       ./run-claim-test-emx.sh UQ....recipient..."
  echo
  echo "[HINT] Or export:"
  echo "       export CLAIM_TEST_RECIPIENT='UQ....'"
  exit 1
fi

# Unique ref for this tiny test
TS="$(date -u +%Y%m%d%H%M%S)"
RAND="$(openssl rand -hex 4 2>/dev/null || echo TEST0001)"
export CLAIM_TEST_REF="${CLAIM_TEST_REF:-CLM-EMX-TEST-${TS}-${RAND}}"

# Optional treasury contribution note for off-chain audit trail
export CLAIM_TEST_TREASURY_TON="${CLAIM_TEST_TREASURY_TON:-0.10}"

echo "[INFO] ========================================="
echo "[INFO] ClaimDistributor tiny EMX live test"
echo "[INFO] CLAIM_DISTRIBUTOR=$CLAIM_DISTRIBUTOR"
echo "[INFO] TOKEN=$CLAIM_TEST_SYMBOL"
echo "[INFO] TOKEN_ID=$CLAIM_TEST_TOKEN_ID"
echo "[INFO] DECIMALS=$CLAIM_TEST_DECIMALS"
echo "[INFO] AMOUNT=$CLAIM_TEST_AMOUNT"
echo "[INFO] AMOUNT_UNITS=$CLAIM_TEST_AMOUNT_UNITS"
echo "[INFO] RECIPIENT=$CLAIM_TEST_RECIPIENT"
echo "[INFO] REF=$CLAIM_TEST_REF"
echo "[INFO] TREASURY_CONTRIB_TON=$CLAIM_TEST_TREASURY_TON"
echo "[INFO] ========================================="
echo

# -------------------------------------------------
# Compatibility exports for common sendClaim.ts patterns
# -------------------------------------------------
export TOKEN_ID="$CLAIM_TEST_TOKEN_ID"
export TOKEN_SYMBOL="$CLAIM_TEST_SYMBOL"
export TOKEN_DECIMALS="$CLAIM_TEST_DECIMALS"
export CLAIM_TOKEN_ID="$CLAIM_TEST_TOKEN_ID"
export CLAIM_TOKEN_SYMBOL="$CLAIM_TEST_SYMBOL"
export CLAIM_AMOUNT="$CLAIM_TEST_AMOUNT"
export CLAIM_AMOUNT_UNITS="$CLAIM_TEST_AMOUNT_UNITS"
export AMOUNT="$CLAIM_TEST_AMOUNT"
export AMOUNT_UNITS="$CLAIM_TEST_AMOUNT_UNITS"
export RECIPIENT="$CLAIM_TEST_RECIPIENT"
export CLAIM_RECIPIENT="$CLAIM_TEST_RECIPIENT"
export TO="$CLAIM_TEST_RECIPIENT"
export REF="$CLAIM_TEST_REF"
export CLAIM_REF="$CLAIM_TEST_REF"
export DISTRIBUTOR="$CLAIM_DISTRIBUTOR"
export CLAIM_DISTRIBUTOR_ADDRESS="$CLAIM_DISTRIBUTOR"

echo "[INFO] Running existing scripts/sendClaim.ts ..."
echo "[INFO] If prompted:"
echo "[INFO]   network = mainnet"
echo "[INFO]   wallet  = TON Connect compatible mobile wallet"
echo

npx blueprint run sendClaim

echo
echo "[DONE] Tiny EMX claim test command finished."
echo "[NEXT] Check contract tx, recipient jetton wallet, and vault EMX wallet delta."
