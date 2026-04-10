#!/usr/bin/env tsx
/**
 * /var/www/html/public/rwa/ton-v10/scripts/sweep-check.ts
 * Version: v1.0.0-20260331
 *
 * Purpose:
 * - read collection account balance
 * - estimate safe sweepable TON
 * - show recent account txs using supported toncenter endpoints
 * - optionally inspect treasury account too
 *
 * Usage:
 *   npx tsx scripts/sweep-check.ts
 *   npx tsx scripts/sweep-check.ts --collection EQ... --treasury UQ...
 *   npx tsx scripts/sweep-check.ts --reserve-ton 0.20 --limit 10
 */

type Json = Record<string, any>;

function argValue(name: string, def = ''): string {
  const i = process.argv.indexOf(name);
  if (i >= 0 && i + 1 < process.argv.length) return String(process.argv[i + 1]).trim();
  return def;
}

function hasArg(name: string): boolean {
  return process.argv.includes(name);
}

function env(name: string, def = ''): string {
  const v = process.env[name];
  return typeof v === 'string' && v.trim() !== '' ? v.trim() : def;
}

function fail(msg: string, extra: Json = {}): never {
  console.error(JSON.stringify({ ok: false, error: msg, ...extra }, null, 2));
  process.exit(1);
}

async function getJson(url: string, apiKey = ''): Promise<Json> {
  const headers: Record<string, string> = { Accept: 'application/json' };
  if (apiKey) headers['X-API-Key'] = apiKey;

  const res = await fetch(url, { headers });
  const text = await res.text();

  let json: Json = {};
  try {
    json = text ? JSON.parse(text) : {};
  } catch {
    json = { raw: text };
  }

  if (!res.ok) {
    return {
      ok: false,
      http_status: res.status,
      url,
      response: json,
    };
  }

  return { ok: true, ...json };
}

function nanoToTonStr(nano: string | number | bigint): string {
  try {
    const n = BigInt(String(nano));
    const sign = n < 0n ? '-' : '';
    const a = n < 0n ? -n : n;
    const whole = a / 1000000000n;
    const frac = (a % 1000000000n).toString().padStart(9, '0').replace(/0+$/, '');
    return frac ? `${sign}${whole}.${frac}` : `${sign}${whole}`;
  } catch {
    return String(nano);
  }
}

function tonToNanoStr(ton: string): string {
  const s = ton.trim();
  if (!/^\d+(\.\d+)?$/.test(s)) fail('BAD_TON_AMOUNT', { ton });
  const [w, f = ''] = s.split('.');
  const frac = (f + '000000000').slice(0, 9);
  return (BigInt(w) * 1000000000n + BigInt(frac)).toString();
}

function pickTxs(json: Json): any[] {
  if (Array.isArray(json.transactions)) return json.transactions;
  if (Array.isArray(json.result)) return json.result;
  if (Array.isArray(json.data)) return json.data;
  return [];
}

function summarizeTx(tx: any): Json {
  const hash =
    String(
      tx?.hash ??
      tx?.transaction_id?.hash ??
      tx?.in_msg?.hash ??
      ''
    ).trim();

  const lt = String(tx?.lt ?? tx?.transaction_id?.lt ?? '').trim();
  const utime = tx?.utime ?? tx?.now ?? null;

  const inMsg = tx?.in_msg ?? {};
  const value = String(inMsg?.value ?? tx?.value ?? tx?.amount ?? '').trim();
  const src = String(inMsg?.source ?? inMsg?.src ?? '').trim();
  const dst = String(inMsg?.destination ?? inMsg?.dst ?? '').trim();

  return {
    hash,
    lt,
    utime,
    amount_nano: value,
    amount_ton: value ? nanoToTonStr(value) : '',
    source: src,
    destination: dst,
  };
}

async function main(): Promise<void> {
  const collection =
    argValue('--collection', '') ||
    env('V10_COLLECTION_ADDRESS', '') ||
    'EQBHMH4g3xy-uOJpPN0XGcDhMifdKio_kYWk3uywaXz2aUrY';

  const treasury =
    argValue('--treasury', '') ||
    env('TREASURY_ADDRESS', '') ||
    'UQDRA7wHzvXPhnIq0tP1aF36Y0I4H3alYWZKj_LFldB1XzCL';

  const reserveTon = argValue('--reserve-ton', '0.20');
  const reserveNano = tonToNanoStr(reserveTon);
  const limit = Number(argValue('--limit', '10')) || 10;

  const base =
    env('TONCENTER_V3_BASE', '').replace(/\/+$/, '') ||
    'https://toncenter.com/api/v3';

  const apiKey =
    env('TONCENTER_API_KEY', '') ||
    env('TON_API_KEY', '');

  if (!collection) fail('MISSING_COLLECTION_ADDRESS');

  const accountUrl = `${base}/account?address=${encodeURIComponent(collection)}`;
  const account = await getJson(accountUrl, apiKey);
  if (!account.ok) fail('COLLECTION_ACCOUNT_FETCH_FAILED', account);

  const balanceNano = String(account.balance ?? '0');
  const balanceTon = nanoToTonStr(balanceNano);

  const sweepableNanoBig =
    BigInt(balanceNano || '0') > BigInt(reserveNano)
      ? BigInt(balanceNano || '0') - BigInt(reserveNano)
      : 0n;

  const txEndpoints = [
    `${base}/transactions?account=${encodeURIComponent(collection)}&limit=${limit}`,
    `${base}/blockchain/accounts/${encodeURIComponent(collection)}/txs?limit=${limit}`,
    `${base}/blockchain/accounts/${encodeURIComponent(collection)}/transactions?limit=${limit}`,
  ];

  let txFetch: Json = { ok: false };
  for (const url of txEndpoints) {
    const j = await getJson(url, apiKey);
    if (j.ok) {
      txFetch = { ...j, _used_url: url };
      break;
    }
    txFetch = { ...j, _used_url: url };
  }

  let treasuryFetch: Json = { ok: false, skipped: true };
  if (treasury) {
    const treasuryUrl = `${base}/account?address=${encodeURIComponent(treasury)}`;
    treasuryFetch = await getJson(treasuryUrl, apiKey);
  }

  const out: Json = {
    ok: true,
    check: 'sweep-check',
    collection: {
      address: collection,
      status: account.status ?? '',
      balance_nano: balanceNano,
      balance_ton: balanceTon,
      last_transaction_lt: String(account.last_transaction_lt ?? ''),
      last_transaction_hash: String(account.last_transaction_hash ?? ''),
    },
    reserve: {
      reserve_ton: reserveTon,
      reserve_nano: reserveNano,
    },
    sweep: {
      safe_sweepable_nano: sweepableNanoBig.toString(),
      safe_sweepable_ton: nanoToTonStr(sweepableNanoBig),
      recommendation:
        sweepableNanoBig > 0n
          ? 'Sweep at or below safe_sweepable_ton'
          : 'Do not sweep yet; balance is below reserve',
    },
    recent_collection_txs: {
      ok: !!txFetch.ok,
      endpoint_used: String(txFetch._used_url ?? ''),
      count: pickTxs(txFetch).length,
      items: pickTxs(txFetch).slice(0, limit).map(summarizeTx),
      note: txFetch.ok ? '' : 'Transaction endpoint unavailable or rate-limited',
      error: txFetch.ok ? '' : String(txFetch.response?.error ?? txFetch.response?.result ?? txFetch.error ?? ''),
      http_status: txFetch.ok ? 200 : Number(txFetch.http_status ?? 0),
    },
    treasury: treasury
      ? {
          address: treasury,
          ok: !!treasuryFetch.ok,
          balance_nano: treasuryFetch.ok ? String(treasuryFetch.balance ?? '0') : '',
          balance_ton: treasuryFetch.ok ? nanoToTonStr(String(treasuryFetch.balance ?? '0')) : '',
          status: treasuryFetch.ok ? String(treasuryFetch.status ?? '') : '',
          error: treasuryFetch.ok ? '' : String(treasuryFetch.response?.result ?? treasuryFetch.response?.error ?? treasuryFetch.error ?? ''),
          http_status: treasuryFetch.ok ? 200 : Number(treasuryFetch.http_status ?? 0),
        }
      : null,
  };

  console.log(JSON.stringify(out, null, 2));
}

main().catch((err) => {
  fail('SWEEP_CHECK_FATAL', {
    message: err instanceof Error ? err.message : String(err),
  });
});
