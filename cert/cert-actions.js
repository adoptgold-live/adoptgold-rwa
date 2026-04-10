/**
 * /var/www/html/public/rwa/cert/cert-actions.js
 * Version: v25.0.0-20260410-global-role-lock
 *
 * GLOBAL MASTER LOCK
 * - Check & Preview owner = cert-actions.js
 * - Issue & Pay owner = cert-actions.js
 * - Reconfirm Payment receiver = cert-actions.js
 * - Storage balance render owner = cert-actions.js
 * - Token image render owner = cert-actions.js
 * - Active cert / modal sync owner = cert-actions.js
 * - Finalize Mint handoff owner = cert-actions.js
 *
 * NEVER owned here:
 * - translator / language dictionary
 * - queue summary polling
 * - queue rendering
 * - verify.php rendering
 *
 * OTHER LOCKS
 * - cert.js = translator only
 * - cert-router.js = queue/render/routing only
 * - exact existing DOM ids preserved
 * - exact DB/API truth only
 * - EOF full-file regen only
 */

(function () {
  'use strict';

  if (window.CERT_ACTIONS_V250_ACTIVE) return;
  window.CERT_ACTIONS_V250_ACTIVE = true;

  const $ = (id) => document.getElementById(id);

  const TXT_DASH = '—';

  function pickFirst(...vals) {
    for (const v of vals) {
      if (v === null || v === undefined) continue;
      const s = String(v).trim();
      if (s !== '' && s !== 'null' && s !== 'undefined') return s;
    }
    return '';
  }

  function setText(id, value, fallback = TXT_DASH) {
    const el = $(id);
    if (!el) return;
    el.textContent = pickFirst(value) || fallback;
  }

  function setHtml(id, value, fallback = '&mdash;') {
    const el = $(id);
    if (!el) return;
    const v = pickFirst(value);
    el.innerHTML = v || fallback;
  }

  function setImg(id, src) {
    const el = $(id);
    if (!el) return;
    const v = pickFirst(src);
    if (!v) return;
    if ('src' in el) el.src = v;
  }

  function setLink(id, href, textFallback) {
    const el = $(id);
    if (!el) return;
    const v = pickFirst(href);
    if (!v) {
      el.removeAttribute('href');
      el.textContent = textFallback || TXT_DASH;
      return;
    }
    el.setAttribute('href', v);
    el.textContent = textFallback || v;
  }

  function normalizePaymentPayload(payload, row) {
    const p = payload || {};
    const r = row || {};
    const payment = p.payment || p.issue_pay || p.data?.payment || {};
    const meta = p.meta || p.data || {};

    return {
      cert_uid: pickFirst(p.cert_uid, r.cert_uid, r.uid),
      token_symbol: pickFirst(payment.token_symbol, payment.token, r.payment_token, r.token_symbol),
      amount: pickFirst(payment.amount, r.payment_amount, r.amount),
      amount_units: pickFirst(payment.amount_units, r.payment_amount_units, r.amount_units),
      payment_ref: pickFirst(payment.payment_ref, p.payment_ref, r.payment_ref),
      payment_status: pickFirst(payment.status, r.payment_status, 'Waiting'),
      wallet_link: pickFirst(payment.wallet_link, payment.deeplink, meta.wallet_link, meta.deeplink, r.wallet_link, r.deeplink),
      qr_image: pickFirst(payment.qr_image, payment.qr, meta.qr_image, meta.qr, r.qr_image),
    };
  }

  function hydrateIssuePayModalFromPayload(payload, row) {
    const x = normalizePaymentPayload(payload, row);

    setText('issuePayCertUid', x.cert_uid);
    setText('paymentCertUid', x.cert_uid);
    setText('issuePayToken', x.token_symbol);
    setText('paymentToken', x.token_symbol);
    setText('issuePayAmount', x.amount);
    setText('paymentAmount', x.amount);
    setText('issuePayRef', x.payment_ref);
    setText('paymentRef', x.payment_ref);
    setText('issuePayStatus', x.payment_status || 'Waiting');
    setText('paymentStatus', x.payment_status || 'Waiting');

    setImg('issuePayQrImage', x.qr_image);
    setImg('paymentQrImage', x.qr_image);

    setLink('issuePayWalletLink', x.wallet_link, x.wallet_link ? 'Open Wallet Deeplink' : TXT_DASH);
    setLink('paymentWalletLink', x.wallet_link, x.wallet_link ? 'Open Wallet Deeplink' : TXT_DASH);

    const hasCore = !!(x.payment_ref && x.token_symbol && x.amount);
    return { ok: hasCore, data: x };
  }

  function guardIssuePayHydration(payload, row) {
    const res = hydrateIssuePayModalFromPayload(payload, row);
    if (!res.ok) {
      const msg = 'Payment payload not loaded';
      if (typeof window.showErrorToast === 'function') {
        window.showErrorToast(msg);
      } else {
        alert(msg);
      }
      return false;
    }
    return true;
  }

  function markCertModalOpen(open) {
    document.body.classList.toggle('cert-modal-open', !!open);
  }

  const TYPE_MAP = {
    green:      { rwa_type: 'green',      family: 'genesis',   rwa_code: 'RCO2C-EMA',  token: 'WEMS', amount: '1000',  title: 'Green',           familyLabel: 'GENESIS',   unit: '10 kg tCO2e' },
    blue:       { rwa_type: 'blue',       family: 'genesis',   rwa_code: 'RH2O-EMA',   token: 'WEMS', amount: '5000',  title: 'Blue',            familyLabel: 'GENESIS',   unit: '100 liters or m³' },
    black:      { rwa_type: 'black',      family: 'genesis',   rwa_code: 'RBLACK-EMA', token: 'WEMS', amount: '10000', title: 'Black',           familyLabel: 'GENESIS',   unit: '1 MWh or energy-unit' },
    gold:       { rwa_type: 'gold',       family: 'genesis',   rwa_code: 'RK92-EMA',   token: 'WEMS', amount: '50000', title: 'Gold',            familyLabel: 'GENESIS',   unit: '1 gram Gold Nugget' },
    pink:       { rwa_type: 'pink',       family: 'secondary', rwa_code: 'RLIFE-EMA',  token: 'EMA$', amount: '100',   title: 'Health',          familyLabel: 'SECONDARY', unit: '1 day health-right unit by BMI' },
    red:        { rwa_type: 'red',        family: 'secondary', rwa_code: 'RTRIP-EMA',  token: 'EMA$', amount: '100',   title: 'Travel',          familyLabel: 'SECONDARY', unit: '1 km travel-right unit' },
    royal_blue: { rwa_type: 'royal_blue', family: 'secondary', rwa_code: 'RPROP-EMA',  token: 'EMA$', amount: '100',   title: 'Property',        familyLabel: 'SECONDARY', unit: '1 ft² property-right unit' },
    yellow:     { rwa_type: 'yellow',     family: 'tertiary',  rwa_code: 'RHRD-EMA',   token: 'EMA$', amount: '100',   title: 'Human Resources', familyLabel: 'TERTIARY',  unit: '10 hours Labor Contribution' }
  };

  const FLOW = {
    IDLE: 'idle',
    PREVIEW: 'preview',
    PAYMENT: 'payment',
    MINT_READY: 'mint_ready',
    MINTING: 'minting',
    ISSUED: 'issued'
  };

  const TOKEN_IMG = {
    WEMS: '/rwa/metadata/wems.png',
    'EMA$': '/rwa/metadata/ema.png',
    EMA: '/rwa/metadata/ema.png',
    TON: '/rwa/metadata/ton.png',
    EMX: '/rwa/metadata/emx.png',
    EMS: '/rwa/metadata/ems.png'
  };

  const state = {
    selectedTypeKey: '',
    selectedCertUid: '',
    activeQueueBucket: '',
    issueJson: null,
    issuePayJson: null,
    finalizeJson: null,
    flowState: FLOW.IDLE,
    lastPaymentStatus: '',
    lastPaymentVerified: 0,
    sufficientCache: Object.create(null),
    autoPayTimer: null,
    autoPayUid: '',
    mintPollTimer: null,
    mintPollUid: ''
  };

  function log(...args) { console.log('[CERT_ACTIONS]', ...args); }
  function warn(...args) { console.warn('[CERT_ACTIONS]', ...args); }
  function err(...args) { console.error('[CERT_ACTIONS]', ...args); }

  function endpoint(id, fallback) {
    return String($(id)?.value || fallback || '').trim();
  }

  function currentWallet() {
    return String($('currentWallet')?.value || '').trim();
  }

  function currentOwnerId() {
    return String($('currentOwnerId')?.value || '').trim();
  }

  function csrfIssue() {
    return String($('csrfIssue')?.value || '').trim();
  }

  function csrfConfirmPayment() {
    return String($('csrfConfirmPayment')?.value || '').trim();
  }

  function getLang() {
    return String(document.documentElement.getAttribute('data-lang') || 'en').trim().toLowerCase() === 'zh' ? 'zh' : 'en';
  }

  function textByLang(en, zh) {
    return getLang() === 'zh' ? zh : en;
  }

  function setText(id, value) {
    const el = $(id);
    if (el) el.textContent = String(value ?? '');
  }

  function setHtml(id, value) {
    const el = $(id);
    if (el) el.innerHTML = String(value ?? '');
  }

  function formatNum(v, fallback = '0') {
    const n = Number(String(v ?? '').replace(/,/g, ''));
    if (!Number.isFinite(n)) return String(fallback);
    return n.toLocaleString(undefined, {
      minimumFractionDigits: n % 1 === 0 ? 0 : 4,
      maximumFractionDigits: 4
    });
  }

  async function getJson(url) {
    const res = await fetch(url, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    });

    const text = await res.text();
    let json = null;
    try { json = JSON.parse(text); } catch (_) {}

    if (!res.ok) throw new Error((json && (json.error || json.detail || json.message)) || `HTTP_${res.status}`);
    if (!json) throw new Error('INVALID_JSON_RESPONSE');
    if (json.ok === false) throw new Error(json.detail || json.error || json.message || 'REQUEST_FAILED');
    return json;
  }

  async function postJson(url, payload) {
    const res = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      body: JSON.stringify(payload || {})
    });

    const text = await res.text();
    let json = null;
    try { json = JSON.parse(text); } catch (_) {}

    if (!res.ok) throw new Error((json && (json.error || json.detail || json.message)) || `HTTP_${res.status}`);
    if (!json) throw new Error('INVALID_JSON_RESPONSE');
    if (json.ok === false) throw new Error(json.detail || json.error || json.message || 'REQUEST_FAILED');
    return json;
  }

  function tokenImageForSymbol(symbol) {
    const key = String(symbol || '').trim().toUpperCase();
    return TOKEN_IMG[key] || '';
  }

  function renderBalanceTokenImages() {
    const wemsBox = document.querySelector('.balance-wems .balance-icon');
    const emaBox = document.querySelector('.balance-ema .balance-icon');
    const tonBox = document.querySelector('.balance-ton .balance-icon');

    if (wemsBox) wemsBox.innerHTML = '<img src="/rwa/metadata/wems.png" alt="wEMS" class="token-balance-img">';
    if (emaBox) emaBox.innerHTML = '<img src="/rwa/metadata/ema.png" alt="EMA$" class="token-balance-img">';
    if (tonBox) tonBox.innerHTML = '<img src="/rwa/metadata/ton.png" alt="TON" class="token-balance-img">';
  }

  async function loadStorageSummary() {
    try {
      const data = await getJson(endpoint('endpointStorageOverview', '/rwa/api/storage/overview.php'));
      const balances = data?.balances || data?.data?.balances || {};

      if (!balances || typeof balances !== 'object') {
        throw new Error('INVALID_STORAGE_SCHEMA');
      }

      const wems = balances.onchain_wems ?? balances.wems ?? '0';
      const ema = balances.onchain_ema ?? balances.ema ?? '0';
      const ton = balances.fuel_ton_gas ?? balances.ton ?? '0';

      setText('balanceWemsText', formatNum(wems, '0'));
      setText('balanceEmaText', formatNum(ema, '0'));
      setText('balanceTonText', formatNum(ton, '0'));

      const tonNum = Number(String(ton).replace(/,/g, ''));
      setText(
        'balanceTonGas',
        Number.isFinite(tonNum)
          ? (tonNum >= 0.5
              ? textByLang('Ready', '已就绪')
              : textByLang('Low', '偏低'))
          : textByLang('Unknown', '未知')
      );
    } catch (e) {
      warn('storage summary failed', e);
      setText('balanceWemsText', '—');
      setText('balanceEmaText', '—');
      setText('balanceTonText', '—');
      setText('balanceTonGas', textByLang('Unknown', '未知'));
    }
  }

  function ensureNoticeModal() {
    let modal = $('certNoticeModal');
    if (modal) return modal;

    const style = document.createElement('style');
    style.id = 'certNoticeModalStyle';
    style.textContent = `
      #certNoticeModal{position:fixed;inset:0;display:none;align-items:center;justify-content:center;padding:20px;background:rgba(0,0,0,.72);z-index:99999}
      #certNoticeModal.is-open{display:flex}
      #certNoticeModal .cert-notice-card{width:min(92vw,520px);background:linear-gradient(180deg,#17120a 0%,#120f0a 100%);border:1px solid rgba(214,185,88,.42);box-shadow:0 0 0 1px rgba(214,185,88,.10) inset,0 16px 50px rgba(0,0,0,.55);border-radius:22px;color:#f4e6b0;padding:24px 22px 18px}
      #certNoticeModal .cert-notice-title{font-size:22px;line-height:1.2;font-weight:700;margin:0 0 14px;color:#ffe7a3;text-align:center}
      #certNoticeModal .cert-notice-body{white-space:pre-line;font-size:18px;line-height:1.65;color:#fff3c8;text-align:center;margin:0 0 20px}
      #certNoticeModal .cert-notice-actions{display:flex;justify-content:center}
      #certNoticeModal .cert-notice-ok{min-width:120px;border:2px solid #d6b958;background:#d9bf62;color:#2b2207;font-weight:700;font-size:18px;border-radius:999px;padding:12px 22px;cursor:pointer}
    `;
    document.head.appendChild(style);

    modal = document.createElement('div');
    modal.id = 'certNoticeModal';
    modal.innerHTML = `
      <div class="cert-notice-card" role="dialog" aria-modal="true" aria-labelledby="certNoticeTitle">
        <div class="cert-notice-title" id="certNoticeTitle">Notice</div>
        <div class="cert-notice-body" id="certNoticeBody"></div>
        <div class="cert-notice-actions">
          <button type="button" class="cert-notice-ok" id="certNoticeOkBtn">OK</button>
        </div>
      </div>
    `;
    document.body.appendChild(modal);

    const close = () => modal.classList.remove('is-open');
    modal.addEventListener('click', (ev) => {
      if (ev.target === modal) close();
    });
    modal.querySelector('#certNoticeOkBtn')?.addEventListener('click', close);

    return modal;
  }

  function showCenterNotice(message, title) {
    const modal = ensureNoticeModal();
    const titleEl = modal.querySelector('#certNoticeTitle');
    const bodyEl = modal.querySelector('#certNoticeBody');
    if (titleEl) titleEl.textContent = String(title || textByLang('Notice', '提示'));
    if (bodyEl) bodyEl.textContent = String(message || '');
    modal.classList.add('is-open');
  }

  function appendLogLine(text, kind) {
    const box = $('factoryConsoleLog');
    if (!box) return;
    const row = document.createElement('div');
    row.className = `log-row tone-${kind || 'ok'}`;
    row.innerHTML = `
      <div class="log-time">${new Date().toLocaleTimeString()}</div>
      <div class="log-msg">${String(text || '')}</div>
    `;
    box.prepend(row);
  }

  function setBtnLoading(btn, normalText) {
    if (!btn) return;
    btn.dataset.normalLabel = normalText || btn.dataset.normalLabel || btn.textContent || '';
    btn.disabled = true;
    btn.setAttribute('data-loading', '1');
    btn.textContent = `${btn.dataset.normalLabel} …`;
  }

  function setBtnNormal(btn, normalText) {
    if (!btn) return;
    btn.disabled = false;
    btn.removeAttribute('data-loading');
    btn.textContent = normalText || btn.dataset.normalLabel || btn.textContent || '';
  }

  function setProgress(step) {
    const fill = $('activeProgressFill');
    const label = $('activeProgressLabel');
    const pct = Math.max(0, Math.min(100, (Number(step) / 5) * 100));
    if (fill) fill.style.width = `${pct}%`;
    if (label) label.textContent = `${step} / 5`;
  }

  function resetStepClasses(el) {
    if (!el) return;
    el.classList.remove('is-active', 'is-done', 'is-next', 'is-pulse');
  }

  function paintStepCards(flow) {
    const steps = [$('factoryStep1'), $('factoryStep2'), $('factoryStep3'), $('factoryStep4'), $('factoryStep5')];
    steps.forEach(resetStepClasses);

    const currentMap = {
      [FLOW.IDLE]: 0,
      [FLOW.PREVIEW]: 1,
      [FLOW.PAYMENT]: 2,
      [FLOW.MINT_READY]: 3,
      [FLOW.MINTING]: 4,
      [FLOW.ISSUED]: 5
    };

    const current = Number(currentMap[flow] || 0);

    steps.forEach((el, idx) => {
      const n = idx + 1;
      if (!el) return;

      if (current === 0) {
        if (n === 1) el.classList.add('is-next', 'is-pulse');
        return;
      }

      if (n < current) {
        el.classList.add('is-done');
        return;
      }

      if (n === current) {
        el.classList.add('is-active', 'is-pulse');
      }
    });

    document.body.setAttribute('data-cert-flow-state', flow);
  }

  function setFlowState(flow, detail = {}) {
    state.flowState = flow;
    if (detail.cert_uid) {
      state.selectedCertUid = String(detail.cert_uid).trim();
      window.__CERT_SELECTED_UID = state.selectedCertUid;
    }

    const map = { idle: 0, preview: 1, payment: 2, mint_ready: 3, minting: 4, issued: 5 };
    setProgress(map[flow] || 0);
    paintStepCards(flow);

    if (flow === FLOW.ISSUED) setText('activeStatusText', 'ISSUED');
    else if (flow === FLOW.MINTING) setText('activeStatusText', 'MINTING');
    else if (flow === FLOW.MINT_READY) setText('activeStatusText', 'MINT READY');
    else if (flow === FLOW.PAYMENT) setText('activeStatusText', 'PAYMENT');
    else if (flow === FLOW.PREVIEW) setText('activeStatusText', 'PREVIEW');
    else setText('activeStatusText', 'READY');
  }

  function getCardByType(typeKey) {
    return document.querySelector(`.rwa-card[data-rule-key="${CSS.escape(typeKey)}"]`) ||
           document.querySelector(`.rwa-card[data-rwa-type="${CSS.escape(typeKey)}"]`);
  }

  function clearSelectedState() {
    document.querySelectorAll('.rwa-card').forEach((card) => {
      card.classList.remove('is-selected');
      card.classList.add('is-dimmed');
    });
    document.querySelectorAll('.issue-btn').forEach((btn) => btn.classList.remove('is-selected'));
  }

  function applySelectedState(typeKey, certUid) {
    clearSelectedState();

    const card = getCardByType(typeKey);
    if (card) {
      card.classList.remove('is-dimmed');
      card.classList.add('is-selected');
      if (certUid) card.setAttribute('data-cert-uid', certUid);
    }

    const btn = $(`issueBtn-${typeKey}`);
    if (btn) btn.classList.add('is-selected');

    if (typeKey) {
      state.selectedTypeKey = typeKey;
      window.__CERT_SELECTED_TYPE = typeKey;
    }
    if (certUid) {
      state.selectedCertUid = certUid;
      window.__CERT_SELECTED_UID = certUid;
    }

    syncUxGuard();
  }

  function syncUxGuard() {
    const certUid = String(state.selectedCertUid || $('activeCertUid')?.textContent || '').trim();
    const issuePayBtn = $('rwaIssuePayBtn');
    const autoBtn = $('rwaAutoIssueBtn');
    const jumpBtn = $('rwaJumpMintBtn');

    if (issuePayBtn) issuePayBtn.disabled = !(certUid && certUid !== '—');
    if (autoBtn) autoBtn.disabled = !(certUid && certUid !== '—');
    if (jumpBtn) jumpBtn.disabled = !(certUid && certUid !== '—');
  }

  async function fetchSufficientSnapshot(typeKey) {
    const wallet = currentWallet();
    const ownerUserId = currentOwnerId();
    const qs = new URLSearchParams({
      rwa_type: String(typeKey || '').trim(),
      wallet,
      owner_user_id: ownerUserId
    });

    const res = await getJson(`/rwa/cert/api/check-sufficient.php?${qs.toString()}`);
    state.sufficientCache[typeKey] = res;
    return res;
  }

  function shortfallMessage(typeKey) {
    const snap = state.sufficientCache[typeKey];
    if (!snap) return textByLang('Insufficient balance.', '余额不足。');

    const token = String(snap.token || '').trim();
    const need = formatNum(snap.required);
    const have = formatNum(snap.available);
    const short = formatNum(snap.shortfall);

    return textByLang(
      `Insufficient ${token} balance.\nRequired: ${need}\nAvailable: ${have}\nShortfall: ${short}`,
      `${token} 余额不足。\n所需: ${need}\n当前: ${have}\n差额: ${short}`
    );
  }

  async function syncBalanceGuard() {
    const typeKey = String(state.selectedTypeKey || '').trim();
    if (!typeKey) return;

    const issuePayBtn = $('rwaIssuePayBtn');
    try {
      const snap = await fetchSufficientSnapshot(typeKey);
      const enough = snap.sufficient === true;
      if (issuePayBtn) issuePayBtn.dataset.balanceGuard = enough ? 'ok' : 'insufficient';
    } catch (e) {
      warn('balance guard failed', e);
    }
  }

  function updateActivePanel(meta, certUid, json) {
    const preview = json?.preview || {};
    const row = json?.preview_row || {};
    const payment = preview?.payment || {};

    setText('activeFamilyPill', meta.familyLabel || '—');
    setText('activeName', preview.rwa_code || meta.rwa_code || meta.title || 'Preview Ready');
    setText('activeCode', row.rwa_code || meta.rwa_code || '—');
    setText('activeSub', meta.unit || meta.unit_of_responsibility || '');
    setText('activeCertUid', certUid || '—');
    setText('activePaymentText', preview.payment_text || `${meta.amount || '-'} ${meta.token || ''}`.trim());
    setText('activePaymentRef', row.payment_ref || preview.payment_ref || payment.payment_ref || '—');
    setText('activeNftItem', row.nft_item_address || '—');
    setText('nextStepBanner', textByLang('Next: Complete Business Payment', '下一步：完成业务支付'));
  }

  function clearQrNode(node) {
    if (!node) return;
    while (node.firstChild) node.removeChild(node.firstChild);
  }

  function renderClientQr(targetEl, text) {
    if (!targetEl) return false;
    const value = String(text || '').trim();
    if (!value) return false;
    if (typeof window.QRCode !== 'function') return false;

    clearQrNode(targetEl);
    targetEl.textContent = '';

    try {
      new window.QRCode(targetEl, {
        text: value,
        width: 300,
        height: 300,
        correctLevel: window.QRCode.CorrectLevel ? window.QRCode.CorrectLevel.M : undefined
      });
      return !!targetEl.querySelector('img,canvas,table');
    } catch (e) {
      warn('client QR render failed', e);
      clearQrNode(targetEl);
      return false;
    }
  }

  function openIssuePayModal() {
    const modal = $('issuePayModal');
    if (!modal) return;
    modal.classList.add('active');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  }

  function closeIssuePayModal() {
    const modal = $('issuePayModal');
    if (!modal) return;
    modal.classList.remove('active');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  }

  function fillIssuePayModal(certUid, json) {
    const payment = json?.payment || {};
    state.issuePayJson = json;
    state.lastPaymentStatus = String(payment.status || '').trim().toLowerCase();
    state.lastPaymentVerified = Number(payment.verified || 0);
    state.activeQueueBucket = String(json?.preview_row?.queue_bucket || '').trim();

    setText('issuePayCertUid', certUid || '—');
    setText('issuePayToken', payment.token_symbol || payment.token || '—');
    setText('issuePayAmount', payment.amount || '—');
    setText('issuePayRef', payment.payment_ref || '—');
    setText('issuePayStatusText', payment.status || textByLang('Waiting.', '等待中。'));

    const tokenImg = $('issuePayTokenImg');
    const tokenSrc = tokenImageForSymbol(payment.token_symbol || payment.token || '');
    if (tokenImg) {
      if (tokenSrc) {
        tokenImg.src = tokenSrc;
        tokenImg.style.display = '';
      } else {
        tokenImg.removeAttribute('src');
        tokenImg.style.display = 'none';
      }
    }

    const deeplink = String(payment.wallet_link || payment.deeplink || payment.wallet_url || '').trim();

    const walletLink = $('issuePayWalletLink');
    if (walletLink) {
      walletLink.href = deeplink || '#';
      walletLink.textContent = deeplink || '—';
    }

    const walletBtn = $('issuePayWalletBtn');
    if (walletBtn) {
      walletBtn.href = deeplink || '#';
      walletBtn.style.pointerEvents = deeplink ? '' : 'none';
      walletBtn.setAttribute('aria-disabled', deeplink ? 'false' : 'true');
    }

    const qrLink = $('issuePayQrLink');
    if (qrLink) {
      qrLink.href = deeplink || '#';
      qrLink.style.pointerEvents = deeplink ? '' : 'none';
      qrLink.setAttribute('aria-disabled', deeplink ? 'false' : 'true');
    }

    const qrImg = $('issuePayQrImage') || $('issuePayQrImg');
    const qrText = $('issuePayQrText');
    const qrPlaceholder = $('issuePayQrPlaceholder');

    const qrImage = String(payment.qr_image || payment.qr_url || '').trim();
    const qrValue = String(payment.qr_text || deeplink || '').trim();

    if (qrImg) {
      qrImg.removeAttribute('src');
      qrImg.style.display = 'none';
      if (qrImage) {
        qrImg.src = qrImage;
        qrImg.style.display = '';
      }
    }

    if (qrText) {
      clearQrNode(qrText);
      qrText.textContent = '';
      qrText.style.display = 'none';
    }

    if (qrPlaceholder) {
      qrPlaceholder.style.display = 'none';
      qrPlaceholder.textContent = '';
    }

    if (!qrImage && qrText && qrValue) {
      const rendered = renderClientQr(qrText, qrValue);
      if (rendered) {
        qrText.style.display = '';
      } else {
        qrText.textContent = qrValue;
        qrText.style.display = '';
      }
    }

    if (!qrImage && !qrValue && qrPlaceholder) {
      qrPlaceholder.style.display = '';
      qrPlaceholder.textContent = textByLang('QR pending...', '二维码待生成...');
    }
  }

  function mapUserFacingErrorMessage(raw) {
    const msg = String(raw || '').trim();

    if (msg.includes('RH2O_REQUIRES_10_GREEN_MINTED')) {
      return {
        title: textByLang('Preview Not Available', '暂时无法预览'),
        body: textByLang(
          'Blue RWA requires 10 minted Green RWA certificates first.',
          '蓝证需要先拥有 10 张已铸造的绿证。'
        )
      };
    }

    if (msg.includes('RBLACK_REQUIRES_1_GOLD_MINTED')) {
      return {
        title: textByLang('Preview Not Available', '暂时无法预览'),
        body: textByLang(
          'Black RWA requires 1 minted Gold RWA certificates first.',
          '黑证需要先拥有 1 张已铸造的金证。'
        )
      };
    }

    if (msg.includes('INSUFFICIENT_BALANCE')) {
      return {
        title: textByLang('Insufficient Balance', '余额不足'),
        body: shortfallMessage(state.selectedTypeKey || '')
      };
    }

    if (msg.includes('CERT_UID_REQUIRED')) {
      return {
        title: textByLang('Certificate ID Required', '缺少证书编号'),
        body: textByLang(
          'Please complete Check & Preview first before continuing.',
          '请先完成检查与预览，再继续下一步。'
        )
      };
    }

    if (msg.includes('NO_ACTIVE_PAYMENT_CERT')) {
      return {
        title: textByLang('Notice', '提示'),
        body: textByLang('No active payment certificate.', '没有可用的支付证书。')
      };
    }

    if (msg.includes('MINT_DEEPLINK_MISSING')) {
      return {
        title: textByLang('Request Failed', '请求失败'),
        body: textByLang('Mint wallet link is missing.', '缺少钱包唤起链接。')
      };
    }

    if (msg.includes('MINT_INIT_FAILED')) {
      return {
        title: textByLang('Request Failed', '请求失败'),
        body: textByLang('Finalize Mint failed.', 'Finalize Mint 失败。')
      };
    }

    return {
      title: textByLang('Request Failed', '请求失败'),
      body: msg || textByLang('Request failed. Please try again.', '请求失败，请稍后再试。')
    };
  }

  async function handleCheckPreview(typeKey, btn) {
    const meta = TYPE_MAP[typeKey];
    if (!meta) throw new Error('UNKNOWN_TYPE');

    const payload = {
      rwa_type: meta.rwa_type,
      family: meta.family,
      rwa_code: meta.rwa_code,
      wallet: currentWallet(),
      ton_wallet: currentWallet(),
      owner_user_id: currentOwnerId(),
      csrf: csrfIssue()
    };

    if (btn) setBtnLoading(btn, textByLang('Check & Preview', '检查与预览'));

    try {
      const res = await postJson(endpoint('endpointIssue', '/rwa/cert/api/issue.php'), payload);
      const certUid = String(res?.cert_uid || res?.uid || res?.cert || '').trim();
      if (!certUid) throw new Error('CERT_UID_MISSING');

      state.issueJson = res;
      applySelectedState(typeKey, certUid);
      updateActivePanel(meta, certUid, res);
      setFlowState(FLOW.PREVIEW, { cert_uid: certUid });
      await syncBalanceGuard();
      appendLogLine(`Preview ready: ${certUid}`, 'ok');
      return res;
    } finally {
      if (btn) setBtnNormal(btn, textByLang('Check & Preview', '检查与预览'));
      syncUxGuard();
    }
  }

  async function handleIssuePay() {
    const typeKey = String(state.selectedTypeKey || window.__CERT_SELECTED_TYPE || '').trim();
    const certUid = String(state.selectedCertUid || $('activeCertUid')?.textContent || '').trim();
    const meta = TYPE_MAP[typeKey];

    if (!meta) throw new Error('UNKNOWN_TYPE');
    if (!certUid || certUid === '—') throw new Error('CERT_UID_REQUIRED');

    const payload = {
      cert_uid: certUid,
      rwa_type: meta.rwa_type,
      family: meta.family,
      rwa_code: meta.rwa_code,
      wallet: currentWallet(),
      ton_wallet: currentWallet(),
      owner_user_id: currentOwnerId(),
      csrf: csrfIssue()
    };

    const res = await postJson(endpoint('endpointIssuePay', '/rwa/cert/api/issue-pay.php'), payload);
    fillIssuePayModal(certUid, res);
    openIssuePayModal();
    setFlowState(FLOW.PAYMENT, { cert_uid: certUid });
    appendLogLine(`Issue & Pay ready: ${certUid}`, 'ok');
    return res;
  }

  async function fetchVerifyStatusRow(certUid) {
    const uid = String(certUid || '').trim();
    if (!uid || uid === '—') throw new Error('CERT_UID_REQUIRED');
    const json = await getJson(`/rwa/cert/api/verify-status.php?cert_uid=${encodeURIComponent(uid)}`);
    const row = Array.isArray(json.rows) && json.rows.length ? json.rows[0] : (json.row || null);
    if (!row) throw new Error('VERIFY_STATUS_ROW_MISSING');
    return row;
  }

  function deriveFlowFromVerifyRow(row) {
    if (!row || typeof row !== 'object') return FLOW.IDLE;
    const minted = Number(row.nft_minted || 0) === 1 && String(row.queue_bucket || '') === 'issued';
    if (minted) return FLOW.ISSUED;
    if (String(row.queue_bucket || '') === 'minting_process') return FLOW.MINTING;
    if (String(row.queue_bucket || '') === 'mint_ready_queue') return FLOW.MINT_READY;
    if (Number(row.payment_verified || 0) === 1 || String(row.payment_status || '').toLowerCase() === 'confirmed') return FLOW.PAYMENT;
    return FLOW.PREVIEW;
  }

  function applyVerifyRowToActive(row) {
    if (!row || typeof row !== 'object') return;

    const certUid = String(row.cert_uid || row.uid || '').trim();
    if (certUid) {
      state.selectedCertUid = certUid;
      window.__CERT_SELECTED_UID = certUid;
      setText('activeCertUid', certUid);
      setText('issuePayCertUid', certUid);
      setText('activeMintCertUid', certUid);
    }

    state.activeQueueBucket = String(row.queue_bucket || '').trim();
    state.lastPaymentStatus = String(row.payment_status || '').trim().toLowerCase();
    state.lastPaymentVerified = Number(row.payment_verified || 0);

    setText('activePaymentRef', row.payment_ref || '—');
    setText('activePaymentText', row.payment_text || '—');
    setText('activeNftItem', row.nft_item_address || '—');

    setText('certMintTitle', certUid || '—');
    setText('certMintRecipient', row?.mint?.recipient || '—');
    setText('certMintAmount', row?.mint?.amount_ton || '—');
    setText('certMintAmountNano', row?.payment_amount_units || row?.unit_of_responsibility || row?.payment_amount || row?.mint?.amount_nano || '—');
    setText('certMintItemIndex', row?.payment_ref ? `REF: ${row.payment_ref}` : (row?.mint?.item_index || '—'));
    setText('certMintStatusText', row?.mint_status || String(row.queue_bucket || '').replaceAll('_', ' '));
    setText('certMintPayloadMini', row?.mint?.payload_b64 || '—');
    setText('certMintDeeplink', row?.deeplink || row?.mint?.deeplink || row?.mint?.wallet_link || '—');
    setText('certMintQrMeta', String(row.queue_bucket || '') === 'mint_ready_queue'
      ? 'Mint ready. Finalize Mint will hand off to NFT Factory.'
      : String(row.queue_bucket || '').replaceAll('_', ' '));

    const getgemsBtn = $('certMintGetgemsBtn');
    if (getgemsBtn) {
      getgemsBtn.href = String(row?.getgems_url || row?.verify_url || '#').trim() || '#';
    }

    const flow = deriveFlowFromVerifyRow(row);
    setFlowState(flow, { cert_uid: certUid });

    if (flow === FLOW.ISSUED) {
      setText('nextStepBanner', textByLang('Issued successfully. NFT mint confirmed.', '已成功签发。NFT 铸造已确认。'));
      stopMintPoll();
      document.dispatchEvent(new CustomEvent('cert:payment-confirmed', { detail: { cert_uid: certUid } }));
      return;
    }

    if (flow === FLOW.MINTING) {
      setText('nextStepBanner', textByLang('Wallet sign requested. Waiting for on-chain mint confirmation.', '已请求钱包签名。等待链上铸造确认。'));
      return;
    }

    if (flow === FLOW.MINT_READY) {
      setText('nextStepBanner', textByLang('Payment confirmed. Finalize Mint is ready.', '支付已确认。可继续 Finalize Mint。'));
      document.dispatchEvent(new CustomEvent('cert:payment-confirmed', { detail: { cert_uid: certUid } }));
      return;
    }

    if (flow === FLOW.PAYMENT) {
      setText('nextStepBanner', textByLang('Business payment verified. Waiting for mint handoff.', '业务支付已验证。等待铸造交接。'));
      document.dispatchEvent(new CustomEvent('cert:payment-confirmed', { detail: { cert_uid: certUid } }));
      return;
    }

    setText('nextStepBanner', textByLang('Next: Complete Business Payment', '下一步：完成业务支付'));
  }

  async function handleConfirmPayment(certUid) {
    const uid = String(certUid || state.selectedCertUid || $('issuePayCertUid')?.textContent || '').trim();
    if (!uid || uid === '—') throw new Error('NO_ACTIVE_PAYMENT_CERT');

    const res = await postJson(endpoint('endpointConfirmPayment', '/rwa/cert/api/confirm-payment.php'), {
      cert_uid: uid,
      wallet: currentWallet(),
      owner_user_id: currentOwnerId(),
      csrf: csrfConfirmPayment()
    });

    const payment = res?.payment || {};
    fillIssuePayModal(uid, { cert_uid: uid, payment, preview_row: { queue_bucket: res?.read_model?.queue_bucket || '' } });

    if (res?.read_model && typeof res.read_model === 'object') {
      applyVerifyRowToActive(res.read_model);
    } else {
      const row = await fetchVerifyStatusRow(uid);
      applyVerifyRowToActive(row);
    }

    document.dispatchEvent(new CustomEvent('cert:payment-reconfirm-done', {
      detail: { cert_uid: uid, response: res }
    }));

    appendLogLine(`Payment verify refreshed: ${uid}`, 'ok');
    return res;
  }

  function stopAutoPay() {
    if (state.autoPayTimer) {
      clearInterval(state.autoPayTimer);
      state.autoPayTimer = null;
    }
    state.autoPayUid = '';
  }

  function startAutoPay(certUid) {
    const uid = String(certUid || '').trim();
    if (!uid || uid === '—') return;

    stopAutoPay();
    state.autoPayUid = uid;

    state.autoPayTimer = window.setInterval(async () => {
      try {
        if (!state.autoPayUid) {
          stopAutoPay();
          return;
        }
        const res = await handleConfirmPayment(uid);
        const verified = res?.verified === true || res?.payment?.status === 'confirmed';
        if (verified) stopAutoPay();
      } catch (e) {
        warn('auto pay verify failed', e);
      }
    }, 5000);
  }

  function stopMintPoll() {
    if (state.mintPollTimer) {
      clearInterval(state.mintPollTimer);
      state.mintPollTimer = null;
    }
    state.mintPollUid = '';
  }

  async function pingMintVerify(certUid) {
    const uid = String(certUid || '').trim();
    if (!uid || uid === '—') return null;
    try {
      return await getJson(`/rwa/cert/api/mint-verify.php?cert_uid=${encodeURIComponent(uid)}`);
    } catch (_) {
      return null;
    }
  }

  function startMintPoll(certUid) {
    const uid = String(certUid || '').trim();
    if (!uid || uid === '—') return;

    stopMintPoll();
    state.mintPollUid = uid;

    state.mintPollTimer = window.setInterval(async () => {
      try {
        if (!state.mintPollUid) {
          stopMintPoll();
          return;
        }

        await pingMintVerify(uid);
        const row = await fetchVerifyStatusRow(uid);
        applyVerifyRowToActive(row);

        if (Number(row.nft_minted || 0) === 1 && String(row.queue_bucket || '') === 'issued') {
          stopMintPoll();
        }
      } catch (e) {
        warn('mint poll failed', e);
      }
    }, 4000);
  }

  async function requestFinalizeMint(certUid) {
    const uid = String(certUid || '').trim();
    if (!uid || uid === '—') throw new Error('CERT_UID_REQUIRED');
    const json = await getJson(`/rwa/cert/api/mint-init.php?cert_uid=${encodeURIComponent(uid)}`);
    if (!json || json.ok === false) throw new Error('MINT_INIT_FAILED');
    state.finalizeJson = json;
    return json;
  }

  function openMintWallet(finalizeJson) {
    const deeplink = String(finalizeJson?.deeplink || finalizeJson?.wallet_link || '').trim();
    if (!deeplink) throw new Error('MINT_DEEPLINK_MISSING');
    window.location.href = deeplink;
  }

  async function handleFinalizeMint(certUid) {
    const uid = String(certUid || state.selectedCertUid || $('activeMintCertUid')?.textContent || $('activeCertUid')?.textContent || '').trim();
    if (!uid || uid === '—') throw new Error('CERT_UID_REQUIRED');

    const finalizeJson = await requestFinalizeMint(uid);
    setFlowState(FLOW.MINTING, { cert_uid: uid });
    setText('nextStepBanner', textByLang('Wallet sign requested. Waiting for on-chain mint confirmation.', '已请求钱包签名。等待链上铸造确认。'));
    appendLogLine(`Finalize Mint ready: ${uid}`, 'ok');

    startMintPoll(uid);
    openMintWallet(finalizeJson);

    return finalizeJson;
  }

  function bindCardSelection() {
    document.querySelectorAll('.rwa-card').forEach((card) => {
      card.addEventListener('click', async () => {
        const typeKey = String(
          card.getAttribute('data-rule-key') ||
          card.getAttribute('data-rwa-type') ||
          ''
        ).trim();

        if (!typeKey) return;

        applySelectedState(typeKey, '');
        setText('activeFamilyPill', TYPE_MAP[typeKey]?.familyLabel || '—');
        setText('activeName', TYPE_MAP[typeKey]?.title || '—');
        setText('activeCode', TYPE_MAP[typeKey]?.rwa_code || '—');
        setText('activeSub', TYPE_MAP[typeKey]?.unit || '');
        setText('activeCertUid', '—');
        setText('activePaymentText', `${TYPE_MAP[typeKey]?.amount || '-'} ${TYPE_MAP[typeKey]?.token || ''}`.trim());
        setText('activePaymentRef', '—');
        setText('activeNftItem', '—');
        setText('nextStepBanner', textByLang('Next: Check & Preview', '下一步：检查与预览'));
        setFlowState(FLOW.IDLE);
        await syncBalanceGuard();
        log('selected type', typeKey);
      });
    });
  }

  function bindIssueButtons() {
    Object.keys(TYPE_MAP).forEach((typeKey) => {
      const btn = $(`issueBtn-${typeKey}`);
      if (!btn) return;

      btn.addEventListener('click', async (ev) => {
        ev.preventDefault();
        try {
          await handleCheckPreview(typeKey, btn);
        } catch (e) {
          err('check preview failed', e);
          appendLogLine(`Check & Preview failed: ${e.message}`, 'warn');
          const mapped = mapUserFacingErrorMessage(e?.message || e);
          showCenterNotice(mapped.body, mapped.title);
        }
      });
    });
  }

  function bindFactoryActionRow() {
    const issuePayBtn = $('rwaIssuePayBtn');
    const autoBtn = $('rwaAutoIssueBtn');
    const jumpBtn = $('rwaJumpMintBtn');

    if (issuePayBtn) {
      issuePayBtn.addEventListener('click', async () => {
        try {
          setBtnLoading(issuePayBtn, textByLang('Issue & Pay', '签发并支付'));
          await handleIssuePay();
        } catch (e) {
          err('issue pay failed', e);
          appendLogLine(`Issue & Pay failed: ${e.message}`, 'warn');
          const mapped = mapUserFacingErrorMessage(e?.message || e);
          showCenterNotice(mapped.body, mapped.title);
        } finally {
          setBtnNormal(issuePayBtn, textByLang('Issue & Pay', '签发并支付'));
        }
      });
    }

    if (autoBtn) {
      autoBtn.addEventListener('click', () => {
        try {
          const certUid = String(state.selectedCertUid || $('activeCertUid')?.textContent || '').trim();
          if (!certUid || certUid === '—') throw new Error('NO_ACTIVE_PAYMENT_CERT');
          startAutoPay(certUid);
          setFlowState(FLOW.PAYMENT, { cert_uid: certUid });
          appendLogLine(`Auto payment verify started: ${certUid}`, 'ok');
        } catch (e) {
          err('auto issue failed', e);
          appendLogLine(`Auto Issue Tx failed: ${e.message}`, 'warn');
          const mapped = mapUserFacingErrorMessage(e?.message || e);
          showCenterNotice(mapped.body, mapped.title);
        }
      });
    }

    if (jumpBtn) {
      jumpBtn.addEventListener('click', () => {
        const certUid = String(state.selectedCertUid || $('activeCertUid')?.textContent || '').trim();
        if (!certUid || certUid === '—') {
          showCenterNotice(textByLang('Select a cert first.', '请先选择证书。'), textByLang('Notice', '提示'));
          return;
        }
        const nftFactory = $('nftFactorySection');
        if (nftFactory) nftFactory.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    }
  }

  function bindIssuePayModal() {
    $('issuePayCloseBtn')?.addEventListener('click', closeIssuePayModal);

    $('issuePayModal')?.addEventListener('click', (ev) => {
      if (ev.target === $('issuePayModal')) markCertModalOpen(false);
      closeIssuePayModal();
    });

    $('issuePayCopyRefBtn')?.addEventListener('click', async () => {
      try {
        const ref = String($('issuePayRef')?.textContent || '').trim();
        if (!ref || ref === '—') return;
        await navigator.clipboard.writeText(ref);
        appendLogLine(`Copied payment ref: ${ref}`, 'ok');
      } catch (e) {
        warn('copy ref failed', e);
      }
    });

    $('issuePayCopyLinkBtn')?.addEventListener('click', async () => {
      try {
        const href = String($('issuePayWalletLink')?.href || '').trim();
        if (!href || href === '#') return;
        await navigator.clipboard.writeText(href);
        appendLogLine('Copied wallet deeplink.', 'ok');
      } catch (e) {
        warn('copy link failed', e);
      }
    });

    $('issuePayVerifyBtn')?.addEventListener('click', async () => {
      try {
        const certUid = String($('issuePayCertUid')?.textContent || '').trim();
        if (!certUid || certUid === '—') throw new Error('NO_ACTIVE_PAYMENT_CERT');
        await handleConfirmPayment(certUid);
      } catch (e) {
        err('manual confirm payment failed', e);
        appendLogLine(`Refresh verify failed: ${e.message}`, 'warn');
        const mapped = mapUserFacingErrorMessage(e?.message || e);
        showCenterNotice(mapped.body, mapped.title);
      }
    });

    $('issuePayAutoBtn')?.addEventListener('click', () => {
      const certUid = String($('issuePayCertUid')?.textContent || '').trim();
      if (!certUid || certUid === '—') {
        showCenterNotice(textByLang('No active payment cert.', '没有可用的支付证书。'), textByLang('Notice', '提示'));
        return;
      }
      startAutoPay(certUid);
      setFlowState(FLOW.PAYMENT, { cert_uid: certUid });
      appendLogLine(`Auto payment verify started: ${certUid}`, 'ok');
    });
  }

  function bindFinalizeMint() {
    const btn = $('certMintPrepareBtn');
    if (!btn) return;

    btn.addEventListener('click', async () => {
      try {
        const certUid = String(state.selectedCertUid || $('activeMintCertUid')?.textContent || $('activeCertUid')?.textContent || '').trim();
        if (!certUid || certUid === '—') throw new Error('CERT_UID_REQUIRED');

        setBtnLoading(btn, textByLang('Step 1 · Prepare & Mint Now', '第 1 步 · 准备并立即铸造'));
        await handleFinalizeMint(certUid);
      } catch (e) {
        err('finalize mint failed', e);
        appendLogLine(`Finalize Mint failed: ${e.message}`, 'error');
        const mapped = mapUserFacingErrorMessage(e?.message || e);
        showCenterNotice(mapped.body, mapped.title);
      } finally {
        setBtnNormal(btn, textByLang('Step 1 · Prepare & Mint Now', '第 1 步 · 准备并立即铸造'));
      }
    });
  }

  function bindRouterHandoff() {
    document.addEventListener('cert:nft-focus', async (ev) => {
      try {
        const certUid = String(ev?.detail?.cert_uid || '').trim();
        if (!certUid) return;
        state.selectedCertUid = certUid;
        window.__CERT_SELECTED_UID = certUid;

        const row = ev?.detail?.row || null;
        if (row && typeof row === 'object') {
          applyVerifyRowToActive(row);
        } else {
          const fresh = await fetchVerifyStatusRow(certUid);
          applyVerifyRowToActive(fresh);
        }

        const nftFactory = $('nftFactorySection');
        if (nftFactory) nftFactory.scrollIntoView({ behavior: 'smooth', block: 'start' });
      } catch (e) {
        warn('cert:nft-focus handoff failed', e);
      }
    });

    document.addEventListener('cert:payment-reconfirm', async (ev) => {
      try {
        const certUid = String(ev?.detail?.cert_uid || '').trim();
        if (!certUid) return;

        state.selectedCertUid = certUid;
        window.__CERT_SELECTED_UID = certUid;
        setText('activeCertUid', certUid);
        setText('issuePayCertUid', certUid);

        const row = ev?.detail?.row || null;
        if (row && typeof row === 'object') {
          setText('activeCode', row.rwa_code || '—');
          setText('activePaymentRef', row.payment_ref || '—');
          setText('activePaymentText', row.payment_text || '—');
          setText('activeNftItem', row.nft_item_address || '—');
          setText('nextStepBanner', textByLang('Refreshing payment verification…', '正在刷新支付验证…'));
        }

        setFlowState(FLOW.PAYMENT, { cert_uid: certUid });
        openIssuePayModal();
        await handleConfirmPayment(certUid);
      } catch (e) {
        err('cert:payment-reconfirm failed', e);
        appendLogLine(`Reconfirm Payment failed: ${e.message}`, 'warn');
        const mapped = mapUserFacingErrorMessage(e?.message || e);
        showCenterNotice(mapped.body, mapped.title);
      }
    });
  }

  function boot() {
    renderBalanceTokenImages();
    loadStorageSummary().catch(warn);
    bindCardSelection();
    bindIssueButtons();
    bindFactoryActionRow();
    bindIssuePayModal();
    bindFinalizeMint();
    bindRouterHandoff();
    syncUxGuard();
    setFlowState(FLOW.IDLE);
    log('cert-actions global role lock ready');
  }

  document.addEventListener('DOMContentLoaded', boot, { once: true });
})();
