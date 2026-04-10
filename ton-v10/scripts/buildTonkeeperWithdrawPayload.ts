#!/usr/bin/env tsx
/**
 * /var/www/html/public/rwa/ton-v10/scripts/buildTonkeeperWithdrawPayload.ts
 * Version: v1.0.0-20260331
 *
 * Purpose:
 * - build Tonkeeper / TonConnect payload for collection admin withdraw
 * - NO server mnemonic required
 * - user signs with Tonkeeper from admin wallet
 *
 * Usage:
 *   npx tsx scripts/buildTonkeeperWithdrawPayload.ts --amount-ton 0.05
 *   npx tsx scripts/buildTonkeeperWithdrawPayload.ts --amount-ton 0.05 --send-ton 0.08
 *   npx tsx scripts/buildTonkeeperWithdrawPayload.ts --amount-ton 0.05 --collection EQ...
 *
 * Output:
 * - recipient
 * - amount_nano (admin gas attached to collection call)
 * - payload_b64
 * - tonconnect.messages[0]
 * - tonkeeper_deeplink
 */

import { beginCell, Cell, toNano, Address } from 'ton-core';
import * as dotenv from 'dotenv';

dotenv.config({ path: '/var/www/secure/.env' });

function argValue(name: string, def = ''): string {
  const i = process.argv.indexOf(name);
  if (i >= 0 && i + 1 < process.argv.length) return String(process.argv[i + 1]).trim();
  return def;
}

function env(name: string, def = ''): string {
  const v = process.env[name];
  return typeof v === 'string' && v.trim() !== '' ? v.trim() : def;
}

function fail(msg: string, extra: Record<string, unknown> = {}): never {
  console.error(JSON.stringify({ ok: false, error: msg, ...extra }, null, 2));
  process.exit(1);
}

function nanoToTonStr(v: bigint): string {
  const sign = v < 0n ? '-' : '';
  const a = v < 0n ? -v : v;
  const whole = a / 1000000000n;
  const frac = (a % 1000000000n).toString().padStart(9, '0').replace(/0+$/, '');
  return frac ? `${sign}${whole}.${frac}` : `${sign}${whole}`;
}

function queryId(): bigint {
  return BigInt(Date.now()) * 1000000n + BigInt(Math.floor(Math.random() * 1000000));
}

function cellToB64(cell: Cell): string {
  return cell.toBoc({ idx: false }).toString('base64');
}

/**
 * IMPORTANT
 * These 2 opcode constants are isolated here on purpose.
 * If your deployed collection wrapper uses different exact admin opcodes,
 * patch ONLY these constants.
 */
const OP_WITHDRAW = 0x57445448; // "WDTH"
const OP_SWEEP    = 0x53575050; // "SWPP"

function buildWithdrawBody(amountNano: bigint, qid: bigint): Cell {
  return beginCell()
    .storeUint(OP_WITHDRAW, 32)
    .storeUint(qid, 64)
    .storeCoins(amountNano)
    .endCell();
}

function buildSweepBody(qid: bigint): Cell {
  return beginCell()
    .storeUint(OP_SWEEP, 32)
    .storeUint(qid, 64)
    .endCell();
}

function makeTonkeeperLink(address: string, amountNano: string, payloadB64: string): string {
  const base = `https://app.tonkeeper.com/transfer/${encodeURIComponent(address)}`;
  const qs = new URLSearchParams({
    amount: amountNano,
    bin: payloadB64,
  });
  return `${base}?${qs.toString()}`;
}

function main(): void {
  const mode = (argValue('--mode', 'withdraw') || 'withdraw').toLowerCase();
  if (!['withdraw', 'sweep'].includes(mode)) {
    fail('BAD_MODE', { mode, allowed: ['withdraw', 'sweep'] });
  }

  const collectionStr = argValue('--collection', env('V10_COLLECTION_ADDRESS', 'EQBHMH4g3xy-uOJpPN0XGcDhMifdKio_kYWk3uywaXz2aUrY'));
  const treasuryStr   = argValue('--treasury', env('TREASURY_ADDRESS', 'UQDRA7wHzvXPhnIq0tP1aF36Y0I4H3alYWZKj_LFldB1XzCL'));
  const amountTon     = argValue('--amount-ton', '0.05');
  const sendTon       = argValue('--send-ton', env('V10_ADMIN_CALL_TON', '0.08'));

  let collection: Address;
  let treasury: Address;
  try {
    collection = Address.parse(collectionStr);
    treasury = Address.parse(treasuryStr);
  } catch (e) {
    fail('BAD_ADDRESS', { message: e instanceof Error ? e.message : String(e) });
  }

  const qid = queryId();
  const sendNano = BigInt(toNano(sendTon).toString());

  let withdrawNano = 0n;
  let body: Cell;

  if (mode === 'withdraw') {
    withdrawNano = BigInt(toNano(amountTon).toString());
    if (withdrawNano <= 0n) {
      fail('BAD_WITHDRAW_AMOUNT', { amount_ton: amountTon });
    }
    body = buildWithdrawBody(withdrawNano, qid);
  } else {
    body = buildSweepBody(qid);
  }

  const payloadB64 = cellToB64(body);
  const recipient = collection.toString();
  const amountNanoStr = sendNano.toString();

  const tonconnect = {
    validUntil: Math.floor(Date.now() / 1000) + 900,
    messages: [
      {
        address: recipient,
        amount: amountNanoStr,
        payload: payloadB64,
      },
    ],
  };

  const out = {
    ok: true,
    mode,
    note: mode === 'withdraw'
      ? 'Send this from Tonkeeper using the ADMIN wallet to call collection withdraw'
      : 'Send this from Tonkeeper using the ADMIN wallet to call collection sweep',
    collection_address: recipient,
    treasury_address: treasury.toString(),
    admin_send_ton: nanoToTonStr(sendNano),
    admin_send_nano: amountNanoStr,
    withdraw_ton: mode === 'withdraw' ? nanoToTonStr(withdrawNano) : 'excess_only',
    withdraw_nano: mode === 'withdraw' ? withdrawNano.toString() : 'excess_only',
    opcode: mode === 'withdraw' ? `0x${OP_WITHDRAW.toString(16)}` : `0x${OP_SWEEP.toString(16)}`,
    query_id: qid.toString(),
    payload_b64: payloadB64,
    tonconnect,
    tonkeeper_deeplink: makeTonkeeperLink(recipient, amountNanoStr, payloadB64),
    how_to_use: [
      'Open Tonkeeper with the ADMIN wallet',
      'Use tonkeeper_deeplink or TonConnect message',
      'Confirm the tx to the COLLECTION contract',
      'After sending, re-check collection balance and treasury balance',
    ],
    warning: 'Do NOT send TON directly to treasury. Send admin message to collection contract only.',
  };

  console.log(JSON.stringify(out, null, 2));
}

main();
