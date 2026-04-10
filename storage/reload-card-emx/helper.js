/*!
 * /rwa/storage/reload-card-emx/helper.js
 * Reload Card with EMX -> RWA€ UI helper
 * MASTER LOCK
 */
(function () {
  'use strict';

  const MOD = {
    state: {
      busyPrepare: false,
      busyVerify: false,
      lastReloadRef: '',
      lastDeepLink: '',
      lastQrText: '',
      rateEurUsd: '',
      rateEmxToRwae: '',
      amountRwae: ''
    },

    els: {},

    init() {
      this.cache();
      if (!this.els.root) return;
      this.bind();
      this.renderIdle();
    },

    cache() {
      this.els.root = document.querySelector('[data-reload-emx-module]');
      if (!this.els.root) return;

      this.els.amount = this.els.root.querySelector('[data-reload-amount]');
      this.els.cardNumber = this.els.root.querySelector('[data-reload-card-number]');
      this.els.csrf = this.els.root.querySelector('[data-reload-csrf]');
      this.els.btnPrepare = this.els.root.querySelector('[data-reload-prepare]');
      this.els.btnVerify = this.els.root.querySelector('[data-reload-verify]');
      this.els.txHash = this.els.root.querySelector('[data-reload-tx-hash]');
      this.els.refView = this.els.root.querySelector('[data-reload-ref-view]');
      this.els.deepLinkView = this.els.root.querySelector('[data-reload-deeplink-view]');
      this.els.qrView = this.els.root.querySelector('[data-reload-qr-view]');
      this.els.msg = this.els.root.querySelector('[data-reload-msg]');
      this.els.rateView = this.els.root.querySelector('[data-reload-rate-view]');
      this.els.rwaeView = this.els.root.querySelector('[data-reload-rwae-view]');
    },

    bind() {
      if (this.els.btnPrepare) {
        this.els.btnPrepare.addEventListener('click', () => this.prepare());
      }
      if (this.els.btnVerify) {
        this.els.btnVerify.addEventListener('click', () => this.verify());
      }
    },

    writeText(el, text) {
      if (el) el.textContent = String(text ?? '');
    },

    setMsg(text, cls) {
      if (!this.els.msg) return;
      this.els.msg.className = 'reload-emx-msg' + (cls ? ' ' + cls : '');
      this.els.msg.textContent = text || '';
    },

    setBusy(which, busy) {
      if (which === 'prepare') {
        this.state.busyPrepare = !!busy;
        if (this.els.btnPrepare) this.els.btnPrepare.disabled = !!busy;
      }
      if (which === 'verify') {
        this.state.busyVerify = !!busy;
        if (this.els.btnVerify) this.els.btnVerify.disabled = !!busy;
      }
    },

    renderIdle() {
      this.writeText(this.els.refView, '-');
      this.writeText(this.els.deepLinkView, '-');
      this.writeText(this.els.qrView, '-');
      this.writeText(this.els.rateView, '-');
      this.writeText(this.els.rwaeView, '-');
      this.setMsg('', '');
    },

    renderPrepared() {
      this.writeText(this.els.refView, this.state.lastReloadRef || '-');
      this.writeText(this.els.deepLinkView, this.state.lastDeepLink || '-');
      this.writeText(this.els.qrView, this.state.lastQrText || '-');
      this.writeText(
        this.els.rateView,
        this.state.rateEmxToRwae
          ? ('1 EMX = ' + this.state.rateEmxToRwae + ' RWA€')
          : '-'
      );
      this.writeText(
        this.els.rwaeView,
        this.state.amountRwae
          ? (this.state.amountRwae + ' RWA€')
          : '-'
      );
    },

    async postJson(url, payload) {
      const res = await fetch(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        body: JSON.stringify(payload || {})
      });
      const json = await res.json().catch(() => ({}));
      return { ok: res.ok, status: res.status, json };
    },

    buildDeepLink(ref, amountEmx) {
      const amountUnits = String(Math.round((parseFloat(amountEmx || '0') || 0) * 1000000));
      const treasury = this.els.root.getAttribute('data-reload-treasury') || '';
      const jetton = this.els.root.getAttribute('data-reload-jetton') || '';
      if (!treasury || !jetton) return '';
      const text = encodeURIComponent(ref || '');
      return `ton://transfer/${treasury}?jetton=${encodeURIComponent(jetton)}&amount=${amountUnits}&text=${text}`;
    },

    async prepare() {
      if (this.state.busyPrepare) return;

      const amountEmx = (this.els.amount?.value || '').trim();
      const cardNumber = (this.els.cardNumber?.value || '').trim();
      const csrf = (this.els.csrf?.value || '').trim();

      if (!amountEmx || Number(amountEmx) <= 0) {
        this.setMsg('Enter valid EMX amount.', 'is-error');
        return;
      }

      this.setBusy('prepare', true);
      this.setMsg('Preparing reload...', 'is-busy');

      try {
        const { json } = await this.postJson('/rwa/api/storage/reload-card-emx/_bootstrap.php', {
          action: 'prepare',
          amount_emx: amountEmx,
          card_number: cardNumber,
          csrf: csrf
        });

        if (!json || !json.ok) {
          this.setMsg(json?.error || 'Prepare failed.', 'is-error');
          return;
        }

        this.state.lastReloadRef = String(json.reload_ref || '').trim();
        this.state.rateEurUsd = String(json.eur_usd || '').trim();
        this.state.rateEmxToRwae = String(json.emx_to_rwae || '').trim();
        this.state.amountRwae = String(json.amount_rwae || '').trim();
        this.state.lastDeepLink = this.buildDeepLink(this.state.lastReloadRef, amountEmx);
        this.state.lastQrText = this.state.lastDeepLink;

        this.renderPrepared();
        this.setMsg('Prepared. Complete payment, then verify.', 'is-ok');
      } catch (e) {
        this.setMsg('Prepare request failed.', 'is-error');
      } finally {
        this.setBusy('prepare', false);
      }
    },

    async verify() {
      if (this.state.busyVerify) return;

      const reloadRef = this.state.lastReloadRef || '';
      const txHash = (this.els.txHash?.value || '').trim();
      const csrf = (this.els.csrf?.value || '').trim();

      if (!reloadRef) {
        this.setMsg('Prepare first.', 'is-error');
        return;
      }

      if (!txHash) {
        this.setMsg('Enter tx hash.', 'is-error');
        return;
      }

      this.setBusy('verify', true);
      this.setMsg('Verifying payment...', 'is-busy');

      try {
        const { json } = await this.postJson('/rwa/api/storage/reload-card-emx/_bootstrap.php', {
          action: 'verify',
          reload_ref: reloadRef,
          tx_hash: txHash,
          csrf: csrf
        });

        if (!json || !json.ok) {
          this.setMsg(json?.error || 'Verify failed.', 'is-error');
          return;
        }

        if (json.credited_rwae) {
          this.state.amountRwae = String(json.credited_rwae).trim();
        }
        this.renderPrepared();
        this.setMsg('Verified. Card credited in RWA€.', 'is-ok');

        if (window.fetchStorageOverview) {
          try { window.fetchStorageOverview(); } catch (_) {}
        }
      } catch (e) {
        this.setMsg('Verify request failed.', 'is-error');
      } finally {
        this.setBusy('verify', false);
      }
    }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => MOD.init());
  } else {
    MOD.init();
  }
})();
