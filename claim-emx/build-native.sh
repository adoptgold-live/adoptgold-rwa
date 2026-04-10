#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
BUILD="$ROOT/build-native"
CONTRACT="$ROOT/contracts/ClaimEmx.fc"

mkdir -p "$BUILD"

if [ ! -f "$CONTRACT" ]; then
  echo "Missing $CONTRACT"
  exit 1
fi

if [ ! -f "$ROOT/contracts/stdlib.fc" ]; then
  echo "Missing $ROOT/contracts/stdlib.fc"
  exit 1
fi

detect_fift_lib() {
  local p
  for p in \
    /usr/share/ton/fift/stdlib \
    /usr/share/ton/fift/lib \
    /opt/ton/build/crypto/fift/lib \
    /opt/ton/crypto/fift/lib
  do
    if [ -f "$p/Fift.fif" ]; then
      echo "$p"
      return 0
    fi
  done
  return 1
}

FIFT_LIB_DIR="$(detect_fift_lib || true)"

if [ -z "${FIFT_LIB_DIR:-}" ]; then
  echo "Could not locate Fift.fif"
  echo "Try: find /usr /opt -type f \\( -name Fift.fif -o -name Asm.fif \\) 2>/dev/null"
  exit 1
fi

echo "Using FIFT_LIB_DIR=$FIFT_LIB_DIR"

/usr/local/bin/func -SPA \
  -o"$BUILD/ClaimEmx.fif" \
  -W"$BUILD/ClaimEmx.boc" \
  "$CONTRACT"

/usr/local/bin/fift \
  -I"$FIFT_LIB_DIR" \
  -I"$ROOT/contracts" \
  -s "$BUILD/ClaimEmx.fif"

if [ ! -s "$BUILD/ClaimEmx.boc" ]; then
  echo "ClaimEmx.boc was not produced"
  ls -la "$BUILD"
  exit 1
fi

echo "OK -> $BUILD/ClaimEmx.boc"
