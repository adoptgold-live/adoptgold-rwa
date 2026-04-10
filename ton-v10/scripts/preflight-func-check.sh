#!/usr/bin/env bash
set -euo pipefail

ROOT="/var/www/html/public/rwa/ton-v10"
ITEM="$ROOT/contracts/v10_nft_item.fc"
COLL="$ROOT/contracts/v10_nft_collection.fc"
STDLIB="$ROOT/contracts/imports/stdlib.fc"

echo "========================================"
echo "V10 FUN C PREFLIGHT CHECK"
echo "========================================"

FAIL=0

echo "[1/6] stdlib check"
if [ -f "$STDLIB" ]; then
  echo "OK stdlib.fc exists"
else
  echo "FAIL stdlib.fc missing"
  FAIL=1
fi

echo "[2/6] forbidden undefined helpers (item)"
if grep -nE "builder_null\?|equal_slices" "$ITEM"; then
  echo "FAIL undefined helpers in v10_nft_item.fc"
  FAIL=1
else
  echo "OK item helpers clean"
fi

echo "[3/6] forbidden undefined helpers (collection)"
if grep -nE "workchain\(|equal_slices" "$COLL"; then
  echo "FAIL undefined helpers in v10_nft_collection.fc"
  FAIL=1
else
  echo "OK collection helpers clean"
fi

echo "[4/6] include path sanity"
if grep -q '#include "imports/stdlib.fc"' "$ITEM" && grep -q '#include "imports/stdlib.fc"' "$COLL"; then
  echo "OK include path correct"
else
  echo "FAIL include path mismatch"
  FAIL=1
fi

echo "[5/6] opcode presence check"
if grep -q "op_transfer" "$ITEM" && grep -q "op_public_mint" "$COLL"; then
  echo "OK core opcodes present"
else
  echo "WARN opcode missing or renamed"
fi

echo "[6/6] summary"
if [ "$FAIL" -eq 0 ]; then
  echo "STATUS: READY FOR BUILD"
else
  echo "STATUS: NOT READY — FIX ABOVE ERRORS FIRST"
fi

echo "========================================"
