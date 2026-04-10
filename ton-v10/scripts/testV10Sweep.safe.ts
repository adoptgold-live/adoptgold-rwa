#!/usr/bin/env tsx
/**
 * /var/www/html/public/rwa/ton-v10/scripts/testV10Sweep.safe.ts
 * Version: v1.0.0-20260331-safe
 *
 * Safe sweep/withdraw tester for V10 collection.
 *
 * Features:
 * - reads live collection balance
 * - enforces reserve buffer locally before sending
 * - supports:
 *     --mode withdraw  (send exact amount)
 *     --mode sweep     (send all excess above reserve)
 * - prints before/after recommendation
 * - can run dry-run by default
 *
 * Examples:
 *   npx tsx scripts/testV10Sweep.safe.ts --mode withdraw --amount-ton 0.05 --dry-run 0
 *   npx tsx scripts/testV10Sweep.safe.ts --mode sweep --dry-run 0
 */

import { Address, beginCell, Cell, toNano } from 'ton-core';
import { TonClient4, WalletContractV4, internal } from 'ton';
import * as dotenv from 'dotenv';
import { mnemonicToPrivateKey } from 'ton-crypto';

dotenv.config({ path: '/var/www/secure/.env' });

type Mode = 'withdraw' | 'sweep';

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

function tonToNanoBig(v: string): bigint {
  try {
    return BigInt(toNano(v).toString());
  } catch {
    fail('BAD_TON_AMOUNT', { value: v });
  }
}

function nanoToTonStr(v: bigint): string {
  const whole = v / 1000000000n;
  const frac = (v % 1000000000n).toString().padStart(9, '0').replace(/0+$/, '');
  return frac ? `${whole}.${frac}` : `${whole}`;
}

function parseBoolish(v: string, def: boolean): boolean {
  if (v === '') return def;
  return ['1', 'true', 'yes', 'y', 'on'].includes(v.toLowerCase());
}

function buildWithdrawBody(amountNano: bigint): Cell {
  // op_withdraw() with amount coins
  // opcode must match contract wrapper expectations:
  // here we use the locked contract op pattern already in your V10 source path.
  // If your wrapper uses a different opcode constant, update this one only.
  return beginCell()
    .storeUint(0x57445448, 32) // "WDTH" placeholder/opcode used by admin tools; adjust if wrapper has exact constant
    .storeUint(BigInt(Date.now()), 64)
    .storeCoins(amountNano)
    .endCell();
}

function buildSweepBody(): Cell {
  return beginCell()
    .storeUint(0x53575050, 32) // "SWPP" placeholder/opcode used by admin tools; adjust if wrapper has exact constant
    .storeUint(BigInt(Date.now()), 64)
    .endCell();
}

async function main(): Promise<void> {
  const mode = (argValue('--mode', 'withdraw') || 'withdraw') as Mode;
  if (!['withdraw', 'sweep'].includes(mode)) {
    fail('BAD_MODE', { mode, allowed: ['withdraw', 'sweep'] });
  }

  const rpc = env('TON_RPC_URL', env('TONCENTER_RPC_URL', 'https://mainnet-v4.tonhubapi.com'));
  const collectionStr = env('V10_COLLECTION_ADDRESS', 'EQBHMH4g3xy-uOJpPN0XGcDhMifdKio_kYWk3uywaXz2aUrY');
  const treasuryStr = env('TREASURY_ADDRESS', 'UQDRA7wHzvXPhnIq0tP1aF36Y0I4H3alYWZKj_LFldB1XzCL');
  const reserveTon = argValue('--reserve-ton', env('V10_SWEEP_RESERVE_TON', '0.20'));
  const requestedTon = argValue('--amount-ton', '0.05');
  const dryRun = parseBoolish(argValue('--dry-run', '1'), true);

  const mnemonic = env('TON_MNEMONIC', '');
  const adminKey = env('TON_ADMIN_MNEMONIC', mnemonic);
  if (!adminKey) {
    fail('MISSING_TON_MNEMONIC', {
      hint: 'Set TON_ADMIN_MNEMONIC or TON_MNEMONIC in /var/www/secure/.env',
    });
  }

  const client = new TonClient4({ endpoint: rpc });
  const collection = Address.parse(collectionStr);
  const treasury = Address.parse(treasuryStr);

  const reserveNano = tonToNanoBig(reserveTon);
  const requestedNano = tonToNanoBig(requestedTon);

  const last = await client.getLastBlock();
  const acct = await client.getAccount(last.last.seqno, collection);

  if (acct.account.state.type !== 'active') {
    fail('COLLECTION_NOT_ACTIVE', { state: acct.account.state.type });
  }

  const balanceNano = BigInt(acct.account.balance.coins);
  const safeSweepable = balanceNano > reserveNano ? (balanceNano - reserveNano) : 0n;

  let sendAmountNano = 0n;
  let body: Cell;

  if (mode === 'withdraw') {
    if (requestedNano <= 0n) fail('WITHDRAW_AMOUNT_MUST_BE_POSITIVE');
    if ((balanceNano - requestedNano) < reserveNano) {
      fail('WITHDRAW_REJECTED_BY_LOCAL_GUARD', {
        balance_ton: nanoToTonStr(balanceNano),
        reserve_ton: nanoToTonStr(reserveNano),
        requested_ton: nanoToTonStr(requestedNano),
        max_safe_ton: nanoToTonStr(safeSweepable),
      });
    }
    sendAmountNano = requestedNano;
    body = buildWithdrawBody(sendAmountNano);
  } else {
    if (safeSweepable <= 0n) {
      fail('NOTHING_SWEEPABLE', {
        balance_ton: nanoToTonStr(balanceNano),
        reserve_ton: nanoToTonStr(reserveNano),
      });
    }
    sendAmountNano = 0n; // contract computes excess internally
    body = buildSweepBody();
  }

  const estAfter = mode === 'withdraw'
    ? (balanceNano - requestedNano)
    : reserveNano;

  const summary = {
    ok: true,
    mode,
    dry_run: dryRun,
    rpc,
    collection: collection.toString(),
    treasury: treasury.toString(),
    balance_nano: balanceNano.toString(),
    balance_ton: nanoToTonStr(balanceNano),
    reserve_nano: reserveNano.toString(),
    reserve_ton: nanoToTonStr(reserveNano),
    safe_sweepable_nano: safeSweepable.toString(),
    safe_sweepable_ton: nanoToTonStr(safeSweepable),
    requested_nano: mode === 'withdraw' ? requestedNano.toString() : '0',
    requested_ton: mode === 'withdraw' ? nanoToTonStr(requestedNano) : 'contract_excess_only',
    estimated_after_nano: estAfter.toString(),
    estimated_after_ton: nanoToTonStr(estAfter),
    recommendation:
      mode === 'withdraw'
        ? `Allowed. Safe max withdraw now is ${nanoToTonStr(safeSweepable)} TON`
        : `Sweep will attempt excess above reserve only`,
  };

  if (dryRun) {
    console.log(JSON.stringify(summary, null, 2));
    return;
  }

  const mn = adminKey.split(/[\s,]+/).filter(Boolean);
  if (mn.length < 12) {
    fail('BAD_TON_MNEMONIC', { words: mn.length });
  }

  const key = await mnemonicToPrivateKey(mn);
  const wallet = WalletContractV4.create({
    workchain: 0,
    publicKey: key.publicKey,
  });

  const openedWallet = client.open(wallet);
  const walletAcct = await client.getAccount(last.last.seqno, wallet.address);
  if (walletAcct.account.state.type !== 'active') {
    fail('ADMIN_WALLET_NOT_ACTIVE', {
      wallet: wallet.address.toString(),
      state: walletAcct.account.state.type,
    });
  }

  const seqno = await openedWallet.getSeqno();
  const sendValue = toNano('0.08'); // enough admin wallet gas, not collection amount

  await openedWallet.sendTransfer({
    secretKey: key.secretKey,
    seqno,
    messages: [
      internal({
        to: collection,
        value: sendValue,
        bounce: true,
        body,
      }),
    ],
  });

  console.log(JSON.stringify({
    ...summary,
    sent: true,
    admin_wallet: wallet.address.toString(),
    admin_seqno: seqno,
    admin_send_value_nano: sendValue.toString(),
    admin_send_value_ton: nanoToTonStr(BigInt(sendValue.toString())),
    next_step: 'Wait a few seconds, then re-run sweep-check.ts or account balance query',
  }, null, 2));
}

main().catch((err) => {
  fail('SWEEP_TEST_FATAL', {
    message: err instanceof Error ? err.message : String(err),
  });
});
