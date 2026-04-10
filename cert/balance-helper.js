(function () {
  'use strict';

  const TOKEN_IMG = {
    WEMS: '/rwa/metadata/wems.png',
    EMA: '/rwa/metadata/ema.png',
    TON: '/rwa/metadata/ton.png'
  };

  function setText(id, value) {
    const el = document.getElementById(id);
    if (el) el.textContent = String(value ?? '');
  }

  function renderIcons() {
    const w = document.querySelector('.balance-wems .balance-icon');
    const e = document.querySelector('.balance-ema .balance-icon');
    const t = document.querySelector('.balance-ton .balance-icon');

    if (w) w.innerHTML = '<img src="' + TOKEN_IMG.WEMS + '" alt="wEMS" class="token-balance-img">';
    if (e) e.innerHTML = '<img src="' + TOKEN_IMG.EMA + '" alt="EMA$" class="token-balance-img">';
    if (t) t.innerHTML = '<img src="' + TOKEN_IMG.TON + '" alt="TON" class="token-balance-img">';
  }

  async function fetchBalance() {
    const wallet = String(document.getElementById('currentWallet')?.value || window.__POADO_WALLET || '').trim();
    const ownerUserId = String(document.getElementById('currentOwnerId')?.value || window.__POADO_OWNER_ID || '').trim();

    const qs = new URLSearchParams();
    if (wallet) qs.set('wallet', wallet);
    if (ownerUserId) qs.set('owner_user_id', ownerUserId);

    const res = await fetch('/rwa/cert/api/balance-local.php?' + qs.toString(), {
      method: 'GET',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    });

    const json = await res.json().catch(() => null);
    if (!res.ok || !json || json.ok !== true) {
      throw new Error(json?.error || ('HTTP_' + res.status));
    }
    return json;
  }

  function renderBalance(json) {
    const b = json?.balances || {};
    setText('balanceWemsText', b.wems ?? '0');
    setText('balanceEmaText', b.ema ?? '0');
    setText('balanceTonText', b.ton ?? '0');
    setText('balanceTonGas', json?.ton_ready ? 'READY' : 'Low');
  }

  function renderFallback() {
    setText('balanceWemsText', '—');
    setText('balanceEmaText', '—');
    setText('balanceTonText', '—');
    setText('balanceTonGas', 'Unknown');
  }

  async function refresh() {
    try {
      renderIcons();
      const json = await fetchBalance();
      renderBalance(json);
    } catch (e) {
      console.warn('[cert balance-helper]', e.message);
      renderFallback();
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    refresh();
    window.setInterval(refresh, 30000);
  });

  window.POADO_CERT_BALANCE = { refresh };
})();
