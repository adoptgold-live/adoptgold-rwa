/**
 * /var/www/html/public/rwa/cert/shared/format.js
 * Version: v2.0.0-20260406-v2-shared-format-baseline
 *
 * MASTER LOCK — RWA Cert V2 format helpers
 * - centralize formatting (numbers, TON, timestamps, addresses, JSON)
 * - no business logic
 * - safe for all modules
 */

/* ======================
   GENERIC
====================== */

function safeStr(v, fallback = '') {
  if (v === null || v === undefined) return String(fallback);
  return String(v);
}

function isNum(v) {
  return typeof v === 'number' && Number.isFinite(v);
}

function toNum(v, fallback = 0) {
  const n = Number(v);
  return Number.isFinite(n) ? n : fallback;
}

/* ======================
   NUMBER
====================== */

function formatNumber(v, opts = {}) {
  const n = toNum(v, 0);
  const {
    min = 0,
    max = 6,
    locale = 'en-US'
  } = opts;

  return new Intl.NumberFormat(locale, {
    minimumFractionDigits: min,
    maximumFractionDigits: max
  }).format(n);
}

/* ======================
   TON / CRYPTO
====================== */

function formatTon(v) {
  return formatNumber(v, { min: 0, max: 4 });
}

function formatUnits(v, decimals = 9) {
  const n = toNum(v, 0);
  const value = n / Math.pow(10, decimals);
  return formatNumber(value, { min: 0, max: 6 });
}

/* ======================
   TIMESTAMP
====================== */

function formatTime(ts) {
  const t = normalizeTimestamp(ts);
  if (!t) return '—';
  return new Date(t).toLocaleTimeString();
}

function formatDateTime(ts) {
  const t = normalizeTimestamp(ts);
  if (!t) return '—';
  return new Date(t).toLocaleString();
}

function normalizeTimestamp(ts) {
  if (!ts) return 0;

  if (typeof ts === 'number') {
    return ts > 1e12 ? ts : ts * 1000;
  }

  if (/^\d+$/.test(String(ts))) {
    const n = Number(ts);
    return n > 1e12 ? n : n * 1000;
  }

  const d = Date.parse(ts);
  return Number.isFinite(d) ? d : 0;
}

/* ======================
   ADDRESS
====================== */

function shortAddress(addr, head = 6, tail = 4) {
  const s = safeStr(addr);
  if (!s || s.length <= head + tail) return s;
  return s.slice(0, head) + '...' + s.slice(-tail);
}

/* ======================
   BOOLEAN
====================== */

function yesNo(v) {
  return v === true || v === 1 || v === '1' ? 'YES' : 'NO';
}

/* ======================
   JSON
====================== */

function prettyJson(v) {
  try {
    return JSON.stringify(v || {}, null, 2);
  } catch (_) {
    return '{}';
  }
}

/* ======================
   HTML ESCAPE
====================== */

function escapeHtml(v) {
  return safeStr(v)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

/* ======================
   EXPORT
====================== */

export {
  safeStr,
  isNum,
  toNum,
  formatNumber,
  formatTon,
  formatUnits,
  formatTime,
  formatDateTime,
  shortAddress,
  yesNo,
  prettyJson,
  escapeHtml
};
