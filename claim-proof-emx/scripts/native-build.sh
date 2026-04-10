#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BUILD="$ROOT/build-native"
CONTRACT="$ROOT/contracts/ClaimProofEMX.fc"
FIFT_LIB_DIR="${FIFT_LIB_DIR:-/opt/ton/crypto/fift/lib}"
FUNC_BIN="${FUNC_BIN:-/usr/local/bin/func}"
FIFT_BIN="${FIFT_BIN:-/usr/local/bin/fift}"

mkdir -p "$BUILD"

STDLIB_SRC=""
for CANDIDATE in \
  /opt/ton-stdlib/stdlib.fc \
  /usr/local/share/ton/stdlib.fc \
  /opt/ton/stdlib.fc \
  /usr/share/ton/stdlib.fc
do
  if [[ -f "$CANDIDATE" ]]; then
    STDLIB_SRC="$CANDIDATE"
    break
  fi
done

if [[ -z "$STDLIB_SRC" ]]; then
  echo "ERROR: stdlib.fc not found" >&2
  exit 1
fi

cp -f "$STDLIB_SRC" "$ROOT/contracts/stdlib.fc"

if [[ ! -x "$FUNC_BIN" ]]; then
  echo "ERROR: func not executable at $FUNC_BIN" >&2
  exit 1
fi

if [[ ! -x "$FIFT_BIN" ]]; then
  echo "ERROR: fift not executable at $FIFT_BIN" >&2
  exit 1
fi

echo "ROOT=$ROOT"
echo "BUILD=$BUILD"
echo "CONTRACT=$CONTRACT"
echo "FIFT_LIB_DIR=$FIFT_LIB_DIR"
echo "STDLIB_SRC=$STDLIB_SRC"

"$FUNC_BIN" -SPA -o"$BUILD/ClaimProofEMX.fif" -W"$BUILD/ClaimProofEMX.boc" "$CONTRACT"
"$FIFT_BIN" -I"$FIFT_LIB_DIR" -I"$ROOT/contracts" -s "$BUILD/ClaimProofEMX.fif"

if [[ ! -s "$BUILD/ClaimProofEMX.boc" ]]; then
  echo "ERROR: $BUILD/ClaimProofEMX.boc not generated or empty" >&2
  exit 1
fi

echo "OK -> $BUILD/ClaimProofEMX.boc"
