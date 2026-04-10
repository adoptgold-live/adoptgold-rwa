/**
 * /var/www/html/public/rwa/cert/nft.js
 * Version: v1.4.0-20260408-manual-wallet-from-payload-link
 *
 * FINAL FACTORY FLOW
 * - Finalize Mint = jump to NFT Factory only
 * - cert:nft-focus must NOT auto-prepare
 * - Prepare & Mint Now:
 *   1) calls mint-init.php
 *   2) hydrates NFT Factory
 *   3) starts mint-verify polling
 *   4) does NOT auto-open wallet
 * - Payload field is clickable and opens wallet manually
 */

(function () {
  'use strict';

  if (window.CERT_NFT_V14_ACTIVE) return;
  window.CERT_NFT_V14_ACTIVE = true;

  const $ = (id) => document.getElementById(id);
  const NFT_MINT_SESSION_KEY = 'poado_nft_mint_session_v1';

  const state = {
    activeCertUid: '',
    mintInit: null,
    mintVerifyTimer: null,
    tonConnectUI: null,
    booted: false,
    autoPreparedUid: '',
    preparing: false,
    mintBusy: false,
    walletOpening: false,
    mintVerifyStartedAt: 0,
    mintVerifyTimeoutMs: 480000
  };

  function log(...args) { console.log('[CERT_NFT]', ...args); }
  function warn(...args) { console.warn('[CERT_NFT]', ...args); }
  function err(...args) { console.error('[CERT_NFT]', ...args); }

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

  function mintInitUrl(certUid) {
    return `/rwa/cert/api/mint-init.php?cert_uid=${encodeURIComponent(certUid)}`;
  }

  function mintVerifyUrl(certUid, nftItemAddress, txHash) {
    const url = new URL('/rwa/cert/api/mint-verify.php', window.location.origin);
    url.searchParams.set('cert_uid', certUid);
    if (nftItemAddress) url.searchParams.set('nft_item_address', nftItemAddress);
    if (txHash) url.searchParams.set('tx_hash', txHash);
    return url.toString();
  }

  function setValueOrText(id, value) {
    const el = $(id);
    if (!el) return false;
    const v = String(value ?? '');
    if ('value' in el) el.value = v;
    el.textContent = v;
    return true;
  }

  function setMany(ids, value) {
    ids.forEach((id) => setValueOrText(id, value));
  }

  function activeUid() {
    return String(
      state.activeCertUid ||
      window.__CERT_SELECTED_UID ||
      $('activeMintCertUid')?.value ||
      $('activeMintCertUid')?.textContent ||
      ''
    ).trim();
  }

  function saveMintSession(extra = {}) {
    const certUid = String(extra.cert_uid || state.activeCertUid || '').trim();
    if (!certUid) return;

    const payload = {
      cert_uid: certUid,
      started_at: state.mintVerifyStartedAt || Date.now(),
      wallet_link: String(
        extra.wallet_link ||
        state.mintInit?.wallet_link ||
        state.mintInit?.deeplink ||
        ''
      ).trim(),
      status: String(extra.status || 'minting').trim()
    };

    try {
      localStorage.setItem(NFT_MINT_SESSION_KEY, JSON.stringify(payload));
    } catch (_) {}
  }

  function loadMintSession() {
    try {
      const raw = localStorage.getItem(NFT_MINT_SESSION_KEY);
      if (!raw) return null;
      const json = JSON.parse(raw);
      return json && typeof json === 'object' ? json : null;
    } catch (_) {
      return null;
    }
  }

  function clearMintSession() {
    try {
      localStorage.removeItem(NFT_MINT_SESSION_KEY);
    } catch (_) {}
  }

  function enterMintBusy() {
    if (state.mintBusy) return false;
    state.mintBusy = true;
    return true;
  }

  function leaveMintBusy() {
    state.mintBusy = false;
  }

  function clearMintSelectionStyles() {
    document.querySelectorAll('[data-cert-uid]').forEach((el) => {
      el.classList.remove('is-selected-mint');
      el.classList.remove('is-focused');
    });
  }

  function markMintSelection(uid) {
    if (!uid) return;
    clearMintSelectionStyles();
    document.querySelectorAll(`[data-cert-uid="${CSS.escape(uid)}"]`).forEach((el) => {
      el.classList.add('is-selected-mint');
      el.classList.add('is-focused');
    });
  }

  function setStatusBanner(text) {
    setMany([
      'nftFactoryBanner',
      'mintFactoryBanner',
      'activeMintBanner',
      'nextStepBanner'
    ], text);
  }

  function setStatusText(text) {
    setMany([
      'nftFactoryStatusText',
      'mintStatusText',
      'activeMintStatusText',
      'factoryStatusText',
      'certMintStatusText'
    ], text);
  }

  function setWalletAnchor(href) {
    const anchors = [
      $('certMintPayloadLink'),
      $('nftFactoryWalletAnchor')
    ].filter(Boolean);

    anchors.forEach((a) => {
      try {
        a.href = href || '#';
        a.setAttribute('data-wallet-link', href || '');
        if (!href) {
          a.setAttribute('aria-disabled', 'true');
          a.style.pointerEvents = 'none';
        } else {
          a.setAttribute('aria-disabled', 'false');
          a.style.pointerEvents = '';
        }
      } catch (_) {}
    });
  }

  function setPrepareDisabled(disabled) {
    const btn = $('certMintPrepareBtn');
    if (!btn) return;
    btn.disabled = !!disabled;
    btn.classList.toggle('is-disabled', !!disabled);
    btn.setAttribute('aria-disabled', disabled ? 'true' : 'false');
  }

  function currentPreviewUrl() {
    return String(
      state.mintInit?.preview?.verify_url ||
      state.mintInit?.verify_url ||
      ''
    ).trim();
  }

  function currentGetgemsUrl() {
    return String(
      state.mintInit?.preview?.metadata_url ||
      state.mintInit?.metadata_url ||
      currentPreviewUrl()
    ).trim();
  }


  async function ensureTonConnectUI() {
    if (state.tonConnectUI) return state.tonConnectUI;
    if (!window.TON_CONNECT_UI) return null;

    try {
      state.tonConnectUI = new window.TON_CONNECT_UI.TonConnectUI({
        manifestUrl: '/tonconnect-manifest.json',
        buttonRootId: 'tonConnectMount'
      });
      return state.tonConnectUI;
    } catch (e) {
      warn('TonConnect init failed', e);
      return null;
    }
  }

  async function openWalletFromMintInit(json) {
    if (state.walletOpening) {
      log('wallet open skipped: already opening');
      return false;
    }

    state.walletOpening = true;

    try {
      const walletLink = String(json?.wallet_link || json?.deeplink || '').trim();

      const ui = await ensureTonConnectUI();
      if (ui && json?.tonconnect) {
        try {
          await ui.sendTransaction(json.tonconnect);
          setStatusText('WALLET SIGN SENT');
          setStatusBanner('Wallet submitted. Waiting for on-chain mint confirmation.');
          log('TonConnect sent');
          return true;
        } catch (e) {
          warn('TonConnect send failed, fallback to deeplink', e);
        }
      }

      if (!walletLink) {
        throw new Error('MINT_WALLET_LINK_MISSING');
      }

      window.open(walletLink, '_blank', 'noopener');
      setStatusText('WALLET OPENED');
      setStatusBanner('Wallet opened. Continue minting NFT to collection.');
      log('wallet deeplink opened');
      return true;
    } finally {
      window.setTimeout(() => {
        state.walletOpening = false;
      }, 1200);
    }
  }

  function hydrateMintPanel(json) {
    const uid = String(json?.cert_uid || '').trim();
    const walletLink = String(json?.wallet_link || json?.deeplink || '').trim();
    const tokenSymbol = String(
      json?.payment?.token_symbol ||
      json?.payment_token ||
      json?.token_symbol ||
      ''
    ).trim().toUpperCase();

    const tokenMap = {
      'WEMS': '/metadata/wems.png',
      'EMA': '/metadata/ema.png',
      'EMA$': '/metadata/ema.png',
      'EMX': '/metadata/emx.png',
      'EMS': '/metadata/ems.png',
      'TON': '/metadata/ton.png'
    };

    state.activeCertUid = uid;
    state.mintInit = json;
    window.__CERT_SELECTED_UID = uid;
    __showMintResultBanner('');
    window.__CERT_MINT_INIT = json;

    setMany(['certMintTitle', 'activeMintCertUid', 'nftFactoryCertUid', 'mintCertUid', 'selectedMintCertUid', 'factoryCertUid'], uid || 'No Active Cert');

    setMany(['certMintRecipient', 'nftFactoryRecipient', 'mintRecipient', 'activeMintRecipient', 'factoryRecipient'], json.recipient || '');
    setMany(['certMintAmount', 'nftFactoryAmountTon', 'mintAmountTon', 'activeMintAmountTon', 'factoryAmountTon'], json.amount_ton || '');
    setMany(['certMintAmountNano', 'nftFactoryAmountNano', 'mintAmountNano', 'activeMintAmountNano', 'factoryAmountNano'], json.amount_nano || '');
    setMany(['certMintItemIndex', 'nftFactoryItemIndex', 'mintItemIndex', 'activeMintItemIndex', 'factoryItemIndex'], json.item_index || '');
    setMany(['certMintPayloadMini', 'nftFactoryPayload', 'mintPayloadB64', 'activeMintPayload', 'factoryPayloadB64'], json.payload_b64 || '');

    setMany(['nftFactoryCollection', 'mintCollectionAddress', 'activeMintCollection', 'factoryCollectionAddress'], json.collection_address || '');
    setMany(['nftFactoryVerifyUrl', 'mintVerifyUrl', 'activeMintVerifyUrl', 'factoryVerifyUrl'], json.verify_url || '');
    setMany(['nftFactoryMetadataPath', 'mintMetadataPath', 'activeMintMetadataPath', 'factoryMetadataPath'], json.metadata_path || '');

    const payloadLink = $('certMintPayloadLink');
    if (payloadLink) {
      payloadLink.href = walletLink || '#';
      payloadLink.setAttribute('data-wallet-link', walletLink || '');
      payloadLink.setAttribute('aria-disabled', walletLink ? 'false' : 'true');
    }

    setWalletAnchor(walletLink);

    const tokenImg = $('certMintTokenImg');
    const tokenWrap = $('certMintTokenImgWrap');
    const tokenSrc = tokenMap[tokenSymbol] || '';

    if (tokenImg && tokenWrap && tokenSrc) {
      tokenImg.src = tokenSrc;
      tokenImg.alt = tokenSymbol || 'Token';
      tokenWrap.style.display = '';
    } else if (tokenWrap) {
      tokenWrap.style.display = 'none';
    }

    setStatusText('MINT PREPARED');
    setStatusBanner('Mint prepared. Click Prepare & Mint Now to open wallet and continue minting.');

    const qrMeta = $('certMintQrMeta');
    if (qrMeta) {
      qrMeta.textContent = walletLink
        ? 'Mint payload ready. Prepare & Mint Now will open wallet to continue minting.'
        : 'Mint payload ready.';
    }

    if ($('certMintGetgemsBtn')) {
      $('certMintGetgemsBtn').href = currentGetgemsUrl() || '#';
    }

    saveMintSession({
      cert_uid: uid,
      wallet_link: walletLink,
      status: 'prepared'
    });

    markMintSelection(uid);
    log('mint panel hydrated', uid, json);
  }

  function stopMintVerifyTimer() {
    if (state.mintVerifyTimer) {
      clearInterval(state.mintVerifyTimer);
      state.mintVerifyTimer = null;
      state.mintVerifyStartedAt = 0;
      log('mint verify timer stopped');
    }
  }

  function startMintVerifyTimer(certUid) {
    const uid = String(certUid || activeUid()).trim();
    if (!uid) return;

    stopMintVerifyTimer();
    state.mintVerifyStartedAt = Date.now();

    saveMintSession({
      cert_uid: uid,
      status: 'minting'
    });

    setPrepareDisabled(true);
    setStatusText('MINTING');
    setStatusBanner('Mint prepared. After signing in wallet, waiting for on-chain confirmation.');

    state.mintVerifyTimer = setInterval(async () => {
      try {
        if (state.mintVerifyStartedAt > 0 && (Date.now() - state.mintVerifyStartedAt) > state.mintVerifyTimeoutMs) {
          stopMintVerifyTimer();
          saveMintSession({
            cert_uid: uid,
            status: 'waiting_confirmation'
          });
          setPrepareDisabled(true);
          setStatusText('WAITING CONFIRMATION');
          setStatusBanner('Mint confirmation is taking longer than expected. You can refresh verify.');
          warn('mint verify timer timeout', uid);
          return;
        }

        await verifyMintOnce({ cert_uid: uid });
      } catch (e) {
        warn('mint verify poll failed', e);
      }
    }, 5000);

    log('mint verify timer started', uid);
  }

  async function prepareMint(uid) {
    const certUid = String(uid || activeUid()).trim();
    if (!certUid) throw new Error('CERT_UID_REQUIRED');
    if (state.preparing) return state.mintInit;

    state.preparing = true;

    try {
      setStatusText('PREPARING');
      setStatusBanner('Preparing mint payload...');

      const json = await getJson(mintInitUrl(certUid));

      if (json.mint_ready !== true) {
        throw new Error(json.error || 'MINT_NOT_READY');
      }

      hydrateMintPanel(json);

      setMany(['activeMintCertUid', 'nftFactoryCertUid', 'mintCertUid', 'selectedMintCertUid', 'factoryCertUid'], certUid);
      setStatusText('MINT PREPARED');
      setStatusBanner('Active cert loaded. Prepare & Mint Now will open wallet to continue minting.');

      document.dispatchEvent(new CustomEvent('cert:mint-init', {
        detail: {
          cert_uid: certUid,
          queue_bucket: 'minting_process',
          mint_status: 'minting'
        }
      }));

      startMintVerifyTimer(certUid);

      return json;
    } finally {
      state.preparing = false;
    }
  }

  async function verifyMintOnce(options = {}) {
    const certUid = String(options.cert_uid || activeUid()).trim();
    if (!certUid) throw new Error('CERT_UID_REQUIRED');

    const nftItemAddress = String(options.nft_item_address || '').trim();
    const txHash = String(options.tx_hash || '').trim();

    const json = await getJson(mintVerifyUrl(certUid, nftItemAddress, txHash));

    if (json?.minted === true || json?.nft_minted === 1 || json?.queue_bucket === 'issued') {
      setStatusText('MINTED');
      setStatusBanner('Issued successfully. NFT mint confirmed.');

      document.dispatchEvent(new CustomEvent('cert:mint-complete', {
        detail: {
          cert_uid: certUid,
          queue_bucket: 'issued',
          mint_status: 'minted',
          nft_minted: 1
        }
      }));

      document.dispatchEvent(new CustomEvent('cert:queue-summary-refresh-requested', {
        detail: {
          cert_uid: certUid,
          queue_bucket: 'issued'
        }
      }));

      clearMintSession();
      setPrepareDisabled(true);
      stopMintVerifyTimer();
    } else {
      setStatusText('MINTING');
      setStatusBanner('Mint prepared. After signing in wallet, waiting for on-chain confirmation.');
    }

    if ($('certMintGetgemsBtn')) {
      $('certMintGetgemsBtn').href =
        String(json?.mint_request?.metadata_url || currentGetgemsUrl() || currentPreviewUrl() || '#').trim();
    }

    return json;
  }

  function handleNftFocus(ev) {
    const uid = String(ev?.detail?.cert_uid || '').trim();
    if (!uid) return;

    state.activeCertUid = uid;
    window.__CERT_SELECTED_UID = uid;
    __showMintResultBanner('');

    setMany(['activeMintCertUid', 'nftFactoryCertUid', 'mintCertUid', 'selectedMintCertUid', 'factoryCertUid'], uid);
    setPrepareDisabled(false);
    setStatusText('READY');
    setStatusBanner('NFT Factory selected. Click Prepare & Mint Now to continue.');

    markMintSelection(uid);
    log('nft focus received', uid);
  }

  function bindButtons() {
    $('certMintPrepareBtn')?.addEventListener('click', async () => {
      if (!enterMintBusy()) {
        log('prepare click skipped: mint busy');
        return;
      }

      try {
        const json = await prepareMint(activeUid());
        await openWalletFromMintInit(json);
        setStatusText('WALLET OPENED');
        setStatusBanner('Wallet opened. Continue minting NFT to collection.');
      } catch (e) {
        err('prepare mint failed', e);
        setStatusText('ERROR');
        setStatusBanner(`Prepare & Mint failed: ${e.message || e}`);
        alert(`Prepare & Mint failed: ${e.message || e}`);
      } finally {
        leaveMintBusy();
      }
    });

    $('certMintAutoBtn')?.addEventListener('click', async () => {
      if (!enterMintBusy()) {
        log('auto mint click skipped: mint busy');
        return;
      }

      try {
        await verifyMintOnce({ cert_uid: activeUid() });
      } catch (e) {
        err('auto mint failed', e);
        setStatusText('ERROR');
        setStatusBanner(`Auto Mint failed: ${e.message || e}`);
        alert(`Auto Mint failed: ${e.message || e}`);
      } finally {
        leaveMintBusy();
      }
    });

    $('certMintRefreshBtn')?.addEventListener('click', async () => {
      try {
        await verifyMintOnce({ cert_uid: activeUid() });
      } catch (e) {
        err('refresh mint verify failed', e);
        alert(`Mint refresh failed: ${e.message || e}`);
      }
    });

    $('certMintGetgemsBtn')?.addEventListener('click', (ev) => {
      const href = currentGetgemsUrl() || currentPreviewUrl();
      if (!href) {
        ev.preventDefault();
        alert('Verify URL not ready.');
        return;
      }
      $('certMintGetgemsBtn').href = href;
    });
  }

  function bindEvents() {
    document.addEventListener('cert:nft-focus', (ev) => {
  try {
    const uid = String(ev?.detail?.cert_uid || '').trim();
    const row = ev?.detail?.row || {};

    if (!uid) return;

    window.__CERT_SELECTED_UID = uid;
    __showMintResultBanner('');

    // 1. CERT UID
    ['activeMintCertUid','certMintCurrentUid','certMintUid','nftFactoryCertUid']
      .forEach(id=>{
        const el=document.getElementById(id);
        if(el) el.textContent = uid;
      });

    // 2. UNIT OF RESPONSIBILITY
    const unit = mapUnitOfResponsibility(row?.rwa_code);
    const nanoEl = document.getElementById('certMintAmountNano');
    if(nanoEl) nanoEl.textContent = unit;

    // 3. MEMO / COMMENT
    const memoEl = document.getElementById('certMintItemIndex');
    if(memoEl) memoEl.textContent = 'REF: ' + uid;

    // 4. RESET TON VALUES (will be filled by mint-init)
    const tonEl = document.getElementById('certMintAmountTon');
    if(tonEl) tonEl.textContent = '—';

    const payloadEl = document.getElementById('certMintPayload');
    if(payloadEl) payloadEl.textContent = '—';

    // 5. GREEN GLOW
    const factory = document.getElementById('nftFactorySection');
    if (factory) {
      factory.classList.add('is-selected-cert','is-focused');
      setTimeout(()=>factory.classList.remove('is-focused'),1600);
    }

  } catch (e) {
    console.warn('[NFT] bind fail', e);
  }
});  }

  function bootFromExistingSelection() {
    const uid = activeUid();
    if (!uid) return;
    setMany(['activeMintCertUid', 'nftFactoryCertUid', 'mintCertUid', 'selectedMintCertUid', 'factoryCertUid'], uid);
    markMintSelection(uid);
  }

  function boot() {
    if (state.booted) return;
    state.booted = true;

    bindButtons();
    bindEvents();
    bootFromExistingSelection();
    setPrepareDisabled(false);

    const saved = loadMintSession();
    if (saved && saved.cert_uid) {
      state.activeCertUid = String(saved.cert_uid).trim();
      window.__CERT_SELECTED_UID = state.activeCertUid;
      state.autoPreparedUid = state.activeCertUid;

      setMany(['activeMintCertUid', 'nftFactoryCertUid', 'mintCertUid', 'selectedMintCertUid', 'factoryCertUid'], state.activeCertUid);
      markMintSelection(state.activeCertUid);

      if (saved.wallet_link) {
        setWalletAnchor(String(saved.wallet_link).trim());
      }

      setPrepareDisabled(true);
      setStatusText('RECOVERED');
      setStatusBanner('Recovered pending mint session. You can click the Payload link or refresh verify.');

      if (saved.started_at && Number.isFinite(Number(saved.started_at))) {
        state.mintVerifyStartedAt = Number(saved.started_at);
      }

      window.setTimeout(() => {
        startMintVerifyTimer(state.activeCertUid);
      }, 150);
    }

    log('nft.js ready v1.4 manual wallet from payload');
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})();

// v20260409-unit-mapping-lock
function mapUnitOfResponsibility(rwaCode){
  const code = String(rwaCode || '').toUpperCase();

  if (code.startsWith('RCO2C')) return '10 kg tCO2e';
  if (code.startsWith('RH2O'))  return '100 Liters Water';
  if (code.startsWith('RBLACK'))return '1 MWh Energy';
  if (code.startsWith('RK92'))  return '1 Gram Gold';
  if (code.startsWith('RLIFE')) return '1 Day Health';
  if (code.startsWith('RTRIP')) return '1 KM Travel';
  if (code.startsWith('RPROP')) return '1 sqft Property';
  if (code.startsWith('RHRD'))  return '10 Hours Labor';

  return code || '-';
}


function __setText(id, value){
  const el = document.getElementById(id);
  if (el) el.textContent = String(value ?? '—');
}

function __showMintResultBanner(text){
  const el = document.getElementById('mintResultBanner');
  if (!el) return;
  el.textContent = String(text || '');
  el.style.display = text ? '' : 'none';
}

async function __pollMintResult(certUid){
  const uid = String(certUid || '').trim();
  if (!uid) return;

  const url = '/rwa/cert/api/verify-status.php?cert_uid=' + encodeURIComponent(uid);

  const tick = async () => {
    try{
      const res = await fetch(url, { credentials:'same-origin' });
      const json = await res.json();
      const row = json?.row || (Array.isArray(json?.rows) ? json.rows[0] : null) || {};
      const minted = Number(row?.nft_minted || 0) === 1;
      const nftAddress = String(row?.nft_item_address || '').trim();
      const txHash = String(row?.tx_hash || row?.mint_tx_hash || row?.router_tx_hash || '').trim();

      if (txHash) {
        __setText('certMintTxHash', txHash);
      }
      if (nftAddress) {
        __setText('certMintNftAddress', nftAddress);
      }

      if (minted && nftAddress) {
        __showMintResultBanner('Mint success. NFT address: ' + nftAddress);
        const statusEl = document.getElementById('certMintStatus');
        if (statusEl) statusEl.textContent = 'MINTED';

        // optional: jump to verify in new tab
        const verifyUrl = '/rwa/cert/verify.php?uid=' + encodeURIComponent(uid);
        console.log('[MINT] success', { uid, nftAddress, txHash, verifyUrl });

        clearInterval(window.__CERT_MINT_POLL_TIMER);
        window.__CERT_MINT_POLL_TIMER = null;
      }
    }catch(e){
      console.warn('[MINT] verify-status poll failed', e);
    }
  };

  if (window.__CERT_MINT_POLL_TIMER) {
    clearInterval(window.__CERT_MINT_POLL_TIMER);
  }

  await tick();
  window.__CERT_MINT_POLL_TIMER = setInterval(tick, 4000);
}


/* v20260409-safe-ui-unit-map */
function mapUnitOfResponsibilitySafe(rwaCode){
  const code = String(rwaCode || '').toUpperCase();
  if (code.startsWith('RCO2C')) return '10 kg tCO2e';
  if (code.startsWith('RH2O')) return '100 Liters Water';
  if (code.startsWith('RBLACK')) return '1 MWh Energy';
  if (code.startsWith('RK92')) return '1 Gram Gold';
  if (code.startsWith('RLIFE')) return '1 Day Health';
  if (code.startsWith('RTRIP')) return '1 KM Travel';
  if (code.startsWith('RPROP')) return '1 sqft Property';
  if (code.startsWith('RHRD')) return '10 Hours Labor';
  return '—';
}


/* v20260409-safe-ui-focus-listener */
document.addEventListener('cert:nft-focus', (ev) => {
  try {
    const uid = String(ev?.detail?.cert_uid || window.__CERT_SELECTED_UID || '').trim();
    const row = ev?.detail?.row || window.__CERT_SELECTED_ROW || {};
    if (!uid) return;

    // CERT UID value
    [
      'activeMintCertUid',
      'certMintCurrentUid',
      'certMintUid',
      'nftFactoryCertUid',
      'activeCertUid'
    ].forEach((id) => {
      const el = document.getElementById(id);
      if (el) el.textContent = uid;
    });

    // UNIT OF RESPONSIBILITY value
    [
      'certMintAmountNano',
      'activeMintAmountNano'
    ].forEach((id) => {
      const el = document.getElementById(id);
      if (el) el.textContent = mapUnitOfResponsibilitySafe(row?.rwa_code || row?.code || '');
    });

    // MEMO / COMMENT OF TON PAYMENT value
    [
      'certMintItemIndex',
      'activeMintItemIndex'
    ].forEach((id) => {
      const el = document.getElementById(id);
      if (el) el.textContent = 'REF: ' + uid;
    });

    // Green glow on NFT Factory
    const factory = document.getElementById('nftFactorySection');
    if (factory) {
      factory.classList.add('is-selected-cert', 'is-focused');
      setTimeout(() => factory.classList.remove('is-focused'), 1600);
    }
  } catch (e) {
    console.warn('[NFT] safe UI focus listener failed', e);
  }
});

