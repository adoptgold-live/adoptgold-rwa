(function () {
  'use strict';

  const Commit = {
    state: {
      busyPrepare: false,
      busyVerify: false,
      commitRef: '',
      deeplink: '',
      qrText: '',
      autoRefreshOn: false,
      autoRefreshTimer: null,
      autoRefreshMs: 5000
    },

    els: {},

    init() {
      this.cache();
      this.bindModalShell();
      if (!this.els.root) return;
      this.bind();
      this.log('Commit module ready.');
      this.setLiveStatus('Idle', 'idle');
    },

    cache() {
      this.els.modal = document.getElementById('commitModal');
      this.els.root = document.querySelector('[data-commit-module]');
      this.els.openers = document.querySelectorAll('[data-open-commit-modal]');
      this.els.closers = document.querySelectorAll('[data-close-commit-modal]');

      if (!this.els.root) return;

      this.els.amount = this.els.root.querySelector('[data-commit-amount]');
      this.els.csrf = this.els.root.querySelector('[data-commit-csrf]');
      this.els.btnPrepare = this.els.root.querySelector('[data-commit-prepare]');
      this.els.btnVerify = this.els.root.querySelector('[data-commit-verify]');
      this.els.btnAutoRefresh = this.els.root.querySelector('[data-commit-auto-refresh]');
      this.els.ref = this.els.root.querySelector('[data-commit-ref]');
      this.els.link = this.els.root.querySelector('[data-commit-link]');
      this.els.qr = this.els.root.querySelector('[data-commit-qr]');
      this.els.log = this.els.root.querySelector('[data-commit-log]');
      this.els.btnOpenWallet = this.els.root.querySelector('[data-commit-open-wallet]');
      this.els.btnCopyRef = this.els.root.querySelector('[data-commit-copy-ref]');
      this.els.btnCopyLink = this.els.root.querySelector('[data-commit-copy-link]');
      this.els.liveStatus = this.els.root.querySelector('[data-commit-live-status]');
    },

    bindModalShell() {
      if (this.els.openers) {
        this.els.openers.forEach((btn) => {
          btn.addEventListener('click', () => this.openModal());
        });
      }

      if (this.els.closers) {
        this.els.closers.forEach((btn) => {
          btn.addEventListener('click', () => this.closeModal());
        });
      }

      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && this.els.modal && !this.els.modal.hidden) {
          this.closeModal();
        }
      });
    },

    openModal() {
      if (!this.els.modal) return;
      this.els.modal.hidden = false;
      this.els.modal.setAttribute('aria-hidden', 'false');
      document.body.classList.add('modal-open');
    },

    closeModal() {
      this.stopAutoRefresh(true);
      if (!this.els.modal) return;
      this.els.modal.hidden = true;
      this.els.modal.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('modal-open');
    },

    bind() {
      if (this.els.btnPrepare) {
        this.els.btnPrepare.addEventListener('click', () => this.prepare());
      }

      if (this.els.btnVerify) {
        this.els.btnVerify.addEventListener('click', () => this.verify());
      }

      if (this.els.btnAutoRefresh) {
        this.els.btnAutoRefresh.addEventListener('click', () => this.toggleAutoRefresh());
      }

      if (this.els.btnOpenWallet) {
        this.els.btnOpenWallet.addEventListener('click', () => {
          if (!this.state.deeplink) {
            this.log('Wallet deeplink not ready.');
            return;
          }
          window.open(this.state.deeplink, '_blank');
        });
      }

      if (this.els.btnCopyRef) {
        this.els.btnCopyRef.addEventListener('click', async () => {
          if (!this.state.commitRef) {
            this.log('Commit ref not ready.');
            return;
          }
          try {
            await navigator.clipboard.writeText(this.state.commitRef);
            this.log('Commit ref copied.');
          } catch (_) {
            this.log('Copy ref failed.');
          }
        });
      }

      if (this.els.btnCopyLink) {
        this.els.btnCopyLink.addEventListener('click', async () => {
          if (!this.state.deeplink) {
            this.log('Wallet deeplink not ready.');
            return;
          }
          try {
            await navigator.clipboard.writeText(this.state.deeplink);
            this.log('Wallet deeplink copied.');
          } catch (_) {
            this.log('Copy link failed.');
          }
        });
      }
    },

    setLiveStatus(text, kind) {
      if (!this.els.liveStatus) return;
      this.els.liveStatus.textContent = text || 'Idle';
      this.els.liveStatus.classList.remove('is-idle', 'is-waiting', 'is-ok', 'is-error');
      this.els.liveStatus.classList.add(`is-${kind || 'idle'}`);
    },

    log(msg) {
      if (!this.els.log) return;
      const t = new Date().toLocaleTimeString();
      this.els.log.textContent += `[${t}] ${msg}\n`;
      this.els.log.scrollTop = this.els.log.scrollHeight;
    },

    async request(payload) {
      const body = new URLSearchParams();
      Object.keys(payload).forEach((k) => {
        const v = payload[k];
        if (v !== undefined && v !== null) {
          body.append(k, String(v));
        }
      });

      const res = await fetch('/rwa/api/storage/commit.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'Accept': 'application/json'
        },
        body: body.toString(),
        credentials: 'same-origin'
      });

      const text = await res.text();

      let json;
      try {
        json = JSON.parse(text);
      } catch (_) {
        throw new Error('NON_JSON_RESPONSE');
      }

      return json;
    },

    renderQr(text) {
      if (!this.els.qr) return;
      this.els.qr.innerHTML = '';

      if (!text) return;

      const img = document.createElement('img');
      img.alt = 'QR';
      img.loading = 'lazy';
      img.src = '/rwa/inc/core/qr.php?text=' + encodeURIComponent(text) + '&size=320';
      this.els.qr.appendChild(img);
    },

    async prepare() {
      if (this.state.busyPrepare) return;

      const amount = (this.els.amount?.value || '').trim();
      const csrf = this.els.csrf?.value || '';

      if (!amount) {
        this.log('Amount required.');
        this.setLiveStatus('Amount required', 'error');
        return;
      }

      this.state.busyPrepare = true;
      this.setLiveStatus('Preparing', 'waiting');
      this.log('Preparing commit...');

      try {
        const json = await this.request({
          action: 'prepare',
          amount,
          csrf
        });

        if (!json || json.ok !== true) {
          this.log(`Prepare failed: ${json?.error || json?.message || 'UNKNOWN_ERROR'}`);
          this.setLiveStatus('Prepare failed', 'error');
          return;
        }

        this.state.commitRef = json.commit_ref || '';
        this.state.deeplink = json.deeplink || '';
        this.state.qrText = json.qr_text || '';

        if (this.els.ref) {
          this.els.ref.textContent = this.state.commitRef || '-';
        }

        if (this.els.link) {
          this.els.link.href = this.state.deeplink || '#';
          this.els.link.textContent = this.state.deeplink || '-';
        }

        this.renderQr(this.state.qrText);
        this.log(`Prepared: ${this.state.commitRef}`);
        this.setLiveStatus('Prepared', 'ok');
      } catch (e) {
        this.log(`Prepare failed: ${e.message || 'REQUEST_FAILED'}`);
        this.setLiveStatus('Prepare failed', 'error');
      } finally {
        this.state.busyPrepare = false;
      }
    },

    async verify() {
      if (this.state.busyVerify) return false;

      const commitRef = this.state.commitRef || (this.els.ref?.textContent || '').trim();
      const csrf = this.els.csrf?.value || '';

      if (!commitRef || commitRef === '-') {
        this.log('Commit ref required before verify.');
        this.setLiveStatus('Missing ref', 'error');
        return false;
      }

      this.state.busyVerify = true;
      this.setLiveStatus('Detecting', 'waiting');
      this.log(`Verifying ${commitRef}...`);

      try {
        const json = await this.request({
          action: 'verify',
          commit_ref: commitRef,
          csrf
        });

        if (!json || json.ok !== true) {
          this.log(`Verify failed: ${json?.error || json?.message || 'UNKNOWN_ERROR'}`);
          this.setLiveStatus('Waiting chain', 'waiting');
          return false;
        }

        const status = json.status || 'OK';
        const tx = json.tx_hash || '-';
        const reward = json.ema_reward || '';

        this.log(`${status} · TX: ${tx}${reward ? ' · EMA: ' + reward : ''}`);

        if (status === 'CONFIRMED' || status === 'ALREADY_CONFIRMED') {
          this.stopAutoRefresh(true);
          this.setLiveStatus('Confirmed', 'ok');
        } else {
          this.setLiveStatus(status, 'waiting');
        }

        if (typeof window.loadStorageOverview === 'function') {
          window.loadStorageOverview();
        }
        if (typeof window.loadStorageHistory === 'function') {
          window.loadStorageHistory();
        }

        return true;
      } catch (e) {
        this.log(`Verify failed: ${e.message || 'REQUEST_FAILED'}`);
        this.setLiveStatus('Verify failed', 'error');
        return false;
      } finally {
        this.state.busyVerify = false;
      }
    },

    async autoRefreshTick() {
      if (!this.state.autoRefreshOn) return;
      await this.verify();
      if (!this.state.autoRefreshOn) return;
      this.state.autoRefreshTimer = window.setTimeout(() => this.autoRefreshTick(), this.state.autoRefreshMs);
    },

    startAutoRefresh() {
      const ref = this.state.commitRef || (this.els.ref?.textContent || '').trim();
      if (!ref || ref === '-') {
        this.log('Prepare commit first before auto refresh.');
        this.setLiveStatus('Prepare first', 'error');
        return;
      }

      this.stopAutoRefresh(true);
      this.state.autoRefreshOn = true;

      if (this.els.btnAutoRefresh) {
        this.els.btnAutoRefresh.textContent = 'STOP AUTO REFRESH';
        this.els.btnAutoRefresh.classList.add('is-live');
      }

      this.log('Auto refresh started (5s).');
      this.setLiveStatus('Auto polling', 'waiting');
      this.state.autoRefreshTimer = window.setTimeout(() => this.autoRefreshTick(), this.state.autoRefreshMs);
    },

    stopAutoRefresh(silent) {
      this.state.autoRefreshOn = false;
      if (this.state.autoRefreshTimer) {
        window.clearTimeout(this.state.autoRefreshTimer);
        this.state.autoRefreshTimer = null;
      }
      if (this.els.btnAutoRefresh) {
        this.els.btnAutoRefresh.textContent = 'AUTO REFRESH (5s)';
        this.els.btnAutoRefresh.classList.remove('is-live');
      }
      if (!silent) {
        this.log('Auto refresh stopped.');
        this.setLiveStatus('Idle', 'idle');
      }
    },

    toggleAutoRefresh() {
      if (this.state.autoRefreshOn) {
        this.stopAutoRefresh(false);
      } else {
        this.startAutoRefresh();
      }
    }
  };

  document.addEventListener('DOMContentLoaded', () => Commit.init());
  window.Commit = Commit;
})();