function getSelectedCertUid() {
  const row = document.querySelector('.cert-queue-row.active');
  if (!row) return '';
  return (row.dataset.certUid || '').trim();
}

/**
 * /var/www/html/public/rwa/cert/cert-actions.js
 * Version: v24.1.1-20260410-full-restore-dom-lock
 *
 * LOCK
 * - Check & Preview owner = cert-actions.js
 * - Issue & Pay owner = cert-actions.js
 * - Issue & Pay never posts blank cert_uid
 * - Card selection -> preview -> issue pay UX guard
 * - Balance/sufficiency truth = /rwa/cert/api/check-sufficient.php
 * - Issue & Pay truth = /rwa/cert/api/issue-pay.php
 * - Mint status truth = /rwa/cert/api/verify-status.php
 * - Finalize Mint truth = /rwa/cert/api/mint-init.php
 * - On-chain mint finality must never be guessed from stale UI labels
 *
 * RESTORE NOTES
 * - Restored full DOM-aware controller for locked index.php
 * - Uses issueBtn-* and rwaIssuePayBtn
 * - Uses issuePayWalletLink / issuePayQrImage / issuePayQrText / issuePayQrPlaceholder
 * - Keeps QR fallback: qr_image first (supports https + data:image), else render client QR from qr_text / deeplink
 */

(function () {
  'use strict';

  if (window.CERT_ACTIONS_ACTIVE) return;
  window.CERT_ACTIONS_ACTIVE = true;

  const $ = (id) => document.getElementById(id);

  const TYPE_MAP = {
    green:      { rwa_type: 'green',      family: 'genesis',   rwa_code: 'RCO2C-EMA',  token: 'WEMS', amount: '1000',  title: 'Green',           familyLabel: 'GENESIS' },
    blue:       { rwa_type: 'blue',       family: 'genesis',   rwa_code: 'RH2O-EMA',   token: 'WEMS', amount: '5000',  title: 'Blue',            familyLabel: 'GENESIS' },
    black:      { rwa_type: 'black',      family: 'genesis',   rwa_code: 'RBLACK-EMA', token: 'WEMS', amount: '10000', title: 'Black',           familyLabel: 'GENESIS' },
    gold:       { rwa_type: 'gold',       family: 'genesis',   rwa_code: 'RK92-EMA',   token: 'WEMS', amount: '50000', title: 'Gold',            familyLabel: 'GENESIS' },
    pink:       { rwa_type: 'pink',       family: 'secondary', rwa_code: 'RLIFE-EMA',  token: 'EMA$', amount: '100',   title: 'Health',          familyLabel: 'SECONDARY' },
    red:        { rwa_type: 'red',        family: 'secondary', rwa_code: 'RTRIP-EMA',  token: 'EMA$', amount: '100',   title: 'Travel',          familyLabel: 'SECONDARY' },
    royal_blue: { rwa_type: 'royal_blue', family: 'secondary', rwa_code: 'RPROP-EMA',  token: 'EMA$', amount: '100',   title: 'Property',        familyLabel: 'SECONDARY' },
    yellow:     { rwa_type: 'yellow',     family: 'tertiary',  rwa_code: 'RHRD-EMA',   token: 'EMA$', amount: '100',   title: 'Human Resources', familyLabel: 'TERTIARY' }
  };

  const FLOW = {
    IDLE: 'idle',
    PREVIEW: 'preview',
    PAYMENT: 'payment',
    MINT_READY: 'mint_ready',
    MINTING: 'minting',
    ISSUED: 'issued'
  };

  const state = {
    selectedTypeKey: '',
    selectedCertUid: '',
    issueJson: null,
    issuePayJson: null,
    finalizeJson: null,
    flowState: FLOW.IDLE,
    lastPaymentStatus: '',
    lastPaymentVerified: 0,
    activeQueueBucket: '',
    autoPayTimer: null,
    autoPayUid: '',
    sufficientCache: Object.create(null),
    mintPollTimer: null,
    mintPollUid: ''
  };

  function log(...args) { console.log('[CERT]', ...args); }
  function warn(...args) { console.warn('[CERT]', ...args); }
  function err(...args) { console.error('[CERT]', ...args); }

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

  function setText(id, value) {
    const el = $(id);
    if (el) el.textContent = String(value ?? '');
  }

  function ensureNoticeModal() {
    let modal = document.getElementById('certNoticeModal');
    if (modal) return modal;

    const style = document.createElement('style');
    style.id = 'certNoticeModalStyle';
    style.textContent = `
      #certNoticeModal {
        position: fixed;
        inset: 0;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 20px;
        background: rgba(0,0,0,.72);
        z-index: 99999;
      }
      #certNoticeModal.is-open { display: flex; }
      #certNoticeModal .cert-notice-card {
        width: min(92vw, 520px);
        background: linear-gradient(180deg, #17120a 0%, #120f0a 100%);
        border: 1px solid rgba(214,185,88,.42);
        box-shadow: 0 0 0 1px rgba(214,185,88,.10) inset, 0 16px 50px rgba(0,0,0,.55);
        border-radius: 22px;
        color: #f4e6b0;
        padding: 24px 22px 18px;
        font-family: inherit;
      }
      #certNoticeModal .cert-notice-title {
        font-size: 22px;
        line-height: 1.2;
        font-weight: 700;
        margin: 0 0 14px;
        color: #ffe7a3;
        text-align: center;
      }
      #certNoticeModal .cert-notice-body {
        white-space: pre-line;
        font-size: 18px;
        line-height: 1.65;
        color: #fff3c8;
        text-align: center;
        margin: 0 0 20px;
      }
      #certNoticeModal .cert-notice-actions {
        display: flex;
        justify-content: center;
      }
      #certNoticeModal .cert-notice-ok {
        min-width: 120px;
        border: 2px solid #d6b958;
        background: #d9bf62;
        color: #2b2207;
        font-weight: 700;
        font-size: 18px;
        border-radius: 999px;
        padding: 12px 22px;
        cursor: pointer;
        box-shadow: 0 0 0 2px rgba(0,0,0,.18) inset;
      }
      @media (max-width: 640px) {
        #certNoticeModal .cert-notice-card {
          width: min(94vw, 94vw);
          border-radius: 18px;
          padding: 20px 16px 16px;
        }
      }
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

  function getLang() {
    try {
      if (window.POADO_I18N_LANG) return window.POADO_I18N_LANG;
      if (localStorage.getItem('lang')) return localStorage.getItem('lang');
    } catch (_) {}
    return 'en';
  }

  function formatNum(n) {
    const x = Number(n);
    if (!Number.isFinite(x)) return String(n ?? '');
    return x.toLocaleString(undefined, {
      minimumFractionDigits: 4,
      maximumFractionDigits: 4
    });
  }

  function i18nTitle(key) {
    const lang = getLang();
    const map = {
      insufficient_balance: { en: 'Insufficient Balance', zh: '余额不足' },
      request_failed:       { en: 'Request Failed',       zh: '请求失败' },
      verify_failed:        { en: 'Verification Failed',  zh: '验证失败' },
      notice:               { en: 'Notice',               zh: '提示' }
    };
    const item = map[key] || map.notice;
    return lang.startsWith('zh') ? item.zh : item.en;
  }

  function mapUserFacingErrorMessage(raw) {
    const msg = String(raw || '').trim();
    const zh = getLang().startsWith('zh');

    if (msg.includes('RH2O_REQUIRES_10_GREEN_MINTED')) {
      return {
        title: zh ? '暂时无法预览' : 'Preview Not Available',
        body: zh
          ? '蓝证需要先拥有 10 张已铸造的绿证。'
          : 'Blue RWA requires 10 minted Green RWA certificates first.'
      };
    }

    if (msg.includes('RBLACK_REQUIRES_1_GOLD_MINTED')) {
      return {
        title: zh ? '暂时无法预览' : 'Preview Not Available',
        body: zh
          ? '黑证需要先拥有 1 张已铸造的金证。'
          : 'Black RWA requires 1 minted Gold RWA certificates first.'
      };
    }

    if (msg.includes('CHECK_PREVIEW_LOCKED')) {
      return {
        title: zh ? '暂时无法预览' : 'Preview Not Available',
        body: zh
          ? '此证书目前还不能预览。\n请先完成所需的资格条件。'
          : 'This certificate cannot be previewed yet.\nPlease complete the required eligibility conditions first.'
      };
    }

    if (msg.includes('INSUFFICIENT_BALANCE')) {
      return {
        title: i18nTitle('insufficient_balance'),
        body: shortfallMessage(state.selectedTypeKey || '')
      };
    }

    if (msg.includes('CERT_UID_REQUIRED')) {
      return {
        title: zh ? '缺少证书编号' : 'Certificate ID Required',
        body: zh
          ? '请先完成 Check & Preview，再继续下一步。'
          : 'Please complete Check & Preview first before continuing.'
      };
    }

    if (msg.includes('NO_ACTIVE_PAYMENT_CERT')) {
      return {
        title: i18nTitle('notice'),
        body: zh ? '没有可用的支付证书。' : 'No active payment certificate.'
      };
    }

    if (msg.includes('MINT_DEEPLINK_MISSING')) {
      return {
        title: i18nTitle('request_failed'),
        body: zh ? '缺少钱包唤起链接。' : 'Mint wallet link is missing.'
      };
    }

    if (msg.includes('MINT_INIT_FAILED')) {
      return {
        title: i18nTitle('request_failed'),
        body: zh ? 'Finalize Mint 失败。' : 'Finalize Mint failed.'
      };
    }

    return {
      title: i18nTitle('request_failed'),
      body: msg || (zh ? '请求失败，请稍后再试。' : 'Request failed. Please try again.')
    };
  }

  function showCenterNotice(message, title) {
    const modal = ensureNoticeModal();
    const titleEl = modal.querySelector('#certNoticeTitle');
    const bodyEl = modal.querySelector('#certNoticeBody');
    if (titleEl) titleEl.textContent = String(title || 'Notice');
    if (bodyEl) bodyEl.textContent = String(message || '');
    modal.classList.add('is-open');
  }

  function appendLogLine(text, kind) {
    const box = $('logBox') || $('factoryConsoleLog');
    if (!box) return;
    const line = document.createElement('div');
    line.className = `log-line ${kind || ''}`.trim();
    line.textContent = text;
    box.prepend(line);
  }

  function tokenImageForSymbol(symbol) {
    const key = String(symbol || '').trim().toUpperCase();
    const map = {
      'WEMS': '/rwa/assets/tokens/wems.png',
      'EMA$': '/rwa/assets/tokens/ema.png',
      'EMA': '/rwa/assets/tokens/ema.png',
      'EMX': '/rwa/assets/tokens/emx.png',
      'EMS': '/rwa/assets/tokens/ems.png'
    };
    return map[key] || '';
  }

  function getSelectedTypeMeta() {
    const typeKey = String(
      state.selectedTypeKey ||
      window.__CERT_SELECTED_TYPE ||
      document.querySelector('.rwa-card.is-selected')?.getAttribute('data-rule-key') ||
      document.querySelector('.rwa-card.is-selected')?.getAttribute('data-rwa-type') ||
      ''
    ).trim();
    return TYPE_MAP[typeKey] || null;
  }

  async function fetchSufficientSnapshot(typeKey) {
    const wallet = currentWallet();
    const ownerUserId = currentOwnerId();

    const qs = new URLSearchParams({
      rwa_type: String(typeKey || '').trim(),
      wallet,
      owner_user_id: ownerUserId
    });

    const res = await fetch(`/rwa/cert/api/check-sufficient.php?${qs.toString()}`, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    });

    const json = await res.json().catch(() => null);
    if (!res.ok || !json || json.ok === false) {
      throw new Error((json && (json.error || json.detail)) || 'CHECK_SUFFICIENT_FAILED');
    }
    return json;
  }

  async function hasEnoughDisplayedBalance(typeKey) {
    const snap = await fetchSufficientSnapshot(typeKey);
    state.sufficientCache[typeKey] = snap;
    return snap.sufficient === true;
  }

  function shortfallMessage(typeKey) {
    const snap = state.sufficientCache[typeKey];
    if (!snap) {
      return getLang().startsWith('zh') ? '余额不足。' : 'Insufficient balance.';
    }

    const lang = getLang();
    const token = String(snap.token || '').trim();
    const need = formatNum(snap.required);
    const have = formatNum(snap.available);
    const short = formatNum(snap.shortfall);

    if (lang.startsWith('zh')) {
      return `余额不足

${token} 余额不足。
所需: ${need}
当前: ${have}
差额: ${short}`;
    }

    return `Insufficient Balance

Insufficient ${token} balance.
Required: ${need}
Available: ${have}
Shortfall: ${short}`;
  }

  async function syncBalanceGuard() {
    const meta = getSelectedTypeMeta();
    const issueBtns = document.querySelectorAll('.issue-btn');
    const issuePayBtn = $('rwaIssuePayBtn');
    const banner = $('nextStepBanner');

    if (!meta) {
      issueBtns.forEach((btn) => { btn.dataset.balanceGuard = 'no-meta'; });
      if (issuePayBtn) issuePayBtn.dataset.balanceGuard = 'no-meta';
      return;
    }

    let enough = true;
    try {
      enough = await hasEnoughDisplayedBalance(meta.rwa_type);
    } catch (e) {
      warn('balance guard fallback:', e);
      enough = true;
    }

    issueBtns.forEach((btn) => {
      const btnType = String(
        btn.getAttribute('data-rule-key') ||
        btn.getAttribute('data-rwa-type') ||
        btn.id.replace(/^issueBtn-/, '')
      ).trim();

      if (btnType === state.selectedTypeKey || btnType === window.__CERT_SELECTED_TYPE) {
        btn.dataset.balanceGuard = enough ? 'ok' : 'insufficient';
      }
    });

    if (issuePayBtn) {
      issuePayBtn.dataset.balanceGuard = enough ? 'ok' : 'insufficient';
      issuePayBtn.disabled = !state.selectedCertUid || state.selectedCertUid === '—' ? true : !enough;
    }

    if (!enough && banner) {
      const snap = state.sufficientCache[meta.rwa_type];
      banner.textContent = `Insufficient ${snap?.token || meta.token} balance.`;
    }
  }

  function setBtnLoading(btn, label) {
    if (!btn) return;
    btn.dataset.normalLabel = label || btn.dataset.normalLabel || btn.textContent || '';
    btn.disabled = true;
    btn.setAttribute('data-loading', '1');
    btn.textContent = `${btn.dataset.normalLabel} …`;
  }

  function setBtnNormal(btn, label) {
    if (!btn) return;
    btn.disabled = false;
    btn.removeAttribute('data-loading');
    btn.textContent = label || btn.dataset.normalLabel || btn.textContent || '';
  }

  function getSelectedCard() {
    return document.querySelector('.rwa-card.is-selected');
  }

  function getCardByType(typeKey) {
    return document.querySelector(`.rwa-card[data-rule-key="${CSS.escape(typeKey)}"]`)
      || document.querySelector(`.rwa-card[data-rwa-type="${CSS.escape(typeKey)}"]`);
  }

  function syncUxGuard() {
    const selectedCard = getSelectedCard();

    const typeKey = String(
      state.selectedTypeKey ||
      selectedCard?.getAttribute('data-rule-key') ||
      selectedCard?.getAttribute('data-rwa-type') ||
      window.__CERT_SELECTED_TYPE ||
      ''
    ).trim();

    const certUid = String(
      state.selectedCertUid ||
      $('activeCertUid')?.textContent ||
      selectedCard?.getAttribute('data-cert-uid') ||
      window.__CERT_SELECTED_UID ||
      ''
    ).trim();

    const issuePayBtn = $('rwaIssuePayBtn');
    if (issuePayBtn) issuePayBtn.disabled = !(typeKey && certUid && certUid !== '—');

    const autoBtn = $('rwaAutoIssueBtn');
    if (autoBtn) autoBtn.disabled = !(certUid && certUid !== '—');

    const jumpBtn = $('rwaJumpMintBtn');
    if (jumpBtn) jumpBtn.disabled = !(certUid && certUid !== '—');
  }

  function forceUnlockActionButtons() {
    document.querySelectorAll('.issue-btn').forEach((btn) => {
      btn.removeAttribute('data-loading');
      btn.disabled = false;
      btn.textContent = 'Check & Preview';
    });

    const issuePayBtn = $('rwaIssuePayBtn');
    if (issuePayBtn) {
      issuePayBtn.removeAttribute('data-loading');
      issuePayBtn.textContent = 'Issue & Pay';
    }

    syncUxGuard();
  }

  async function post(url, payload) {
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
        await syncBalanceGuard();
        log('selected type', typeKey);
      });
    });
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
    el.classList.remove(
      'is-active',
      'is-done',
      'is-next',
      'is-pulse',
      'is-current-preview',
      'is-current-payment',
      'is-current-mint-ready',
      'is-current-minting',
      'is-current-issued'
    );
  }

  function paintStepCards(flow) {
    const steps = [
      $('factoryStep1'),
      $('factoryStep2'),
      $('factoryStep3'),
      $('factoryStep4'),
      $('factoryStep5')
    ];

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
        if (n === 1) {
          el.classList.add('is-next', 'is-pulse', 'is-current-preview');
        }
        return;
      }

      if (n < current) {
        el.classList.add('is-done');
        return;
      }

      if (n === current) {
        el.classList.add('is-active', 'is-pulse');
        if (flow === FLOW.PREVIEW) el.classList.add('is-current-preview');
        if (flow === FLOW.PAYMENT) el.classList.add('is-current-payment');
        if (flow === FLOW.MINT_READY) el.classList.add('is-current-mint-ready');
        if (flow === FLOW.MINTING) el.classList.add('is-current-minting');
        if (flow === FLOW.ISSUED) el.classList.add('is-current-issued');
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
    else if (flow === FLOW.PAYMENT) setText('activeStatusText', 'PAYMENT CONFIRMED');
    else if (flow === FLOW.PREVIEW) setText('activeStatusText', 'PREVIEW READY');
    else setText('activeStatusText', 'READY');

    syncUxGuard();
  }

  function stopAutoPay() {
    if (state.autoPayTimer) {
      clearInterval(state.autoPayTimer);
      state.autoPayTimer = null;
    }
    state.autoPayUid = '';
  }

  function stopMintPoll() {
    if (state.mintPollTimer) {
      clearInterval(state.mintPollTimer);
      state.mintPollTimer = null;
    }
    state.mintPollUid = '';
  }

  function isFinalMintedRow(row) {
    if (!row || typeof row !== 'object') return false;
    return Number(row.nft_minted || 0) === 1
      && String(row.nft_item_address || '').trim() !== ''
      && String(row.minted_at || '').trim() !== ''
      && String(row.queue_bucket || '').trim() === 'issued';
  }

  function deriveFlowFromVerifyRow(row) {
    if (!row || typeof row !== 'object') return FLOW.IDLE;
    if (isFinalMintedRow(row)) return FLOW.ISSUED;

    const queueBucket = String(row.queue_bucket || '').trim();
    if (queueBucket === 'minting_process') return FLOW.MINTING;
    if (queueBucket === 'mint_ready_queue') return FLOW.MINT_READY;

    const paymentReady = Number(row.payment_verified || 0) === 1
      || String(row.payment_status || '').trim().toLowerCase() === 'confirmed';

    if (paymentReady) return FLOW.PAYMENT;
    return FLOW.PREVIEW;
  }

  function applyVerifyRowToActive(row) {
    if (!row || typeof row !== 'object') return;

    const certUid = String(row.cert_uid || row.uid || row.cert || '').trim();
    if (certUid) {
      state.selectedCertUid = certUid;
      window.__CERT_SELECTED_UID = certUid;
      setText('activeCertUid', certUid);
    }

    state.activeQueueBucket = String(row.queue_bucket || '').trim();
    state.lastPaymentStatus = String(row.payment_status || '').trim().toLowerCase();
    state.lastPaymentVerified = Number(row.payment_verified || 0);

    setText('activePaymentRef', row.payment_ref || '—');
    setText('activePaymentText', row.payment_text || '—');
    setText('activeNftItem', row.nft_item_address || '—');

    const flow = deriveFlowFromVerifyRow(row);
    setFlowState(flow, { cert_uid: certUid });

    if (flow === FLOW.ISSUED) {
      setText('nextStepBanner', 'Issued successfully. NFT mint confirmed.');
      appendLogLine(`Issued: ${certUid}`, 'ok');
      stopMintPoll();
      return;
    }

    if (flow === FLOW.MINTING) {
      setText('nextStepBanner', 'Wallet sign requested. Waiting for on-chain mint confirmation.');
      return;
    }

    if (flow === FLOW.MINT_READY) {
      setText('nextStepBanner', 'Payment confirmed. Finalize Mint is ready.');
      return;
    }

    if (flow === FLOW.PAYMENT) {
      setText('nextStepBanner', 'Business payment verified. Waiting for mint handoff.');
      return;
    }

    setText('nextStepBanner', 'Next: Complete Business Payment');
  }

  async function fetchVerifyStatusRow(certUid) {
    const uid = String(certUid || '').trim();
    if (!uid || uid === '—') throw new Error('CERT_UID_REQUIRED');

    const url = `/rwa/cert/api/verify-status.php?cert_uid=${encodeURIComponent(uid)}`;
    const res = await fetch(url, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    });

    const json = await res.json().catch(() => null);
    if (!res.ok || !json || json.ok === false) {
      throw new Error((json && (json.error || json.detail)) || 'VERIFY_STATUS_FAILED');
    }

    const row = Array.isArray(json.rows) && json.rows.length ? json.rows[0] : (json.row || null);
    if (!row) throw new Error('VERIFY_STATUS_ROW_MISSING');
    return row;
  }

  async function refreshMintStatus(certUid) {
    const row = await fetchVerifyStatusRow(certUid);
    applyVerifyRowToActive(row);
    return row;
  }

  async function pingMintVerify(certUid) {
    const uid = String(certUid || '').trim();
    if (!uid || uid === '—') return null;

    try {
      const res = await fetch(`/rwa/cert/api/mint-verify.php?cert_uid=${encodeURIComponent(uid)}`, {
        method: 'GET',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      });
      return await res.json().catch(() => null);
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
        const row = await refreshMintStatus(uid);

        if (isFinalMintedRow(row)) {
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

    const res = await fetch(`/rwa/cert/api/mint-init.php?cert_uid=${encodeURIComponent(uid)}`, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    });

    const json = await res.json().catch(() => null);
    if (!res.ok || !json || json.ok === false) {
      throw new Error((json && (json.error || json.detail)) || 'MINT_INIT_FAILED');
    }

    state.finalizeJson = json;
    return json;
  }

  function openMintWallet(finalizeJson) {
    const deeplink = String(finalizeJson?.deeplink || finalizeJson?.wallet_link || '').trim();
    if (!deeplink) throw new Error('MINT_DEEPLINK_MISSING');
    window.location.href = deeplink;
  }

  async function handleFinalizeMint(certUid) {
    const uid = String(certUid || state.selectedCertUid || '').trim();
    if (!uid || uid === '—') throw new Error('CERT_UID_REQUIRED');

    const finalizeJson = await requestFinalizeMint(uid);

    setFlowState(FLOW.MINTING, { cert_uid: uid });
    setText('nextStepBanner', 'Wallet sign requested. Waiting for on-chain mint confirmation.');
    appendLogLine(`Finalize Mint ready: ${uid}`, 'ok');

    startMintPoll(uid);
    openMintWallet(finalizeJson);

    return finalizeJson;
  }

  function updateActiveFromIssue(typeKey, certUid, json) {
    const meta = TYPE_MAP[typeKey] || {};
    const preview = json?.preview || {};
    const row = json?.preview_row || {};
    const payment = preview?.payment || {};

    state.issueJson = json;
    applySelectedState(typeKey, certUid);

    setText('activeFamilyPill', meta.familyLabel || '—');
    setText('activeName', preview.rwa_code || meta.rwa_code || meta.title || 'Preview Ready');
    setText('activeCode', row.rwa_code || meta.rwa_code || '—');
    setText('activeSub', 'Preview response received. Continue to Issue & Pay.');
    setText('activeCertUid', certUid || '—');
    setText('activePaymentText', preview.payment_text || `${meta.amount || '-'} ${meta.token || ''}`.trim());
    setText('activePaymentRef', row.payment_ref || preview.payment_ref || payment.payment_ref || '—');
    setText('activeNftItem', row.nft_item_address || '—');
    setText('nextStepBanner', 'Next: Complete Business Payment');

    setFlowState(FLOW.PREVIEW, { cert_uid: certUid });
    syncBalanceGuard();
    appendLogLine(`Preview ready: ${certUid}`, 'ok');
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

    if (btn) setBtnLoading(btn, 'Check & Preview');

    try {
      const res = await post(endpoint('endpointIssue', '/rwa/cert/api/issue.php'), payload);
      const certUid = String(res?.cert_uid || res?.uid || res?.cert || '').trim();
      if (!certUid) throw new Error('CERT_UID_MISSING');
      updateActiveFromIssue(typeKey, certUid, res);
      return res;
    } finally {
      if (btn) setBtnNormal(btn, 'Check & Preview');
      forceUnlockActionButtons();
    }
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
    setText('issuePayStatusText', payment.status || 'Waiting.');

    const deeplink = String(payment.wallet_link || payment.deeplink || payment.wallet_url || '').trim();

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

    const linkEl = $('issuePayWalletLink');
    if (linkEl) {
      linkEl.href = deeplink || '#';
      linkEl.textContent = deeplink || '—';
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

    let renderedClientQr = false;
    let renderedQrImg = false;

    if (qrImg) {
      qrImg.removeAttribute('src');
      qrImg.style.display = 'none';

      if (qrImage) {
        qrImg.src = qrImage;
        qrImg.style.display = '';
        renderedQrImg = true;

        qrImg.onerror = () => {
          qrImg.style.display = 'none';
          qrImg.removeAttribute('src');

          if (qrText && qrValue) {
            clearQrNode(qrText);
            renderedClientQr = renderClientQr(qrText, qrValue);
            if (renderedClientQr) {
              qrText.style.display = '';
              if (qrPlaceholder) qrPlaceholder.style.display = 'none';
            } else {
              qrText.textContent = qrValue;
              qrText.style.display = '';
              if (qrPlaceholder) qrPlaceholder.style.display = 'none';
            }
          } else if (qrPlaceholder) {
            qrPlaceholder.style.display = '';
            qrPlaceholder.textContent = 'QR pending...';
          }
        };
      }
    }

    if (qrText) {
      clearQrNode(qrText);
      qrText.textContent = '';
      qrText.style.display = 'none';

      if (!renderedQrImg && qrValue) {
        renderedClientQr = renderClientQr(qrText, qrValue);
        if (renderedClientQr) {
          qrText.style.display = '';
        } else {
          qrText.textContent = qrValue;
          qrText.style.display = '';
        }
      }
    }

    if (qrPlaceholder) {
      const hasImg = renderedQrImg || !!(qrImg && qrImg.style.display !== 'none');
      const hasFallback = !!qrValue;
      qrPlaceholder.style.display = (!hasImg && !hasFallback) ? '' : 'none';
      if (!hasImg && !hasFallback) {
        qrPlaceholder.textContent = 'QR pending...';
      }
    }

    const statusBox = $('issuePayStatus');
    if (statusBox) {
      statusBox.classList.remove('payment-status--pending', 'payment-status--confirmed', 'payment-status--error');
      const status = String(payment.status || '').toLowerCase();
      const verified = Number(payment.verified || 0) === 1;
      if (verified || status === 'confirmed') statusBox.classList.add('payment-status--confirmed');
      else if (status && status !== 'pending') statusBox.classList.add('payment-status--error');
      else statusBox.classList.add('payment-status--pending');
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

  async function handleConfirmPayment(certUid) {
    const res = await post(endpoint('endpointConfirmPayment', '/rwa/cert/api/confirm-payment.php'), {
      cert_uid: certUid,
      uid: certUid,
      cert: certUid,
      csrf: csrfConfirmPayment()
    });

    const payment = res?.payment || {};
    const verified = res?.verified === true || Number(payment.verified || 0) === 1 || String(payment.status || '').toLowerCase() === 'confirmed';

    fillIssuePayModal(certUid, { payment, preview_row: res?.preview_row || {} });

    if (verified) {
      setFlowState(FLOW.MINT_READY, { cert_uid: certUid });
      setText('nextStepBanner', 'Payment confirmed. Continue in Mint Ready Queue.');
      stopAutoPay();
      appendLogLine(`Payment confirmed: ${certUid}`, 'ok');
      try {
        await refreshMintStatus(certUid);
      } catch (e) {
        warn('verify-status refresh after payment failed', e);
      }
    } else {
      setFlowState(FLOW.PAYMENT, { cert_uid: certUid });
      appendLogLine(`Payment still pending: ${certUid}`, 'warn');
    }

    return res;
  }

  function startAutoPay(certUid) {
    if (!certUid) return;
    stopAutoPay();
    state.autoPayUid = certUid;
    state.autoPayTimer = window.setInterval(async () => {
      try {
        if (!state.autoPayUid) return stopAutoPay();
        await handleConfirmPayment(certUid);
      } catch (e) {
        warn('auto payment verify failed', e);
      }
    }, 5000);
  }

  async function handleIssuePay() {
    let certUid = String(state.selectedCertUid || '').trim();
    let typeKey = String(state.selectedTypeKey || '').trim();

    if (!typeKey) {
      const selectedCard = getSelectedCard();
      if (selectedCard) {
        typeKey = String(
          selectedCard.getAttribute('data-rule-key') ||
          selectedCard.getAttribute('data-rwa-type') ||
          window.__CERT_SELECTED_TYPE ||
          ''
        ).trim();
      }
    }

    if (typeKey) {
      state.selectedTypeKey = typeKey;
      window.__CERT_SELECTED_TYPE = typeKey;
    }

    if (!certUid) {
      certUid = String($('activeCertUid')?.textContent || '').trim();
    }

    if ((!certUid || certUid === '—') && typeKey) {
      const card = getCardByType(typeKey);
      const cardUid = String(card?.getAttribute('data-cert-uid') || '').trim();
      if (cardUid) certUid = cardUid;
    }

    if (!certUid || certUid === '—') {
      if (!typeKey) {
        throw new Error('Please run Check & Preview first so a valid Cert UID is created.');
      }

      appendLogLine(`Issue & Pay requested without cert UID. Auto-running Check & Preview for ${typeKey}.`, 'warn');

      const previewBtn = $(`issueBtn-${typeKey}`);
      const previewRes = await handleCheckPreview(typeKey, previewBtn || null);
      certUid = String(previewRes?.cert_uid || previewRes?.uid || previewRes?.cert || '').trim();
    }

    if (!certUid || certUid === '—') {
      appendLogLine('BLOCKED: Missing cert_uid before POST', 'error');
      throw new Error('CERT_UID_REQUIRED');
    }

    state.selectedCertUid = certUid;
    window.__CERT_SELECTED_UID = certUid;

    const selectedCard = getCardByType(typeKey) || getSelectedCard();
    if (selectedCard) {
      selectedCard.setAttribute('data-cert-uid', certUid);
    }

    setText('activeCertUid', certUid);

    const res = await post(endpoint('endpointIssuePay', '/rwa/cert/api/issue-pay.php'), {
      cert_uid: certUid,
      uid: certUid,
      cert: certUid
    });

    log('issue pay ok', res);
    fillIssuePayModal(certUid, res);
    openIssuePayModal();

    const payment = res?.payment || {};
    const isConfirmed =
      String(payment.status || '').toLowerCase() === 'confirmed' ||
      Number(payment.verified || 0) === 1;

    setText('activePaymentText', `${payment.amount || '—'} ${payment.token_symbol || payment.token || ''}`.trim());
    setText('activePaymentRef', payment.payment_ref || '—');
    setText('nextStepBanner', isConfirmed ? 'Payment already confirmed. Ready for Mint Queue.' : 'Business payment modal opened.');
    syncUxGuard();
    syncBalanceGuard();

    appendLogLine(`Issue & Pay opened: ${certUid}`, 'ok');

    if (!isConfirmed) {
      setFlowState(FLOW.PAYMENT, { cert_uid: certUid });
      startAutoPay(certUid);
    } else {
      setFlowState(FLOW.MINT_READY, { cert_uid: certUid });
      try {
        await refreshMintStatus(certUid);
      } catch (e) {
        warn('verify-status refresh after issue-pay failed', e);
      }
    }

    return res;
  }

  function bindCheckPreview() {
    Object.keys(TYPE_MAP).forEach((typeKey) => {
      const btn = $(`issueBtn-${typeKey}`);
      if (!btn) return;

      btn.addEventListener('click', async () => {
        try {
          forceUnlockActionButtons();
          applySelectedState(typeKey, '');

          const enough = await hasEnoughDisplayedBalance(typeKey);
          if (!enough) {
            appendLogLine(`Blocked: insufficient balance for ${typeKey}.`, 'error');
            showCenterNotice(shortfallMessage(typeKey), i18nTitle('insufficient_balance'));
            await syncBalanceGuard();
            return;
          }

          await handleCheckPreview(typeKey, btn);
        } catch (e) {
          err('preview failed', e);
          appendLogLine(`Check & Preview failed: ${e.message}`, 'error');
          const mapped = mapUserFacingErrorMessage(e?.message || e);
          showCenterNotice(mapped.body, mapped.title);
        } finally {
          setBtnNormal(btn, 'Check & Preview');
          forceUnlockActionButtons();
        }
      });
    });
  }

  function bindIssuePay() {
    const btn = $('rwaIssuePayBtn');
    if (btn) {
      btn.addEventListener('click', async () => {
        try {
          forceUnlockActionButtons();

          const meta = getSelectedTypeMeta();
          if (meta) {
            const enough = await hasEnoughDisplayedBalance(meta.rwa_type);
            if (!enough) {
              appendLogLine(`Blocked: insufficient balance for ${meta.rwa_type}.`, 'error');
              showCenterNotice(shortfallMessage(meta.rwa_type), i18nTitle('insufficient_balance'));
              await syncBalanceGuard();
              return;
            }
          }

          setBtnLoading(btn, 'Issue & Pay');
          await handleIssuePay();
        } catch (e) {
          err('issue pay failed', e);
          appendLogLine(`Issue & Pay failed: ${e.message}`, 'error');
          const mapped = mapUserFacingErrorMessage(e?.message || e);
          showCenterNotice(mapped.body, mapped.title);
        } finally {
          setBtnNormal(btn, 'Issue & Pay');
          forceUnlockActionButtons();
        }
      });
    }

    const autoBtn = $('rwaAutoIssueBtn');
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
          showCenterNotice(mapped.body, mapped.title || i18nTitle('notice'));
        }
      });
    }

    const jumpBtn = $('rwaJumpMintBtn');
    if (jumpBtn) {
      jumpBtn.addEventListener('click', () => {
        const certUid = String(state.selectedCertUid || $('activeCertUid')?.textContent || '').trim();
        if (!certUid || certUid === '—') {
          showCenterNotice(getLang().startsWith('zh') ? '请先选择证书。' : 'Select a cert first.', i18nTitle('notice'));
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
      if (ev.target === $('issuePayModal')) closeIssuePayModal();
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
        showCenterNotice(mapped.body, mapped.title || i18nTitle('verify_failed'));
      }
    });

    $('issuePayAutoBtn')?.addEventListener('click', () => {
      const certUid = String($('issuePayCertUid')?.textContent || '').trim();
      if (!certUid || certUid === '—') {
        showCenterNotice(getLang().startsWith('zh') ? '没有可用的支付证书。' : 'No active payment cert.', i18nTitle('notice'));
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

        setBtnLoading(btn, 'Step 1 · Prepare & Mint Now');
        await handleFinalizeMint(certUid);
      } catch (e) {
        err('finalize mint failed', e);
        appendLogLine(`Finalize Mint failed: ${e.message}`, 'error');
        const mapped = mapUserFacingErrorMessage(e?.message || e);
        showCenterNotice(mapped.body, mapped.title);
      } finally {
        setBtnNormal(btn, 'Step 1 · Prepare & Mint Now');
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
        setText('activeCertUid', certUid);
        setText('activeMintCertUid', certUid);

        try {
          await refreshMintStatus(certUid);
        } catch (e) {
          warn('handoff verify-status refresh failed', e);
        }

        const nftFactory = $('nftFactorySection');
        if (nftFactory) nftFactory.scrollIntoView({ behavior: 'smooth', block: 'start' });
      } catch (e) {
        warn('router handoff failed', e);
      }
    });
  }

  async function boot() {
    bindCardSelection();
    bindCheckPreview();
    bindIssuePay();
    bindIssuePayModal();
    bindFinalizeMint();
    bindRouterHandoff();

    setFlowState(FLOW.IDLE);
    forceUnlockActionButtons();
    syncUxGuard();
    await syncBalanceGuard();

    if (state.selectedCertUid) {
      try {
        await refreshMintStatus(state.selectedCertUid);
      } catch (e) {
        warn('initial verify-status sync failed', e);
      }
    }

    log('cert-actions ready');
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => { boot().catch(err); }, { once: true });
  } else {
    boot().catch(err);
  }
})();

function renderFactoryPayload(json) {
  const el = document.getElementById('factoryPayload');
  if (!el) return;

  const payload = json.payload_b64 || '';
  const wallet = json.wallet_link || '';

  el.innerHTML = `
    <div class="payload-box">
      <div><b>Wallet Link</b></div>
      <a href="${wallet}" target="_blank">${wallet}</a>

      <div style="margin-top:10px;"><b>Payload</b></div>
      <textarea style="width:100%;height:120px;">${payload}</textarea>
    </div>
  `;
}
