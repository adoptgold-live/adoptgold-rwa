#!/usr/bin/env bash
set -euo pipefail

APP_DIR="/var/www/html/public/rwa/claim/contract"
TMP_JS="$APP_DIR/scripts/.tmp-derive-vault-wallets.mjs"

cd "$APP_DIR"

export ALL_CLAIM_VAULT="${ALL_CLAIM_VAULT:-UQDQFyFgdZ5HuT8DjXsS8YPpYd59o2DlFo1kVDhBdrsHqoIw}"
export TON_TREASURY_ADDRESS="${TON_TREASURY_ADDRESS:-UQBdYfGArtoCUmBs5TjYQtfPFfQuGC2Ydbj2pQr3zIlNrDta}"

export EMA_JETTON_MASTER="${EMA_JETTON_MASTER:-EQDK-bRI706S1cIIoLhTrTf-e8pL2TpOD5rcP3OaxYyzs74b}"
export EMX_JETTON_MASTER="${EMX_JETTON_MASTER:-EQBj0zGcHOvN5IsBP_BAAG5NRiuAa_SLBu-xjsJn7AeM4nQy}"
export EMS_JETTON_MASTER="${EMS_JETTON_MASTER:-EQCpJURzB4DJcL1keSRF8u5J5SmakM-_FaftTAyXRrVnnNmD}"
export WEMS_JETTON_MASTER="${WEMS_JETTON_MASTER:-EQA8dAgNtnsfGF0M-MJfnqii5AhxcRe73M8nCkkxuq85Tr-Q}"
export USDT_JETTON_MASTER="${USDT_JETTON_MASTER:-EQCxE6mUtQJKFnGfaROTKOt1lZbDiiX1kCixRv7Nw2Id_sDs}"

export TONCENTER_URL="${TONCENTER_URL:-https://toncenter.com/api/v2/jsonRPC}"
export TONCENTER_API_KEY="${TONCENTER_API_KEY:-}"

need_cmd() {
  command -v "$1" >/dev/null 2>&1 || {
    echo "[FATAL] Missing command: $1"
    exit 1
  }
}

need_cmd node
need_cmd npx
need_cmd sha256sum

if [ ! -f "$APP_DIR/build-native/ClaimDistributor.boc" ]; then
  echo "[FATAL] Missing prebuilt BOC: $APP_DIR/build-native/ClaimDistributor.boc"
  exit 1
fi

if [ ! -f "$APP_DIR/scripts/deployClaimDistributor.ts" ]; then
  echo "[FATAL] Missing deploy script: $APP_DIR/scripts/deployClaimDistributor.ts"
  exit 1
fi

echo "[INFO] ClaimDistributor deploy helper starting..."
echo "[INFO] APP_DIR=$APP_DIR"
echo "[INFO] ALL_CLAIM_VAULT=$ALL_CLAIM_VAULT"
echo "[INFO] TON_TREASURY_ADDRESS=$TON_TREASURY_ADDRESS"
echo

BOC_SHA="$(sha256sum "$APP_DIR/build-native/ClaimDistributor.boc" | awk '{print $1}')"
echo "[INFO] Current BOC SHA256: $BOC_SHA"
echo "[INFO] Locked expected SHA256: 5a7bb8c4bc8cbc688e74d2e736e811b9787c6aea0062eb6fc2390a0617d3e548"
echo

cat > "$TMP_JS" <<'EOMJS'
import { Address, beginCell } from '@ton/core';
import { TonClient } from '@ton/ton';

const TONCENTER_URL = process.env.TONCENTER_URL || 'https://toncenter.com/api/v2/jsonRPC';
const TONCENTER_API_KEY = process.env.TONCENTER_API_KEY || '';
const ALL_CLAIM_VAULT = process.env.ALL_CLAIM_VAULT || 'UQDQFyFgdZ5HuT8DjXsS8YPpYd59o2DlFo1kVDhBdrsHqoIw';

const MASTERS = {
  EMA: process.env.EMA_JETTON_MASTER || 'EQDK-bRI706S1cIIoLhTrTf-e8pL2TpOD5rcP3OaxYyzs74b',
  EMX: process.env.EMX_JETTON_MASTER || 'EQBj0zGcHOvN5IsBP_BAAG5NRiuAa_SLBu-xjsJn7AeM4nQy',
  EMS: process.env.EMS_JETTON_MASTER || 'EQCpJURzB4DJcL1keSRF8u5J5SmakM-_FaftTAyXRrVnnNmD',
  WEMS: process.env.WEMS_JETTON_MASTER || 'EQA8dAgNtnsfGF0M-MJfnqii5AhxcRe73M8nCkkxuq85Tr-Q',
  USDT: process.env.USDT_JETTON_MASTER || 'EQCxE6mUtQJKFnGfaROTKOt1lZbDiiX1kCixRv7Nw2Id_sDs',
};

function toFriendly(addr) {
  return addr.toString({ bounceable: true, urlSafe: true });
}

function stackItemToAddress(item) {
  if (!item) throw new Error('Empty stack item');

  if (item.type === 'slice' && item.cell) {
    return item.cell.beginParse().loadAddress();
  }
  if (item.type === 'cell' && item.cell) {
    return item.cell.beginParse().loadAddress();
  }
  if (item.type === 'builder' && item.cell) {
    return item.cell.beginParse().loadAddress();
  }

  throw new Error(`Unsupported stack item type: ${item.type || typeof item}`);
}

async function deriveWallet(client, master, owner) {
  const ownerCell = beginCell().storeAddress(owner).endCell();

  const res = await client.runMethod(master, 'get_wallet_address', [
    { type: 'slice', cell: ownerCell }
  ]);

  if (!res || !res.stack) {
    throw new Error(`No stack returned for master ${toFriendly(master)}`);
  }

  if (typeof res.stack.readAddress === 'function') {
    try {
      const a = res.stack.readAddress();
      if (a) return a;
    } catch (_) {}
  }

  const items = res.stack.items || res.stack._items || [];
  if (!Array.isArray(items) || items.length === 0) {
    throw new Error(`Empty stack items for master ${toFriendly(master)}`);
  }

  return stackItemToAddress(items[0]);
}

async function main() {
  const client = new TonClient({
    endpoint: TONCENTER_URL,
    apiKey: TONCENTER_API_KEY || undefined
  });

  const owner = Address.parse(ALL_CLAIM_VAULT);
  console.log(`OWNER=${toFriendly(owner)}`);

  for (const [symbol, masterStr] of Object.entries(MASTERS)) {
    const master = Address.parse(masterStr);
    const wallet = await deriveWallet(client, master, owner);
    console.log(`${symbol}_MASTER=${toFriendly(master)}`);
    console.log(`${symbol}_WALLET=${toFriendly(wallet)}`);
  }
}

main().catch((err) => {
  console.error('DERIVE_FAILED');
  console.error(err?.stack || err?.message || String(err));
  process.exit(1);
});
EOMJS

echo "[INFO] Deriving vault jetton wallets from masters..."
DERIVE_OUT="$(node "$TMP_JS")" || {
  echo "[FATAL] Wallet derivation failed"
  rm -f "$TMP_JS"
  exit 1
}

rm -f "$TMP_JS"

echo "$DERIVE_OUT"
echo

extract_var() {
  local key="$1"
  echo "$DERIVE_OUT" | awk -F= -v k="$key" '$1==k {print $2}' | tail -n1
}

export EMA_WALLET="$(extract_var EMA_WALLET)"
export EMX_WALLET="$(extract_var EMX_WALLET)"
export EMS_WALLET="$(extract_var EMS_WALLET)"
export WEMS_WALLET="$(extract_var WEMS_WALLET)"
export USDT_WALLET="$(extract_var USDT_WALLET)"

for k in EMA_WALLET EMX_WALLET EMS_WALLET WEMS_WALLET USDT_WALLET; do
  v="${!k:-}"
  if [ -z "$v" ]; then
    echo "[FATAL] Missing derived env: $k"
    exit 1
  fi
done

echo "[INFO] Derived wallet envs:"
echo "  EMA_WALLET=$EMA_WALLET"
echo "  EMX_WALLET=$EMX_WALLET"
echo "  EMS_WALLET=$EMS_WALLET"
echo "  WEMS_WALLET=$WEMS_WALLET"
echo "  USDT_WALLET=$USDT_WALLET"
echo

ENV_SNAPSHOT="$APP_DIR/.claim-distributor.deploy.env"
cat > "$ENV_SNAPSHOT" <<ENV
export ALL_CLAIM_VAULT='$ALL_CLAIM_VAULT'
export TON_TREASURY_ADDRESS='$TON_TREASURY_ADDRESS'
export EMA_JETTON_MASTER='$EMA_JETTON_MASTER'
export EMX_JETTON_MASTER='$EMX_JETTON_MASTER'
export EMS_JETTON_MASTER='$EMS_JETTON_MASTER'
export WEMS_JETTON_MASTER='$WEMS_JETTON_MASTER'
export USDT_JETTON_MASTER='$USDT_JETTON_MASTER'
export TONCENTER_URL='$TONCENTER_URL'
export TONCENTER_API_KEY='$TONCENTER_API_KEY'
export EMA_WALLET='$EMA_WALLET'
export EMX_WALLET='$EMX_WALLET'
export EMS_WALLET='$EMS_WALLET'
export WEMS_WALLET='$WEMS_WALLET'
export USDT_WALLET='$USDT_WALLET'
ENV

chmod 600 "$ENV_SNAPSHOT"
echo "[INFO] Saved env snapshot: $ENV_SNAPSHOT"
echo

echo "[INFO] Running deployClaimDistributor ..."
DEPLOY_LOG="$(mktemp)"
if npx blueprint run deployClaimDistributor | tee "$DEPLOY_LOG"; then
  :
else
  echo
  echo "[FATAL] Deploy command failed"
  echo "[INFO] Env snapshot retained at: $ENV_SNAPSHOT"
  echo "[INFO] Deploy log: $DEPLOY_LOG"
  exit 1
fi

CLAIM_DISTRIBUTOR="$(grep -Eo 'DEPLOYED:[[:space:]]+EQ[[:alnum:]_-]+' "$DEPLOY_LOG" | awk '{print $2}' | tail -n1 || true)"

if [ -z "$CLAIM_DISTRIBUTOR" ]; then
  CLAIM_DISTRIBUTOR="$(grep -Eo 'EQ[[:alnum:]_-]{40,}' "$DEPLOY_LOG" | tail -n1 || true)"
fi

if [ -n "$CLAIM_DISTRIBUTOR" ]; then
  echo
  echo "[SUCCESS] CLAIM_DISTRIBUTOR=$CLAIM_DISTRIBUTOR"
  {
    echo "export CLAIM_DISTRIBUTOR='$CLAIM_DISTRIBUTOR'"
  } >> "$ENV_SNAPSHOT"
  echo "[INFO] CLAIM_DISTRIBUTOR appended to $ENV_SNAPSHOT"
else
  echo
  echo "[WARN] Deploy ran but deployed address was not auto-parsed from output."
  echo "[WARN] Check log manually: $DEPLOY_LOG"
fi

echo
echo "[DONE] ClaimDistributor v1 deploy helper complete."
echo "[NOTE] Business lock preserved:"
echo "       - user pays TON gas"
echo "       - each claim adds fixed 0.10 TON treasury contribution"
echo "       - payout only after payment confirmation"
