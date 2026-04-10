/*!
 * /var/www/html/public/rwa/assets/js/storage.js
 * AdoptGold / POAdo — Storage Index
 * Version: Storage Master v3
 */

(() => {
  'use strict';

  const boot = window.STORAGE_PAGE_BOOT || {};
  const body = document.body;

  const LANG = {
    en: {
      heroEyebrow: 'Storage Card',
      heroTitle: 'RWA Adoption Card',
      heroSubtitle: 'Top Up with EMX',
      btnTopupLabel: 'Top Up EMX',
      cardBalanceLabel: 'CARD BALANCE',
      bindEyebrow: 'Bind Card',
      bindTitle: 'Card Number',
      btnBindCard: 'Bind Card',
      btnClearCardInput: 'Clear',
      btnActivateCard: 'Activate Card (100 EMX)',
      cardNotice: 'No expiry. No sensitive data stored.',
      bindNoticeFull: 'Please verify your email before binding or activating card. We are not responsible for wrong card number input that may cause top-up delay.',
      onChainEyebrow: 'On Chain',
      onChainTitle: 'My Digital Rights 数权',
      bindTonTitle: 'Bind TON First',
      bindTonText: 'On-chain address, token balances, and chain actions need a bound TON wallet.',
      labelEmx: 'EMX 金权',
      labelEma: 'EMA$ 数权',
      labelWems: 'wEMS 网金',
      boundAddressLabel: 'BOUND TON ADDRESS',
      qrEmptyText: 'Bind TON to view QR',
      ewalletLabel: 'Full Ewallet',
      storeInLabel: 'STORE IN',
      storeOutLabel: 'STORE OUT',
      scanLabel: 'SCAN QR',
      storeInSub: 'Store In supports all storage tokens',
      storeOutSub: 'Store Out supports EMX / EMA$ / wEMS only',
      scanSub: '3 on-chain token selection only',
      offChainEyebrow: 'Off Chain',
      offChainTitle: 'Off Chain Unclaimed',
      unclaimEmaLabel: 'My Unclaimed EMA$',
      unclaimWemsLabel: 'My Unclaimed Web Gold wEMS',
      unclaimPacketLabel: 'My Unclaimed Gold Packet USDT-TON',
      unclaimTipsLabel: 'My Unclaimed Tips EMX',
      claimNoticeTitle: 'Claim Notice',
      claimNoticeText: 'All unclaim to on-chain actions must be user-triggered and user-paid in TON gas.',
      fuelUsdtTitle: 'My TON USDT',
      fuelEmsTitle: 'EMS (Non Gas Fees)',
      fuelTonTitle: 'My TON GAS',
      fuelUpEmxLabel: 'FUEL UP EMX',
      fuelUpEmxSub: 'FULL UP EMX from USDT-TON 1:1',
      fuelUpEmsLabel: 'FUEL UP EMS',
      fuelUpEmsSub: 'FUEL EMS GAS by exchange with 100 wEMS = 1 EMS',
      fuelFeeText: '(0.1% each transaction)',
      historyEyebrow: 'History',
      historyTitle: 'Recent Activity',
      copied: 'Copied',
      copyFailed: 'Copy failed',
      loading: 'Loading...',
      noHistory: 'No recent activity',
      bindSuccess: 'Card bound successfully',
      bindInvalid: 'Please enter exactly 16 digits',
      bindError: 'Unable to bind card',
      bindEmailRequired: 'Please verify your email before binding card',
      clearDone: 'Input cleared',
      topupOpen: 'Top up successful',
      activateOpen: 'Card activated',
      activateError: 'Activation failed',
      activateEmailRequired: 'Please verify your email before activating card',
      storeInOpen: 'Store in successful',
      storeOutOpen: 'Store out successful',
      scanOpen: 'Scan QR action reserved',
      bindRequired: 'Bind TON first',
      fuelUpEmxOpen: 'Fuel up successful',
      fuelUpEmsOpen: 'FUEL UP EMS flow reserved',
      ledEmx: 'EMX',
      ledEma: 'EMA$',
      ledWems: 'wEMS'
    },
    zh: {
      heroEyebrow: '储值卡',
      heroTitle: 'RWA 领养卡',
      heroSubtitle: '使用 EMX 充值',
      btnTopupLabel: '充值 EMX',
      cardBalanceLabel: '卡片余额',
      bindEyebrow: '绑定卡片',
      bindTitle: '卡号',
      btnBindCard: '绑定卡片',
      btnClearCardInput: '清除',
      btnActivateCard: 'Activate Card (100 EMX)',
      cardNotice: '不收取有效期。不保存敏感资料。',
      bindNoticeFull: '请先完成邮箱验证后再绑定或开卡。如因输入错误卡号导致充值延迟，本系统概不负责。',
      onChainEyebrow: '链上',
      onChainTitle: '我的数字权益',
      bindTonTitle: '请先绑定 TON',
      bindTonText: '链上地址、代币余额与链上操作都需要已绑定的 TON 钱包。',
      labelEmx: 'EMX 金权',
      labelEma: 'EMA$ 数权',
      labelWems: 'wEMS 网金',
      boundAddressLabel: '已绑定 TON 地址',
      qrEmptyText: '绑定 TON 后查看二维码',
      ewalletLabel: '完整电子钱包',
      storeInLabel: '存入',
      storeOutLabel: '转出',
      scanLabel: '扫码',
      storeInSub: '存入支持所有储值代币',
      storeOutSub: '转出仅支持 EMX / EMA$ / wEMS',
      scanSub: '仅限 3 种链上代币',
      offChainEyebrow: '链下',
      offChainTitle: '链下未领取',
      unclaimEmaLabel: '我的未领取 EMA$',
      unclaimWemsLabel: '我的未领取 网金 wEMS',
      unclaimPacketLabel: '我的未领取 金包 USDT-TON',
      unclaimTipsLabel: '我的未领取 小费 EMX',
      claimNoticeTitle: '领取提示',
      claimNoticeText: '所有从未领取转到链上的操作都必须由用户自行触发，并由用户自己支付 TON Gas。',
      fuelUsdtTitle: '我的 TON USDT',
      fuelEmsTitle: 'EMS（非Gas费用）',
      fuelTonTitle: '我的 TON GAS',
      fuelUpEmxLabel: '补充 EMX',
      fuelUpEmxSub: '从 USDT-TON 1:1 补充 EMX',
      fuelUpEmsLabel: '补充 EMS',
      fuelUpEmsSub: '100 wEMS = 1 EMS 兑换补充',
      fuelFeeText: '（每笔交易 0.1%）',
      historyEyebrow: '记录',
      historyTitle: '最近活动',
      copied: '已复制',
      copyFailed: '复制失败',
      loading: '加载中...',
      noHistory: '暂无最近记录',
      bindSuccess: '绑定卡片成功',
      bindInvalid: '请输入完整 16 位数字',
      bindError: '无法绑定卡片',
      bindEmailRequired: '请先完成邮箱验证后再绑定卡片',
      clearDone: '已清除输入',
      topupOpen: '充值成功',
      activateOpen: '开卡成功',
      activateError: '开卡失败',
      activateEmailRequired: '请先完成邮箱验证后再开卡',
      storeInOpen: '存入成功',
      storeOutOpen: '转出成功',
      scanOpen: '扫码功能预留中',
      bindRequired: '请先绑定 TON',
      fuelUpEmxOpen: '补充成功',
      fuelUpEmsOpen: '补充 EMS 流程预留中',
      ledEmx: 'EMX',
      ledEma: 'EMA$',
      ledWems: 'wEMS'
    }
  };

  const ids = [
    'heroEyebrow','heroTitle','heroSubtitle','btnTopupLabel',
    'cardBalanceLabel','bindEyebrow','bindTitle',
    'onChainEyebrow','onChainTitle','bindTonTitle','bindTonText','labelEmx','labelEma','labelWems',
    'boundAddressLabel','qrEmptyText','storeInLabel','storeOutLabel','scanLabel',
    'storeInSub','storeOutSub','scanSub','offChainEyebrow','offChainTitle','unclaimEmaLabel',
    'unclaimWemsLabel','unclaimPacketLabel','unclaimTipsLabel','claimNoticeTitle','claimNoticeText',
    'fuelUsdtTitle','fuelEmsTitle','fuelTonTitle','fuelUpEmxLabel',
    'fuelUpEmxSub','fuelUpEmsLabel','fuelUpEmsSub','fuelFeeText','historyEyebrow','historyTitle'
  ];

  const els = {
    langButtons: [...document.querySelectorAll('.lang-inline-btn')],
    cardDigits: [...document.querySelectorAll('.card-digit')],
    storageCardNumber: document.getElementById('storageCardNumber'),
    storageTonAddress: document.getElementById('storageTonAddress'),
    storageQrWrap: document.getElementById('storageQrWrap'),
    storageHistoryList: document.getElementById('storageHistoryList'),
    storageBindNotice: document.getElementById('storageBindNotice'),
    cardBindStatus: document.getElementById('cardBindStatus'),
    copyStatus: document.getElementById('copyStatus'),
    actionStatus: document.getElementById('actionStatus'),
    fuelStatus: document.getElementById('fuelStatus'),
    btnBindCard: document.getElementById('btnBindCard'),
    btnClearCardInput: document.getElementById('btnClearCardInput'),
    btnActivateCard: document.getElementById('btnActivateCard'),
    btnTopupEmx: document.getElementById('btnTopupEmx'),
    btnStoreIn: document.getElementById('btnStoreIn'),
    btnStoreOut: document.getElementById('btnStoreOut'),
    btnScan: document.getElementById('btnScan'),
    btnCopyAddress: document.getElementById('btnCopyAddress'),
    btnFuelUpEmx: document.getElementById('btnFuelUpEmx'),
    btnFuelUpEms: document.getElementById('btnFuelUpEms'),
    btnActivateCardText: document.getElementById('btnActivateCardText'),
    btnBindCardText: document.getElementById('btnBindCardText'),
    btnClearCardInputText: document.getElementById('btnClearCardInputText'),
    cardBalanceValue: document.getElementById('cardBalanceValue'),
    balEMX: document.getElementById('balEMX'),
    balEMA: document.getElementById('balEMA'),
    balWEMS: document.getElementById('balWEMS'),
    unclaimEMA: document.getElementById('unclaimEMA'),
    unclaimWEMS: document.getElementById('unclaimWEMS'),
    unclaimPacket: document.getElementById('unclaimPacket'),
    unclaimTips: document.getElementById('unclaimTips'),
    fuelUSDT: document.getElementById('fuelUSDT'),
    fuelEMS: document.getElementById('fuelEMS'),
    fuelTON: document.getElementById('fuelTON'),
    chainTokenScope: document.getElementById('chainTokenScope')
  };

  ids.forEach((id) => { els[id] = document.getElementById(id); });

  const state = {
    lang: resolveInitialLanguage(),
    hasTonBind: String(body.dataset.hasTonBind || (boot.hasTonBind ? '1' : '0')) === '1',
    tonAddress: String(boot.walletAddress || body.dataset.wallet || '').trim(),
    emailVerified: String(body.dataset.emailVerified || '0') === '1',
    qrSvg: '',
    history: [],
    cardMasked: '•••• - •••• - •••• - ••••',
    cardBalanceRaw: 0,
    cardBalanceLastDirection: 'flat'
  };

  function resolveInitialLanguage() {
    const saved = localStorage.getItem('rwa_storage_lang');
    if (saved === 'en' || saved === 'zh') return saved;
    const browser = (navigator.language || 'en').toLowerCase();
    return browser.startsWith('zh') ? 'zh' : 'en';
  }

  function t(key) {
    const dict = LANG[state.lang] || LANG.en;
    return dict[key] || LANG.en[key] || key;
  }

  function setText(el, value) {
    if (el) el.textContent = value;
  }

  function setHtml(el, value) {
    if (el) el.innerHTML = value;
  }

  function escapeHtml(str) {
    return String(str ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function renderLedScope() {
    if (!els.chainTokenScope) return;
    els.chainTokenScope.innerHTML = `
      <span class="led-chip gold"><span class="led-dot"></span><span>${escapeHtml(t('ledEmx'))}</span></span>
      <span class="led-chip orange"><span class="led-dot"></span><span>${escapeHtml(t('ledEma'))}</span></span>
      <span class="led-chip green"><span class="led-dot"></span><span>${escapeHtml(t('ledWems'))}</span></span>
    `;
  }

  function applyLanguage(lang) {
    state.lang = lang === 'zh' ? 'zh' : 'en';
    document.documentElement.lang = state.lang === 'zh' ? 'zh-CN' : 'en';
    localStorage.setItem('rwa_storage_lang', state.lang);

    els.langButtons.forEach((btn) => {
      btn.classList.toggle('is-active', btn.dataset.lang === state.lang);
    });

    ids.forEach((id) => setText(els[id], t(id)));
    setText(els.btnBindCardText, t('btnBindCard'));
    setText(els.btnClearCardInputText, t('btnClearCardInput'));
    setText(els.btnActivateCardText, t('btnActivateCard'));
    if (els.storageBindNotice) {
      els.storageBindNotice.innerHTML = escapeHtml(t('bindNoticeFull'));
    }

    renderLedScope();
    renderHistory();
  }

  function onlyDigits(value) {
    return String(value || '').replace(/\D+/g, '');
  }

  function format16Digits(value) {
    const digits = onlyDigits(value).slice(0, 16);
    if (!digits) return '•••• - •••• - •••• - ••••';
    const groups = digits.match(/.{1,4}/g) || [];
    return groups.join(' - ');
  }

  function cardDigitsValue() {
    return els.cardDigits.map((input) => onlyDigits(input.value).slice(0, 1)).join('');
  }

  function updateCardDisplayFromInputs() {
    const digits = cardDigitsValue();
    if (els.storageCardNumber) {
      els.storageCardNumber.textContent = digits ? format16Digits(digits) : state.cardMasked;
    }
  }

  function clearCardDigits(focusFirst = false) {
    els.cardDigits.forEach((input) => { input.value = ''; });
    updateCardDisplayFromInputs();
    if (focusFirst && els.cardDigits[0]) els.cardDigits[0].focus();
  }

  function setCardDigitsFromString(value) {
    const digits = onlyDigits(value).slice(0, 16).split('');
    els.cardDigits.forEach((input, idx) => { input.value = digits[idx] || ''; });
    updateCardDisplayFromInputs();
  }

  function setStatus(el, message, isError = false) {
    if (!el) return;
    el.textContent = message || '';
    el.classList.toggle('is-error', !!isError);
  }

  function parseMoney(value) {
    const n = Number(String(value ?? '').replace(/[^0-9.\-]/g, ''));
    return Number.isFinite(n) ? n : 0;
  }

  function formatRwaBalance(value) {
    return `RWA$ ${Number(value || 0).toFixed(4)}`;
  }

  function balanceChars(value) {
    return formatRwaBalance(value).split('');
  }

  function buildRollingBalanceHtml(value) {
    return balanceChars(value).map((ch) => {
      const safe = escapeHtml(ch);
      return `<span class="rb-char"><span class="rb-char-inner">${safe}</span></span>`;
    }).join('');
  }

  function syncRollingCardBalance(nextValue) {
    const el = els.cardBalanceValue;
    if (!el) return;

    const oldValue = Number.isFinite(state.cardBalanceRaw) ? state.cardBalanceRaw : 0;
    const newValue = parseMoney(nextValue);

    let direction = 'flat';
    if (newValue > oldValue) direction = 'up';
    if (newValue < oldValue) direction = 'down';

    state.cardBalanceRaw = newValue;
    state.cardBalanceLastDirection = direction;

    el.classList.remove('is-up', 'is-down', 'is-flat', 'is-rolling');
    void el.offsetWidth;

    el.innerHTML = buildRollingBalanceHtml(newValue);
    el.classList.add('is-rolling');
    el.classList.add(direction === 'up' ? 'is-up' : direction === 'down' ? 'is-down' : 'is-flat');
  }

  function renderHistory() {
    if (!els.storageHistoryList) return;
    if (!Array.isArray(state.history) || state.history.length === 0) {
      els.storageHistoryList.innerHTML = `<div class="history-empty">${escapeHtml(t('noHistory'))}</div>`;
      return;
    }

    els.storageHistoryList.innerHTML = state.history.map((item) => {
      const type = String(item.type || '').replace(/_/g, ' ').toUpperCase();
      const token = String(item.token || '');
      const amount = String(item.amount || '0.0000');
      const createdAt = String(item.created_at || '');
      return `
        <div class="history-row">
          <div class="history-main">
            <div class="history-type">${escapeHtml(type)}</div>
            <div class="history-meta">${escapeHtml(token)} · ${escapeHtml(createdAt)}</div>
          </div>
          <div class="history-amount">${escapeHtml(amount)}</div>
        </div>
      `;
    }).join('');
  }

  async function fetchJson(url, options = {}) {
    const response = await fetch(url, {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      ...options
    });

    const text = await response.text();

    if (!response.ok) {
      let msg = text || `HTTP ${response.status}`;
      try {
        const parsed = JSON.parse(text);
        if (parsed && parsed.message) msg = parsed.message;
      } catch (_) {}
      throw new Error(msg);
    }

    if (text.trim().startsWith('<')) {
      throw new Error('Expected JSON but received HTML');
    }

    return JSON.parse(text);
  }

  async function loadOverview() {
    try {
      const data = await fetchJson('/rwa/api/storage/overview.php');
      if (!data || !data.ok) throw new Error('overview failed');

      if (data.card_number_masked) {
        state.cardMasked = String(data.card_number_masked);
        if (!cardDigitsValue() && els.storageCardNumber) {
          els.storageCardNumber.textContent = state.cardMasked;
        }
      }

      const hiddenCardBalanceSource = (
        data.fuel_usdt_ton !== undefined
          ? data.fuel_usdt_ton
          : (data.card_balance_rwa !== undefined ? data.card_balance_rwa : '0.0000')
      );

      const incomingBalance = parseMoney(hiddenCardBalanceSource);
      if (els.cardBalanceValue && !els.cardBalanceValue.dataset.initDone) {
        state.cardBalanceRaw = incomingBalance;
        els.cardBalanceValue.innerHTML = buildRollingBalanceHtml(incomingBalance);
        els.cardBalanceValue.classList.remove('is-up', 'is-down');
        els.cardBalanceValue.classList.add('is-flat');
        els.cardBalanceValue.dataset.initDone = '1';
      } else {
        syncRollingCardBalance(incomingBalance);
      }

      if (data.onchain_emx !== undefined) setText(els.balEMX, String(data.onchain_emx));
      if (data.onchain_ema !== undefined) setText(els.balEMA, String(data.onchain_ema));
      if (data.onchain_wems !== undefined) setText(els.balWEMS, String(data.onchain_wems));

      if (data.unclaim_ema !== undefined) setText(els.unclaimEMA, String(data.unclaim_ema));
      if (data.unclaim_wems !== undefined) setText(els.unclaimWEMS, String(data.unclaim_wems));
      if (data.unclaim_gold_packet_usdt !== undefined) setText(els.unclaimPacket, String(data.unclaim_gold_packet_usdt));
      if (data.unclaim_tips_emx !== undefined) setText(els.unclaimTips, String(data.unclaim_tips_emx));

      if (data.fuel_usdt_ton !== undefined) setText(els.fuelUSDT, String(data.fuel_usdt_ton));
      if (data.fuel_ems !== undefined) setText(els.fuelEMS, String(data.fuel_ems));
      if (data.fuel_ton_gas !== undefined) setText(els.fuelTON, String(data.fuel_ton_gas));

      if (typeof data.card_is_bound !== 'undefined') state.cardIsBound = !!data.card_is_bound;
      if (typeof data.card_is_active !== 'undefined') state.cardIsActive = !!data.card_is_active;
    } catch (err) {
      console.error(err);
    }
  }

  async function loadAddress() {
    if (!state.hasTonBind) {
      if (els.storageTonAddress) els.storageTonAddress.textContent = '-';
      if (els.storageQrWrap) {
        els.storageQrWrap.innerHTML = `<div class="wallet-empty">${escapeHtml(t('qrEmptyText'))}</div>`;
      }
      return;
    }

    try {
      const data = await fetchJson('/rwa/api/storage/address.php');
      if (!data || !data.ok) throw new Error('address failed');

      state.tonAddress = String(data.ton_address || '').trim();
      state.qrSvg = String(data.qr_svg || '').trim();

      if (els.storageTonAddress) els.storageTonAddress.textContent = state.tonAddress || '-';
      if (els.storageQrWrap) {
        els.storageQrWrap.innerHTML = state.qrSvg || `<div class="wallet-empty">${escapeHtml(t('loading'))}</div>`;
      }
    } catch (err) {
      console.error(err);
    }
  }

  async function loadHistory() {
    try {
      const data = await fetchJson('/rwa/api/storage/history.php');
      if (!data || !data.ok) throw new Error('history failed');
      state.history = Array.isArray(data.items) ? data.items : [];
      renderHistory();
    } catch (err) {
      console.error(err);
      state.history = [];
      renderHistory();
    }
  }

  async function reloadAll() {
    await Promise.all([loadOverview(), loadAddress(), loadHistory()]);
  }

  function requireVerifiedEmail(messageKey, statusEl) {
    if (state.emailVerified) return true;
    setStatus(statusEl, t(messageKey), true);
    return false;
  }

  async function bindCard() {
    if (!requireVerifiedEmail('bindEmailRequired', els.cardBindStatus)) return;

    const digits = cardDigitsValue();
    if (digits.length !== 16) {
      setStatus(els.cardBindStatus, t('bindInvalid'), true);
      return;
    }

    setStatus(els.cardBindStatus, t('loading'));
    const form = new FormData();
    form.append('action', 'bind');
    form.append('csrf', body.dataset.bindCsrf || '');
    form.append('card_number', digits);

    try {
      const data = await fetchJson('/rwa/api/storage/overview.php', { method: 'POST', body: form });
      state.cardMasked = String(data.card_number_masked || format16Digits(digits));
      if (els.storageCardNumber) els.storageCardNumber.textContent = state.cardMasked;
      clearCardDigits(false);
      setStatus(els.cardBindStatus, data.message || t('bindSuccess'));
      await reloadAll();
    } catch (err) {
      console.error(err);
      setStatus(els.cardBindStatus, err.message || t('bindError'), true);
    }
  }

  async function topupEmx() {
    setStatus(els.actionStatus, t('loading'));
    const form = new FormData();
    form.append('action', 'topup');
    form.append('csrf', body.dataset.topupCsrf || '');
    form.append('token', 'EMX');
    form.append('amount', '0');

    try {
      const data = await fetchJson('/rwa/api/storage/topup.php', { method: 'POST', body: form });
      setStatus(els.actionStatus, data.message || t('topupOpen'));
      await reloadAll();
    } catch (err) {
      console.error(err);
      setStatus(els.actionStatus, err.message || t('topupOpen'), true);
    }
  }

  async function activateCard() {
    if (!requireVerifiedEmail('activateEmailRequired', els.actionStatus)) return;

    setStatus(els.actionStatus, t('loading'));
    const form = new FormData();
    form.append('action', 'activate');
    form.append('csrf', body.dataset.topupCsrf || '');

    try {
      const data = await fetchJson('/rwa/api/storage/activate.php', { method: 'POST', body: form });
      setStatus(els.actionStatus, data.message || t('activateOpen'));
      await reloadAll();
    } catch (err) {
      console.error(err);
      setStatus(els.actionStatus, err.message || t('activateError'), true);
    }
  }

  async function storeAction(direction) {
    if (!state.hasTonBind) {
      setStatus(els.actionStatus, t('bindRequired'), true);
      return;
    }

    setStatus(els.actionStatus, t('loading'));
    const form = new FormData();
    form.append('action', direction);
    form.append('csrf', body.dataset.storeCsrf || '');
    form.append('token', 'EMX');
    form.append('amount', '0');

    try {
      const data = await fetchJson('/rwa/api/storage/store-action.php', { method: 'POST', body: form });
      setStatus(els.actionStatus, data.message || (direction === 'in' ? t('storeInOpen') : t('storeOutOpen')));
      await reloadAll();
    } catch (err) {
      console.error(err);
      setStatus(els.actionStatus, err.message || (direction === 'in' ? t('storeInOpen') : t('storeOutOpen')), true);
    }
  }

  async function copyAddress() {
    if (!state.hasTonBind || !state.tonAddress) {
      setStatus(els.copyStatus, t('bindRequired'), true);
      return;
    }

    try {
      await navigator.clipboard.writeText(state.tonAddress);
      setStatus(els.copyStatus, t('copied'));
    } catch (err) {
      console.error(err);
      setStatus(els.copyStatus, t('copyFailed'), true);
    }
  }

  async function fuelUpEmx() {
    setStatus(els.fuelStatus, t('loading'));
    const form = new FormData();
    form.append('action', 'topup');
    form.append('mode', 'FUEL_EMX');
    form.append('token', 'EMX');
    form.append('amount', '100.0000');
    form.append('csrf', body.dataset.topupCsrf || '');

    try {
      const data = await fetchJson('/rwa/api/storage/topup.php', { method: 'POST', body: form });
      setStatus(els.fuelStatus, data.message || t('fuelUpEmxOpen'));
      await reloadAll();
    } catch (err) {
      console.error(err);
      setStatus(els.fuelStatus, err.message || t('fuelUpEmxOpen'), true);
    }
  }

  function fuelUpEms() {
    setStatus(els.fuelStatus, t('fuelUpEmsOpen'));
  }

  function setupLanguage() {
    els.langButtons.forEach((btn) => {
      btn.addEventListener('click', () => applyLanguage(btn.dataset.lang));
    });
    applyLanguage(state.lang);
  }

  function setupCardInputs() {
    els.cardDigits.forEach((input, index) => {
      input.addEventListener('input', (e) => {
        const value = onlyDigits(e.target.value).slice(0, 1);
        e.target.value = value;

        if (value && index < els.cardDigits.length - 1) {
          els.cardDigits[index + 1].focus();
          els.cardDigits[index + 1].select();
        }

        updateCardDisplayFromInputs();
        setStatus(els.cardBindStatus, '');
      });

      input.addEventListener('keydown', (e) => {
        if (e.key === 'Backspace' && !input.value && index > 0) {
          els.cardDigits[index - 1].focus();
          els.cardDigits[index - 1].value = '';
          updateCardDisplayFromInputs();
        }
      });

      input.addEventListener('paste', (e) => {
        e.preventDefault();
        const pasted = onlyDigits((e.clipboardData || window.clipboardData).getData('text')).slice(0, 16);
        if (!pasted) return;
        setCardDigitsFromString(pasted);
      });
    });
  }

  function setupActions() {
    els.btnBindCard?.addEventListener('click', bindCard);
    els.btnClearCardInput?.addEventListener('click', () => {
      clearCardDigits(true);
      setStatus(els.cardBindStatus, t('clearDone'));
    });
    els.btnTopupEmx?.addEventListener('click', topupEmx);
    els.btnActivateCard?.addEventListener('click', activateCard);
    els.btnStoreIn?.addEventListener('click', () => storeAction('in'));
    els.btnStoreOut?.addEventListener('click', () => storeAction('out'));
    els.btnScan?.addEventListener('click', () => {
      if (!state.hasTonBind) {
        setStatus(els.actionStatus, t('bindRequired'), true);
        return;
      }
      setStatus(els.actionStatus, t('scanOpen'));
    });
    els.btnCopyAddress?.addEventListener('click', copyAddress);
    els.btnFuelUpEmx?.addEventListener('click', fuelUpEmx);
    els.btnFuelUpEms?.addEventListener('click', fuelUpEms);
  }

  async function init() {
    setupLanguage();
    setupCardInputs();
    setupActions();

    if (els.storageHistoryList) {
      els.storageHistoryList.innerHTML = `<div class="history-empty">${escapeHtml(t('loading'))}</div>`;
    }

    await reloadAll();
  }

  document.addEventListener('DOMContentLoaded', init);
})();