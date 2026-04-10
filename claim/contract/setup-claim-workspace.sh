#!/usr/bin/env bash
set -e

ROOT="/var/www/html/public/rwa/claim/contract"

echo "Creating folders..."
mkdir -p "$ROOT/contracts" "$ROOT/wrappers" "$ROOT/scripts"

############################################
# ClaimDistributor.fc
############################################
cat > "$ROOT/contracts/ClaimDistributor.fc" <<'FC'
#include "/opt/ton-stdlib/stdlib.fc";

const int OP_EXECUTE = 0x434C4D32;

const int TOKEN_EMA  = 1;
const int TOKEN_EMX  = 2;
const int TOKEN_EMS  = 3;
const int TOKEN_WEMS = 4;
const int TOKEN_USDT = 5;

(slice, int, int, cell) load_data() inline {
    var ds = get_data().begin_parse();
    return (
        ds~load_msg_addr(),
        ds~load_uint(1),
        ds~load_uint(256),
        ds~load_ref()
    );
}

() save_data(slice admin, int enabled, int last_hash, cell wallets) impure inline {
    set_data(
        begin_cell()
            .store_slice(admin)
            .store_uint(enabled,1)
            .store_uint(last_hash,256)
            .store_ref(wallets)
        .end_cell()
    );
}

slice get_wallet(cell dict, int code) inline {
    var ds = dict.begin_parse();
    var w1 = ds~load_msg_addr();
    var w2 = ds~load_msg_addr();
    var w3 = ds~load_msg_addr();
    var w4 = ds~load_msg_addr();
    var w5 = ds~load_msg_addr();

    if (code == TOKEN_EMA) return w1;
    if (code == TOKEN_EMX) return w2;
    if (code == TOKEN_EMS) return w3;
    if (code == TOKEN_WEMS) return w4;
    if (code == TOKEN_USDT) return w5;

    throw(401);
}

int calc_hash(int qid, int token, slice to, int amount, slice ref) inline {
    return cell_hash(
        begin_cell()
            .store_uint(qid,64)
            .store_uint(token,8)
            .store_slice(to)
            .store_coins(amount)
            .store_slice(ref)
        .end_cell()
    );
}

() send_jetton(slice wallet, int amount, slice to, slice ref) impure inline {

    cell body = begin_cell()
        .store_uint(0x0f8a7ea5,32)
        .store_uint(0,64)
        .store_coins(amount)
        .store_slice(to)
        .store_slice(to)
        .store_uint(0,1)
        .store_coins(1)
        .store_uint(1,1)
        .store_ref(begin_cell().store_slice(ref).end_cell())
    .end_cell();

    var msg = begin_cell()
        .store_uint(0x18,6)
        .store_slice(wallet)
        .store_coins(5000000)
        .store_uint(0,1+4+4+64+32+1+1)
        .store_ref(body)
    .end_cell();

    send_raw_message(msg,1);
}

() recv_internal(int balance, int value, cell in_msg, slice body) impure {

    if (body.slice_empty?()) return ();

    var cs = in_msg.begin_parse();
    cs~load_uint(4);
    slice sender = cs~load_msg_addr();

    var (admin, enabled, last_hash, dict) = load_data();

    throw_unless(403, equal_slices(sender, admin));
    throw_unless(404, enabled == 1);

    int op = body~load_uint(32);
    int qid = body~load_uint(64);

    if (op == OP_EXECUTE) {

        int token = body~load_uint(8);
        slice to = body~load_msg_addr();
        int amount = body~load_coins();
        slice ref = body;

        int h = calc_hash(qid, token, to, amount, ref);
        throw_unless(405, h != last_hash);

        slice wallet = get_wallet(dict, token);

        send_jetton(wallet, amount, to, ref);

        save_data(admin, enabled, h, dict);
        return ();
    }

    throw(400);
}
FC

############################################
# compile wrapper
############################################
cat > "$ROOT/wrappers/ClaimDistributor.compile.ts" <<'TS'
export const compile = {
  lang: 'func',
  sources: ['contracts/ClaimDistributor.fc'],
};
TS

############################################
# deploy script
############################################
cat > "$ROOT/scripts/deployClaimDistributor.ts" <<'TS'
import { Address, beginCell } from "ton-core";
import { compile } from "../wrappers/ClaimDistributor.compile";

export async function run(provider) {

  const admin = Address.parse(process.env.TON_TREASURY_ADDRESS);

  const wallets = beginCell()
    .storeAddress(Address.parse(process.env.EMA_WALLET))
    .storeAddress(Address.parse(process.env.EMX_WALLET))
    .storeAddress(Address.parse(process.env.EMS_WALLET))
    .storeAddress(Address.parse(process.env.WEMS_WALLET))
    .storeAddress(Address.parse(process.env.USDT_WALLET))
    .endCell();

  const data = beginCell()
    .storeAddress(admin)
    .storeUint(1,1)
    .storeUint(0,256)
    .storeRef(wallets)
    .endCell();

  const code = await compile();

  const contract = provider.open({
    code,
    data
  });

  await contract.deploy(provider.sender(), { value: "0.2" });

  console.log("DEPLOYED:", contract.address.toString());
}
TS

############################################
# sendClaim script
############################################
cat > "$ROOT/scripts/sendClaim.ts" <<'TS'
import { Address, beginCell } from "ton-core";

export async function run(provider, args) {

  const contract = provider.open(
    Address.parse(process.env.CLAIM_DISTRIBUTOR)
  );

  const body = beginCell()
    .storeUint(0x434C4D32,32)
    .storeUint(Date.now(),64)
    .storeUint(Number(args.token),8)
    .storeAddress(Address.parse(args.to))
    .storeCoins(BigInt(args["amount-units"]))
    .storeStringTail(args["claim-ref"])
    .endCell();

  await contract.send(provider.sender(), {
    value: "0.05",
    body
  });

  console.log("CLAIM SENT");
}
TS

############################################
# package.json
############################################
cat > "$ROOT/package.json" <<'JSON'
{
  "name": "claim-distributor",
  "version": "1.0.0",
  "dependencies": {
    "ton-core": "^0.53.0",
    "@ton/blueprint": "^0.8.0"
  }
}
JSON

echo "Workspace created successfully."
