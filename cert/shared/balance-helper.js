(function () {
  'use strict';

  const TOKEN_IMG = {
    WEMS: '/rwa/metadata/wems.png',
    EMA: '/rwa/metadata/ema.png',
    TON: '/rwa/metadata/ton.png'
  };

  function $(id) {
    return document.getElementById(id);
  }

  function setText(id, value) {
    const el = $(id);
    if (el) el.textContent = String(value ?? '');
  }

  function getValue(id, fallback = '') {
    const el = $(id);
    const v = el && 'value' in el ? String(el.value || '') : '';
    return v.trim() || String(fallback || '').trim();
  }

  function getEndpoint() {
    return getValue('endpointBalanceLocal', '/rwa/cert/api/balance-local.php');
  }

  function renderIcons() {
    const w = document.querySelector('.balance-wems .balance-icon');
    const e = document.querySelector('.balance-ema .balance-icon');
    const t = document.querySelector('.balance-ton .balance-icon');

    if (w) w.innerHTML = '<img src="' + TOKEN_IMG.WEMS + '" alt="wEMS" class="token-balance-img">';
    if (e) e.innerHTML = '<img src="' + TOKEN_IMG.EMA + '" alt="EMA$" class="token-balance-img">';
    if (t) t.innerHTML = '<img src="' + TOKEN_IMG.TON + '" alt="TON" class="token-balance-img">';
  }

  function renderFallback(reason) {
    setText('balanceWemsText', '—');
    setText('balanceEmaText', '—');
    setText('balanceTonText', '—');
    setText('balanceTonGas', 'Unknown');
    if (reason) console.warn('[cert balance-helper]', reason);
  }

  function renderBalance(json) {
    const b = json && json.balances ? json.balances : {};
    setText('balanceWemsText', b.wems ?? '0');
    setText('balanceEmaText', b.ema ?? '0');
    setText('balanceTonText', b.ton ?? '0');
    setText('balanceTonGas', json && json.ton_ready ? 'READY' : 'Low');
  }

  async function fetchBalance() {
    const wallet = getValue('currentWallet', window.__POADO_WALLET || '');
    const ownerUserId = getValue('currentOwnerId', window.__POADO_OWNER_ID || '');

    if (!wallet && !ownerUserId) {
      throw new Error('BALANCE_CONTEXT_REQUIRED');
    }

    const qs = new URLSearchParams();
    if (wallet) qs.set('wallet', wallet);
    if (ownerUserId) qs.set('owner_user_id', ownerUserId);

    const url = getEndpoint() + '?' + qs.toString();

    const res = await fetch(url, {
      method: 'GET',
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json'
      }
    });

    const raw = await res.text();
    let json = null;

    try {
      json = raw ? JSON.parse(raw) : null;
    } catch (_) {
      throw new Error('BALANCE_INVALID_JSON: ' + raw.slice(0, 180));
    }

    if (!res.ok || !json || json.ok !== true) {
      throw new Error(
        String(
          (json && (json.error || json.detail)) ||
          ('HTTP_' + res.status)
        )
      );
    }

    return json;
  }

  let timer = null;
  let inFlight = false;

  async function refresh() {
    if (inFlight) return;
    inFlight = true;

    try {
      renderIcons();
      const json = await fetchBalance();
      renderBalance(json);
    } catch (e) {
      renderFallback(e && e.message ? e.message : 'BALANCE_REFRESH_FAILED');
    } finally {
      inFlight = false;
    }
  }

  function start() {
    refresh();
    if (timer) window.clearInterval(timer);
    timer = window.setInterval(refresh, 30000);
  }

  document.addEventListener('DOMContentLoaded', start);

  window.POADO_CERT_BALANCE = {
    refresh,
    start
  };
})();
