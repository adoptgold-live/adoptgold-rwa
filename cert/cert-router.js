/**
 * /var/www/html/public/rwa/cert/cert-router.js
 * Version: v9.3.1-20260409-handoff-only-finalize-router-fix
 *
 * LOCK
 * - Queue / continuity helper only
 * - Must NOT own Check & Preview
 * - Must NOT own Issue & Pay
 * - Must NOT amend verify.php
 * - Must NOT amend local QR flow
 * - Backend truth remains API-driven
 *
 * OWNERSHIP
 * - queue-summary polling
 * - mint-ready queue render
 * - continuity highlight
 * - finalize handoff ONLY → NFT Factory
 *
 * FINALIZE RULE (CRITICAL)
 * - MUST NOT call finalize.php
 * - MUST NOT call mint-init.php
 * - MUST ONLY:
 *   ✔ set selected cert uid
 *   ✔ dispatch cert:nft-focus
 *   ✔ scroll to NFT Factory
 */

(function () {
  'use strict';

  if (window.CERT_ROUTER_V93_ACTIVE) return;
  window.CERT_ROUTER_V93_ACTIVE = true;

  const $ = (id) => document.getElementById(id);

  const state = {
    booted: false,
    currentBucket: 'issuance_factory',
    selectedCertUid: '',
    summaryTimer: null,
    summaryIntervalMs: 12000,
    queueJson: null,
    pollingPausedUntil: 0
  };

  function log(...a){ console.log('[CERT_ROUTER]', ...a); }
  function warn(...a){ console.warn('[CERT_ROUTER]', ...a); }
  function err(...a){ console.error('[CERT_ROUTER]', ...a); }

  function esc(v){
    return String(v ?? '')
      .replace(/&/g,'&amp;')
      .replace(/</g,'&lt;')
      .replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;')
      .replace(/'/g,'&#039;');
  }

  async function getJson(url){
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

  function queueUrl(){
    const url = new URL('/rwa/cert/api/queue-summary.php', window.location.origin);
    url.searchParams.set('_ts', String(Date.now()));
    return url.toString();
  }

  function normalizeBuckets(json){
    const b = json?.buckets || {};
    window.__CERT_QUEUE_SUMMARY__ = json || {};
    return {
      issuance_factory: b.issuance_factory || [],
      mint_ready_queue: b.mint_ready_queue || [],
      minting_process: b.minting_process || [],
      issued: b.issued || [],
      blocked: b.blocked || []
    };
  }

  function itemCertUid(row){
    return String(row?.cert_uid || row?.uid || '').trim();
  }

  function itemRwaCode(row){
    return String(row?.rwa_code || row?.rwa_type || 'RWA').trim();
  }

  function buildRow(row, bucket){
    const uid = itemCertUid(row);
    const code = itemRwaCode(row);
    const paymentStatus = String(row?.payment_status || '').trim().toLowerCase();
    const paymentVerified = Number(row?.payment_verified || 0) === 1;
    const artifactReady = row?.artifact_ready === true;
    const nftHealthy = row?.nft_healthy === true;
    const queueBucket = String(row?.queue_bucket || bucket || '').trim();

    const confirmedChip = (paymentVerified || paymentStatus === 'confirmed')
      ? '<span class="cert-chip is-ok">CONFIRMED</span>'
      : '<span class="cert-chip is-warn">PENDING</span>';

    const artifactChip = artifactReady
      ? '<span class="cert-chip is-ok">ARTIFACT READY</span>'
      : '<span class="cert-chip is-warn">ARTIFACT PENDING</span>';

    const nftChip = nftHealthy
      ? '<span class="cert-chip is-ok">NFT HEALTHY</span>'
      : '<span class="cert-chip is-warn">NFT PENDING</span>';

    return `
      <div class="mint-row queue-row"
           data-cert-uid="${esc(uid)}"
           data-queue-bucket="${esc(queueBucket)}"
           data-payment-status="${esc(paymentStatus)}"
           data-payment-verified="${esc(String(paymentVerified ? 1 : 0))}">
        <div class="cert-row-main">
          <div class="queue-code cert-row-title">${esc(code)}</div>
          <div class="queue-uid cert-row-sub mono">${esc(uid)}</div>
          <div class="cert-row-meta">
            ${confirmedChip}
            ${artifactChip}
            ${nftChip}
          </div>
        </div>
        <div class="mint-queue-actions cert-row-actions">
          <button type="button"
                  class="btn-row-mini preview-nft-btn"
                  data-action="preview-nft"
                  data-cert-uid="${esc(uid)}">Preview NFT</button>
          <button type="button"
                  class="btn-row-mini gold finalize-mint-btn"
                  data-action="finalize-mint"
                  data-cert-uid="${esc(uid)}">Finalize Mint</button>
        </div>
      </div>
    `;
  }

  function renderMintReady(rows){
    const wrap = $('cert-list-mint-ready');
    const empty = $('mintReadyEmpty');
    if (!wrap) return;

    const list = Array.isArray(rows) ? rows.filter(r => r && itemCertUid(r)) : [];
    wrap.innerHTML = list.map(r => buildRow(r, 'mint_ready_queue')).join('');

    if (empty) {
      empty.style.display = list.length ? 'none' : '';
    }

    bindActions();
  }

  function preview(uid){
    uid = String(uid || '').trim();
    if (!uid) return;
    window.open('/rwa/cert/verify.php?uid=' + encodeURIComponent(uid), '_blank');
  }

  function handoffToNftFactory(uid){
    uid = String(uid || '').trim();
    if (!uid) return;

    window.__CERT_SELECTED_UID = uid;
    state.selectedCertUid = uid;

    let row = {};
    try {
      const rows = (window.__CERT_QUEUE_SUMMARY__ && window.__CERT_QUEUE_SUMMARY__.buckets && window.__CERT_QUEUE_SUMMARY__.buckets.mint_ready_queue) || [];
      row = rows.find(r => String(r?.cert_uid || '').trim() === uid) || {};
    } catch (_) {
      row = {};
    }
    window.__CERT_SELECTED_ROW = row;

    document.querySelectorAll('.mint-row[data-cert-uid]').forEach(el => {
      el.classList.remove('is-selected-mint', 'is-focused');
    });

    document.querySelectorAll(`.mint-row[data-cert-uid="${CSS.escape(uid)}"]`).forEach(el => {
      el.classList.add('is-selected-mint', 'is-focused');
    });

    [
      'certMintTitle',
      'activeMintCertUid',
      'activeCertUid',
      'certMintCurrentUid',
      'certMintUid',
      'nftFactoryCertUid'
    ].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.textContent = uid;
    });

    const memoEl = document.getElementById('certMintItemIndex');
    if (memoEl && (!memoEl.textContent || memoEl.textContent.trim() === '—')) {
      memoEl.textContent = 'REF: ' + uid;
    }

    document.dispatchEvent(new CustomEvent('cert:nft-focus', {
      detail: { cert_uid: uid, row: row, queue_bucket: 'mint_ready_queue' }
    }));

    const factory = document.getElementById('nftFactorySection');
    if (factory) {
      factory.classList.add('is-selected-cert', 'is-focused');
      factory.scrollIntoView({ behavior: 'smooth', block: 'start' });
      setTimeout(() => factory.classList.remove('is-focused'), 1600);
    }

    log('handoff → NFT Factory', uid);
  }

  function bindActions(){
    document.querySelectorAll('[data-action="preview-nft"]').forEach(btn => {
      if (btn.dataset.boundPreview === '1') return;
      btn.dataset.boundPreview = '1';
      btn.addEventListener('click', () => {
        preview(btn.getAttribute('data-cert-uid') || '');
      });
    });

    document.querySelectorAll('[data-action="finalize-mint"]').forEach(btn => {
      if (btn.dataset.boundFinalize === '1') return;
      btn.dataset.boundFinalize = '1';
      btn.addEventListener('click', () => {
        handoffToNftFactory(btn.getAttribute('data-cert-uid') || '');
      });
    });
  }

  async function refresh(){
    if (Date.now() < state.pollingPausedUntil) return;

    try {
      const json = await getJson(queueUrl());
      state.queueJson = json;

      const buckets = normalizeBuckets(json);
      renderMintReady(buckets.mint_ready_queue || []);
    } catch (e) {
      err('queue refresh failed', e);
    }
  }

  function boot(){
    if (state.booted) return;
    state.booted = true;

    bindActions();
    refresh().catch(warn);

    if (state.summaryTimer) clearInterval(state.summaryTimer);
    state.summaryTimer = window.setInterval(() => {
      refresh().catch(warn);
    }, state.summaryIntervalMs);

    log('router v9.3 handoff-only ready');
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})();
