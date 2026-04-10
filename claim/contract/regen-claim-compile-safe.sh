#!/usr/bin/env bash
set -euo pipefail

ROOT="/var/www/html/public/rwa/claim/contract"
mkdir -p "$ROOT/contracts" "$ROOT/scripts"

cat > "$ROOT/contracts/ClaimDistributor.fc" <<'FC'
#include "/opt/ton-stdlib/stdlib.fc";

const int OP_EXECUTE = 0x434C4D32;

const int TOKEN_EMA  = 1;
const int TOKEN_EMX  = 2;
const int TOKEN_EMS  = 3;
const int TOKEN_WEMS = 4;
const int TOKEN_USDT = 5;

(slice, int, int, cell) load_data() inline {
    slice ds = get_data().begin_parse();
    slice admin = ds~load_msg_addr();
    int enabled = ds~load_uint(1);
    int last_hash = ds~load_uint(256);
    cell wallets = ds~load_ref();
    return (admin, enabled, last_hash, wallets);
}

() save_data(slice admin, int enabled, int last_hash, cell wallets) impure inline {
    cell data = begin_cell()
        .store_slice(admin)
        .store_uint(enabled, 1)
        .store_uint(last_hash, 256)
        .store_ref(wallets)
    .end_cell();
    set_data(data);
}

slice get_wallet(cell wallets, int token_code) inline {
    slice ds = wallets.begin_parse();
    slice w1 = ds~load_msg_addr();
    slice w2 = ds~load_msg_addr();
    slice w3 = ds~load_msg_addr();
    slice w4 = ds~load_msg_addr();
    slice w5 = ds~load_msg_addr();

    if (token_code == TOKEN_EMA) {
        return w1;
    }
    if (token_code == TOKEN_EMX) {
        return w2;
    }
    if (token_code == TOKEN_EMS) {
        return w3;
    }
    if (token_code == TOKEN_WEMS) {
        return w4;
    }
    if (token_code == TOKEN_USDT) {
        return w5;
    }

    throw(401);
    return w1;
}

int calc_hash(int qid, int token_code, slice to_addr, int amount, int ref_hash) inline {
    cell c = begin_cell()
        .store_uint(qid, 64)
        .store_uint(token_code, 8)
        .store_slice(to_addr)
        .store_coins(amount)
        .store_uint(ref_hash, 256)
    .end_cell();
    return cell_hash(c);
}

cell build_jetton_body(int amount, slice to_addr, int ref_hash) inline {
    cell fwd = begin_cell()
        .store_uint(ref_hash, 256)
    .end_cell();

    return begin_cell()
        .store_uint(0x0f8a7ea5, 32)
        .store_uint(0, 64)
        .store_coins(amount)
        .store_slice(to_addr)
        .store_slice(to_addr)
        .store_uint(0, 1)
        .store_coins(1)
        .store_uint(1, 1)
        .store_ref(fwd)
    .end_cell();
}

() send_jetton(slice wallet_addr, int amount, slice to_addr, int ref_hash) impure inline {
    cell body = build_jetton_body(amount, to_addr, ref_hash);

    cell msg = begin_cell()
        .store_uint(0x18, 6)
        .store_slice(wallet_addr)
        .store_coins(5000000)
        .store_uint(0, 1 + 4 + 4 + 64 + 32 + 1 + 1)
        .store_ref(body)
    .end_cell();

    send_raw_message(msg, 1);
}

() recv_internal(int my_balance, int msg_value, cell in_msg_full, slice in_msg_body) impure {
    if (in_msg_body.slice_empty?()) {
        return ();
    }

    slice cs = in_msg_full.begin_parse();
    int flags = cs~load_uint(4);
    if (flags & 1) {
        return ();
    }

    slice sender = cs~load_msg_addr();

    var (admin, enabled, last_hash, wallets) = load_data();

    throw_unless(403, equal_slices(sender, admin));
    throw_unless(404, enabled == 1);

    int op = in_msg_body~load_uint(32);
    int qid = in_msg_body~load_uint(64);

    if (op != OP_EXECUTE) {
        throw(400);
    }

    int token_code = in_msg_body~load_uint(8);
    slice to_addr = in_msg_body~load_msg_addr();
    int amount = in_msg_body~load_coins();
    int ref_hash = in_msg_body~load_uint(256);

    throw_unless(406, amount > 0);

    int h = calc_hash(qid, token_code, to_addr, amount, ref_hash);
    throw_unless(405, h != last_hash);

    slice wallet_addr = get_wallet(wallets, token_code);

    send_jetton(wallet_addr, amount, to_addr, ref_hash);

    save_data(admin, enabled, h, wallets);
}
FC

cat > "$ROOT/scripts/sendClaim.ts" <<'TS'
import { Address, beginCell, Cell, SendMode } from "ton-core";
import { sha256_sync } from "ton-crypto";
import { NetworkProvider } from "@ton/blueprint";

const OP_EXECUTE = 0x434c4d32;

function needEnv(name: string): string {
  const v = process.env[name]?.trim();
  if (!v) throw new Error(`Missing env: ${name}`);
  return v;
}

function needArg(args: string[], name: string): string {
  const prefix = `--${name}=`;
  const hit = args.find((a) => a.startsWith(prefix));
  if (!hit) throw new Error(`Missing required arg: --${name}=...`);
  const value = hit.slice(prefix.length).trim();
  if (!value) throw new Error(`Empty required arg: --${name}`);
  return value;
}

function parseTokenCode(raw: string): number {
  const n = Number(raw);
  if (!Number.isInteger(n) || n < 1 || n > 5) {
    throw new Error(`Invalid token code: ${raw}. Allowed: 1=EMA 2=EMX 3=EMS 4=WEMS 5=USDT`);
  }
  return n;
}

function parseAmountUnits(raw: string): bigint {
  if (!/^\d+$/.test(raw)) throw new Error(`Invalid amount-units: ${raw}`);
  const v = BigInt(raw);
  if (v <= 0n) throw new Error("amount-units must be > 0");
  return v;
}

function tokenSymbol(code: number): string {
  switch (code) {
    case 1: return "EMA";
    case 2: return "EMX";
    case 3: return "EMS";
    case 4: return "WEMS";
    case 5: return "USDT";
    default: return "UNKNOWN";
  }
}

function makeQueryId(): bigint {
  return BigInt(Date.now()) & ((1n << 64n) - 1n);
}

function claimRefHash(ref: string): bigint {
  const hex = sha256_sync(Buffer.from(ref)).toString("hex");
  return BigInt("0x" + hex);
}

function buildExecuteBody(params: {
  queryId: bigint;
  tokenCode: number;
  to: Address;
  amountUnits: bigint;
  refHash: bigint;
}): Cell {
  return beginCell()
    .storeUint(OP_EXECUTE, 32)
    .storeUint(params.queryId, 64)
    .storeUint(params.tokenCode, 8)
    .storeAddress(params.to)
    .storeCoins(params.amountUnits)
    .storeUint(params.refHash, 256)
    .endCell();
}

type OpenedSender = {
  send: (
    sender: ReturnType<NetworkProvider["sender"]>,
    args: { value: string; sendMode?: number; body?: Cell }
  ) => Promise<unknown>;
};

export async function run(provider: NetworkProvider): Promise<void> {
  const rawArgs = process.argv.slice(2);

  const contractAddr = Address.parse(needEnv("CLAIM_DISTRIBUTOR"));
  const claimRef = needArg(rawArgs, "claim-ref");
  const to = Address.parse(needArg(rawArgs, "to"));
  const amountUnits = parseAmountUnits(needArg(rawArgs, "amount-units"));
  const tokenCode = parseTokenCode(needArg(rawArgs, "token"));
  const queryId = makeQueryId();
  const refHash = claimRefHash(claimRef);

  const body = buildExecuteBody({
    queryId,
    tokenCode,
    to,
    amountUnits,
    refHash,
  });

  const contract = provider.open({
    address: contractAddr,
  }) as OpenedSender;

  console.log("==================================================");
  console.log("ClaimDistributor Execute Preview");
  console.log("==================================================");
  console.log("Contract:   ", contractAddr.toString());
  console.log("Claim Ref:  ", claimRef);
  console.log("Ref Hash:   ", "0x" + refHash.toString(16));
  console.log("Token:      ", tokenSymbol(tokenCode), `(${tokenCode})`);
  console.log("To:         ", to.toString());
  console.log("Amount:     ", amountUnits.toString());
  console.log("Query ID:   ", queryId.toString());
  console.log("==================================================");

  await contract.send(provider.sender(), {
    value: "0.05",
    sendMode: SendMode.PAY_GAS_SEPARATELY,
    body,
  });

  console.log(JSON.stringify({
    ok: true,
    claim_ref: claimRef,
    ref_hash: "0x" + refHash.toString(16),
    token_code: tokenCode,
    token: tokenSymbol(tokenCode),
    to: to.toString(),
    amount_units: amountUnits.toString(),
    query_id: queryId.toString(),
    contract: contractAddr.toString(),
  }));
}
TS

echo "Compile-safe regen complete."
echo " - $ROOT/contracts/ClaimDistributor.fc"
echo " - $ROOT/scripts/sendClaim.ts"
