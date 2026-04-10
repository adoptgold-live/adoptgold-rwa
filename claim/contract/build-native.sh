#!/usr/bin/env bash
set -euo pipefail

ROOT="/var/www/html/public/rwa/claim/contract"
BUILD="$ROOT/build-native"
CONTRACT="$ROOT/contracts/ClaimDistributor.fc"
STDLIB_FILE="$ROOT/contracts/stdlib.fc"
FIFT_LIB_DIR="/opt/ton/crypto/fift/lib"

mkdir -p "$BUILD"

test -f "$CONTRACT" || { echo "Missing contract: $CONTRACT"; exit 1; }
test -f "$STDLIB_FILE" || { echo "Missing local stdlib: $STDLIB_FILE"; exit 1; }
test -x /usr/local/bin/func || { echo "Missing /usr/local/bin/func"; exit 1; }
test -x /usr/local/bin/fift || { echo "Missing /usr/local/bin/fift"; exit 1; }
test -f "$FIFT_LIB_DIR/Fift.fif" || { echo "Missing $FIFT_LIB_DIR/Fift.fif"; exit 1; }

rm -f "$BUILD/ClaimDistributor.fif" "$BUILD/ClaimDistributor.boc"

echo "=== DEBUG: PATHS ==="
echo "ROOT         = $ROOT"
echo "BUILD        = $BUILD"
echo "CONTRACT     = $CONTRACT"
echo "STDLIB       = $STDLIB_FILE"
echo "FUNC         = $(which func)"
echo "FIFT         = $(which fift)"
echo "FIFT_LIB_DIR = $FIFT_LIB_DIR"
echo

echo "=== DEBUG: compile Func -> Fift (+save boc) ==="
/usr/local/bin/func -SPA -o"$BUILD/ClaimDistributor.fif" -W"$BUILD/ClaimDistributor.boc" "$CONTRACT"

test -s "$BUILD/ClaimDistributor.fif" || { echo "FIF not created"; exit 1; }

echo
echo "=== DEBUG: build Fift -> BOC ==="
/usr/local/bin/fift -I"$FIFT_LIB_DIR" -I"$ROOT/contracts" -s "$BUILD/ClaimDistributor.fif"

test -s "$BUILD/ClaimDistributor.boc" || { echo "BOC not created"; exit 1; }

echo
echo "=== OK ==="
ls -lh "$BUILD/ClaimDistributor.fif" "$BUILD/ClaimDistributor.boc"
sha256sum "$BUILD/ClaimDistributor.boc"
