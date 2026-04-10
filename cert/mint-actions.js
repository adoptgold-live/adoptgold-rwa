/**
 * /var/www/html/public/rwa/cert/mint-actions.js
 * Version: v4.0.0-20260409-clean-final
 *
 * CLEAN FINAL
 * - consumes mint-init.php handoff only
 * - hydrates NFT Factory from mint-init response
 * - opens wallet once
 * - polls mint-verify.php until issued
 * - switches Verify -> Getgems when nft_item_address appears
 * - queue move/highlight works when .cert-queue-row[data-cert-uid] exists
 */

(function () {
  'use strict';

  if (window.__CERT_MINT_ACTIONS_CLEAN_FINAL__) return;
  window.__CERT_MINT_ACTIONS_CLEAN_FINAL__ = true;

  const $ = (id) => document.getElementById(id);

  const IDS = {
    certUid: ['nftFactoryCertUid'],
    recipient: ['nftFactoryRecipient'],
    collection: ['nftFactoryCollection'],
    ownerWallet: ['nftFactoryOwnerWallet'],
    amountTon: ['nftFactoryAmountTon'],
    amountNano: ['nftFactoryAmountNano'],
    itemIndex: ['nftFactoryItemIndex'],
    queryId: ['nftFactoryQueryId'],
    payload: ['nftFactoryPayload'],
    verifyUrl: ['nftFactoryVerifyUrl'],
    verifyJson: ['nftFactoryVerifyJson'],
    imagePath: ['nftFactoryImagePath'],
    metadataPath: ['nftFactoryMetadataPath', 'nftFactoryMetadataPathMirror'],
    walletLinkText: ['nftFactoryWalletLink'],
    tonconnectJson: ['nftFactoryTonconnectJson'],
    mintStatus: ['nftFactoryStatusText'],
    settlementStatus: ['settlementStatusText'],
    banner: ['nftFactoryBanner', 'nextStepBanner'],
    previewImages: ['nftFactoryPreviewImage'],
    walletButtons: ['nftFactoryWalletBtn'],
    error: ['nftFactoryError']
  };

  const state = {
    activeCertUid: '',
    finalizeResponse: null,
    mintVerifyTimer: null,
    mintVerifyStartedAt: 0,
    walletOpenedForUid: '',
    walletReturnedForUid: '',
    pollIntervalMs: 5000,
    pollTimeoutMs: 480000,
    countdownTimer: null,
    getgemsItemUrl: '',
    busy: false
  };

  function firstEl(ids) {
    for (const id of ids) {
      const el = $(id);
      if (el) return el;
    }
    return null;
  }

  function setText(ids, value) {
    const val = value == null || value === '' ? '—' : String(value);
    ids.forEach((id) => {
      const el = $(id);
      if (!el) return;
      if ('value' in el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA')) {
        el.value = val;
      } else {
        el.textContent = val;
      }
    });
  }

  function setRaw(ids, value) {
    const val = value == null ? '' : String(value);
    ids.forEach((id) => {
      const el = $(id);
      if (!el) return;
      if ('value' in el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA')) {
        el.value = val;
      } else {
        el.textContent = val || '—';
      }
    });
  }

  function setHtml(ids, value) {
    ids.forEach((id) => {
      const el = $(id);
      if (el) el.innerHTML = String(value || '');
    });
  }

  function setError(msg) {
    const text = String(msg || '').trim();
    setText(IDS.error, text);
    if (text) {
      setText(IDS.mintStatus, 'ERROR');
      setText(IDS.settlementStatus, text);
    }
  }

  function clearError() {
    setText(IDS.error, '');
  }

  async function getJson(url) {
    const res = await fetch(url, {
      method: 'GET',
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      }
    });

    const text = await res.text();
    let json = null;
    try { json = JSON.parse(text); } catch (_) {}

    if (!res.ok) {
      throw new Error((json && (json.error || json.detail || json.message)) || text || ('HTTP_' + res.status));
    }
    if (!json) throw new Error('INVALID_JSON_RESPONSE');
    if (json.ok === false) {
      throw new Error(json.error || json.detail || json.message || 'REQUEST_FAILED');
    }
    return json;
  }

  function finalizeUrl(certUid) {
    return `/rwa/cert/api/mint-init.php?cert_uid=${encodeURIComponent(certUid)}`;
  }

  function mintVerifyUrl(certUid, override) {
    if (override && String(override).trim()) return String(override).trim();
    return `/rwa/cert/api/mint-verify.php?cert_uid=${encodeURIComponent(certUid)}`;
  }

  function setWalletLink(link) {
    const safe = String(link || '').trim();
    IDS.walletButtons.forEach((id) => {
      const el = $(id);
      if (!el) return;
      if ('href' in el) el.href = safe || '#';
      el.setAttribute('data-wallet-link', safe);
      if (safe) {
        el.removeAttribute('aria-disabled');
        el.style.pointerEvents = '';
        el.style.opacity = '';
      } else {
        el.setAttribute('aria-disabled', 'true');
        el.style.pointerEvents = 'none';
        el.style.opacity = '0.6';
      }
    });
    setRaw(IDS.walletLinkText, safe);
  }

  function setPreviewImage(url) {
    const safe = String(url || '').trim();
    IDS.previewImages.forEach((id) => {
      const el = $(id);
      if (!el) return;
      if (safe) {
        el.src = safe;
        el.style.display = '';
      } else {
        el.removeAttribute('src');
      }
    });
  }

  function updateStatusTexts(main, settlement, banner) {
    if (main != null) setText(IDS.mintStatus, main);
    if (settlement != null) setText(IDS.settlementStatus, settlement);
    if (banner != null) setText(IDS.banner, banner);
  }

  function setPrepareCtaActive(isActive) {
    const btn = $('certMintPrepareBtn');
    if (!btn) return;
    btn.classList.toggle('is-glow', !!isActive);
    btn.classList.toggle('is-pulse', !!isActive);
    btn.classList.toggle('prepare-cta-ready', !!isActive);
    btn.disabled = !isActive;
    btn.setAttribute('aria-disabled', isActive ? 'false' : 'true');
    if (isActive) {
      btn.setAttribute('data-cta-ready', '1');
      btn.removeAttribute('title');
    } else {
      btn.removeAttribute('data-cta-ready');
      btn.setAttribute('title', 'Finalize Mint first to prepare payload');
    }
  }

  function setRealtimeProgress(pct) {
    const el = $('mintRealtimeBarFill');
    if (!el) return;
    const n = Math.max(0, Math.min(100, Number(pct || 0)));
    el.style.width = `${n}%`;
  }

  function setLiveTxStatus(text) {
    const el = $('mintLiveTxStatus');
    if (el) el.textContent = String(text || '');
  }

  function flashIssuedSuccess() {
    const section = $('nftFactorySection');
    if (!section) return;
    section.classList.remove('issued-success-flash');
    void section.offsetWidth;
    section.classList.add('issued-success-flash');
    setTimeout(() => section.classList.remove('issued-success-flash'), 1800);
  }

  function setLastCheckedNow() {
    const el = $('mintLastChecked');
    if (el) el.textContent = `Last checked: ${new Date().toLocaleTimeString()}`;
  }

  function setPollHeartbeat(isLive) {
    const el = $('mintPollHeartbeat');
    if (!el) return;
    el.classList.toggle('is-live', !!isLive);
  }

  function stopCountdown() {
    if (state.countdownTimer) {
      clearInterval(state.countdownTimer);
      state.countdownTimer = null;
    }
  }

  function startCountdown(validUntil) {
    stopCountdown();
    const ts = Number(validUntil || 0);
    const el = $('mintCountdown');
    if (!el || !ts) return;

    const tick = () => {
      const left = ts - Math.floor(Date.now() / 1000);
      if (left <= 0) {
        el.textContent = 'Payload expired';
        el.className = 'countdown expired';
        stopCountdown();
        return;
      }
      const m = Math.floor(left / 60);
      const s = left % 60;
      el.textContent = `Payload expires in ${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
      if (left <= 20) el.className = 'countdown danger';
      else if (left <= 60) el.className = 'countdown warn';
      else el.className = 'countdown ok';
    };

    tick();
    state.countdownTimer = setInterval(tick, 1000);
  }

  function setStepState(step) {
    const el = $('mintStepLadder');
    if (!el) return;
    const labels = [
      'Waiting',
      'Payload prepared',
      'Wallet opened',
      'Wallet returned',
      'Chain verifying',
      'NFT issued'
    ];
    const current = Math.max(0, Math.min(5, Number(step || 0)));
    el.innerHTML = [1, 2, 3, 4, 5].map((n) => {
      const cls = n < current ? 'done' : (n === current ? 'active' : 'idle');
      return `<div class="mint-step ${cls}"><span class="num">${n}</span><span class="txt">${labels[n]}</span></div>`;
    }).join('');
  }

  function buildGetgemsItemUrl(itemAddress) {
    const addr = String(itemAddress || '').trim();
    if (!addr) return '';
    return `https://getgems.io/ton/${encodeURIComponent(addr)}`;
  }

  function setSmartVerifyButton(label, href) {
    const btn = $('verifyAtGetgemsBtn') || $('verifyCertBtn') || $('mintVerifyActionBtn');
    if (!btn) return;

    const safeHref = String(href || '').trim();
    btn.textContent = label || 'Verify Certificate';
    btn.setAttribute('data-href', safeHref);
    if ('href' in btn) btn.href = safeHref || '#';
    btn.onclick = (ev) => {
      ev.preventDefault();
      if (!safeHref) return;
      window.open(safeHref, '_blank', 'noopener');
    };
  }

  function setGetgemsLinkFromItem(itemAddress, certUid) {
    const addr = String(itemAddress || '').trim();
    const uid = String(certUid || state.activeCertUid || '').trim();
    const itemEl = $('activeNftItem');
    if (itemEl) itemEl.textContent = addr || '—';

    if (addr) {
      const href = buildGetgemsItemUrl(addr);
      state.getgemsItemUrl = href;
      setSmartVerifyButton('View on Getgems', href);
      return;
    }

    setSmartVerifyButton('Verify Certificate', `/rwa/cert/verify.php?uid=${encodeURIComponent(uid)}`);
  }

  function renderConfidenceBadges(res) {
    const badges = [];
    if (Number(res?.payment?.verified || 0) === 1) badges.push('<span class="mint-badge ok">Payment Confirmed</span>');
    if (String(res?.getgems_metadata?.mode || '') === 'canonical_metadata_only') badges.push('<span class="mint-badge ok">Canonical Metadata</span>');
    if (res?.artifact_health?.ok === true) badges.push('<span class="mint-badge ok">Verify JSON Healthy</span>');
    setHtml(['mintConfidenceBadges', 'nftConfidenceBadges'], badges.join(' '));
  }

  async function copyText(value) {
    const text = String(value || '').trim();
    if (!text) return false;
    try {
      await navigator.clipboard.writeText(text);
      return true;
    } catch (_) {
      return false;
    }
  }

  function bindCopyButtons(res) {
    const map = [
      ['copyRecipientBtn', res?.recipient || res?.mint_request?.recipient || ''],
      ['copyAmountNanoBtn', res?.amount_nano || res?.mint_request?.amount_nano || ''],
      ['copyPayloadBtn', res?.payload_b64 || res?.mint_request?.payload_b64 || ''],
      ['copyMetadataBtn', res?.metadata_url || res?.mint_request?.metadata_url || ''],
      ['copyVerifyUrlBtn', res?.verify_url || res?.mint_request?.verify_url || ''],
    ];

    map.forEach(([id, value]) => {
      const btn = $(id);
      if (!btn || btn.dataset.boundCopy === '1') return;
      btn.dataset.boundCopy = '1';
      btn.addEventListener('click', async () => {
        const ok = await copyText(value);
        const old = btn.textContent;
        btn.textContent = ok ? 'Copied' : 'Copy failed';
        setTimeout(() => { btn.textContent = old || 'Copy'; }, 900);
      });
    });
  }

  function setStaticLinks(res, mint) {
    const metadataBtn = $('openMetadataBtn');
    const verifyBtn = $('openVerifyBtn');

    const metadataUrl = String(res?.metadata_url || mint?.metadata_url || '').trim();
    const verifyUrl = String(res?.verify_url || mint?.verify_url || '').trim();

    if (metadataBtn) metadataBtn.href = metadataUrl || '#';
    if (verifyBtn) verifyBtn.href = verifyUrl || '#';
  }

  function findQueueRow(certUid) {
    const uid = String(certUid || '').trim();
    if (!uid) return null;
    try {
      return document.querySelector(`.mint-row.queue-row[data-cert-uid="${CSS.escape(uid)}"]`);
    } catch (_) {
      return document.querySelector(`.mint-row.queue-row[data-cert-uid="${uid}"]`);
    }
  }

  function moveRowToBucket(certUid, bucket) {
    const uid = String(certUid || '').trim();
    const row = findQueueRow(uid);
    if (!row) return;

    const target =
      bucket === 'issued'
        ? $('cert-list-issued')
        : bucket === 'minting_process'
          ? ($('cert-list-minting-process') || $('cert-list-mint-ready'))
          : $('cert-list-mint-ready');

    if (!target) return;

    row.setAttribute('data-queue-bucket', bucket);
    row.classList.add('queue-moving');

    setTimeout(() => {
      target.prepend(row);
      row.classList.remove('queue-moving');
      row.classList.add('queue-moved');
      setTimeout(() => row.classList.remove('queue-moved'), 1200);
    }, 160);
  }

  function setRowState(certUid, stateName) {
    const row = findQueueRow(certUid);
    if (!row) return;
    row.classList.remove('is-in-progress', 'is-issued');
    if (stateName === 'minting') row.classList.add('is-in-progress');
    if (stateName === 'issued') row.classList.add('is-issued');
  }

  function showMintSuccessToast(certUid) {
    let toast = $('mintSuccessToast');
    if (!toast) {
      toast = document.createElement('div');
      toast.id = 'mintSuccessToast';
      toast.className = 'mint-success-toast';
      document.body.appendChild(toast);
    }
    toast.textContent = `Issued successfully: ${String(certUid || '').trim()}`;
    toast.classList.add('show');
    setTimeout(() => toast.classList.remove('show'), 2200);
  }

  function storeResponse(res) {
    state.finalizeResponse = res;
    state.activeCertUid = String(res?.cert_uid || '').trim();
    window.__CERT_FINALIZE_RESPONSE = res;
    window.__CERT_MINT_REQUEST = res?.mint_request || res || {};
    if (state.activeCertUid) window.__CERT_SELECTED_UID = state.activeCertUid;
  }

  function hydrateFactory(res) {
    const mint = res?.mint_request || res || {};
    const artifact = res?.artifact || {};
    const verify = res?.verify || {};
    const certUid = String(res?.cert_uid || '').trim();

    storeResponse(res);
    clearError();

    setText(IDS.certUid, certUid);
    setText(IDS.recipient, mint.recipient || res?.recipient || '');
    setText(IDS.collection, mint.collection_address || res?.collection_address || '');
    setText(IDS.ownerWallet, mint.owner_wallet || res?.ton_wallet || '');
    setText(IDS.amountTon, mint.amount_ton || res?.amount_ton || '');
    setText(IDS.amountNano, mint.amount_nano || res?.amount_nano || '');
    setText(IDS.itemIndex, mint.item_index ?? res?.item_index ?? '');
    setText(IDS.queryId, mint.query_id || res?.query_id || '');
    setRaw(IDS.payload, mint.payload_b64 || res?.payload_b64 || '');
    setText(IDS.verifyUrl, mint.verify_url || res?.verify_url || '');
    setText(IDS.verifyJson, mint.verify_json || artifact.verify_path || res?.artifact_health?.verify_json_path || '');
    setText(IDS.imagePath, mint.image_path || artifact.image_path || res?.artifact_health?.image_path || '');
    setText(IDS.metadataPath, mint.metadata_path || artifact.meta_path || res?.metadata_path || '');
    setRaw(IDS.tonconnectJson, res?.tonconnect ? JSON.stringify(res.tonconnect, null, 2) : '');

    setWalletLink(res?.wallet_link || res?.deeplink || mint.wallet_link || mint.deeplink || '');
    setPreviewImage(
      artifact.image_url ||
      mint.image_url ||
      verify.image_url ||
      res?.preview?.image_url ||
      res?.artifact_health?.metadata_image ||
      ''
    );

    renderConfidenceBadges(res);
    bindCopyButtons(res);
    setStaticLinks(res, mint);
    setGetgemsLinkFromItem('', certUid);

    setRealtimeProgress(20);
    setLiveTxStatus('Payload prepared');
    setStepState(1);
    startCountdown(res?.valid_until || mint?.valid_until || 0);

    updateStatusTexts(
      'PAYLOAD READY',
      'Wallet settlement waiting.',
      'Payload prepared. Review the fields below and confirm in TON wallet.'
    );

    const section = $('nftFactorySection');
    if (section) section.classList.add('is-selected-mint');

    document.dispatchEvent(new CustomEvent('cert:nft-factory-hydrated', {
      detail: { cert_uid: certUid, finalize: res, mint_request: mint }
    }));
  }

  function openWalletOnce(link, certUid) {
    const safe = String(link || '').trim();
    const uid = String(certUid || '').trim();
    if (!safe || !uid) return;
    if (state.walletOpenedForUid === uid) return;

    state.walletOpenedForUid = uid;
    setRealtimeProgress(40);
    setLiveTxStatus('Wallet opened. Please confirm the transaction.');
    setStepState(2);

    updateStatusTexts(
      'WALLET OPENED',
      'Waiting for wallet confirmation…',
      'TON wallet opened. Please sign the transaction.'
    );

    try {
      window.open(safe, '_blank', 'noopener');
    } catch (_) {}
  }

  function onWalletReturn() {
    if (!state.activeCertUid) return;
    if (state.walletReturnedForUid === state.activeCertUid) return;
    state.walletReturnedForUid = state.activeCertUid;

    setRealtimeProgress(60);
    setLiveTxStatus('Wallet returned. Checking chain status...');
    setStepState(3);

    updateStatusTexts(
      'VERIFYING',
      'Checking chain status…',
      'Wallet returned. Verifying on-chain result...'
    );
  }

  function stopMintVerifyPolling() {
    if (state.mintVerifyTimer) {
      clearInterval(state.mintVerifyTimer);
      state.mintVerifyTimer = null;
    }
    setPollHeartbeat(false);
  }

  async function runMintVerifyOnce(certUid, urlOverride) {
    const uid = String(certUid || '').trim();
    if (!uid) return;

    const url = mintVerifyUrl(uid, urlOverride);
    const json = await getJson(url);

    setRealtimeProgress(80);
    setLiveTxStatus('Checking blockchain confirmation...');
    setLastCheckedNow();
    setStepState(4);

    const minted = json?.minted === true || Number(json?.nft_minted || 0) === 1;
    const nftItemAddress = String(json?.nft_item_address || '').trim();
    if (nftItemAddress) setGetgemsLinkFromItem(nftItemAddress, uid);

    const mintedAt = String(json?.minted_at || '').trim();
    const queueBucket = String(json?.queue_bucket || '').trim();
    const status = String(json?.status || json?.mint_status || '').trim();

    if (minted && nftItemAddress && mintedAt && queueBucket === 'issued') {
      setRealtimeProgress(100);
      setLiveTxStatus('Mint confirmed. NFT issued.');
      setStepState(5);
      setPrepareCtaActive(false);
      setGetgemsLinkFromItem(nftItemAddress, uid);

      updateStatusTexts(
        'NFT ISSUED',
        'Issued successfully. NFT mint confirmed.',
        'Mint complete. You can now view the NFT on Getgems or open the certificate verify page.'
      );

      setRowState(uid, 'issued');
      moveRowToBucket(uid, 'issued');
      showMintSuccessToast(uid);
      flashIssuedSuccess();

      stopMintVerifyPolling();

      document.dispatchEvent(new CustomEvent('cert:mint-complete', {
        detail: {
          cert_uid: uid,
          queue_bucket: 'issued',
          mint_status: 'minted',
          nft_minted: 1,
          nft_item_address: nftItemAddress,
          minted_at: mintedAt,
          verify: json
        }
      }));

      document.dispatchEvent(new CustomEvent('cert:queue-summary-refresh-requested', {
        detail: { cert_uid: uid, queue_bucket: 'issued' }
      }));

      return;
    }

    const reason = String(json?.reason || '').trim();
    const detail = String(json?.detail || '').trim();

    updateStatusTexts(
      status ? status.toUpperCase() : 'CHAIN VERIFYING',
      'Checking on-chain confirmation…',
      detail || reason || 'Wallet signed. Waiting for blockchain confirmation.'
    );
  }

  function startMintVerifyPolling(certUid, urlOverride, intervalMs, timeoutMs) {
    const uid = String(certUid || '').trim();
    if (!uid) return;

    stopMintVerifyPolling();
    state.mintVerifyStartedAt = Date.now();
    state.pollIntervalMs = Number(intervalMs || 5000) > 0 ? Number(intervalMs) : 5000;
    state.pollTimeoutMs = Number(timeoutMs || 480000) > 0 ? Number(timeoutMs) : 480000;

    const tick = async () => {
      if (!state.activeCertUid || state.activeCertUid !== uid) return;
      if ((Date.now() - state.mintVerifyStartedAt) > state.pollTimeoutMs) {
        stopMintVerifyPolling();
        updateStatusTexts(
          'MINTING',
          'Wallet settlement waiting.',
          'Mint verify polling timeout reached. You can refresh status again after wallet signing.'
        );
        return;
      }

      try {
        await runMintVerifyOnce(uid, urlOverride);
      } catch (_) {}
    };

    setPollHeartbeat(true);
    tick();
    state.mintVerifyTimer = setInterval(tick, state.pollIntervalMs);
  }

  async function hydrateFromFinalize(certUid) {
    const uid = String(certUid || '').trim();
    if (!uid || state.busy) return;

    state.busy = true;
    clearError();

    try {
      const res = await getJson(finalizeUrl(uid));
      hydrateFactory(res);

      const verifyPoll = res?.verify_poll || {};
      const pollUrl = verifyPoll.mint_verify_url || `/rwa/cert/api/mint-verify.php?cert_uid=${encodeURIComponent(uid)}`;
      const pollIntervalMs = verifyPoll.poll_interval_ms || 5000;
      const pollTimeoutMs = verifyPoll.timeout_ms || 480000;

      setRealtimeProgress(20);
      setLiveTxStatus('Payload prepared. Review and press Prepare & Mint Now.');
      setStepState(1);

      updateStatusTexts(
        'PAYLOAD READY',
        'Wallet settlement waiting.',
        'Finalize Mint prepared the payload. Press Prepare & Mint Now to open TON wallet.'
      );
      setPrepareCtaActive(true);

      const prepareBtn = $('certMintPrepareBtn');
      if (prepareBtn) {
        prepareBtn.setAttribute('data-wallet-link', String(res?.wallet_link || res?.deeplink || ''));
        prepareBtn.setAttribute('data-cert-uid', uid);
        prepareBtn.setAttribute('data-poll-url', String(pollUrl || ''));
        prepareBtn.setAttribute('data-poll-interval-ms', String(pollIntervalMs || 5000));
        prepareBtn.setAttribute('data-poll-timeout-ms', String(pollTimeoutMs || 480000));
      }
    } catch (e) {
      setError(`Finalize mint preparation failed: ${String(e && e.message ? e.message : e || 'UNKNOWN_ERROR')}`);
      throw e;
    } finally {
      state.busy = false;
    }
  }

  function bindLegacyButtons() {
    const ids = ['finalizeMintBtn', 'prepareMintBtn', 'mintPrepareBtn', 'openWalletDirectBtn', 'nftFactoryPrepareBtn'];
    ids.forEach((id) => {
      const btn = $(id);
      if (!btn || btn.dataset.bound === '1') return;
      btn.dataset.bound = '1';
      btn.addEventListener('click', async () => {
        const uid = String(
          btn.getAttribute('data-cert-uid') ||
          state.activeCertUid ||
          firstEl(IDS.certUid)?.textContent ||
          ''
        ).trim();
        if (!uid || uid === '—') {
          setError('Missing active cert uid.');
          return;
        }
        try { await hydrateFromFinalize(uid); } catch (_) {}
      });
    });

    const prepareBtn = $('certMintPrepareBtn');
    if (prepareBtn && prepareBtn.dataset.walletBound !== '1') {
      prepareBtn.dataset.walletBound = '1';
      prepareBtn.addEventListener('click', async (ev) => {
        ev.preventDefault();

        if (prepareBtn.disabled || prepareBtn.getAttribute('data-cta-ready') !== '1') {
          setError('Finalize Mint first to prepare payload.');
          return;
        }

        const walletLink = String(prepareBtn.getAttribute('data-wallet-link') || '').trim();
        const uid = String(
          prepareBtn.getAttribute('data-cert-uid') ||
          state.activeCertUid ||
          firstEl(IDS.certUid)?.textContent ||
          ''
        ).trim();

        if (!uid || uid === '—') {
          setError('Missing active cert uid.');
          return;
        }

        if (!walletLink) {
          try {
            await hydrateFromFinalize(uid);
          } catch (_) {}
          return;
        }

        setPrepareCtaActive(false);

        const pollUrl = String(
          prepareBtn.getAttribute('data-poll-url') ||
          `/rwa/cert/api/mint-verify.php?cert_uid=${encodeURIComponent(uid)}`
        ).trim();

        const pollIntervalMs = Number(prepareBtn.getAttribute('data-poll-interval-ms') || 5000);
        const pollTimeoutMs = Number(prepareBtn.getAttribute('data-poll-timeout-ms') || 480000);

        openWalletOnce(walletLink, uid);

        setRowState(uid, 'minting');
        moveRowToBucket(uid, 'minting_process');

        document.dispatchEvent(new CustomEvent('cert:queue-summary-refresh-requested', {
          detail: { cert_uid: uid, queue_bucket: 'minting_process' }
        }));

        startMintVerifyPolling(uid, pollUrl, pollIntervalMs, pollTimeoutMs);
      });
    }
  }

  function bindEvents() {
    document.addEventListener('cert:nft-factory-handoff-ready', (ev) => {
      const detail = ev?.detail || {};
      const finalize = detail.finalize || {};
      const uid = String(detail.cert_uid || finalize?.cert_uid || '').trim();
      if (!uid) return;

      hydrateFactory(finalize);

      const pollUrl = String(
        finalize?.verify_poll?.mint_verify_url ||
        detail?.mint_verify_url ||
        `/rwa/cert/api/mint-verify.php?cert_uid=${encodeURIComponent(uid)}`
      ).trim();

      const pollIntervalMs = Number(finalize?.verify_poll?.poll_interval_ms || 5000);
      const pollTimeoutMs = Number(finalize?.verify_poll?.timeout_ms || 480000);

      const prepareBtn = $('certMintPrepareBtn');
      if (prepareBtn) {
        prepareBtn.setAttribute('data-wallet-link', String(finalize?.wallet_link || finalize?.deeplink || ''));
        prepareBtn.setAttribute('data-cert-uid', uid);
        prepareBtn.setAttribute('data-poll-url', String(pollUrl || ''));
        prepareBtn.setAttribute('data-poll-interval-ms', String(pollIntervalMs || 5000));
        prepareBtn.setAttribute('data-poll-timeout-ms', String(pollTimeoutMs || 480000));
      }

      updateStatusTexts(
        'PAYLOAD READY',
        'Wallet settlement waiting.',
        'Finalize Mint prepared the payload. Press Prepare & Mint Now to open TON wallet.'
      );
      setPrepareCtaActive(true);
    });

    document.addEventListener('cert:nft-factory-handoff-failed', (ev) => {
      setError(String(ev?.detail?.error || 'Finalize failed').trim());
    });

    document.addEventListener('cert:nft-focus', async (ev) => {
      const uid = String(ev?.detail?.cert_uid || '').trim();
      if (!uid) return;

      try {
        await hydrateFromFinalize(uid);
      } catch (err) {
        console.error('[MINT] hydrateFromFinalize failed', err);
        setError(err?.message || 'Mint init failed');
      }
    });

    document.addEventListener('cert:mint-ready-selected', (ev) => {
      const uid = String(ev?.detail?.cert_uid || '').trim();
      if (!uid) return;
      state.activeCertUid = uid;
      setText(IDS.certUid, uid);
      updateStatusTexts(
        'MINT READY',
        'Wallet settlement waiting.',
        'Finalize Mint selected. Preparing NFT Factory handoff.'
      );
    });

    document.addEventListener('cert:queue-summary-refresh-requested', (ev) => {
      const uid = String(ev?.detail?.cert_uid || '').trim();
      const bucket = String(ev?.detail?.queue_bucket || '').trim();
      if (!uid || !bucket) return;

      if (bucket === 'minting_process') setRowState(uid, 'minting');
      if (bucket === 'issued') {
        setRowState(uid, 'issued');
        moveRowToBucket(uid, 'issued');
      }
    });

    document.addEventListener('cert:mint-complete', (ev) => {
      const uid = String(ev?.detail?.cert_uid || '').trim();
      if (!uid) return;
      setRowState(uid, 'issued');
      moveRowToBucket(uid, 'issued');
      showMintSuccessToast(uid);
    });

    window.addEventListener('focus', onWalletReturn);
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) onWalletReturn();
    });
  }

  function bootFromExistingState() {
    const res = window.__CERT_FINALIZE_RESPONSE;
    if (res && typeof res === 'object' && res.cert_uid) {
      hydrateFactory(res);
      setGetgemsLinkFromItem(res?.nft_item_address || '', String(res.cert_uid));
      const pollUrl = String(
        res?.verify_poll?.mint_verify_url ||
        `/rwa/cert/api/mint-verify.php?cert_uid=${encodeURIComponent(res.cert_uid)}`
      ).trim();
      startMintVerifyPolling(
        String(res.cert_uid),
        pollUrl,
        Number(res?.verify_poll?.poll_interval_ms || 5000),
        Number(res?.verify_poll?.timeout_ms || 480000)
      );
    }
  }

  function boot() {
    setPrepareCtaActive(false);
    bindEvents();
    bindLegacyButtons();
    bootFromExistingState();
    console.log('[MINT_ACTIONS] clean final ready');
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})();
