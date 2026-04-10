#!/usr/bin/env bash
set -euo pipefail

ROOT="/var/www/html/public/rwa/ton"
CONTRACTS="$ROOT/contracts"
BUILD="$ROOT/build"
ENV_FILE="$ROOT/.env"

FUNC_BIN="${FUNC_BIN:-/usr/local/bin/func}"
FIFT_BIN="${FIFT_BIN:-/usr/local/bin/fift}"
FIFT_LIB_DIR="${FIFT_LIB_DIR:-/opt/ton/crypto/fift/lib}"

STD_CANDIDATES=(
  "/opt/ton-stdlib/stdlib.fc"
  "/usr/local/share/ton/stdlib.fc"
  "/opt/ton/crypto/smartcont/stdlib.fc"
)

need_bin() {
  local bin="$1"
  if [ ! -x "$bin" ]; then
    echo "ERROR: missing executable: $bin" >&2
    exit 1
  fi
}

ensure_stdlib() {
  if [ -f "$CONTRACTS/stdlib.fc" ]; then
    return 0
  fi

  local found=""
  for f in "${STD_CANDIDATES[@]}"; do
    if [ -f "$f" ]; then
      found="$f"
      break
    fi
  done

  if [ -z "$found" ]; then
    echo "ERROR: stdlib.fc not found in expected paths" >&2
    exit 1
  fi

  cp "$found" "$CONTRACTS/stdlib.fc"
  echo "Copied stdlib from: $found -> $CONTRACTS/stdlib.fc"
}

compile_one() {
  local name="$1"
  local src="$CONTRACTS/${name}.fc"
  local fif="$BUILD/${name}.fif"
  local cell="$BUILD/${name}.cell"

  echo "==> Compiling $src"
  "$FUNC_BIN" -SPA -o"$fif" -W"$cell" "$src"
  "$FIFT_BIN" -I"$FIFT_LIB_DIR" -I"$CONTRACTS" -s "$fif" >/dev/null

  if [ ! -s "$fif" ]; then
    echo "ERROR: empty fif output for $name" >&2
    exit 1
  fi
  if [ ! -s "$cell" ]; then
    echo "ERROR: empty cell output for $name" >&2
    exit 1
  fi

  echo "Built:"
  echo "  $fif"
  echo "  $cell"
}

install_deps() {
  cd "$ROOT"
  if [ ! -d "$ROOT/node_modules" ]; then
    npm install
  else
    npm install
  fi
}

do_build() {
  mkdir -p "$BUILD"
  need_bin "$FUNC_BIN"
  need_bin "$FIFT_BIN"
  ensure_stdlib

  compile_one "rwa_item"
  compile_one "rwa_collection"
}

do_deploy() {
  do_build
  install_deps
  cd "$ROOT"
  export DOTENV_CONFIG_PATH="$ENV_FILE"
  npx ts-node ./scripts/deploy_collection.ts
}

usage() {
  cat <<USAGE
Usage:
  bash /var/www/html/public/rwa/ton/deploy.sh build
  bash /var/www/html/public/rwa/ton/deploy.sh deploy
USAGE
}

CMD="${1:-}"

case "$CMD" in
  build)
    do_build
    ;;
  deploy)
    do_deploy
    ;;
  *)
    usage
    exit 1
    ;;
esac
