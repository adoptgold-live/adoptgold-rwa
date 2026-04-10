#!/usr/bin/env bash
set -euo pipefail

APP_DIR="/var/www/html/public/rwa/claim/contract"
VERIFY_JS="$APP_DIR/scripts/.tmp-verify-claim-distributor.mjs"

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

need_cmd node

need_var CLAIM_DISTRIBUTOR
need_var TONCENTER_URL
need_var TON_TREASURY_ADDRESS
need_var EMA_WALLET
need_var EMX_WALLET
need_var EMS_WALLET
need_var WEMS_WALLET
need_var USDT_WALLET

cat > "$VERIFY_JS" <<'EOMJS'
import { Address, Cell } from '@ton/core';
import { TonClient } from '@ton/ton';

const ENV = {
  endpoint: process.env.TONCENTER_URL || 'https://toncenter.com/api/v2/jsonRPC',
  apiKey: process.env.TONCENTER_API_KEY || '',
  claimDistributor: process.env.CLAIM_DISTRIBUTOR,
  treasury: process.env.TON_TREASURY_ADDRESS,
  ema: process.env.EMA_WALLET,
  emx: process.env.EMX_WALLET,
  ems: process.env.EMS_WALLET,
  wems: process.env.WEMS_WALLET,
  usdt: process.env.USDT_WALLET,
};

function must(v, name) {
  if (!v || !String(v).trim()) throw new Error(`Missing env: ${name}`);
  return String(v).trim();
}

function parseFriendly(v, name) {
  return Address.parse(must(v, name));
}

function norm(addr) {
  return addr.toString({ bounceable: true, urlSafe: true });
}

function eqAddr(a, b) {
  return a.equals(b);
}

function loadAddrSafe(slice, label) {
  try {
    return slice.loadAddress();
  } catch (e) {
    throw new Error(`Failed to load address for ${label}: ${e?.message || e}`);
  }
}

async function getAccountState(client, address) {
  const res = await client.api.getAddressInformation(address);
  return res;
}

async function getData(client, address) {
  const res = await client.api.callGetMethod(address, 'get_contract_data', []);
  return res;
}

function cellFromToncenterStackItem(item) {
  if (!item) throw new Error('Empty stack item');
  if (item.type === 'cell' || item.type === 'slice') {
    const b64 = item.cell || item.value;
    if (!b64) throw new Error('Missing cell payload in stack item');
    return Cell.fromBase64(b64);
  }
  if (item.type === 'num') {
    throw new Error('Unexpected numeric stack item where cell was expected');
  }
  throw new Error(`Unsupported stack item type: ${item.type}`);
}

function readContractDataCell(rootCell) {
  const root = rootCell.beginParse();

  const admin = loadAddrSafe(root, 'root.admin');
  const enabled = root.loadUint(1);
  const lastHash = root.loadUintBig(256);

  if (root.remainingRefs !== 1) {
    throw new Error(`Expected 1 root ref, got ${root.remainingRefs}`);
  }

  const vaultsA = root.loadRef().beginParse();

  const treasury = loadAddrSafe(vaultsA, 'vaultsA.treasury');
  const ema = loadAddrSafe(vaultsA, 'vaultsA.ema');
  const emx = loadAddrSafe(vaultsA, 'vaultsA.emx');

  if (vaultsA.remainingRefs !== 1) {
    throw new Error(`Expected 1 vaultsA ref, got ${vaultsA.remainingRefs}`);
  }

  const vaultsB = vaultsA.loadRef().beginParse();

  const ems = loadAddrSafe(vaultsB, 'vaultsB.ems');
  const wems = loadAddrSafe(vaultsB, 'vaultsB.wems');
  const usdt = loadAddrSafe(vaultsB, 'vaultsB.usdt');

  return {
    admin,
    enabled,
    lastHash,
    treasury,
    ema,
    emx,
    ems,
    wems,
    usdt,
  };
}

function compareField(label, actual, expected) {
  const ok = eqAddr(actual, expected);
  console.log(`${ok ? 'OK' : 'FAIL'} ${label}`);
  console.log(`  actual:   ${norm(actual)}`);
  console.log(`  expected: ${norm(expected)}`);
  return ok;
}

async function main() {
  const client = new TonClient({
    endpoint: ENV.endpoint,
    apiKey: ENV.apiKey || undefined,
  });

  const distributor = parseFriendly(ENV.claimDistributor, 'CLAIM_DISTRIBUTOR');
  const treasury = parseFriendly(ENV.treasury, 'TON_TREASURY_ADDRESS');
  const ema = parseFriendly(ENV.ema, 'EMA_WALLET');
  const emx = parseFriendly(ENV.emx, 'EMX_WALLET');
  const ems = parseFriendly(ENV.ems, 'EMS_WALLET');
  const wems = parseFriendly(ENV.wems, 'WEMS_WALLET');
  const usdt = parseFriendly(ENV.usdt, 'USDT_WALLET');

  console.log('=== CLAIM DISTRIBUTOR VERIFY ===');
  console.log(`CLAIM_DISTRIBUTOR=${norm(distributor)}`);
  console.log('');

  const account = await getAccountState(client, distributor);
  console.log('=== ACCOUNT STATE ===');
  console.log(`state=${account.state}`);
  console.log(`balance=${account.balance}`);
  console.log(`code=${account.code ? 'present' : 'missing'}`);
  console.log(`data=${account.data ? 'present' : 'missing'}`);
  console.log('');

  if (account.state !== 'active') {
    throw new Error(`Contract is not active. state=${account.state}`);
  }
  if (!account.data) {
    throw new Error('Active contract has no data cell');
  }

  const rootCell = Cell.fromBase64(account.data);
  const cfg = readContractDataCell(rootCell);

  console.log('=== ON-CHAIN CONFIG ===');
  console.log(`admin=${norm(cfg.admin)}`);
  console.log(`enabled=${cfg.enabled}`);
  console.log(`last_hash=${cfg.lastHash.toString()}`);
  console.log(`treasury=${norm(cfg.treasury)}`);
  console.log(`ema=${norm(cfg.ema)}`);
  console.log(`emx=${norm(cfg.emx)}`);
  console.log(`ems=${norm(cfg.ems)}`);
  console.log(`wems=${norm(cfg.wems)}`);
  console.log(`usdt=${norm(cfg.usdt)}`);
  console.log('');

  let failed = 0;

  if (!compareField('admin', cfg.admin, treasury)) failed++;
  if (cfg.enabled !== 1) {
    console.log('FAIL enabled');
    console.log(`  actual:   ${cfg.enabled}`);
    console.log('  expected: 1');
    failed++;
  } else {
    console.log('OK enabled');
    console.log('  actual:   1');
    console.log('  expected: 1');
  }
  console.log(`OK last_hash`);
  console.log(`  actual:   ${cfg.lastHash.toString()}`);
  console.log(`  expected: 0`);
  if (cfg.lastHash !== 0n) {
    console.log('WARN last_hash is non-zero; this may be fine only if state changed after deploy');
  }

  if (!compareField('treasury', cfg.treasury, treasury)) failed++;
  if (!compareField('ema', cfg.ema, ema)) failed++;
  if (!compareField('emx', cfg.emx, emx)) failed++;
  if (!compareField('ems', cfg.ems, ems)) failed++;
  if (!compareField('wems', cfg.wems, wems)) failed++;
  if (!compareField('usdt', cfg.usdt, usdt)) failed++;

  console.log('');
  if (failed > 0) {
    console.log(`VERIFY_RESULT=FAIL (${failed} mismatches)`);
    process.exit(2);
  }

  console.log('VERIFY_RESULT=OK');
  console.log('');
  console.log('SAFE_NEXT_STEP=prepare first tiny controlled claim test');
}
main().catch((err) => {
  console.error('VERIFY_FATAL');
  console.error(err?.stack || err?.message || String(err));
  process.exit(1);
});
EOMJS

echo "[INFO] Running ClaimDistributor verify..."
node "$VERIFY_JS"
RC=$?
rm -f "$VERIFY_JS"
exit $RC
