/**
 * /var/www/html/public/rwa/cert/cert-router.js
 * Version: v10.1.0-20260410-reconfirm-fix-maintain-functions
 *
 * LOCK
 * - Queue / continuity helper only
 * - Must NOT own Check & Preview
 * - Must NOT own Issue & Pay
 * - Must NOT amend verify.php
 * - Must NOT amend local QR flow
 * - Backend truth remains API-driven
 * - Must preserve exact existing DOM ids
 * - Must consume queue-summary.php only
 *
 * OWNERSHIP
 * - queue-summary polling
 * - queue render
 * - continuity highlight
 * - finalize handoff ONLY → NFT Factory
 * - payment confirmation handoff only
 *
 * FINALIZE RULE (CRITICAL)
 * - MUST NOT call finalize.php
 * - MUST NOT call mint-init.php
 * - MUST ONLY:
 *   ✔ set selected cert uid
 *   ✔ dispatch cert:nft-focus
 *   ✔ scroll to NFT Factory
 *
 * PAYMENT CONFIRM RULE
 * - MUST NOT verify payment by itself
 * - MUST ONLY:
 *   ✔ set selected cert uid
 *   ✔ dispatch cert:payment-reconfirm
 *   ✔ optionally trigger existing modal verify button if present
 *   ✔ refresh queues after handoff request
 *
 * CANONICAL QUEUE ORDER
 * - issuance_factory
 * - payment_confirmation
 * - payment_confirmed_pending_artifact
 * - mint_ready_queue
 * - minting_process
 * - issued
 * - blocked
 */

(function () {
  'use strict';

  if (window.CERT_ROUTER_V101_ACTIVE) return;
  window.CERT_ROUTER_V101_ACTIVE = true;

  const $ = (id) => document.getElementById(id);

  const state = {
    booted: false,
    currentBucket: 'issuance_factory',
    selectedCertUid: '',
    selectedRow: null,
    summaryTimer: null,
    summaryIntervalMs: 12000,
    queueJson: null,
    pollingPausedUntil: 0
  };

  const LABELS = {
    en: {
      previewNft: 'Preview NFT',
      finalizeMint: 'Finalize Mint',
      reconfirmPayment: 'Reconfirm Payment',
      confirmed: 'CONFIRMED',
      pending: 'PENDING',
      artifactReady: 'ARTIFACT READY',
      artifactPending: 'ARTIFACT PENDING',
      nftHealthy: 'NFT HEALTHY',
      nftPending: 'NFT PENDING',
      noMintReady: 'No mint-ready cert yet.',
      noPaymentConfirmation: 'No payment confirmation items.',
      noPendingArtifact: 'No payment-confirmed pending-artifact items.',
      noIssuance: 'No issuance items.',
      noMinting: 'No minting items.',
      noIssued: 'No issued items.',
      noBlocked: 'No blocked items.',
      paymentConfirmation: 'Awaiting confirmation',
      pendingArtifact: 'Payment confirmed, artifact pending',
      mintReady: 'Mint ready',
      minting: 'Minting',
      issued: 'Issued / Minted',
      blocked: 'Blocked'
    },
    zh: {
      previewNft: '预览 NFT',
      finalizeMint: '前往铸造',
      reconfirmPayment: '重新确认支付',
      confirmed: '已确认',
      pending: '待处理',
      artifactReady: '工件已就绪',
      artifactPending: '工件待完成',
      nftHealthy: 'NFT 正常',
      nftPending: 'NFT 待完成',
      noMintReady: '当前没有可铸造证书。',
      noPaymentConfirmation: '当前没有待确认支付项目。',
      noPendingArtifact: '当前没有待补工件项目。',
      noIssuance: '当前没有签发工厂项目。',
      noMinting: '当前没有铸造中项目。',
      noIssued: '当前没有已签发项目。',
      noBlocked: '当前没有阻塞项目。',
      paymentConfirmation: '等待确认',
      pendingArtifact: '支付已确认，工件待完成',
      mintReady: '可铸造',
      minting: '铸造中',
      issued: '已签发 / 已铸造',
      blocked: '阻塞'
    }
  };

  function log(...a) { console.log('[CERT_ROUTER]', ...a); }
  function warn(...a) { console.warn('[CERT_ROUTER]', ...a); }
  function err(...a) { console.error('[CERT_ROUTER]', ...a); }

  function esc(v) {
    return String(v ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function getLang() {
    const attr = String(document.documentElement.getAttribute('data-lang') || '').trim().toLowerCase();
    if (attr === 'zh') return 'zh';
    if (window.__CERT_LANG === 'zh') return 'zh';
    return 'en';
  }

  function t(key) {
    const lang = getLang();
    return (LABELS[lang] && LABELS[lang][key]) || LABELS.en[key] || key;
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

    if (!res.ok) {
      throw new Error((json && (json.error || json.detail || json.message)) || text || ('HTTP_' + res.status));
    }
    if (!json) throw new Error('INVALID_JSON_RESPONSE');
    if (json.ok === false) throw new Error(json.error || json.detail || json.message || 'REQUEST_FAILED');

    return json;
  }

  function queueUrl() {
    const endpoint = String($('endpointQueueSummary')?.value || '/rwa/cert/api/queue-summary.php').trim();
    const url = new URL(endpoint, window.location.origin);
    url.searchParams.set('_ts', String(Date.now()));
    return url.toString();
  }

  function normalizeBuckets(json) {
    const b = json?.buckets || {};
    window.__CERT_QUEUE_SUMMARY__ = json || {};

    return {
      issuance_factory: Array.isArray(b.issuance_factory) ? b.issuance_factory : [],
      payment_confirmation: Array.isArray(b.payment_confirmation) ? b.payment_confirmation : [],
      payment_confirmed_pending_artifact: Array.isArray(b.payment_confirmed_pending_artifact) ? b.payment_confirmed_pending_artifact : [],
      mint_ready_queue: Array.isArray(b.mint_ready_queue) ? b.mint_ready_queue : [],
      minting_process: Array.isArray(b.minting_process) ? b.minting_process : [],
      issued: Array.isArray(b.issued) ? b.issued : [],
      blocked: Array.isArray(b.blocked) ? b.blocked : []
    };
  }

  function itemCertUid(row) {
    return String(row?.cert_uid || row?.uid || '').trim();
  }

  function itemRwaCode(row) {
    return String(row?.rwa_code || row?.rwa_type || 'RWA').trim();
  }

  function itemQueueBucket(row, fallbackBucket) {
    return String(row?.queue_bucket || fallbackBucket || '').trim();
  }

  function itemPaymentStatus(row) {
    return String(row?.payment_status || '').trim().toLowerCase();
  }

  function itemPaymentVerified(row) {
    return Number(row?.payment_verified || 0) === 1;
  }

  function itemArtifactReady(row) {
    return row?.artifact_ready === true;
  }

  function itemNftHealthy(row) {
    return row?.nft_healthy === true;
  }

  function queueLabel(bucket) {
    switch (bucket) {
      case 'payment_confirmation':
        return t('paymentConfirmation');
      case 'payment_confirmed_pending_artifact':
        return t('pendingArtifact');
      case 'mint_ready_queue':
        return t('mintReady');
      case 'minting_process':
        return t('minting');
      case 'issued':
        return t('issued');
      case 'blocked':
        return t('blocked');
      default:
        return String(bucket || '').replaceAll('_', ' ');
    }
  }

  function allRows() {
    const json = window.__CERT_QUEUE_SUMMARY__ || {};
    const b = normalizeBuckets(json);

    return []
      .concat(b.issuance_factory)
      .concat(b.payment_confirmation)
      .concat(b.payment_confirmed_pending_artifact)
      .concat(b.mint_ready_queue)
      .concat(b.minting_process)
      .concat(b.issued)
      .concat(b.blocked);
  }

  function findRowByUid(uid) {
    uid = String(uid || '').trim();
    if (!uid) return {};
    return allRows().find((r) => itemCertUid(r) === uid) || {};
  }

  function buildActionButtons(row, bucket) {
    const uid = itemCertUid(row);
    const queueBucket = itemQueueBucket(row, bucket);

    if (!uid) return '';

    if (queueBucket === 'payment_confirmation') {
      return `
        <button type="button"
                class="btn-row-mini gold"
                data-action="reconfirm-payment"
                data-cert-uid="${esc(uid)}">${esc(t('reconfirmPayment'))}</button>
      `;
    }

    if (queueBucket === 'payment_confirmed_pending_artifact') {
      return `
        <button type="button"
                class="btn-row-mini preview-nft-btn"
                data-action="preview-nft"
                data-cert-uid="${esc(uid)}">${esc(t('previewNft'))}</button>
      `;
    }

    if (queueBucket === 'mint_ready_queue') {
      return `
        <button type="button"
                class="btn-row-mini preview-nft-btn"
                data-action="preview-nft"
                data-cert-uid="${esc(uid)}">${esc(t('previewNft'))}</button>
        <button type="button"
                class="btn-row-mini gold finalize-mint-btn"
                data-action="finalize-mint"
                data-cert-uid="${esc(uid)}">${esc(t('finalizeMint'))}</button>
      `;
    }

    return `
      <button type="button"
              class="btn-row-mini preview-nft-btn"
              data-action="preview-nft"
              data-cert-uid="${esc(uid)}">${esc(t('previewNft'))}</button>
    `;
  }

  function buildRow(row, bucket) {
    const uid = itemCertUid(row);
    const code = itemRwaCode(row);
    const paymentStatus = itemPaymentStatus(row);
    const paymentVerified = itemPaymentVerified(row);
    const artifactReady = itemArtifactReady(row);
    const nftHealthy = itemNftHealthy(row);
    const queueBucket = itemQueueBucket(row, bucket);
    const paymentRef = String(row?.payment_ref || '').trim();
    const paymentText = String(row?.payment_text || '').trim();

    const confirmedChip = (paymentVerified || paymentStatus === 'confirmed')
      ? '<span class="cert-chip is-ok">' + esc(t('confirmed')) + '</span>'
      : '<span class="cert-chip is-warn">' + esc(t('pending')) + '</span>';

    const artifactChip = artifactReady
      ? '<span class="cert-chip is-ok">' + esc(t('artifactReady')) + '</span>'
      : '<span class="cert-chip is-warn">' + esc(t('artifactPending')) + '</span>';

    const nftChip = nftHealthy
      ? '<span class="cert-chip is-ok">' + esc(t('nftHealthy')) + '</span>'
      : '<span class="cert-chip is-warn">' + esc(t('nftPending')) + '</span>';

    const refChip = paymentRef !== ''
      ? '<span class="cert-chip is-gold">' + esc('REF ' + paymentRef) + '</span>'
      : '';

    const payLine = paymentText !== ''
      ? '<div class="queue-pay cert-row-sub">' + esc(paymentText) + '</div>'
      : '';

    return `
      <div class="mint-row queue-row"
           data-cert-uid="${esc(uid)}"
           data-queue-bucket="${esc(queueBucket)}"
           data-payment-status="${esc(paymentStatus)}"
           data-payment-verified="${esc(String(paymentVerified ? 1 : 0))}">
        <div class="cert-row-main">
          <div class="queue-code cert-row-title">${esc(code)}</div>
          <div class="queue-uid cert-row-sub mono">${esc(uid)}</div>
          ${payLine}
          <div class="cert-row-meta">
            ${confirmedChip}
            ${artifactChip}
            ${nftChip}
            ${refChip}
          </div>
        </div>
        <div class="mint-queue-actions cert-row-actions">
          ${buildActionButtons(row, queueBucket)}
        </div>
      </div>
    `;
  }

  function renderInto(wrapId, emptyId, rows, fallbackBucket, emptyText) {
    const wrap = $(wrapId);
    const empty = $(emptyId);
    if (!wrap) return;

    const list = Array.isArray(rows) ? rows.filter((r) => r && itemCertUid(r)) : [];
    wrap.innerHTML = list.map((r) => buildRow(r, fallbackBucket)).join('');

    if (empty) {
      empty.style.display = list.length ? 'none' : '';
      empty.textContent = emptyText;
    }

    bindActions();
    refreshSelectionHighlight();
  }

  function renderPaymentConfirmation(rows) {
    renderInto(
      'paymentConfirmationQueueList',
      'paymentConfirmationQueueEmpty',
      rows,
      'payment_confirmation',
      t('noPaymentConfirmation')
    );
  }

  function renderPendingArtifact(rows) {
    renderInto(
      'paymentConfirmedPendingArtifactList',
      'paymentConfirmedPendingArtifactEmpty',
      rows,
      'payment_confirmed_pending_artifact',
      t('noPendingArtifact')
    );
  }

  function renderMintReady(rows) {
    renderInto(
      'cert-list-mint-ready',
      'mintReadyEmpty',
      rows,
      'mint_ready_queue',
      t('noMintReady')
    );
  }

  function renderExistingPanels(buckets) {
    renderInto(
      'cert-list-issuance-factory',
      'cert-empty-issuance-factory',
      buckets.issuance_factory || [],
      'issuance_factory',
      t('noIssuance')
    );

    renderInto(
      'cert-list-minting-process',
      'cert-empty-minting-process',
      buckets.minting_process || [],
      'minting_process',
      t('noMinting')
    );

    renderInto(
      'cert-list-issued',
      'cert-empty-issued',
      buckets.issued || [],
      'issued',
      t('noIssued')
    );

    renderInto(
      'cert-list-blocked',
      'cert-empty-blocked',
      buckets.blocked || [],
      'blocked',
      t('noBlocked')
    );

    const countMap = {
      'cert-panel-count-issuance-factory': (buckets.issuance_factory || []).length,
      'cert-panel-count-mint-ready': (buckets.mint_ready_queue || []).length,
      'cert-panel-count-minting-process': (buckets.minting_process || []).length,
      'cert-panel-count-issued': (buckets.issued || []).length,
      'cert-panel-count-blocked': (buckets.blocked || []).length,
      'paymentConfirmationQueueCount': (buckets.payment_confirmation || []).length,
      'paymentConfirmedPendingArtifactCount': (buckets.payment_confirmed_pending_artifact || []).length
    };

    Object.keys(countMap).forEach((id) => {
      const el = $(id);
      if (el) el.textContent = String(countMap[id]);
    });
  }

  function preview(uid) {
    uid = String(uid || '').trim();
    if (!uid) return;
    window.open('/rwa/cert/verify.php?uid=' + encodeURIComponent(uid), '_blank');
  }

  function setSelectedRow(row, bucket) {
    const uid = itemCertUid(row);
    if (!uid) return;

    state.selectedCertUid = uid;
    state.selectedRow = row || null;
    state.currentBucket = String(bucket || itemQueueBucket(row, '') || '').trim();

    window.__CERT_SELECTED_UID = uid;
    window.__CERT_SELECTED_ROW = row || {};
  }

  function refreshSelectionHighlight() {
    const uid = String(state.selectedCertUid || window.__CERT_SELECTED_UID || '').trim();
    if (!uid) return;

    document.querySelectorAll('.mint-row[data-cert-uid]').forEach((el) => {
      el.classList.remove('is-selected-mint', 'is-focused');
    });

    document.querySelectorAll(`.mint-row[data-cert-uid="${CSS.escape(uid)}"]`).forEach((el) => {
      el.classList.add('is-selected-mint', 'is-focused');
    });
  }

  function fillSelectedContext(row, bucket) {
    const uid = itemCertUid(row);
    const queueBucket = String(bucket || itemQueueBucket(row, '')).trim();

    const certUidEl = $('cert-selected-cert-uid');
    const certCodeEl = $('cert-selected-cert-code');
    const bucketEl = $('cert-selected-cert-bucket');
    const stageEl = $('cert-selected-cert-stage');

    if (certUidEl) certUidEl.textContent = uid || '';
    if (certCodeEl) certCodeEl.textContent = itemRwaCode(row) || '';
    if (bucketEl) bucketEl.textContent = queueBucket || '';
    if (stageEl) stageEl.textContent = queueLabel(queueBucket);

    const stageContext = $('cert-stage-context-root');
    if (stageContext && uid) {
      stageContext.textContent = itemRwaCode(row) + ' · ' + uid + ' · ' + queueLabel(queueBucket);
    }
  }

  function applySelectedRowToVisibleUi(row, bucket) {
    const uid = itemCertUid(row);
    if (!uid) return;

    const queueBucket = String(bucket || itemQueueBucket(row, '')).trim();
    const mint = row?.mint && typeof row.mint === 'object' ? row.mint : {};

    setSelectedRow(row, queueBucket);
    fillSelectedContext(row, queueBucket);

    const activeName = $('activeName');
    const activeCode = $('activeCode');
    const activeSub = $('activeSub');
    const activeCertUid = $('activeCertUid');
    const activePaymentText = $('activePaymentText');
    const activePaymentRef = $('activePaymentRef');
    const activeNftItem = $('activeNftItem');
    const activeStatusText = $('activeStatusText');
    const nextStepBanner = $('nextStepBanner');

    if (activeName) activeName.textContent = itemRwaCode(row) || 'RWA';
    if (activeCode) activeCode.textContent = uid;
    if (activeSub) activeSub.textContent = queueLabel(queueBucket);
    if (activeCertUid) activeCertUid.textContent = uid;
    if (activePaymentText) activePaymentText.textContent = String(row?.payment_text || '—');
    if (activePaymentRef) activePaymentRef.textContent = String(row?.payment_ref || '—');
    if (activeNftItem) activeNftItem.textContent = String(row?.nft_item_address || '—');
    if (activeStatusText) activeStatusText.textContent = queueLabel(queueBucket);
    if (nextStepBanner) {
      nextStepBanner.textContent = row?.ui?.next_banner
        ? String(row.ui.next_banner)
        : queueLabel(queueBucket);
    }

    const certMintTitle = $('certMintTitle');
    const activeMintCertUid = $('activeMintCertUid');
    const certMintRecipient = $('certMintRecipient');
    const certMintAmount = $('certMintAmount');
    const certMintAmountNano = $('certMintAmountNano');
    const certMintItemIndex = $('certMintItemIndex');
    const certMintStatusText = $('certMintStatusText');
    const certMintPayloadMini = $('certMintPayloadMini');
    const certMintDeeplink = $('certMintDeeplink');
    const certMintQrMeta = $('certMintQrMeta');
    const certMintGetgemsBtn = $('certMintGetgemsBtn');

    if (certMintTitle) certMintTitle.textContent = uid;
    if (activeMintCertUid) activeMintCertUid.textContent = uid;
    if (certMintRecipient) certMintRecipient.textContent = String(mint.recipient || '—');
    if (certMintAmount) certMintAmount.textContent = String(mint.amount_ton || '—');
    if (certMintAmountNano) {
      certMintAmountNano.textContent = String(
        row?.payment_amount_units ||
        row?.unit_of_responsibility ||
        row?.payment_amount ||
        mint.amount_nano ||
        '—'
      );
    }

    if (certMintItemIndex) {
      const current = String(certMintItemIndex.textContent || '').trim();
      const nextMemo = String(
        row?.payment_ref
          ? ('REF: ' + row.payment_ref)
          : (mint.item_index || current || '—')
      ).trim();
      certMintItemIndex.textContent = nextMemo !== '' ? nextMemo : '—';
    }

    if (certMintStatusText) {
      certMintStatusText.textContent = String(row?.mint_status || queueLabel(queueBucket));
    }

    if (certMintPayloadMini) {
      certMintPayloadMini.textContent = String(mint.payload_b64 || '—');
    }

    if (certMintDeeplink) {
      certMintDeeplink.textContent = String(row?.deeplink || mint.deeplink || mint.wallet_link || '—');
    }

    if (certMintQrMeta) {
      certMintQrMeta.textContent = queueBucket === 'mint_ready_queue'
        ? 'Mint ready. Finalize Mint will hand off to NFT Factory.'
        : queueLabel(queueBucket);
    }

    if (certMintGetgemsBtn) {
      const href = String(row?.getgems_url || row?.verify_url || '#').trim() || '#';
      certMintGetgemsBtn.href = href;
    }

    refreshSelectionHighlight();
  }

  function selectRow(row, bucket) {
    if (!itemCertUid(row)) return;
    applySelectedRowToVisibleUi(row, bucket);
  }

  function handoffToNftFactory(uid) {
    uid = String(uid || '').trim();
    if (!uid) return;

    const row = findRowByUid(uid);
    selectRow(row, 'mint_ready_queue');

    document.dispatchEvent(new CustomEvent('cert:nft-focus', {
      detail: { cert_uid: uid, row: row, queue_bucket: 'mint_ready_queue' }
    }));

    const factory = $('nftFactorySection');
    if (factory) {
      factory.classList.add('is-selected-cert', 'is-focused');
      factory.scrollIntoView({ behavior: 'smooth', block: 'start' });
      setTimeout(() => factory.classList.remove('is-focused'), 1600);
    }

    log('handoff → NFT Factory', uid);
  }

  function pausePolling(ms) {
    const waitMs = Number(ms || 0);
    if (waitMs > 0) {
      state.pollingPausedUntil = Date.now() + waitMs;
    }
  }

  function triggerExistingPaymentVerify() {
    const verifyBtn = $('issuePayVerifyBtn');
    if (verifyBtn) {
      verifyBtn.click();
      return true;
    }
    return false;
  }

  function dispatchPaymentReconfirm(uid, row) {
    document.dispatchEvent(new CustomEvent('cert:payment-reconfirm', {
      detail: { cert_uid: uid, row: row, queue_bucket: 'payment_confirmation' }
    }));
  }

  function requestQueueRefreshSoon(delayMs) {
    const wait = Number(delayMs || 0);
    window.setTimeout(() => {
      refresh().catch(warn);
    }, wait);
  }

  function reconfirmPayment(uid) {
    uid = String(uid || '').trim();
    if (!uid) return;

    const row = findRowByUid(uid);
    selectRow(row, 'payment_confirmation');

    dispatchPaymentReconfirm(uid, row);

    const clicked = triggerExistingPaymentVerify();
    if (!clicked) {
      warn('payment reconfirm requested but issuePayVerifyBtn not found');
    }

    pausePolling(1500);
    requestQueueRefreshSoon(1800);
    requestQueueRefreshSoon(4200);

    log('handoff → Payment Reconfirm', uid);
  }

  function bindActions() {
    document.querySelectorAll('[data-action="preview-nft"]').forEach((btn) => {
      if (btn.dataset.boundPreview === '1') return;
      btn.dataset.boundPreview = '1';
      btn.addEventListener('click', () => {
        preview(btn.getAttribute('data-cert-uid') || '');
      });
    });

    document.querySelectorAll('[data-action="finalize-mint"]').forEach((btn) => {
      if (btn.dataset.boundFinalize === '1') return;
      btn.dataset.boundFinalize = '1';
      btn.addEventListener('click', () => {
        handoffToNftFactory(btn.getAttribute('data-cert-uid') || '');
      });
    });

    document.querySelectorAll('[data-action="reconfirm-payment"]').forEach((btn) => {
      if (btn.dataset.boundReconfirm === '1') return;
      btn.dataset.boundReconfirm = '1';
      btn.addEventListener('click', () => {
        reconfirmPayment(btn.getAttribute('data-cert-uid') || '');
      });
    });

    document.querySelectorAll('.mint-row[data-cert-uid]').forEach((rowEl) => {
      if (rowEl.dataset.boundSelect === '1') return;
      rowEl.dataset.boundSelect = '1';
      rowEl.addEventListener('click', (ev) => {
        const actionEl = ev.target && ev.target.closest('[data-action]');
        if (actionEl) return;

        const uid = String(rowEl.getAttribute('data-cert-uid') || '').trim();
        const bucket = String(rowEl.getAttribute('data-queue-bucket') || '').trim();
        if (!uid) return;

        const row = findRowByUid(uid);
        selectRow(row, bucket);
      });
    });
  }

  function syncSelectedFromGlobals() {
    const uid = String(window.__CERT_SELECTED_UID || state.selectedCertUid || '').trim();
    if (!uid) return;

    const row = findRowByUid(uid);
    if (itemCertUid(row)) {
      state.selectedCertUid = uid;
      state.selectedRow = row;
      state.currentBucket = itemQueueBucket(row, state.currentBucket);
      applySelectedRowToVisibleUi(row, state.currentBucket);
    }
  }

  function autoSelectFirstVisible(buckets) {
    const currentUid = String(state.selectedCertUid || window.__CERT_SELECTED_UID || '').trim();
    if (currentUid) return;

    const priority = []
      .concat(buckets.payment_confirmation || [])
      .concat(buckets.payment_confirmed_pending_artifact || [])
      .concat(buckets.mint_ready_queue || [])
      .concat(buckets.issuance_factory || [])
      .concat(buckets.minting_process || [])
      .concat(buckets.issued || [])
      .concat(buckets.blocked || []);

    const first = priority.find((r) => itemCertUid(r));
    if (!first) return;

    selectRow(first, itemQueueBucket(first, 'issuance_factory'));
  }

  async function refresh() {
    if (Date.now() < state.pollingPausedUntil) return;

    try {
      const json = await getJson(queueUrl());
      state.queueJson = json;

      const buckets = normalizeBuckets(json);

      renderPaymentConfirmation(buckets.payment_confirmation || []);
      renderPendingArtifact(buckets.payment_confirmed_pending_artifact || []);
      renderMintReady(buckets.mint_ready_queue || []);
      renderExistingPanels(buckets);

      autoSelectFirstVisible(buckets);
      syncSelectedFromGlobals();
    } catch (e) {
      err('queue refresh failed', e);
    }
  }

  function bindRouterEvents() {
    document.addEventListener('cert:lang-changed', () => {
      if (state.queueJson) {
        const buckets = normalizeBuckets(state.queueJson);
        renderPaymentConfirmation(buckets.payment_confirmation || []);
        renderPendingArtifact(buckets.payment_confirmed_pending_artifact || []);
        renderMintReady(buckets.mint_ready_queue || []);
        renderExistingPanels(buckets);
        syncSelectedFromGlobals();
      }
    });

    document.addEventListener('cert:queue-refresh', () => {
      refresh().catch(warn);
    });

    document.addEventListener('cert:mint-ready-refresh', () => {
      refresh().catch(warn);
    });

    document.addEventListener('cert:nft-focus-applied', (ev) => {
      const uid = String(ev?.detail?.cert_uid || '').trim();
      if (!uid) return;
      const row = findRowByUid(uid);
      selectRow(row, 'mint_ready_queue');
    });

    document.addEventListener('cert:payment-confirmed', () => {
      refresh().catch(warn);
    });

    document.addEventListener('cert:artifacts-ready', () => {
      refresh().catch(warn);
    });

    document.addEventListener('cert:payment-reconfirm-done', () => {
      requestQueueRefreshSoon(300);
      requestQueueRefreshSoon(1500);
    });
  }

  function boot() {
    if (state.booted) return;
    state.booted = true;

    bindActions();
    bindRouterEvents();
    refresh().catch(warn);

    if (state.summaryTimer) clearInterval(state.summaryTimer);
    state.summaryTimer = window.setInterval(() => {
      refresh().catch(warn);
    }, state.summaryIntervalMs);

    log('router v10.1 locked queue ready');
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})();
