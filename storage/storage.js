/*!
 * /var/www/html/public/rwa/storage/storage.js
 * AdoptGold / POAdo — Storage Index
 * Version: FINAL-LOCK-7
 *
 * Storage Master v7.8f — overview-only balance writer
 * Locked updates:
 * - preserve existing Storage preview/card behavior
 * - do not overwrite card preview container; update only dedicated DOM ids
 * - keep /rwa/api/storage/balance.php and /rwa/api/storage/balances.php in endpoint config for rollback safety
 * - Storage UI balances now render from /rwa/api/storage/overview.php only
 * - card balance display symbol locked to RWA€
 * - keep activation / reload / commit / claim / fuel wiring
 * - no broad selector writes for card preview
 */

(function () {
  'use strict';

  const BOOT = window.STORAGE_PAGE_BOOT || {};
  const BODY = document.body;
  const RWA_CARD_SYMBOL = 'RWA€';

  const state = {
    lang: (BOOT.lang || BODY?.dataset?.lang || 'en').toLowerCase(),
    userId: String(BOOT.userId || BODY?.dataset?.userId || '0'),
    wallet: String(BOOT.wallet || BODY?.dataset?.wallet || ''),
    walletAddress: String(BOOT.walletAddress || BODY?.dataset?.walletAddress || ''),
    boundTonAddress: String(BOOT.boundTonAddress || BOOT.walletAddress || BODY?.dataset?.walletAddress || ''),
    displayName: String(BOOT.displayName || BODY?.dataset?.name || 'Storage User'),
    hasTonBind: !!BOOT.hasTonBind || BODY?.dataset?.hasTonBind === '1',
    emailVerified: !!BOOT.emailVerified || BODY?.dataset?.emailVerified === '1',
    tradeUrl: String(BODY?.dataset?.tradeUrl || ''),
    endpoints: {
      activatePrepare: String(BOOT?.activation?.prepareUrl || '/rwa/api/storage/activate-card/activate-prepare.php'),
      activateVerify: String(BOOT?.activation?.verifyUrl || '/rwa/api/storage/activate-card/activate-verify.php'),
      activateConfirm: String(BOOT?.activation?.confirmUrl || '/rwa/api/storage/activate-card/activate-confirm.php'),
      activateRouter: String(BOOT?.activation?.routerUrl || '/rwa/api/storage/activate-card/activate.php'),
      balanceMain: String(BOOT?.storage?.balanceUrl || '/rwa/api/storage/balance.php'),
      balanceResolver: String(BOOT?.storage?.balancesUrl || '/rwa/api/storage/balances.php'),
      overview: String(BOOT?.storage?.overviewUrl || '/rwa/api/storage/overview.php'),
      address: String(BOOT?.storage?.addressUrl || '/rwa/api/storage/address.php'),
      history: String(BOOT?.storage?.historyUrl || '/rwa/api/storage/history.php')
    },
    csrf: {
      bind: String(BOOT?.csrf?.bind || BODY?.dataset?.bindCsrf || ''),
      activate: String(BOOT?.csrf?.activate || BODY?.dataset?.activateCsrf || ''),
      reload: String(BOOT?.csrf?.reload || BODY?.dataset?.reloadCsrf || ''),
      commit: String(BOOT?.csrf?.commit || BODY?.dataset?.commitCsrf || ''),
      claim: String(BOOT?.csrf?.claim || BODY?.dataset?.claimCsrf || ''),
      fuelEmx: String(BOOT?.csrf?.fuelEmx || BODY?.dataset?.fuelEmxCsrf || ''),
      fuelEmxConfirm: String(BOOT?.csrf?.fuelEmxConfirm || BODY?.dataset?.fuelEmxConfirmCsrf || ''),
      fuelEms: String(BOOT?.csrf?.fuelEms || BODY?.dataset?.fuelEmsCsrf || '')
    },
    balances: {
      card_balance_rwa: '0.000000',
      onchain_emx: '0.000000',
      onchain_ema: '0.000000',
      onchain_wems: '0.000000',
      unclaim_ema: '0.000000',
      unclaim_wems: '0.000000',
      unclaim_gold_packet_usdt: '0.000000',
      unclaim_tips_emx: '0.000000',
      fuel_usdt_ton: '0.000000',
      fuel_ems: '0.000000',
      fuel_ton_gas: '0.000000'
    },
    claimable: {
      claim_ema: null,
      claim_wems: null,
      claim_usdt_ton: null,
      claim_emx_tips: null,
      fuel_ems: null
    },
    card: {
      number: '',
      status: 'none',
      locked: false,
      is_active: false,
      inputValue: ''
    },
    activation: {
      ref: '',
      treasury: '',
      required_emx: '100.000000',
      required_units: '',
      token: 'EMX',
      decimals: 9,
      tx_hash: '',
      ema_price_snapshot: '',
      ema_reward: '',
      reward_token: 'EMA',
      reward_status: '',
      payment_request: null,
      payment_qr_text: '',
      payment_qr_payload: '',
      ton_transfer_uri: '',
      jetton_master: '',
      memo: '',
      pending: false,
      verified: false,
      ui_open: false,
      success_summary: ''
    },
    autoVerify: {
      enabled: false,
      timer: null,
      running: false,
      startedAt: 0,
      timeoutMs: 120000,
      intervalMs: 5000,
      endpoint: String(BOOT?.activation?.verifyUrl || '/rwa/api/storage/activate-card/activate-verify.php')
    },
    sync: {
      running: false,
      timer: null,
      lastAt: 0,
      debounceMs: 1200
    },
    locks: {
      bind: false,
      activate: false,
      activateConfirm: false,
      commit: false,
      reload: false,
      claim: false,
      fuelEmx: false,
      fuelEms: false
    }
  };

  const TEXT = {
    en: {
      bindCard: 'Bind Card',
      clear: 'Clear',
      activateCard: 'Activate Card (100 EMX)',
      copied: 'Copied',
      copyFailed: 'Copy failed',
      noTon: 'Bind TON first',
      needEmail: 'Please verify your email first.',
      needCard: 'Please enter 16 digits.',
      invalidAmount: 'Please enter a valid amount.',
      loading: 'Loading...',
      bindSuccess: 'Card bound successfully.',
      bindFail: 'Bind card failed.',
      bindLocked: 'Card is locked after activation. Admin release required',
      actionPrepared: 'Prepared successfully.',
      actionFailed: 'Action failed.',
      historyEmpty: 'No recent activity.',
      qrEmpty: 'Bind TON to view QR',
      activationQrEmpty: 'Activation QR will appear after prepare',
      alreadyActive: 'Card already active.',
      loginRequired: 'Login required',
      activateFirst: 'Please activate card first',
      activationRefMissing: 'Activation reference missing',
      txHashRequired: 'Transaction hash required',
      invalidResponse: 'Server returned invalid response',
      invalidJson: 'Server returned invalid JSON',
      requestRunning: 'Request already in progress',
      syncIdle: 'Chain sync ready',
      syncingChain: 'Syncing on-chain balances...',
      syncUpdated: 'On-chain balances updated',
      syncFailed: 'Unable to refresh chain balances',
      activationConfirmed: 'Card activated successfully',
      activationPanelHidden: 'Activation confirmation is not required right now.',
      preparedNoMutation: 'Prepared only. Balances stay unchanged until verification.',
      pendingChainSync: 'Pending blockchain sync',
      bindCardFirst: 'Bind card first',
      paymentNotVerifiedYet: 'Payment not verified yet',
      activationMemoCopied: 'Memo copied',
      activationRefCopied: 'Reference copied',
      publicDepositNumber: 'Public Swap Number',
      cardNumberVisible: 'Card number is public and fully visible',
      walletPaymentReady: 'EMX payment request prepared',
      waitingTxHash: 'Manual tx hash is optional',
      paymentQrReady: 'EMX QR ready',
      treasuryNote: 'Send exact 100 EMX from your bound TON address',
      autoVerifyWaiting: 'Waiting for EMX payment',
      autoVerifyDetecting: 'Detecting payment automatically...',
      autoVerifyTimeout: 'Auto verify timed out. Paste tx hash if needed',
      autoVerifyVerified: 'Payment verified automatically',
      autoVerifyStart: 'Auto verification started',
      autoVerifyManualFallback: 'Manual tx hash confirm remains available',
      emxOnlyRule: 'Activation requires exact 100 EMX only',
      cardDraft: 'Card is editable before activation',
      cardLocked: 'Card is locked after activation',
      openDeepLink: 'Open Payment Deep Link',
      reloadHelperMissing: 'Reload helper not loaded',
      reloadConfirmed: 'Reload confirmed',
      rewardCredited: 'Free EMA$ credited',
      rewardAlreadyCredited: 'EMA$ reward already credited',
      emaPriceSnapshot: 'EMA Price Snapshot',
      activationClosed: 'Activation window closed',
      refreshAutoConfirm: 'Refresh Auto Confirm',
      close: 'Close',
      claimModalNotReady: 'Claim module not wired yet',
      fuelModalNotReady: 'Fuel module not wired yet',
      reserveAwareReady: 'Reserve-aware balances ready'
    },
    zh: {
      bindCard: '绑定卡',
      clear: '清除',
      activateCard: '激活卡（100 EMX）',
      copied: '已复制',
      copyFailed: '复制失败',
      noTon: '请先绑定 TON',
      needEmail: '请先完成邮箱验证。',
      needCard: '请输入16位卡号。',
      invalidAmount: '请输入有效数量。',
      loading: '加载中...',
      bindSuccess: '绑定卡成功。',
      bindFail: '绑定卡失败。',
      bindLocked: '卡在激活后已锁定，需管理员释放',
      actionPrepared: '预备成功。',
      actionFailed: '操作失败。',
      historyEmpty: '暂无记录。',
      qrEmpty: '绑定 TON 后显示二维码',
      activationQrEmpty: '激活预备后显示付款二维码',
      alreadyActive: '该卡已激活。',
      loginRequired: '需要登录',
      activateFirst: '请先激活卡',
      activationRefMissing: '缺少激活编号',
      txHashRequired: '请输入交易哈希',
      invalidResponse: '服务器返回了无效响应',
      invalidJson: '服务器返回了无效 JSON',
      requestRunning: '请求处理中，请勿重复提交',
      syncIdle: '链上同步已就绪',
      syncingChain: '正在同步链上余额...',
      syncUpdated: '链上余额已更新',
      syncFailed: '链上余额刷新失败',
      activationConfirmed: '卡已成功激活',
      activationPanelHidden: '当前无需激活确认。',
      preparedNoMutation: '当前仅为预备状态，余额不会在验证前变动。',
      pendingChainSync: '等待区块链同步',
      bindCardFirst: '请先绑定卡',
      paymentNotVerifiedYet: '付款尚未验证',
      activationMemoCopied: '备注已复制',
      activationRefCopied: '编号已复制',
      publicDepositNumber: '公开存款编号',
      cardNumberVisible: '卡号为公开存款编号，保持完整显示',
      walletPaymentReady: 'EMX 付款请求已准备',
      waitingTxHash: '手动交易哈希为可选',
      paymentQrReady: 'EMX 二维码已就绪',
      treasuryNote: '必须从已绑定 TON 地址发送精确 100 EMX',
      autoVerifyWaiting: '等待 EMX 付款',
      autoVerifyDetecting: '正在自动检测付款...',
      autoVerifyTimeout: '自动验证超时，如需要可粘贴交易哈希',
      autoVerifyVerified: '付款已自动验证',
      autoVerifyStart: '已启动自动验证',
      autoVerifyManualFallback: '仍可使用手动交易哈希确认',
      emxOnlyRule: '激活仅接受精确 100 EMX',
      cardDraft: '激活前可修改卡号',
      cardLocked: '激活后卡号已锁定',
      openDeepLink: '打开付款深链接',
      reloadHelperMissing: '充值模块未加载',
      reloadConfirmed: '充值已确认',
      rewardCredited: '免费 EMA$ 已入账',
      rewardAlreadyCredited: 'EMA$ 奖励已入账',
      emaPriceSnapshot: 'EMA 价格快照',
      activationClosed: '激活窗口已关闭',
      refreshAutoConfirm: '刷新自动确认',
      close: '关闭',
      claimModalNotReady: 'Claim 模块尚未接好',
      fuelModalNotReady: 'Fuel 模块尚未接好',
      reserveAwareReady: '保留量已对齐'
    }
  };

  function t(key) {
    return (TEXT[state.lang] && TEXT[state.lang][key]) || (TEXT.en && TEXT.en[key]) || key;
  }

  function qs(sel, root) { return (root || document).querySelector(sel); }
  function qsa(sel, root) { return Array.from((root || document).querySelectorAll(sel)); }
  function byId(id) { return document.getElementById(id); }

  function setText(id, value) {
    const el = typeof id === 'string' ? byId(id) : id;
    if (el) el.textContent = value;
  }

  function setStatus(id, message, kind) {
    const el = typeof id === 'string' ? byId(id) : id;
    if (!el) return;
    el.classList.remove('is-ok', 'is-warn', 'is-error', 'is-loading', 'is-idle', 'is-syncing', 'is-prepared', 'is-pending', 'is-success', 'is-fail');
    if (kind) {
      el.classList.add(`is-${kind}`);
      if (kind === 'ok') el.classList.add('is-success');
      if (kind === 'error') el.classList.add('is-fail');
      if (kind === 'loading') el.classList.add('is-syncing');
      if (kind === 'warn') el.classList.add('is-pending');
    } else {
      el.classList.add('is-idle');
    }
    el.textContent = message || '';
  }

  function setButtonBusy(el, busy) {
    if (!el) return;
    if (busy) {
      el.setAttribute('disabled', 'disabled');
      el.setAttribute('aria-busy', 'true');
      el.dataset.busy = '1';
    } else {
      el.removeAttribute('aria-busy');
      delete el.dataset.busy;
      el.removeAttribute('disabled');
    }
  }

  function setButtonDisabled(el, disabled) {
    if (!el) return;
    if (disabled) el.setAttribute('disabled', 'disabled');
    else if (!el.dataset.busy) el.removeAttribute('disabled');
  }

  function num6(v) {
    const n = Number(v);
    return Number.isFinite(n) ? n.toFixed(6) : '0.000000';
  }

  function num4(v) {
    const n = Number(v);
    return Number.isFinite(n) ? n.toFixed(4) : '0.0000';
  }

  function fmtRwa(v) {
    return `${RWA_CARD_SYMBOL} ${num4(v)}`;
  }

  function fmtTime(ts) {
    if (!ts) return '';
    try {
      const d = new Date(ts);
      if (Number.isNaN(d.getTime())) return '';
      return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}:${String(d.getSeconds()).padStart(2, '0')}`;
    } catch (_) {
      return '';
    }
  }

  function readJsonSafe(text) {
    try { return JSON.parse(text); } catch (_) { return null; }
  }

  function normalizeErrorCode(code) {
    return String(code || '').trim() || 'UNKNOWN_ERROR';
  }

  function friendlyErrorMessage(err) {
    const code = normalizeErrorCode(err?.code || err?.message);
    switch (code) {
      case 'AUTH_REQUIRED': return t('loginRequired');
      case 'CARD_NOT_ACTIVE':
      case 'CARD_NOT_ACTIVE_RELOAD_BLOCKED': return t('activateFirst');
      case 'ACTIVATION_REF_REQUIRED': return t('activationRefMissing');
      case 'ACTIVATION_TX_HASH_REQUIRED':
      case 'TX_HASH_REQUIRED': return t('txHashRequired');
      case 'ACTIVATION_PAYMENT_NOT_VERIFIED':
      case 'ACTIVATION_NOT_FOUND_YET':
      case 'NOT_VERIFIED_YET':
      case 'NO_MATCH': return t('paymentNotVerifiedYet');
      case 'CARD_NOT_BOUND': return t('bindCardFirst');
      case 'TON_NOT_BOUND': return t('noTon');
      case 'CARD_ALREADY_ACTIVE_BIND_LOCKED':
      case 'CARD_LOCKED': return t('bindLocked');
      case 'CARD_ALREADY_ACTIVE':
      case 'CARD_ACTIVATED':
      case 'ALREADY_ACTIVE':
      case 'ALREADY_VERIFIED': return t('alreadyActive');
      case 'NON_JSON_RESPONSE': return t('invalidResponse');
      case 'INVALID_JSON': return t('invalidJson');
      case 'CSRF_INVALID': return 'CSRF INVALID';
      default: return code.replace(/_/g, ' ');
    }
  }

  async function fetchJson(url, options) {
    const started = performance.now();
    const res = await fetch(url, options || {});
    const text = await res.text();
    const latency = Math.round(performance.now() - started);

    if (text.trim().startsWith('<')) {
      const err = new Error('NON_JSON_RESPONSE');
      err.code = 'NON_JSON_RESPONSE';
      err.status = res.status;
      err.latency = latency;
      throw err;
    }

    const json = readJsonSafe(text);
    if (!json) {
      const err = new Error('INVALID_JSON');
      err.code = 'INVALID_JSON';
      err.status = res.status;
      err.latency = latency;
      throw err;
    }

    json.__latency_ms = latency;
    json.__http_status = res.status;

    if (!res.ok || json.ok === false) {
      const err = new Error(json.code || json.error || json.message || `HTTP_${res.status}`);
      err.code = json.code || json.error || json.message || `HTTP_${res.status}`;
      err.status = res.status;
      err.latency = latency;
      err.json = json;
      throw err;
    }

    return json;
  }

  async function apiGet(url) {
    return fetchJson(url, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    });
  }

  async function apiPost(url, data) {
    const fd = new FormData();
    Object.keys(data || {}).forEach((k) => {
      if (data[k] !== undefined && data[k] !== null) {
        fd.append(k, String(data[k]));
      }
    });

    return fetchJson(url, {
      method: 'POST',
      credentials: 'same-origin',
      body: fd,
      headers: { Accept: 'application/json' }
    });
  }

  function pick(obj, keys, fallback) {
    for (const k of keys) {
      if (obj && obj[k] !== undefined && obj[k] !== null && obj[k] !== '') return obj[k];
    }
    return fallback;
  }

  function escapeHtml(v) {
    return String(v == null ? '' : v)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function getCardDigitInputs() {
    const row = byId('cardInputRow');
    if (!row) return [];
    return qsa('input.card-digit', row);
  }

  function getCardDigits() {
    return getCardDigitInputs()
      .map((el) => String(el.value || '').replace(/\D+/g, '').slice(0, 1))
      .join('')
      .slice(0, 16);
  }

  function setCardDigits(value) {
    const digits = String(value || '').replace(/\D+/g, '').slice(0, 16).split('');
    getCardDigitInputs().forEach((el, idx) => {
      el.value = digits[idx] || '';
    });
    syncCardPreview();
  }

  function clearCardDigits() {
    getCardDigitInputs().forEach((el) => { el.value = ''; });
    syncCardPreview();
  }

  function formatCardDisplay(value) {
    const raw = String(value || '').replace(/\D+/g, '').slice(0, 16);
    if (!raw) return '0000 - 0000 - 0000 - 0000';
    const chunks = [];
    for (let i = 0; i < 16; i += 4) chunks.push(raw.slice(i, i + 4).padEnd(4, '0'));
    return chunks.join(' - ');
  }

  function shortenAddress(v) {
    const s = String(v || '');
    if (!s) return '-';
    if (s.length <= 18) return s;
    return `${s.slice(0, 8)}...${s.slice(-8)}`;
  }

  function syncCardPreview() {
    const raw = getCardDigits();
    state.card.inputValue = raw;

    if (raw.length > 0) {
      setText('storageCardNumber', formatCardDisplay(raw));
      return;
    }

    if (state.card.number) {
      setText('storageCardNumber', formatCardDisplay(state.card.number));
      return;
    }

    setText('storageCardNumber', '0000 - 0000 - 0000 - 0000');
  }

  function storageSafeText(v) {
    return String(v == null ? '' : v).trim();
  }

  function buildTonTransferUri(address) {
    const a = storageSafeText(address);
    return a ? `ton://transfer/${a}` : '';
  }

  function deriveActivationQrPayload() {
    if (state.activation.ton_transfer_uri) return state.activation.ton_transfer_uri;
    if (state.activation.payment_qr_payload) return state.activation.payment_qr_payload;
    if (state.activation.payment_qr_text) return state.activation.payment_qr_text;
    if (state.activation.payment_request && typeof state.activation.payment_request === 'object') {
      try { return JSON.stringify(state.activation.payment_request); } catch (_) {}
    }
    return '';
  }

  function renderQrInto(wrap, rawPayload, emptyText) {
    if (!wrap) return;
    const payload = storageSafeText(rawPayload);
    if (!payload) {
      wrap.innerHTML = `<div class="wallet-empty">${escapeHtml(emptyText || t('qrEmpty'))}</div>`;
      return;
    }
    const qrUrl = `/rwa/inc/core/qr.php?text=${encodeURIComponent(payload)}&size=320`;
    wrap.innerHTML = `
      <div class="wallet-qr-card">
        <img class="wallet-qr-image" src="${qrUrl}" alt="QR" loading="lazy">
        <div class="wallet-qr-caption" title="${escapeHtml(payload)}">${escapeHtml(payload)}</div>
      </div>
    `;
  }

  function renderStorageQr(address) {
    const wrap = byId('storageQrWrap');
    const rawAddress = storageSafeText(address);
    if (!rawAddress) return renderQrInto(wrap, '', t('qrEmpty'));
    renderQrInto(wrap, buildTonTransferUri(rawAddress), t('qrEmpty'));
  }

  function renderActivationQr() {
    renderQrInto(byId('activationQrWrap'), deriveActivationQrPayload(), t('activationQrEmpty'));
  }

  function updateActivationDeepLink() {
    const btn = byId('btnActivationDeepLink');
    if (!btn) return;
    const uri = storageSafeText(state.activation.ton_transfer_uri || deriveActivationQrPayload());
    if (uri && /^ton:\/\/transfer\//i.test(uri)) {
      btn.href = uri;
      btn.style.display = '';
      btn.textContent = t('openDeepLink');
    } else {
      btn.href = '#';
      btn.style.display = 'none';
    }
  }

  function updateReloadButtonState() {
    const btn = byId('btnTopupEmx');
    setButtonDisabled(btn, !state.card.is_active || !state.hasTonBind);
    if (!state.card.is_active) btn?.setAttribute('title', t('activateFirst'));
    else btn?.removeAttribute('title');
  }

  function resetActivationPreparedData() {
    state.activation.payment_request = null;
    state.activation.payment_qr_text = '';
    state.activation.payment_qr_payload = '';
    state.activation.ton_transfer_uri = '';
    state.activation.jetton_master = '';
    state.activation.memo = '';
    state.activation.pending = false;
    state.activation.ui_open = false;
  }

  function resetActivationStateAfterSuccess() {
    stopAutoVerify();
    state.activation.pending = false;
    state.activation.verified = true;
    state.activation.ui_open = false;
    resetActivationPreparedData();
  }

  function closeActivationUi(showClosedMessage) {
    const panel = byId('activationConfirmPanel');
    state.activation.ui_open = false;
    if (panel) panel.style.display = 'none';

    if (showClosedMessage) setStatus('activationStatus', t('activationClosed'), 'ok');
    else setStatus('activationStatus', '', null);

    setStatus('activationAutoStatus', '', null);
    setStatus('activationSummary', t('activationPanelHidden'), null);

    const txInput = byId('activationTxHashInput');
    if (txInput) txInput.value = '';

    updateActivationDeepLink();
  }

  function buildRewardSummaryHtml() {
    const parts = [];
    const reward = storageSafeText(state.activation.ema_reward);
    const price = storageSafeText(state.activation.ema_price_snapshot);
    const token = storageSafeText(state.activation.reward_token || 'EMA');
    const rewardStatus = storageSafeText(state.activation.reward_status);
    const successSummary = storageSafeText(state.activation.success_summary);

    if (successSummary) parts.push(`<div><strong>${escapeHtml(successSummary)}</strong></div>`);
    else if (rewardStatus === 'already_rewarded') parts.push(`<div><strong>${escapeHtml(t('rewardAlreadyCredited'))}</strong></div>`);
    else if (reward) parts.push(`<div><strong>${escapeHtml(t('rewardCredited'))}</strong></div>`);

    if (reward) parts.push(`<div>+ ${escapeHtml(reward)} ${escapeHtml(token)}</div>`);
    if (price) parts.push(`<div>@ ${escapeHtml(t('emaPriceSnapshot'))} ${escapeHtml(price)}</div>`);
    if (rewardStatus) parts.push(`<div>${escapeHtml(rewardStatus)}</div>`);

    return parts.join('');
  }

  function updateActivationSummaryBox() {
    const box = byId('activationSuccessSummary');
    if (!box) return;
    const html = buildRewardSummaryHtml();
    if (html) {
      box.style.display = '';
      box.classList.add('is-success');
      box.innerHTML = html;
    } else {
      box.style.display = 'none';
      box.innerHTML = '';
    }
  }

  function updateBindUiState() {
    const bindBtn = byId('btnBindCard');
    const clearBtn = byId('btnClearCardInput');
    const activateBtn = byId('btnActivateCard');
    const inputRow = byId('cardInputRow');

    const locked = !!state.card.locked || !!state.card.is_active || state.card.status === 'active';

    if (inputRow) {
      qsa('.card-digit', inputRow).forEach((el) => {
        if (locked) {
          el.setAttribute('disabled', 'disabled');
          el.setAttribute('readonly', 'readonly');
        } else {
          el.removeAttribute('disabled');
          el.removeAttribute('readonly');
        }
      });
    }

    setButtonDisabled(bindBtn, locked);
    setButtonDisabled(clearBtn, locked);
    setButtonDisabled(activateBtn, locked || !state.hasTonBind);

    if (locked) setStatus('cardBindStatus', t('cardLocked'), 'warn');
    else if (state.card.number) setStatus('cardBindStatus', t('cardDraft'), 'ok');
  }

  function updateActivationPanel() {
    const panel = byId('activationConfirmPanel');
    const isPendingUi = !!state.activation.ui_open && !state.card.is_active && !state.activation.verified && !!state.activation.ref;

    if (panel) panel.style.display = isPendingUi ? '' : 'none';

    setText('activationRefValue', state.activation.ref || '-');
    setText('activationTreasuryValue', state.activation.treasury || '-');
    setText('activationAmountValue', String(state.activation.required_emx || '100.000000'));
    setText('activationMemoValue', state.activation.memo || state.activation.ref || '-');
    setText('activationRewardValue', state.activation.ema_reward ? `${state.activation.ema_reward} ${state.activation.reward_token || 'EMA'}` : 'Free EMA$ after confirmed activation');
    setText('activationEmaPriceValue', state.activation.ema_price_snapshot || '-');

    const input = byId('activationTxHashInput');
    if (input && input.value !== state.activation.tx_hash) input.value = state.activation.tx_hash || '';

    updateActivationSummaryBox();
    updateActivationDeepLink();

    if (!isPendingUi) {
      if (!state.card.is_active) setStatus('activationSummary', t('activationPanelHidden'), null);
      return;
    }

    renderActivationQr();

    let summary = `${t('walletPaymentReady')} · ${t('emxOnlyRule')}`;
    if (state.activation.ema_price_snapshot && state.activation.ema_reward) {
      summary += ` · EMA ${state.activation.ema_reward} @ ${state.activation.ema_price_snapshot}`;
    }
    setStatus('activationSummary', summary, 'warn');

    if (state.autoVerify.enabled) setStatus('activationAutoStatus', t('autoVerifyDetecting'), 'loading');
    else if (deriveActivationQrPayload()) setStatus('activationAutoStatus', `${t('paymentQrReady')} · ${t('treasuryNote')}`, 'warn');
    else setStatus('activationAutoStatus', t('autoVerifyWaiting'), 'warn');

    if (!byId('activationStatus')?.textContent) {
      setStatus('activationStatus', `${t('waitingTxHash')} · ${t('autoVerifyManualFallback')}`, 'warn');
    }
  }

  function applyCardPayload(card) {
    const resolvedNumber = String(
      card?.card_number ||
      card?.number ||
      state.card.number ||
      ''
    );
    const resolvedStatus = String(card?.status || state.card.status || 'none');
    const resolvedLocked = Number(card?.locked || 0) === 1;
    const resolvedActive = !!card?.is_active || !!card?.active || resolvedLocked || resolvedStatus === 'active';

    state.card.number = resolvedNumber;
    state.card.status = resolvedStatus;
    state.card.locked = resolvedLocked;
    state.card.is_active = resolvedActive;

    if (!state.card.inputValue) {
      setText('storageCardNumber', formatCardDisplay(state.card.number));
    }

    if (state.card.is_active) {
      state.activation.pending = false;
      state.activation.verified = true;
      state.activation.ui_open = false;
    }

    updateBindUiState();
    updateReloadButtonState();
  }

  function applyActivationRewardFields(obj) {
    if (!obj || typeof obj !== 'object') return;
    if (obj.ema_price_snapshot !== undefined) state.activation.ema_price_snapshot = String(obj.ema_price_snapshot || '');
    if (obj.ema_reward !== undefined) state.activation.ema_reward = String(obj.ema_reward || '');
    if (obj.reward_token !== undefined) state.activation.reward_token = String(obj.reward_token || 'EMA');
    if (obj.reward_status !== undefined) state.activation.reward_status = String(obj.reward_status || '');
    if (obj.success_summary !== undefined) state.activation.success_summary = String(obj.success_summary || '');
    updateActivationSummaryBox();
  }

  function applyOverviewClaimable(claimable) {
    if (!claimable || typeof claimable !== 'object') return;
    Object.keys(state.claimable).forEach((key) => {
      if (claimable[key] !== undefined) {
        state.claimable[key] = claimable[key];
      }
    });
  }

  function applyOverviewSync(sync) {
    if (!sync || typeof sync !== 'object') return;
    const updatedAt = String(sync.updated_at || '');
    if (updatedAt) {
      const parsed = Date.parse(updatedAt.replace(' ', 'T'));
      if (!Number.isNaN(parsed)) state.sync.lastAt = parsed;
    }
  }

  function updateBalancesUI(data) {
    const balances = pick(data, ['balances', 'balance', 'data'], {}) || {};

    state.balances.card_balance_rwa = String(pick(balances, ['card_balance_rwa'], state.balances.card_balance_rwa));
    state.balances.onchain_emx = String(pick(balances, ['onchain_emx'], state.balances.onchain_emx));
    state.balances.onchain_ema = String(pick(balances, ['onchain_ema'], state.balances.onchain_ema));
    state.balances.onchain_wems = String(pick(balances, ['onchain_wems'], state.balances.onchain_wems));
    state.balances.unclaim_ema = String(pick(balances, ['unclaim_ema'], state.balances.unclaim_ema));
    state.balances.unclaim_wems = String(pick(balances, ['unclaim_wems'], state.balances.unclaim_wems));
    state.balances.unclaim_gold_packet_usdt = String(pick(balances, ['unclaim_gold_packet_usdt'], state.balances.unclaim_gold_packet_usdt));
    state.balances.unclaim_tips_emx = String(pick(balances, ['unclaim_tips_emx'], state.balances.unclaim_tips_emx));
    state.balances.fuel_usdt_ton = String(pick(balances, ['fuel_usdt_ton'], state.balances.fuel_usdt_ton));
    state.balances.fuel_ems = String(pick(balances, ['fuel_ems'], state.balances.fuel_ems));
    state.balances.fuel_ton_gas = String(pick(balances, ['fuel_ton_gas'], state.balances.fuel_ton_gas));

    setText('cardBalanceValue', fmtRwa(state.balances.card_balance_rwa));
    setText('balEMX', num6(state.balances.onchain_emx));
    setText('balEMA', num6(state.balances.onchain_ema));
    setText('balWEMS', num6(state.balances.onchain_wems));
    setText('unclaimEMA', num6(state.balances.unclaim_ema));
    setText('unclaimWEMS', num6(state.balances.unclaim_wems));
    setText('unclaimPacket', num6(state.balances.unclaim_gold_packet_usdt));
    setText('unclaimTips', num6(state.balances.unclaim_tips_emx));
    setText('fuelUSDT', num6(state.balances.fuel_usdt_ton));
    setText('fuelEMS', num6(state.balances.fuel_ems));
    setText('fuelTON', num6(state.balances.fuel_ton_gas));

    applyCardPayload(pick(data, ['card'], {}) || {});
    applyOverviewClaimable(pick(data, ['claimable'], null));
    applyOverviewSync(pick(data, ['sync'], null));

    const activation = pick(data, ['activation'], null);
    if (activation && typeof activation === 'object') {
      state.activation.ref = String(activation.activation_ref || state.activation.ref || '');
      state.activation.tx_hash = String(activation.tx_hash || state.activation.tx_hash || '');
      state.activation.pending = !!state.activation.ref && !state.card.is_active;
      state.activation.verified = !!activation.verified || !!activation.is_active || state.card.is_active;
      applyActivationRewardFields(activation);

      if (state.card.is_active || state.activation.verified) {
        state.activation.pending = false;
        state.activation.verified = true;
        state.activation.ui_open = false;
      }
    }

    updateActivationPanel();
  }

  /* rollback-safe legacy helpers: kept but not used in normal UI flow */
  function updateBalanceApiUI(data) {
    const tkn = data?.tokens || {};
    const card = data?.card || {};
    const sync = data?.sync || {};

    state.balances.card_balance_rwa = String(
      pick(card, ['balance_rwa', 'card_balance_rwa'], state.balances.card_balance_rwa)
    );
    state.balances.onchain_ema = String(tkn?.EMA?.on_chain || state.balances.onchain_ema);
    state.balances.onchain_emx = String(tkn?.EMX?.on_chain || state.balances.onchain_emx);
    state.balances.fuel_ems = String(tkn?.EMS?.on_chain || state.balances.fuel_ems);
    state.balances.onchain_wems = String(tkn?.WEMS?.on_chain || state.balances.onchain_wems);
    state.balances.fuel_usdt_ton = String(tkn?.USDT_TON?.on_chain || state.balances.fuel_usdt_ton);
    state.balances.fuel_ton_gas = String(tkn?.TON?.on_chain || state.balances.fuel_ton_gas);

    state.balances.unclaim_ema = String(tkn?.EMA?.available || state.balances.unclaim_ema);
    state.balances.unclaim_tips_emx = String(tkn?.EMX?.available || state.balances.unclaim_tips_emx);
    state.balances.unclaim_wems = String(tkn?.WEMS?.available || state.balances.unclaim_wems);
    state.balances.unclaim_gold_packet_usdt = String(tkn?.USDT_TON?.available || state.balances.unclaim_gold_packet_usdt);

    setText('cardBalanceValue', fmtRwa(state.balances.card_balance_rwa));
    setText('balEMX', num6(state.balances.onchain_emx));
    setText('balEMA', num6(state.balances.onchain_ema));
    setText('balWEMS', num6(state.balances.onchain_wems));
    setText('unclaimEMA', num6(state.balances.unclaim_ema));
    setText('unclaimWEMS', num6(state.balances.unclaim_wems));
    setText('unclaimPacket', num6(state.balances.unclaim_gold_packet_usdt));
    setText('unclaimTips', num6(state.balances.unclaim_tips_emx));
    setText('fuelUSDT', num6(state.balances.fuel_usdt_ton));
    setText('fuelEMS', num6(state.balances.fuel_ems));
    setText('fuelTON', num6(state.balances.fuel_ton_gas));

    applyCardPayload({
      card_number: card.card_number || card.number || state.card.number || '',
      status: card.status || state.card.status || 'none',
      locked: card.locked || 0,
      is_active: !!card.active || !!card.is_active || state.card.is_active
    });

    if (sync && sync.ok === false) {
      updateChainSyncStatus('warn', `${t('syncFailed')} · ${sync.error || ''}`.trim());
    }

    updateActivationPanel();
  }

  function applyClaimableRow(row) {
    const flow = String(row?.flow_type || '');
    if (!flow) return;
    state.claimable[flow] = row;

    const display = String(row?.display_amount || '0.000000');
    switch (flow) {
      case 'claim_ema':
        state.balances.unclaim_ema = display;
        setText('unclaimEMA', num6(display));
        break;
      case 'claim_wems':
        state.balances.unclaim_wems = display;
        setText('unclaimWEMS', num6(display));
        break;
      case 'claim_usdt_ton':
        state.balances.unclaim_gold_packet_usdt = display;
        setText('unclaimPacket', num6(display));
        break;
      case 'claim_emx_tips':
        state.balances.unclaim_tips_emx = display;
        setText('unclaimTips', num6(display));
        break;
      case 'fuel_ems':
        state.balances.fuel_ems = display;
        setText('fuelEMS', num6(display));
        break;
      default:
        break;
    }
  }

  function updateClaimableBalancesUI(data) {
    const items = Array.isArray(data?.items) ? data.items : [];
    items.forEach(applyClaimableRow);
    if (items.length) {
      const msg = `${t('reserveAwareReady')} · ${fmtTime(Date.now())}`;
      setStatus('actionStatus', msg, 'ok');
    }
  }

  function updateAddressUI(data) {
    const address = String(pick(data, ['address', 'wallet_address'], state.walletAddress || ''));
    if (address) {
      state.walletAddress = address;
      state.boundTonAddress = address;
      state.hasTonBind = true;
      const addressLine = byId('storageTonAddress');
      if (addressLine) {
        addressLine.textContent = shortenAddress(address);
        addressLine.title = address;
      }
      byId('btnCopyAddress')?.removeAttribute('disabled');
      renderStorageQr(address);
      setText('chainTokenScope', 'TON');
      BODY.dataset.walletAddress = address;
      BODY.dataset.hasTonBind = '1';
    } else {
      state.hasTonBind = false;
      setText('storageTonAddress', '-');
      byId('btnCopyAddress')?.setAttribute('disabled', 'disabled');
      renderStorageQr('');
      setText('chainTokenScope', '');
      BODY.dataset.hasTonBind = '0';
    }
    updateReloadButtonState();
    updateBindUiState();
  }

  function renderHistoryItem(item) {
    const type = escapeHtml(String(item.type || item.event_type || 'activity'));
    const token = escapeHtml(String(item.token || 'SYSTEM'));
    const amount = escapeHtml(String(item.amount || '0.000000'));
    const createdAt = escapeHtml(String(item.created_at || item.ts || ''));
    const meta = item.meta_json || item.meta || item.context_json || '';
    let metaText = '';
    if (typeof meta === 'string' && meta.trim()) metaText = meta.trim();
    else if (meta && typeof meta === 'object') {
      try { metaText = JSON.stringify(meta); } catch (_) {}
    }

    return `
      <div class="wallet-history-item">
        <div class="wallet-history-top">
          <div class="wallet-history-type">${type}</div>
          <div class="wallet-history-time">${createdAt}</div>
        </div>
        <div class="wallet-history-main">
          <div class="wallet-history-token">${token}</div>
          <div class="wallet-history-amount">${amount}</div>
        </div>
        ${metaText ? `<div class="wallet-history-meta">${escapeHtml(metaText)}</div>` : ''}
      </div>
    `;
  }

  function updateHistoryUI(data) {
    const list = byId('storageHistoryList');
    if (!list) return;
    const items = pick(data, ['items', 'history', 'rows'], []) || [];
    if (!Array.isArray(items) || !items.length) {
      list.innerHTML = `<div class="wallet-empty">${escapeHtml(t('historyEmpty'))}</div>`;
      return;
    }
    list.innerHTML = items.map(renderHistoryItem).join('');
  }

  function withLock(lockKey, btnId, fn) {
    return async function wrappedAction(...args) {
      if (state.locks[lockKey]) {
        const msg = t('requestRunning');
        if (btnId === 'btnBindCard' || btnId === 'btnActivateCard' || btnId === 'btnActivationConfirm') {
          setStatus(btnId === 'btnActivationConfirm' ? 'activationStatus' : 'cardBindStatus', msg, 'warn');
        } else if (btnId === 'btnFuelUpEmx' || btnId === 'btnFuelUpEms') {
          setStatus('fuelStatus', msg, 'warn');
        } else {
          setStatus('actionStatus', msg, 'warn');
        }
        return;
      }

      state.locks[lockKey] = true;
      const btn = btnId ? byId(btnId) : null;
      setButtonBusy(btn, true);

      try {
        return await fn.apply(this, args);
      } finally {
        state.locks[lockKey] = false;
        setButtonBusy(btn, false);
        updateReloadButtonState();
        updateBindUiState();
      }
    };
  }

  /* rollback-safe legacy runners: kept but removed from active balance flow */
  async function runTokenBalance() {
    const json = await apiGet(state.endpoints.balanceMain);
    updateBalanceApiUI(json);
    return json;
  }

  async function runClaimableBalances() {
    const json = await apiGet(state.endpoints.balanceResolver);
    updateClaimableBalancesUI(json);
    return json;
  }

  async function runOverview() {
    const json = await apiGet(state.endpoints.overview);
    updateBalancesUI(json);
    updateAddressUI(json);
    return json;
  }

  async function runHistory() {
    const json = await apiGet(state.endpoints.history);
    updateHistoryUI(json);
    return json;
  }

  async function runAddress() {
    const json = await apiGet(state.endpoints.address);
    updateAddressUI(json);
    return json;
  }

  async function runClaimPreset() {
    return { ok: true, claim_token: 'EMA', claim_amount: '1.000000', treasury_ton: '0.10' };
  }

  async function refreshAllStoragePanels() {
    await runAddress().catch(() => {});
    await runOverview().catch(() => {});
    await runHistory().catch(() => {});
    await runClaimPreset().catch(() => {});

    try {
      if (window.StorageManualClaimsPanel && typeof window.StorageManualClaimsPanel.refresh === 'function') {
        await window.StorageManualClaimsPanel.refresh();
      }
    } catch (_) {}
  }

  function updateChainSyncStatus(kind, message) {
    setStatus('chainSyncStatus', message, kind);
  }

  async function syncChainNow() {
    if (state.sync.running) return false;
    if (!state.hasTonBind || !state.walletAddress) {
      updateChainSyncStatus('warn', t('noTon'));
      return false;
    }

    state.sync.running = true;
    updateChainSyncStatus('loading', t('syncingChain'));

    try {
      await runOverview();
      state.sync.lastAt = Date.now();
      updateChainSyncStatus('ok', `${t('syncUpdated')} · ${fmtTime(state.sync.lastAt)}`);
      return true;
    } catch (err) {
      updateChainSyncStatus('error', `${t('syncFailed')} · ${friendlyErrorMessage(err)}`);
      return false;
    } finally {
      state.sync.running = false;
    }
  }

  function queueChainSync() {
    clearTimeout(state.sync.timer);
    state.sync.timer = setTimeout(() => {
      syncChainNow().catch(() => {});
    }, state.sync.debounceMs);
  }

  function stopAutoVerify() {
    state.autoVerify.enabled = false;
    state.autoVerify.running = false;
    clearTimeout(state.autoVerify.timer);
    state.autoVerify.timer = null;
  }

  function isActivationSuccessJson(json) {
    const code = String(json?.code || json?.message || '').trim();
    return !!(
      json?.verified === true ||
      json?.is_active === true ||
      code === 'ACTIVATION_CONFIRMED' ||
      code === 'ALREADY_VERIFIED' ||
      code === 'ALREADY_ACTIVE' ||
      code === 'CARD_ACTIVATED'
    );
  }

  async function handleActivationSuccess(json) {
    state.card.is_active = true;
    state.card.locked = true;
    state.card.status = 'active';
    state.activation.tx_hash = String(json.tx_hash || state.activation.tx_hash || '');
    state.activation.ref = String(json.activation_ref || state.activation.ref || '');
    applyActivationRewardFields(json);

    resetActivationStateAfterSuccess();
    updateBindUiState();
    updateReloadButtonState();

    const successMsg = String(json.success_summary || json.message || t('activationConfirmed'));
    setStatus('cardBindStatus', successMsg, 'ok');
    setStatus('activationSummary', successMsg, 'ok');

    const outer = byId('activationSuccessSummary');
    if (outer) {
      outer.style.display = '';
      outer.classList.add('is-success');
      outer.innerHTML = buildRewardSummaryHtml();
    }

    closeActivationUi(false);

    await refreshAllStoragePanels();
    queueChainSync();
  }

  async function runAutoVerifyOnce() {
    if (!state.autoVerify.enabled || !state.activation.ref || state.card.is_active || state.activation.verified) return;
    if (state.autoVerify.running) return;
    state.autoVerify.running = true;

    try {
      setStatus('activationAutoStatus', t('autoVerifyDetecting'), 'loading');

      const json = await apiPost(state.autoVerify.endpoint, {
        csrf_token: state.csrf.activate,
        activation_ref: state.activation.ref
      });

      if (isActivationSuccessJson(json)) {
        if (json.is_active === true || json.code === 'ALREADY_ACTIVE') {
          await handleActivationSuccess(json);
          return;
        }

        if (json.verified === true && json.is_active !== true) {
          setStatus('activationAutoStatus', t('autoVerifyVerified'), 'ok');
          setStatus('activationStatus', 'Matching transfer found. Please continue to confirm activation.', 'ok');
          return;
        }
      }

      const elapsed = Date.now() - state.autoVerify.startedAt;
      if (elapsed >= state.autoVerify.timeoutMs) {
        stopAutoVerify();
        setStatus('activationAutoStatus', t('autoVerifyTimeout'), 'warn');
        return;
      }

      state.autoVerify.timer = setTimeout(() => { runAutoVerifyOnce().catch(() => {}); }, state.autoVerify.intervalMs);
    } catch (_) {
      const elapsed = Date.now() - state.autoVerify.startedAt;
      if (elapsed >= state.autoVerify.timeoutMs) {
        stopAutoVerify();
        setStatus('activationAutoStatus', t('autoVerifyTimeout'), 'warn');
      } else {
        state.autoVerify.timer = setTimeout(() => { runAutoVerifyOnce().catch(() => {}); }, state.autoVerify.intervalMs);
      }
    } finally {
      state.autoVerify.running = false;
    }
  }

  function startAutoVerify() {
    stopAutoVerify();
    if (!state.activation.ref) return;
    state.autoVerify.enabled = true;
    state.autoVerify.startedAt = Date.now();
    state.activation.ui_open = true;
    setStatus('activationAutoStatus', `${t('autoVerifyStart')} · ${t('autoVerifyManualFallback')}`, 'warn');
    state.autoVerify.timer = setTimeout(() => { runAutoVerifyOnce().catch(() => {}); }, 800);
  }

  function storeActivationPrepared(prepared) {
    state.activation.ref = String(prepared.activation_ref || prepared.text || '');
    state.activation.treasury = String(prepared.to_address || prepared.treasury || prepared.receiver_wallet || prepared.treasury_address || '');
    state.activation.required_emx = String(prepared.required_emx || prepared.amount_emx || prepared.required_amount_display || '100.000000');
    state.activation.required_units = String(prepared.required_units || prepared.amount_units || prepared.required_amount_units || '');
    state.activation.token = String(prepared.token || prepared.token_key || 'EMX');
    state.activation.decimals = Number(prepared.decimals || 9);
    state.activation.jetton_master = String(prepared.jetton_master || prepared.jetton_master_raw || '');
    state.activation.ema_price_snapshot = String(prepared.ema_price_snapshot || '');
    state.activation.ema_reward = String(prepared.ema_reward || '');
    state.activation.reward_token = String(prepared.reward_token || state.activation.reward_token || 'EMA');
    state.activation.reward_status = String(prepared.reward_status || '');
    state.activation.payment_request = prepared.payment_request || null;
    state.activation.payment_qr_text = String(prepared.payment_qr_text || prepared.text || '');
    state.activation.payment_qr_payload = String(
      prepared.payment_qr_payload ||
      prepared.payment_qr_text ||
      prepared.qr_text ||
      prepared.ton_transfer_uri ||
      prepared.deeplink ||
      ''
    );
    state.activation.ton_transfer_uri = String(prepared.ton_transfer_uri || prepared.deeplink || '');
    state.activation.memo = String(prepared.text || prepared.memo || prepared.memo_text || prepared.activation_ref || '');
    state.activation.success_summary = String(prepared.success_summary || '');
    state.activation.pending = !!state.activation.ref;
    state.activation.verified = false;
    state.activation.ui_open = true;
    updateActivationPanel();
  }

  async function bindCardCore() {
    if (!state.emailVerified) {
      setStatus('cardBindStatus', t('needEmail'), 'warn');
      return;
    }

    if (state.card.locked || state.card.is_active || state.card.status === 'active') {
      setStatus('cardBindStatus', t('bindLocked'), 'warn');
      return;
    }

    const cardNumber = getCardDigits();

    if (!/^\d{16}$/.test(cardNumber)) {
      setStatus('cardBindStatus', `${t('needCard')} [${cardNumber.length}/16]`, 'warn');
      return;
    }

    setStatus('cardBindStatus', t('loading'), 'loading');

    try {
      const json = await apiPost('/rwa/api/storage/bind-card.php', {
        csrf_token: state.csrf.bind,
        card_number: cardNumber
      });

      state.card.number = String(json.card_number || cardNumber);
      state.card.status = String(json.status || 'draft');
      state.card.locked = Number(json.locked || 0) === 1;
      state.card.is_active = !!json.is_active;
      state.card.inputValue = '';

      setCardDigits(state.card.number);
      setStatus('cardBindStatus', json.message || t('bindSuccess'), 'ok');
      updateBindUiState();

      await refreshAllStoragePanels();
    } catch (err) {
      setStatus('cardBindStatus', `${t('bindFail')} · ${friendlyErrorMessage(err)}`, 'error');
    }
  }

  async function activateCardCore() {
    if (!state.hasTonBind || !state.walletAddress) {
      setStatus('cardBindStatus', t('noTon'), 'warn');
      return;
    }
    if (!state.card.number && !state.card.inputValue && getCardDigits().length !== 16) {
      setStatus('cardBindStatus', t('bindCardFirst'), 'warn');
      return;
    }
    if (state.card.is_active) {
      setStatus('cardBindStatus', t('alreadyActive'), 'ok');
      closeActivationUi(false);
      return;
    }

    stopAutoVerify();
    setStatus('cardBindStatus', t('loading'), 'loading');

    try {
      const prepared = await apiPost(state.endpoints.activatePrepare, {
        csrf_token: state.csrf.activate
      });

      if (prepared.is_active) {
        state.card.is_active = true;
        state.card.locked = true;
        state.card.status = 'active';
        applyActivationRewardFields(prepared);

        resetActivationStateAfterSuccess();
        closeActivationUi(false);
        updateReloadButtonState();
        updateBindUiState();

        setStatus('cardBindStatus', prepared.message || t('alreadyActive'), 'ok');
        await refreshAllStoragePanels();
        return;
      }

      storeActivationPrepared(prepared);
      setStatus('cardBindStatus', prepared.message || t('actionPrepared'), 'ok');
      setStatus('activationStatus', `${t('preparedNoMutation')} ${t('waitingTxHash')}`, 'warn');

      const modalPayload = {
        kind: 'storage_activate_emx',
        payment_request: state.activation.payment_request,
        payment_qr_payload: deriveActivationQrPayload(),
        ton_transfer_uri: state.activation.ton_transfer_uri,
        activation_ref: state.activation.ref
      };

      if (typeof window.openTonPaymentModal === 'function') {
        try {
          const walletResult = await window.openTonPaymentModal(modalPayload);
          const txHash = walletResult && (walletResult.tx_hash || walletResult.txHash || walletResult.hash);
          if (txHash) state.activation.tx_hash = String(txHash);
        } catch (_) {}
      } else if (typeof window.storageOpenTonPaymentModal === 'function') {
        try {
          const walletResult = await window.storageOpenTonPaymentModal(modalPayload);
          const txHash = walletResult && (walletResult.tx_hash || walletResult.txHash || walletResult.hash);
          if (txHash) state.activation.tx_hash = String(txHash);
        } catch (_) {}
      }

      updateActivationPanel();
      startAutoVerify();
    } catch (err) {
      setStatus('cardBindStatus', `${t('actionFailed')} · ${friendlyErrorMessage(err)}`, 'error');
    }
  }

  async function confirmActivationCore() {
    if (!state.activation.ref) {
      setStatus('activationStatus', t('activationRefMissing'), 'warn');
      return;
    }

    const txHashInput = byId('activationTxHashInput');
    const txHash = String(txHashInput?.value || state.activation.tx_hash || '').trim();
    state.activation.tx_hash = txHash;
    state.activation.ui_open = true;
    setStatus('activationStatus', t('loading'), 'loading');

    try {
      const confirmed = await apiPost(state.endpoints.activateConfirm, {
        csrf_token: state.csrf.activate,
        activation_ref: state.activation.ref,
        tx_hash: txHash
      });

      if (isActivationSuccessJson(confirmed)) {
        await handleActivationSuccess(confirmed);
        return;
      }

      setStatus('activationStatus', friendlyErrorMessage({ code: confirmed.code || confirmed.message || confirmed.error || 'NOT_VERIFIED_YET' }), 'warn');
    } catch (err) {
      setStatus('activationStatus', `${t('actionFailed')} · ${friendlyErrorMessage(err)}`, 'error');
    }
  }

  function getFlowDisplayAmount(flowType) {
    const row = state.claimable[flowType];
    if (row && typeof row === 'object' && row.display_amount !== undefined && row.display_amount !== null && row.display_amount !== '') {
      return String(row.display_amount);
    }
    if (row !== null && row !== undefined && typeof row !== 'object') {
      return String(row);
    }
    switch (flowType) {
      case 'claim_ema': return String(state.balances.unclaim_ema || '0.000000');
      case 'claim_wems': return String(state.balances.unclaim_wems || '0.000000');
      case 'claim_usdt_ton': return String(state.balances.unclaim_gold_packet_usdt || '0.000000');
      case 'claim_emx_tips': return String(state.balances.unclaim_tips_emx || '0.000000');
      case 'fuel_ems': return String(state.balances.fuel_ems || '0.000000');
      default: return '0.000000';
    }
  }

  async function claimNowCore(token) {
    const map = {
      EMA: 'claim_ema',
      WEMS: 'claim_wems',
      'USDT-TON': 'claim_usdt_ton',
      EMX: 'claim_emx_tips'
    };
    const flowType = map[token] || '';
    const amount = getFlowDisplayAmount(flowType);
    setStatus('actionStatus', `${t('claimModalNotReady')} · ${token} · ${num6(amount)}`, 'warn');

    try {
      if (window.StorageManualClaimsPanel && typeof window.StorageManualClaimsPanel.refresh === 'function') {
        await window.StorageManualClaimsPanel.refresh();
      }
    } catch (_) {}
  }

  async function fuelUpEmxCore() {
    setStatus('fuelStatus', t('fuelModalNotReady'), 'warn');
  }

  async function fuelUpEmsCore() {
    const amount = getFlowDisplayAmount('fuel_ems');
    setStatus('fuelStatus', `${t('fuelModalNotReady')} · EMS · ${num6(amount)}`, 'warn');

    try {
      if (window.StorageManualClaimsPanel && typeof window.StorageManualClaimsPanel.refresh === 'function') {
        await window.StorageManualClaimsPanel.refresh();
      }
    } catch (_) {}
  }

  async function copyText(text, statusId, okText) {
    try {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        await navigator.clipboard.writeText(text);
      } else {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', 'readonly');
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
      }
      setStatus(statusId, okText, 'ok');
    } catch (_) {
      setStatus(statusId, t('copyFailed'), 'error');
    }
  }

  async function copyAddress() {
    if (!state.walletAddress) {
      setStatus('copyStatus', t('noTon'), 'warn');
      return;
    }
    await copyText(state.walletAddress, 'copyStatus', t('copied'));
  }

  async function copyActivationRef() {
    if (!state.activation.ref) {
      setStatus('activationStatus', t('activationRefMissing'), 'warn');
      return;
    }
    await copyText(state.activation.ref, 'activationStatus', t('activationRefCopied'));
  }

  async function copyActivationMemo() {
    const memo = state.activation.memo || state.activation.ref;
    if (!memo) {
      setStatus('activationStatus', t('activationRefMissing'), 'warn');
      return;
    }
    await copyText(memo, 'activationStatus', t('activationMemoCopied'));
  }

  function openTradeWebGold() {
    if (!state.tradeUrl) {
      setStatus('actionStatus', 'Trade URL missing', 'warn');
      return;
    }
    setStatus('actionStatus', 'Opening STON.fi...', 'ok');
    window.open(state.tradeUrl, '_blank', 'noopener,noreferrer');
  }

  const bindCard = withLock('bind', 'btnBindCard', bindCardCore);
  const activateCard = withLock('activate', 'btnActivateCard', activateCardCore);
  const confirmActivation = withLock('activateConfirm', 'btnActivationConfirm', confirmActivationCore);
  const fuelUpEmx = withLock('fuelEmx', 'btnFuelUpEmx', fuelUpEmxCore);
  const fuelUpEms = withLock('fuelEms', 'btnFuelUpEms', fuelUpEmsCore);

  function claimNow(token) {
    const wrapped = withLock('claim', null, () => claimNowCore(token));
    return wrapped();
  }

  function setupLanguage() {
    byId('langEnBtn')?.addEventListener('click', () => applyLang('en'));
    byId('langZhBtn')?.addEventListener('click', () => applyLang('zh'));
    applyLang(state.lang);
  }

  function applyLang(lang) {
    state.lang = lang === 'zh' ? 'zh' : 'en';
    document.documentElement.setAttribute('lang', state.lang);
    BODY.setAttribute('data-lang', state.lang);

    byId('langEnBtn')?.classList.toggle('is-active', state.lang === 'en');
    byId('langZhBtn')?.classList.toggle('is-active', state.lang === 'zh');

    setText('btnTopupLabel', state.lang === 'zh' ? '用 EMX 充值卡' : 'Reload Card with EMX');
    setText('bindEyebrow', state.lang === 'zh' ? '绑定卡' : 'Bind Card');
    setText('bindTitle', state.lang === 'zh' ? '卡号' : 'Card Number');
    setText('btnBindCard', t('bindCard'));
    setText('btnClearCardInput', t('clear'));
    setText('btnActivateCard', t('activateCard'));
    setText('btnActivationConfirm', state.lang === 'zh' ? '手动确认激活' : 'Confirm Activation');
    setText('btnActivationRefreshAutoConfirm', t('refreshAutoConfirm'));
    setText('btnCloseActivationPanel', t('close'));
    setText('btnActivationDeepLink', t('openDeepLink'));
    setText('cardCaption', t('publicDepositNumber'));
    setText('cardNotice', `${t('needEmail')} ${t('cardNumberVisible')}`);
    setText('cardBalanceValue', fmtRwa(state.balances.card_balance_rwa));

    updateActivationPanel();
    updateBindUiState();
    updateChainSyncStatus('idle', state.sync.lastAt ? `${t('syncUpdated')} · ${fmtTime(state.sync.lastAt)}` : t('syncIdle'));

    if (window.poadoI18n && typeof window.poadoI18n.setLang === 'function') {
      try { window.poadoI18n.setLang(state.lang); } catch (_) {}
    }

    try {
      const evt = new CustomEvent('poado:lang_changed', {
        detail: { lang: state.lang }
      });
      window.dispatchEvent(evt);
      document.dispatchEvent(evt);
    } catch (_) {}
  }

  function setupCardInputs() {
    const inputs = getCardDigitInputs();
    if (!inputs.length) return;

    inputs.forEach((el, idx) => {
      el.addEventListener('input', (e) => {
        const v = String(e.target.value || '').replace(/\D+/g, '').slice(0, 1);
        e.target.value = v;
        syncCardPreview();
        if (v && idx < inputs.length - 1) {
          inputs[idx + 1].focus();
          if (typeof inputs[idx + 1].select === 'function') inputs[idx + 1].select();
        }
      });

      el.addEventListener('keydown', (e) => {
        if (e.key === 'Backspace' && !el.value && idx > 0) inputs[idx - 1].focus();
        if (e.key === 'ArrowLeft' && idx > 0) inputs[idx - 1].focus();
        if (e.key === 'ArrowRight' && idx < inputs.length - 1) inputs[idx + 1].focus();
      });

      el.addEventListener('paste', (e) => {
        const txt = String((e.clipboardData || window.clipboardData)?.getData('text') || '').replace(/\D+/g, '').slice(0, 16);
        if (!txt) return;
        e.preventDefault();
        setCardDigits(txt);
      });
    });
  }

  function setupActions() {
    byId('btnBindCard')?.addEventListener('click', bindCard);

    byId('btnClearCardInput')?.addEventListener('click', () => {
      if (state.card.locked || state.card.is_active || state.card.status === 'active') {
        setStatus('cardBindStatus', t('bindLocked'), 'warn');
        return;
      }
      clearCardDigits();
      setStatus('cardBindStatus', '', null);
    });

    byId('btnActivateCard')?.addEventListener('click', activateCard);

    byId('btnTopupEmx')?.addEventListener('click', () => {
      if (!state.hasTonBind) {
        setStatus('actionStatus', t('noTon'), 'warn');
        return;
      }

      if (!state.card.is_active) {
        setStatus('actionStatus', t('activateFirst'), 'warn');
        return;
      }

      if (!window.StorageReloadEmx || typeof window.StorageReloadEmx.openPrepareModal !== 'function') {
        setStatus('actionStatus', t('reloadHelperMissing'), 'warn');
        return;
      }

      window.StorageReloadEmx.openPrepareModal({
        csrfToken: state.csrf.reload,
        cardNumber: state.card.number,
        walletAddress: state.walletAddress,
        cardBalance: state.balances.card_balance_rwa,
        cardStatus: state.card.is_active ? 'Active' : 'Inactive'
      });
    });

    byId('btnCommitEmx')?.addEventListener('click', () => {
      if (!window.StorageCommitEmx || typeof window.StorageCommitEmx.openPrepareModal !== 'function') {
        setStatus('actionStatus', 'Commit helper not loaded', 'warn');
        return;
      }

      window.StorageCommitEmx.openPrepareModal({
        csrfToken: state.csrf.commit,
        cardNumber: state.card.number,
        walletAddress: state.walletAddress,
        cardBalance: state.balances.card_balance_rwa,
        cardStatus: state.card.is_active ? 'Active' : 'Inactive'
      });
    });

    byId('btnTradeWebGold')?.addEventListener('click', openTradeWebGold);
    byId('btnClaimEMA')?.addEventListener('click', () => claimNow('EMA'));
    byId('btnClaimWEMS')?.addEventListener('click', () => claimNow('WEMS'));
    byId('btnClaimPacket')?.addEventListener('click', () => claimNow('USDT-TON'));
    byId('btnClaimTips')?.addEventListener('click', () => claimNow('EMX'));
    byId('btnFuelUpEmx')?.addEventListener('click', fuelUpEmx);
    byId('btnFuelUpEms')?.addEventListener('click', fuelUpEms);
    byId('btnActivationConfirm')?.addEventListener('click', confirmActivation);

    byId('btnActivationRefreshAutoConfirm')?.addEventListener('click', () => {
      if (!state.activation.ref) {
        setStatus('activationStatus', t('activationRefMissing'), 'warn');
        return;
      }
      state.activation.ui_open = true;
      updateActivationPanel();
      startAutoVerify();
    });

    byId('btnCloseActivationPanel')?.addEventListener('click', () => {
      stopAutoVerify();
      closeActivationUi(true);
    });

    byId('btnCopyActivationRef')?.addEventListener('click', copyActivationRef);
    byId('btnCopyActivationMemo')?.addEventListener('click', copyActivationMemo);

    byId('activationTxHashInput')?.addEventListener('input', (e) => {
      state.activation.tx_hash = String(e.target.value || '').trim();
    });

    byId('btnCopyAddress')?.addEventListener('click', copyAddress);
  }

  function setupSfx() {
    qsa('[data-click-sfx]').forEach((el) => {
      el.addEventListener('click', () => {
        try {
          if (typeof window.playPoadoClick === 'function') window.playPoadoClick();
        } catch (_) {}
      });
    });
  }

  function setupVisibilitySync() {
    window.addEventListener('focus', () => {
      if (state.hasTonBind && state.walletAddress) queueChainSync();
    });

    document.addEventListener('visibilitychange', () => {
      if (!document.hidden && state.hasTonBind && state.walletAddress) queueChainSync();
    });

    window.addEventListener('storage:wallet:reconnect', () => {
      if (state.hasTonBind && state.walletAddress) queueChainSync();
    });
  }

  async function initialLoad() {
    renderStorageQr(state.walletAddress || '');
    renderActivationQr();
    updateActivationDeepLink();
    syncCardPreview();
    updateReloadButtonState();
    updateBindUiState();
    updateActivationPanel();
    updateChainSyncStatus('idle', t('syncIdle'));
    setStatus('activationSummary', t('activationPanelHidden'), null);
    setText('cardBalanceValue', fmtRwa(state.balances.card_balance_rwa));

    await runAddress().catch(() => {});
    await runOverview().catch(() => {});
    await runHistory().catch(() => {});
    await runClaimPreset().catch(() => {});

    try {
      if (window.StorageManualClaimsPanel && typeof window.StorageManualClaimsPanel.refresh === 'function') {
        await window.StorageManualClaimsPanel.refresh();
      }
    } catch (_) {}

    if (state.hasTonBind && state.walletAddress) {
      queueChainSync();
    }
  }

  function boot() {
    setupLanguage();
    setupCardInputs();
    setupActions();
    setupSfx();
    setupVisibilitySync();

    window.addEventListener('storage:reload-emx:success', async () => {
      setStatus('actionStatus', t('reloadConfirmed'), 'ok');
      await refreshAllStoragePanels();
      queueChainSync();
    });

    window.addEventListener('storage:commit-emx:success', async () => {
      setStatus('actionStatus', 'Commit confirmed', 'ok');
      await refreshAllStoragePanels();
      queueChainSync();
    });

    initialLoad().catch(() => {});
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})();

/* LIVE RATE ENGINE v1 (non-invasive, FINAL LOCK) */
(function(){

  async function loadLiveRateSafe(){
    try{
      const r = await fetch('/rwa/api/global/rates.php',{
        credentials:'same-origin',
        headers:{'Accept':'application/json'}
      });

      const d = await r.json();
      if(!d || d.ok !== true) return;

      window.STORAGE_LIVE_RATE = d;

      const elRate    = document.getElementById('cardLiveRate');
      const elReverse = document.getElementById('cardReverseRate');
      const elUpdated = document.getElementById('cardLiveRateUpdated');
      const elSource  = document.getElementById('cardLiveRateSource');

      if(elRate){
        elRate.textContent = d.display?.rate_line
          ? d.display.rate_line.replace(/^1 EMX =\s*/, '')
          : (d.emx_to_rwae || '-') + ' RWA€';
      }

      if(elReverse){
        elReverse.textContent = d.display?.reverse_line
          ? d.display.reverse_line.replace(/^1 RWA€ =\s*/, '')
          : (d.rwae_to_emx || '-') + ' EMX';
      }

      if(elUpdated){
        elUpdated.textContent = d.ts || '-';
      }

      if(elSource){
        elSource.textContent = (d.source || '-') + ' / ' + (d.mode || '-');
      }

    }catch(e){
      console.warn('LIVE RATE FAILED', e);
    }
  }

  if(document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', loadLiveRateSafe);
  }else{
    loadLiveRateSafe();
  }

  document.addEventListener('visibilitychange', function(){
    if(!document.hidden) loadLiveRateSafe();
  });

})();
/* END LIVE RATE ENGINE */
