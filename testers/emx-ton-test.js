#!/usr/bin/env node

/**
 * EMX TON Auto Verify Tester
 * Version: v1.6.0-20260319
 *
 * FINAL RULE:
 * If jetton + amount + ref match -> ACCEPT
 *
 * Notes:
 * - Uses owner_address + direction=out + jetton_master on Toncenter v3
 * - Decodes forward payload when possible
 * - Random ref if --ref is not provided
 * - Does NOT require destination match anymore
 */

const BASE = 'https://toncenter.com/api/v3';
const TREASURY_OWNER = 'UQBdYfGArtoCUmBs5TjYQtfPFfQuGC2Ydbj2pQr3zIlNrDta';
const EMX_JETTON_MASTER_RAW = '0:63d3319c1cebcde48b013ff040006e4d462b806bf48b06efb18ec267ec078ce2';
const AMOUNT_UNITS = '1000000000'; // 1 EMX
const DEFAULT_LIMIT = 100;
const DEFAULT_POLL_SECONDS = 0;
const DEFAULT_MAX_ATTEMPTS = 10;

const API_KEY = process.env.TONCENTER_API_KEY || '';

function arg(name, fallback = '') {
  const i = process.argv.indexOf(`--${name}`);
  if (i !== -1 && process.argv[i + 1] && !process.argv[i + 1].startsWith('--')) {
    return process.argv[i + 1];
  }
  return fallback;
}

function toInt(v, fallback) {
  const n = Number(v);
  return Number.isFinite(n) && n >= 0 ? Math.floor(n) : fallback;
}

function canonical(v) {
  return String(v || '')
    .trim()
    .toLowerCase()
    .replace(/^0:/, '')
    .replace(/^eq/, '')
    .replace(/^uq/, '');
}

function randRef(prefix = 'EMX1-TEST') {
  const d = new Date();
  const yyyy = d.getFullYear();
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  const dd = String(d.getDate()).padStart(2, '0');
  const hh = String(d.getHours()).padStart(2, '0');
  const mi = String(d.getMinutes()).padStart(2, '0');
  const ss = String(d.getSeconds()).padStart(2, '0');
  const r = Math.random().toString(16).slice(2, 10).toUpperCase();
  return `${prefix}-${yyyy}${mm}${dd}-${hh}${mi}${ss}-${r}`;
}

function buildPayload(ref) {
  return `ton://transfer/${TREASURY_OWNER}?jetton=${encodeURIComponent(EMX_JETTON_MASTER_RAW)}&amount=${AMOUNT_UNITS}&text=${encodeURIComponent(ref)}`;
}

function sleep(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}

async function fetchJSON(url) {
  const headers = API_KEY ? { 'X-API-Key': API_KEY } : {};
  const res = await fetch(url, { headers });
  const text = await res.text();

  let json;
  try {
    json = JSON.parse(text);
  } catch {
    throw new Error(`INVALID_JSON: ${text.slice(0, 500)}`);
  }

  if (!res.ok) {
    throw new Error(`HTTP_${res.status}: ${JSON.stringify(json).slice(0, 500)}`);
  }

  return json;
}

function decodeMaybeBase64(s) {
  const v = String(s || '').trim();
  if (!v) return '';

  try {
    if (!/^[A-Za-z0-9+/=_-]+$/.test(v) || v.length < 8) return '';
    const norm = v.replace(/-/g, '+').replace(/_/g, '/');
    const buf = Buffer.from(norm, 'base64');
    const txt = buf.toString('utf8');
    if (txt && /[ -~]{4,}/.test(txt)) return txt;
  } catch (_) {}

  return '';
}

function extractPayloadTexts(tx) {
  const payloadCandidates = [
    tx.decoded_forward_payload,
    tx.forward_payload,
    tx.comment,
    tx.text,
    tx.msg_data_text,
    tx.decoded_comment
  ].filter(Boolean).map(v => String(v));

  const decodedPayloads = [];
  for (const p of payloadCandidates) {
    const d = decodeMaybeBase64(p);
    if (d) decodedPayloads.push(d);
  }

  const allPayloadText = [...payloadCandidates, ...decodedPayloads].join(' | ');

  return {
    payloadCandidates,
    decodedPayloads,
    allPayloadText
  };
}

async function verify(from, ref, limit = DEFAULT_LIMIT) {
  const url =
    `${BASE}/jetton/transfers` +
    `?owner_address=${encodeURIComponent(from)}` +
    `&direction=out` +
    `&jetton_master=${encodeURIComponent(EMX_JETTON_MASTER_RAW)}` +
    `&limit=${encodeURIComponent(String(limit))}`;

  const j = await fetchJSON(url);
  const list = j.jetton_transfers || j.result || [];
  const debug = [];

  for (const tx of list) {
    const txHash = String(tx.transaction_hash || tx.hash || '').trim();
    const sourceRaw = String(tx.source || tx.sender || tx.from || '').trim();
    const destRaw = String(tx.destination || tx.recipient || tx.to || '').trim();
    const amountRaw = String(tx.amount || '').trim();
    const jettonRaw = String(tx.jetton_master || tx.jetton || tx.jetton_address || '').trim();

    const { payloadCandidates, decodedPayloads, allPayloadText } = extractPayloadTexts(tx);

    const verdict = {
      tx_hash: txHash,
      tx_url: txHash ? `https://tonviewer.com/transaction/${encodeURIComponent(txHash)}` : '',
      source_raw: sourceRaw,
      destination_raw: destRaw,
      jetton_raw: jettonRaw,
      amount_units: amountRaw,
      payload_candidates: payloadCandidates,
      decoded_payloads: decodedPayloads,
      match_jetton: canonical(jettonRaw) === canonical(EMX_JETTON_MASTER_RAW),
      match_amount: amountRaw === AMOUNT_UNITS,
      match_ref: allPayloadText.toLowerCase().includes(ref.toLowerCase())
    };

    const near = verdict.match_jetton || verdict.match_amount || verdict.match_ref;
    if (near) debug.push(verdict);

    // FINAL RULE
    if (
      verdict.match_jetton &&
      verdict.match_amount &&
      verdict.match_ref
    ) {
      return {
        ok: true,
        tx_hash: txHash,
        tx_url: verdict.tx_url,
        debug
      };
    }
  }

  return {
    ok: false,
    debug
  };
}

(async () => {
  const from = arg('from');
  if (!from) {
    console.log(JSON.stringify({ ok: false, error: 'FROM_REQUIRED' }, null, 2));
    process.exit(1);
  }

  const ref = arg('ref') || randRef(arg('ref-prefix', 'EMX1-TEST'));
  const poll = toInt(arg('poll', String(DEFAULT_POLL_SECONDS)), DEFAULT_POLL_SECONDS);
  const max = toInt(arg('max', String(DEFAULT_MAX_ATTEMPTS)), DEFAULT_MAX_ATTEMPTS);
  const limit = toInt(arg('limit', String(DEFAULT_LIMIT)), DEFAULT_LIMIT);

  console.log('=== SEND THIS ===');
  console.log(buildPayload(ref));

  console.log('\n=== VERIFY RESULT ===');

  try {
    if (poll > 0) {
      for (let attempt = 1; attempt <= max; attempt++) {
        const res = await verify(from, ref, limit);
        console.log(JSON.stringify({ ref, attempt, max, result: res }, null, 2));
        if (res.ok) process.exit(0);
        if (attempt < max) await sleep(poll * 1000);
      }
      process.exit(2);
    } else {
      const res = await verify(from, ref, limit);
      console.log(JSON.stringify({ ref, result: res }, null, 2));
      process.exit(res.ok ? 0 : 2);
    }
  } catch (e) {
    console.log(JSON.stringify({
      ref,
      result: {
        ok: false,
        error: e && e.message ? e.message : String(e)
      }
    }, null, 2));
    process.exit(99);
  }
})();