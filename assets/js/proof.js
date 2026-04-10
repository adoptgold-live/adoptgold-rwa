(function () {
  'use strict';

  function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
  }

  function safeNowIso() {
    try { return new Date().toISOString(); }
    catch (_) { return ''; }
  }

  function isProbablyTonAddress(addr) {
    const value = String(addr || '').trim();
    return /^(EQ|UQ|kQ|0Q)[A-Za-z0-9_-]{40,}$/.test(value)
      || /^[0-9a-fA-F]{64}$/.test(value)
      || /^-?\d+:[0-9a-fA-F]{64}$/.test(value);
  }

  function extractTonProof(wallet) {
    if (!wallet || typeof wallet !== 'object') return null;

    return (
      wallet?.connectItems?.tonProof ||
      wallet?.tonProof ||
      wallet?.connectItems?.proof ||
      null
    );
  }

  function clearTonConnectStorageOnly() {
    try {
      Object.keys(localStorage).forEach((k) => {
        if (k.toLowerCase().includes('tonconnect')) {
          localStorage.removeItem(k);
        }
      });
    } catch (_) {}

    try {
      Object.keys(sessionStorage).forEach((k) => {
        if (k.toLowerCase().includes('tonconnect')) {
          sessionStorage.removeItem(k);
        }
      });
    } catch (_) {}
  }

  async function loadTonConnectUi() {
    if (window.TON_CONNECT_UI && window.TON_CONNECT_UI.TonConnectUI) {
      return window.TON_CONNECT_UI.TonConnectUI;
    }

    await new Promise((resolve, reject) => {
      const existing = document.querySelector('script[data-tonconnect-ui="1"]');
      if (existing) {
        let done = false;
        const timer = setInterval(() => {
          if (window.TON_CONNECT_UI && window.TON_CONNECT_UI.TonConnectUI) {
            clearInterval(timer);
            if (!done) {
              done = true;
              resolve();
            }
          }
        }, 120);

        setTimeout(() => {
          clearInterval(timer);
          if (!done) reject(new Error('TonConnect UI load timeout.'));
        }, 8000);
        return;
      }

      const s = document.createElement('script');
      s.src = 'https://unpkg.com/@tonconnect/ui@2.0.9/dist/tonconnect-ui.min.js';
      s.async = true;
      s.dataset.tonconnectUi = '1';
      s.onload = resolve;
      s.onerror = () => reject(new Error('TonConnect UI load failed.'));
      document.head.appendChild(s);
    });

    if (!(window.TON_CONNECT_UI && window.TON_CONNECT_UI.TonConnectUI)) {
      throw new Error('TonConnect UI unavailable.');
    }

    return window.TON_CONNECT_UI.TonConnectUI;
  }

  function create(config) {
    const cfg = {
      manifestUrl: '',
      buttonRootId: '',
      getNoncePayload: async () => '',
      resetServerSession: async () => {},
      onStatus: () => {},
      onDebug: () => {},
      onWalletChange: () => {},
      onProofReady: async () => {},
      ...config
    };

    const state = {
      tc: null,
      waiting: false,
      finalizing: false,
      noncePayload: '',
      modalOpenedAt: 0,
      finalizeTimer: null,
      visibilityHandler: null,
      listenerBound: false
    };

    function debug(stage, extra = {}) {
      const wallet = state.tc?.wallet || null;
      const proof = extractTonProof(wallet);

      const snap = {
        stage,
        at: safeNowIso(),
        waiting: !!state.waiting,
        finalizing: !!state.finalizing,
        noncePayload: state.noncePayload || '',
        nonceLength: String(state.noncePayload || '').length,
        hasWallet: !!wallet,
        walletAddress: wallet?.account?.address || '',
        hasConnectItems: !!wallet?.connectItems,
        hasTonProof: !!proof,
        proofKeys: proof && typeof proof === 'object' ? Object.keys(proof) : [],
        ...extra
      };

      try { cfg.onDebug(snap); } catch (_) {}
      try { console.log('[RwaTonProof]', snap); } catch (_) {}

      return snap;
    }

    async function ensureTonConnect() {
      if (state.tc) return state.tc;

      const TonConnectUI = await loadTonConnectUi();

      const root = document.getElementById(cfg.buttonRootId);
      if (!root) throw new Error('TonConnect mount root missing: ' + cfg.buttonRootId);

      state.tc = new TonConnectUI({
        manifestUrl: cfg.manifestUrl,
        buttonRootId: cfg.buttonRootId
      });

      if (!state.listenerBound) {
        state.tc.onStatusChange(async (wallet) => {
          debug('status_change');
          try { cfg.onWalletChange(wallet); } catch (_) {}

          if (state.waiting) {
            await finalize('status');
          }
        });
        state.listenerBound = true;
      }

      return state.tc;
    }

    function prepareProofRequest(tc, payload) {
      const proofPayload = String(payload || '').trim();
      if (!tc) throw new Error('TonConnect not ready.');
      if (!proofPayload) throw new Error('TON proof payload missing.');

      tc.setConnectRequestParameters({
        state: 'ready',
        value: {
          tonProof: proofPayload
        }
      });

      debug('proof_request_prepared');
      return true;
    }

    function stopFallbackWatchers() {
      if (state.finalizeTimer) {
        clearInterval(state.finalizeTimer);
        state.finalizeTimer = null;
      }

      if (state.visibilityHandler) {
        document.removeEventListener('visibilitychange', state.visibilityHandler);
        window.removeEventListener('focus', state.visibilityHandler);
        state.visibilityHandler = null;
      }
    }

    function clearTransientState() {
      state.noncePayload = '';
      state.waiting = false;
      state.finalizing = false;
      stopFallbackWatchers();
      debug('transient_cleared');
    }

    async function disconnect() {
      if (!state.tc) {
        clearTonConnectStorageOnly();
        clearTransientState();
        return;
      }

      try {
        state.tc.setConnectRequestParameters?.({ state: 'loading' });
      } catch (_) {}

      try {
        await state.tc.disconnect();
      } catch (_) {}

      await sleep(400);
      clearTonConnectStorageOnly();
      clearTransientState();
      debug('disconnect_done');
    }

    async function fullReset() {
      await cfg.resetServerSession();
      await disconnect();
      cfg.onStatus('TON client session cleared.', 'ok');
      debug('full_reset_done');
    }

    async function finalize(source) {
      if (!state.waiting || state.finalizing) return null;
      state.finalizing = true;

      try {
        const wallet = state.tc?.wallet || null;
        const address = wallet?.account?.address ? String(wallet.account.address) : '';
        const proof = extractTonProof(wallet);

        debug('finalize_enter', { source });

        if (!address) {
          state.finalizing = false;
          return null;
        }

        if (!isProbablyTonAddress(address)) {
          throw new Error('Invalid TON wallet address.');
        }

        if (!proof) {
          cfg.onStatus('Waiting wallet proof...', 'warn');
          debug('proof_missing', { source });
          state.finalizing = false;
          return null;
        }

        cfg.onStatus('Submitting proof...', 'warn');
        debug('proof_ready', { source });

        const result = await cfg.onProofReady({
          address,
          proof,
          source,
          wallet
        });

        state.waiting = false;
        state.finalizing = false;
        stopFallbackWatchers();
        debug('proof_submit_success', { source });

        return result;
      } catch (err) {
        state.finalizing = false;
        state.waiting = false;
        stopFallbackWatchers();
        debug('finalize_failed', {
          source,
          error: err && err.message ? err.message : String(err)
        });
        throw err;
      }
    }

    async function start() {
      cfg.onStatus('Preparing fresh TON bind session...', 'warn');

      await fullReset();

      const payload = await cfg.getNoncePayload();
      state.noncePayload = String(payload || '').trim();
      debug('nonce_loaded');

      if (!state.noncePayload) {
        throw new Error('TON proof payload missing.');
      }

      const tc = await ensureTonConnect();

      if (tc?.wallet?.account?.address) {
        throw new Error('Wallet already connected. Reset TON first, then bind again.');
      }

      prepareProofRequest(tc, state.noncePayload);

      state.waiting = true;
      state.modalOpenedAt = Date.now();

      cfg.onStatus('Open mobile wallet, switch to the target TON address, then approve.', 'warn');

      if (typeof tc.openModal !== 'function') {
        throw new Error('TonConnect modal open is unavailable.');
      }

      await tc.openModal();
      debug('modal_opened');

      state.visibilityHandler = async () => {
        if (!state.waiting) return;
        if (document.visibilityState === 'visible') {
          await finalize('visibility');
        }
      };

      document.addEventListener('visibilitychange', state.visibilityHandler);
      window.addEventListener('focus', state.visibilityHandler);

      state.finalizeTimer = setInterval(async () => {
        if (!state.waiting) return;
        if (Date.now() - state.modalOpenedAt < 1500) return;
        await finalize('poll');
      }, 1800);

      return true;
    }

    return {
      ensureTonConnect,
      start,
      finalize,
      fullReset,
      disconnect,
      clearTransientState,
      debugSnapshot: debug,
      extractTonProof,
      isProbablyTonAddress,
      getWallet() {
        return state.tc?.wallet || null;
      },
      getState() {
        return {
          waiting: state.waiting,
          finalizing: state.finalizing,
          noncePayload: state.noncePayload,
          modalOpenedAt: state.modalOpenedAt
        };
      }
    };
  }

  window.RwaTonProof = { create };
})();