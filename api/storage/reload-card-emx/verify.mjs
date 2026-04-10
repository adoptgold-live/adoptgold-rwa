#!/usr/bin/env node
/**
 * /var/www/html/public/rwa/api/storage/reload-card-emx/verify.mjs
 * Storage Master v7.6.6
 * Reload Card with EMX - Node HTTP verifier
 */

import http from 'node:http';

const VERSION = 'v7.6.6-reload-node-http-verify-20260320';
const REQUIRED_CONFIRMATIONS = 3;
const LOOKBACK_SECONDS = 60 * 60 * 24 * 3;
const LIMIT = 50;
const HOST = '127.0.0.1';
const PORT = 3001;

function json(res, status, payload) {
  const body = JSON.stringify(payload);
  res.writeHead(status, {
    'Content-Type': 'application/json; charset=utf-8',
    'Cache-Control': 'no-store',
    'Content-Length': Buffer.byteLength(body),
  });
  res.end(body);
}

function normalize(v) {
  return String(v ?? '').trim();
}

function normalizeLooseAddress(v) {
  return normalize(v).toLowerCase();
}

function normalizeHash(v) {
  return normalize(v).toLowerCase();
}

function pickFirst(obj, keys, fallback = '') {
  for (const key of keys) {
    if (obj && obj[key] !== undefined && obj[key] !== null && obj[key] !== '') {
      return obj[key];
    }
  }
  return fallback;
}

function safeJsonParse(raw) {
  try {
    return JSON.parse(raw);
  } catch {
    return null;
  }
}

function extractCommentFields(transfer) {
  const fields = [
    pickFirst(transfer, ['forward_payload', 'comment', 'text', 'message', 'payload', 'body'], ''),
    pickFirst(transfer, ['decoded_forward_payload', 'decoded_payload'], ''),
  ];

  const out = [];
  for (const field of fields) {
    if (typeof field === 'string' && field.trim() !== '') {
      out.push(field.trim());
      continue;
    }
    if (field && typeof field === 'object') {
      try {
        out.push(JSON.stringify(field));
      } catch {}
    }
  }
  return out;
}

function commentMatchesReloadRef(transfer, reloadRef) {
  const ref = normalize(reloadRef);
  if (!ref) return false;

  const comments = extractCommentFields(transfer);
  for (const c of comments) {
    if (normalize(c).includes(ref)) {
      return true;
    }
  }
  return false;
}

function extractAmountUnits(transfer) {
  return normalize(pickFirst(transfer, ['amount', 'jetton_amount', 'quantity'], '0'));
}

function extractTxHash(transfer) {
  return normalize(pickFirst(transfer, [
    'transaction_hash',
    'tx_hash',
    'hash',
    'trace_id',
  ], ''));
}

function extractConfirmations(transfer) {
  const candidates = [
    transfer?.confirmations,
    transfer?.metadata?.confirmations,
    transfer?.mc_seqno_delta,
  ];

  for (const c of candidates) {
    const n = Number(c);
    if (Number.isFinite(n) && n >= 0) return Math.floor(n);
  }

  return 0;
}

function extractSourceCandidates(transfer) {
  const raw = [
    pickFirst(transfer, ['source', 'sender', 'from'], ''),
    pickFirst(transfer, ['source_wallet', 'sender_wallet', 'from_wallet'], ''),
    transfer?.source_owner?.address,
    transfer?.source_wallet_info?.owner_address,
    transfer?.source_wallet_info?.wallet_address,
  ];

  return raw.map((v) => normalize(v)).filter(Boolean);
}

function extractDestinationCandidates(transfer) {
  const raw = [
    pickFirst(transfer, ['destination', 'to', 'receiver'], ''),
    pickFirst(transfer, ['destination_wallet', 'to_wallet', 'receiver_wallet'], ''),
    transfer?.destination_owner?.address,
    transfer?.destination_wallet_info?.owner_address,
    transfer?.destination_wallet_info?.wallet_address,
  ];

  return raw.map((v) => normalize(v)).filter(Boolean);
}

function senderMatches(transfer, expectedWalletAddress) {
  const expected = normalizeLooseAddress(expectedWalletAddress);
  if (!expected) return false;

  const candidates = extractSourceCandidates(transfer);
  return candidates.some((c) => normalizeLooseAddress(c) === expected);
}

function destinationMayMatch(transfer, expectedTreasuryAddress) {
  const expected = normalizeLooseAddress(expectedTreasuryAddress);
  if (!expected) return true;

  const candidates = extractDestinationCandidates(transfer);
  if (!candidates.length) return true;

  return candidates.some((c) => normalizeLooseAddress(c) === expected);
}

function txHashMatches(transfer, requestedTxHash) {
  const expected = normalizeHash(requestedTxHash);
  if (!expected) return true;
  return normalizeHash(extractTxHash(transfer)) === expected;
}

function buildUrl(base, path, params) {
  const root = String(base || 'https://toncenter.com/api/v3').replace(/\/+$/, '');
  const url = new URL(path, root + '/');

  for (const [key, value] of Object.entries(params)) {
    if (Array.isArray(value)) {
      for (const item of value) {
        if (item !== undefined && item !== null && item !== '') {
          url.searchParams.append(key, String(item));
        }
      }
    } else if (value !== undefined && value !== null && value !== '') {
      url.searchParams.set(key, String(value));
    }
  }

  return url.toString();
}

async function fetchJettonTransfers(input) {
  const headers = { Accept: 'application/json' };

  if (normalize(input.toncenter_api_key)) {
    headers['X-API-Key'] = normalize(input.toncenter_api_key);
  }

  const endUtime = Math.floor(Date.now() / 1000);
  const startUtime = endUtime - LOOKBACK_SECONDS;

  const url = buildUrl(input.toncenter_base, 'jetton/transfers', {
    owner_address: [input.expected_wallet_address],
    jetton_master: normalize(input.jetton_master),
    direction: 'out',
    start_utime: startUtime,
    end_utime: endUtime,
    limit: LIMIT,
    sort: 'desc',
  });

  const res = await fetch(url, { method: 'GET', headers });
  const text = await res.text();

  if (!res.ok) {
    return {
      ok: false,
      error: 'TONCENTER_HTTP_ERROR',
      http_status: res.status,
      body: text.slice(0, 1000),
      url,
    };
  }

  const json = safeJsonParse(text);
  if (!json || typeof json !== 'object') {
    return {
      ok: false,
      error: 'TONCENTER_INVALID_JSON',
      http_status: res.status,
      body: text.slice(0, 1000),
      url,
    };
  }

  const rows = json.jetton_transfers || json.transfers || json.data || [];
  if (!Array.isArray(rows)) {
    return {
      ok: false,
      error: 'TONCENTER_UNEXPECTED_PAYLOAD',
      sample_keys: Object.keys(json),
      url,
    };
  }

  return { ok: true, rows };
}

function chooseBestCandidate(transfers, input) {
  const expectedAmount = normalize(input.expected_amount_units);
  const requestedTxHash = normalize(input.tx_hash);
  const reloadRef = normalize(input.reload_ref);
  const expectedWalletAddress = normalize(input.expected_wallet_address);
  const expectedTreasuryAddress = normalize(input.expected_treasury_address);

  let bestAmountMismatch = null;
  let bestRefMismatch = null;
  let bestSenderMismatch = null;

  for (const t of transfers) {
    if (!txHashMatches(t, requestedTxHash)) continue;

    const amountUnits = extractAmountUnits(t);
    const hash = extractTxHash(t);
    const confirmations = extractConfirmations(t);
    const amountOk = amountUnits === expectedAmount;
    const refOk = commentMatchesReloadRef(t, reloadRef);
    const senderOk = senderMatches(t, expectedWalletAddress);
    const destOk = destinationMayMatch(t, expectedTreasuryAddress);

    const base = {
      transfer: t,
      tx_hash: hash,
      confirmations,
      amount_units: amountUnits,
    };

    if (!amountOk && !bestAmountMismatch) bestAmountMismatch = base;
    if (amountOk && !refOk && !bestRefMismatch) bestRefMismatch = base;
    if (amountOk && refOk && (!senderOk || !destOk) && !bestSenderMismatch) bestSenderMismatch = base;

    if (amountOk && refOk && senderOk && destOk) {
      return {
        kind: confirmations >= REQUIRED_CONFIRMATIONS ? 'confirmed' : 'pending_confirmations',
        ...base,
      };
    }
  }

  if (bestSenderMismatch) return { kind: 'invalid_sender', ...bestSenderMismatch };
  if (bestRefMismatch) return { kind: 'invalid_ref', ...bestRefMismatch };
  if (bestAmountMismatch) return { kind: 'invalid_amount', ...bestAmountMismatch };

  return null;
}

function validateInput(input) {
  const required = [
    'reload_ref',
    'expected_amount_units',
    'expected_wallet_address',
    'expected_treasury_address',
    'toncenter_base',
    'jetton_master',
  ];

  for (const key of required) {
    if (!normalize(input[key])) {
      return 'INPUT_MISSING_' + key.toUpperCase();
    }
  }

  return null;
}

async function handleVerify(input) {
  const missing = validateInput(input);
  if (missing) {
    return {
      ok: false,
      error: missing,
      verify_source: 'node_toncenter_v3',
      version: VERSION,
    };
  }

  const transferResult = await fetchJettonTransfers(input);
  if (!transferResult.ok) {
    return {
      ok: false,
      error: transferResult.error,
      verify_source: 'node_toncenter_v3',
      version: VERSION,
      details: transferResult,
    };
  }

  const candidate = chooseBestCandidate(transferResult.rows, input);

  if (!candidate) {
    return {
      ok: false,
      error: 'TX_NOT_FOUND',
      status: 'TX_NOT_FOUND',
      verified: false,
      confirmations: 0,
      verify_source: 'node_toncenter_v3',
      version: VERSION,
      message: 'No matching transfer found yet',
    };
  }

  if (candidate.kind === 'invalid_amount') {
    return {
      ok: false,
      error: 'INVALID_AMOUNT',
      status: 'INVALID_AMOUNT',
      verified: false,
      tx_hash: candidate.tx_hash,
      confirmations: candidate.confirmations,
      amount_units_found: candidate.amount_units,
      verify_source: 'node_toncenter_v3',
      version: VERSION,
      message: 'Amount does not match expected units',
    };
  }

  if (candidate.kind === 'invalid_ref') {
    return {
      ok: false,
      error: 'INVALID_REF',
      status: 'INVALID_REF',
      verified: false,
      tx_hash: candidate.tx_hash,
      confirmations: candidate.confirmations,
      verify_source: 'node_toncenter_v3',
      version: VERSION,
      message: 'Reload ref not found in payload/comment',
    };
  }

  if (candidate.kind === 'invalid_sender') {
    return {
      ok: false,
      error: 'INVALID_SENDER',
      status: 'INVALID_SENDER',
      verified: false,
      tx_hash: candidate.tx_hash,
      confirmations: candidate.confirmations,
      verify_source: 'node_toncenter_v3',
      version: VERSION,
      message: 'Sender or destination mismatch',
    };
  }

  if (candidate.kind === 'pending_confirmations') {
    return {
      ok: false,
      error: 'RELOAD_PENDING',
      status: 'RELOAD_PENDING',
      verified: false,
      tx_hash: candidate.tx_hash,
      confirmations: candidate.confirmations,
      verify_source: 'node_toncenter_v3',
      version: VERSION,
      message: 'Matching transfer found but confirmations are below threshold',
    };
  }

  return {
    ok: true,
    status: 'RELOAD_CONFIRMED',
    verified: true,
    tx_hash: candidate.tx_hash,
    confirmations: candidate.confirmations,
    verify_source: 'node_toncenter_v3',
    version: VERSION,
    message: 'Reload transfer verified',
  };
}

const server = http.createServer(async (req, res) => {
  try {
    if (req.method === 'GET' && req.url === '/health') {
      return json(res, 200, { ok: true, service: 'verify-emx', version: VERSION });
    }

    if (req.method !== 'POST' || req.url !== '/verify-emx') {
      return json(res, 404, { ok: false, error: 'NOT_FOUND', version: VERSION });
    }

    let raw = '';
    req.on('data', chunk => {
      raw += chunk.toString('utf8');
      if (raw.length > 1024 * 1024) {
        req.destroy();
      }
    });

    req.on('end', async () => {
      const input = safeJsonParse(raw);
      if (!input || typeof input !== 'object') {
        return json(res, 400, { ok: false, error: 'INVALID_JSON', version: VERSION });
      }

      const result = await handleVerify(input);
      return json(res, 200, result);
    });
  } catch (err) {
    return json(res, 500, {
      ok: false,
      error: 'UNHANDLED_NODE_VERIFY_ERROR',
      message: err instanceof Error ? err.message : String(err),
      version: VERSION,
    });
  }
});

server.listen(PORT, HOST, () => {
  console.log(`verify-emx listening on http://${HOST}:${PORT}`);
});