/**
 * /var/www/html/public/rwa/ton/scripts/buildV8PublicMintPayload.ts
 * Version: v8.0.0-20260330-router-payload-builder
 */

import 'dotenv/config';
import { Address, beginCell, toNano } from '@ton/core';

const OP_PUBLIC_MINT = 0x504d4e54;

function argValue(name: string): string {
  const flag = `--${name}`;
  const idx = process.argv.indexOf(flag);
  if (idx === -1 || idx + 1 >= process.argv.length) {
    throw new Error(`Missing CLI arg: ${flag}`);
  }
  return String(process.argv[idx + 1]).trim();
}

function optionalArg(name: string, fallback: string): string {
  const flag = `--${name}`;
  const idx = process.argv.indexOf(flag);
  if (idx === -1 || idx + 1 >= process.argv.length) {
    return fallback;
  }
  return String(process.argv[idx + 1]).trim();
}

function envRequired(name: string): string {
  const v = process.env[name];
  if (!v || !v.trim()) throw new Error(`Missing env: ${name}`);
  return v.trim();
}

async function main() {
  const certUid = argValue('cert_uid');
  const owner = Address.parse(argValue('owner'));
  const suffix = argValue('suffix');

  const routerAddress = Address.parse(envRequired('V8_ROUTER_ADDRESS'));
  const queryId = BigInt(optionalArg('query_id', String(Date.now())));
  const txValueTon = optionalArg('tx_value', process.env.V8_PUBLIC_MIN_ATTACH_TON || '0.50');

  const itemContent = beginCell()
    .storeBuffer(Buffer.from(suffix, 'utf8'))
    .endCell();

  const body = beginCell()
    .storeUint(OP_PUBLIC_MINT, 32)
    .storeUint(queryId, 64)
    .storeAddress(owner)
    .storeRef(itemContent)
    .endCell();

  process.stdout.write(JSON.stringify({
    ok: true,
    cert_uid: certUid,
    router_address: routerAddress.toString(),
    recipient: routerAddress.toString(),
    owner: owner.toString(),
    query_id: queryId.toString(),
    tx_value_ton: txValueTon,
    tx_value_nano: toNano(txValueTon).toString(),
    item_content_suffix: suffix,
    payload_b64: body.toBoc().toString('base64'),
    op_code: OP_PUBLIC_MINT,
    op_code_hex: '0x504d4e54',
  }));
}

main().catch((err) => {
  console.error('[buildV8PublicMintPayload] fatal:', err);
  process.exit(1);
});
