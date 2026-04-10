/**
 * /var/www/html/public/rwa/cert/cert.js
 * Version: v7.5.0-20260410-translator-only-global-lock
 *
 * GLOBAL MASTER LOCK
 * - cert.js = translator only
 * - NEVER own:
 *   - Check & Preview
 *   - Issue & Pay
 *   - Reconfirm Payment
 *   - balance loading
 *   - token image loading
 *   - queue polling
 *   - queue rendering
 *   - mint init / mint verify
 *   - verify-status business logic
 * - Safe to load before or after cert-router.js / cert-actions.js
 * - Must preserve exact existing DOM ids
 */

(function () {
  'use strict';

  if (window.CERT_TRANSLATOR_ONLY_ACTIVE) return;
  window.CERT_TRANSLATOR_ONLY_ACTIVE = true;

  const LANG_KEY = 'poado_cert_lang_v750';
  const $ = (id) => document.getElementById(id);

  const I18N = {
    en: {
      storage_kicker: 'Storage',
      storage_title: 'My Gold Mining Storage Balance',
      storage_sub: 'Live on-chain balances used for business payment and TON settlement readiness.',
      storage_wems_sub: 'On-chain Genesis mint balance',
      storage_ema_sub: 'On-chain Secondary / Tertiary mint balance',
      storage_ton_sub_prefix: 'Mint Gas Ready:',
      operator_label: 'Operator',

      factory_kicker: 'RWA FACTORY',
      factory_title: 'RWA Cert Issuance Settlement',
      factory_sub: 'Business payment only. Already paid certs move out of this section and appear only in Mint Ready Queue / NFT Factory.',

      step_1: 'Check & Preview',
      step_2: 'Business Payment',
      step_3: 'Mint Init',
      step_4: 'Wallet Sign',
      step_5: 'On-chain Verify',

      btn_check_preview: 'Check & Preview',
      btn_issue_pay: 'Issue & Pay',
      btn_auto_issue: 'Auto Issue Tx 5s',
      btn_finalize_mint: 'Finalize Mint',

      active_empty_title: 'No payment-pending cert yet',
      active_empty_sub: 'Choose any of the 8 RWA cards and click Check & Preview.',
      mini_cert_uid: 'Cert UID',
      mini_payment: 'Payment',
      mini_payment_ref: 'Payment Ref',
      mini_nft_item: 'NFT Item',
      next_step_default: 'Next step will highlight automatically when previous step is done.',

      mint_queue_kicker: 'Mint Ready Queue',
      mint_queue_sub: 'Only payment-confirmed and mint-ready certs appear here.',
      mint_queue_empty: 'No mint-ready cert yet.',

      nft_kicker: 'NFT FACTORY',
      nft_title: 'NFT Minting Process Settlement',
      nft_sub: 'Mint only. Prepare opens TON wallet directly. QR is hidden to avoid settlement confusion.',
      mint_active_cert: 'Active Cert',
      mint_recipient: 'Recipient',
      mint_amount_ton: 'Amount TON',
      mint_amount_nano: 'Amount Nano',
      mint_item_index: 'Item Index',
      mint_status: 'Mint Status',
      mint_payload: 'Payload',
      settlement_flow: 'Settlement Flow',
      settlement_flow_text: 'Step 1 prepares mint payload and opens TON wallet.<br>Step 2 refreshes on-chain verify loop.<br>Step 3 opens verification / marketplace view.',
      wallet_deeplink: 'Wallet Deeplink',
      settlement_status: 'Settlement Status',
      mint_btn_prepare: 'Step 1 · Prepare & Mint Now',
      mint_btn_auto: 'Step 2 · Auto Mint Tx (5s)',
      mint_btn_verify: 'Step 3 · Verify at Getgems.io',

      console_kicker: 'Factory Console Log',
      console_title: 'Factory Console Log',

      business_payment: 'BUSINESS PAYMENT',
      issue_pay_title: 'Issue & Pay',
      close_btn: 'Close',
      modal_token: 'Token',
      modal_amount: 'Amount',
      open_wallet_btn: 'Open Wallet',
      copy_ref_btn: 'Copy Ref',
      refresh_verify_btn: 'Refresh Verify',
      auto_issue_modal_btn: 'Auto Issue Tx (5s)',
      payment_qr: 'Payment QR',
      payment_status: 'Payment Status',
      qr_pending: 'QR pending.',
      waiting_text: 'Waiting.'
    },

    zh: {
      storage_kicker: '存储',
      storage_title: '我的黄金矿业存储余额',
      storage_sub: '用于业务支付与 TON 结算准备的链上实时余额。',
      storage_wems_sub: '链上 Genesis 铸造余额',
      storage_ema_sub: '链上 Secondary / Tertiary 铸造余额',
      storage_ton_sub_prefix: '铸造 Gas 就绪：',
      operator_label: '操作员',

      factory_kicker: 'RWA 工厂',
      factory_title: 'RWA 证书签发结算',
      factory_sub: '这里只处理业务支付。已支付证书会移出此区，仅显示于 Mint Ready Queue / NFT Factory。',

      step_1: '检查与预览',
      step_2: '业务支付',
      step_3: '铸造初始化',
      step_4: '钱包签名',
      step_5: '链上验证',

      btn_check_preview: '检查与预览',
      btn_issue_pay: '签发并支付',
      btn_auto_issue: '自动验证支付 5 秒',
      btn_finalize_mint: '前往铸造',

      active_empty_title: '当前没有待支付证书',
      active_empty_sub: '请选择 8 张 RWA 卡之一并点击检查与预览。',
      mini_cert_uid: '证书 UID',
      mini_payment: '支付',
      mini_payment_ref: '支付参考号',
      mini_nft_item: 'NFT 项目',
      next_step_default: '完成上一步后，下一步会自动高亮。',

      mint_queue_kicker: '可铸造队列',
      mint_queue_sub: '只有已确认支付且可铸造的证书会显示在这里。',
      mint_queue_empty: '当前没有可铸造证书。',

      nft_kicker: 'NFT 工厂',
      nft_title: 'NFT 铸造流程结算',
      nft_sub: '这里只处理铸造。准备后会直接打开 TON 钱包。为避免混淆，QR 隐藏。',
      mint_active_cert: '当前证书',
      mint_recipient: '接收方',
      mint_amount_ton: 'TON 数量',
      mint_amount_nano: 'Nano 数量',
      mint_item_index: '项目索引',
      mint_status: '铸造状态',
      mint_payload: '载荷',
      settlement_flow: '结算流程',
      settlement_flow_text: '第 1 步准备铸造载荷并打开 TON 钱包。<br>第 2 步刷新链上验证循环。<br>第 3 步打开验证 / 市场页面。',
      wallet_deeplink: '钱包 Deeplink',
      settlement_status: '结算状态',
      mint_btn_prepare: '第 1 步 · 准备并立即铸造',
      mint_btn_auto: '第 2 步 · 自动检测铸造 (5 秒)',
      mint_btn_verify: '第 3 步 · 前往 Getgems.io 验证',

      console_kicker: '工厂控制台日志',
      console_title: '工厂控制台日志',

      business_payment: '业务支付',
      issue_pay_title: '签发并支付',
      close_btn: '关闭',
      modal_token: '代币',
      modal_amount: '数量',
      open_wallet_btn: '打开钱包',
      copy_ref_btn: '复制参考号',
      refresh_verify_btn: '刷新验证',
      auto_issue_modal_btn: '自动验证支付 (5 秒)',
      payment_qr: '支付二维码',
      payment_status: '支付状态',
      qr_pending: '二维码待生成。',
      waiting_text: '等待中。'
    }
  };

  function certLog(...args) {
    console.log('[CERT_TRANSLATOR]', ...args);
  }

  function getLang() {
    const saved = localStorage.getItem(LANG_KEY);
    return saved === 'zh' ? 'zh' : 'en';
  }

  function saveLang(lang) {
    localStorage.setItem(LANG_KEY, lang === 'zh' ? 'zh' : 'en');
  }

  function t(lang, key) {
    return (I18N[lang] && I18N[lang][key]) || (I18N.en && I18N.en[key]) || key;
  }

  function applyLang(lang) {
    const next = lang === 'zh' ? 'zh' : 'en';

    document.documentElement.setAttribute('lang', next === 'zh' ? 'zh-CN' : 'en');
    document.documentElement.setAttribute('data-lang', next);
    document.body.classList.remove('lang-en', 'lang-zh');
    document.body.classList.add(next === 'zh' ? 'lang-zh' : 'lang-en');

    document.querySelectorAll('[data-i18n]').forEach((node) => {
      const key = node.getAttribute('data-i18n');
      if (!key) return;
      node.innerHTML = t(next, key);
    });

    const enBtn = $('langBtnEn');
    const zhBtn = $('langBtnZh');
    if (enBtn) enBtn.classList.toggle('is-active', next === 'en');
    if (zhBtn) zhBtn.classList.toggle('is-active', next === 'zh');

    window.__CERT_LANG = next;

    document.dispatchEvent(new CustomEvent('cert:lang-changed', {
      detail: { lang: next }
    }));
  }

  function bindLangButtons() {
    $('langBtnEn')?.addEventListener('click', () => {
      saveLang('en');
      applyLang('en');
    });

    $('langBtnZh')?.addEventListener('click', () => {
      saveLang('zh');
      applyLang('zh');
    });
  }

  function initTranslator() {
    bindLangButtons();
    applyLang(getLang());
    certLog('translator-only ready');
  }

  document.addEventListener('DOMContentLoaded', initTranslator, { once: true });
})();
