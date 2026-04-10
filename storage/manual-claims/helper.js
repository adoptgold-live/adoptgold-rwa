/*!
 * /var/www/html/public/rwa/storage/manual-claims/helper.js
 * Storage Manual Claims UI Integration
 * FINAL-LOCK-1
 *
 * Purpose:
 * - bind Storage UI claim/fuel buttons to shared manual-claims API
 * - auto-detect canonical API base path
 * - map visible Storage cards to locked flow_type values
 * - submit request using full displayed amount
 * - show live status / pending state / recent history
 *
 * Locked flow_type mapping:
 *   claim_ema
 *   claim_wems
 *   claim_usdt_ton
 *   claim_emx_tips
 *   fuel_ems
 */

(function () {
  'use strict';

  const MOD = {
    state: {
      apiBase: '',
      history: [],
      pendingByFlow: Object.create(null),
      busyByFlow: Object.create(null),
      rows: [],
      root: null,
    },

    cfg: {
      apiCandidates: [
        '/rwa/api/storage/manual-claims',
        '/rwa/api/storage/manual-claims/manual-claims'
      ],
      labels: {
        claim_ema: 'EMA$',
        claim_wems: 'wEMS',
        claim_usdt_ton: 'USDT-TON',
        claim_emx_tips: 'EMX',
        fuel_ems: 'EMS'
      },
      flowMapByTitle: [
        { test: /unclaimed\s+ema/i, flow: 'claim_ema' },
        { test: /unclaimed\s+web\s+gold\s+wems/i, flow: 'claim_wems' },
        { test: /unclaimed\s+gold\s+packet\s+usdt[\s-]*ton/i, flow: 'claim_usdt_ton' },
        { test: /unclaimed\s+tips\s+emx/i, flow: 'claim_emx_tips' },
        { test: /fuel\s*up\s*ems/i, flow: 'fuel_ems' }
      ],
      pendingStatuses: new Set(['requested', 'approved', 'proof_submitted']),
      finalStatuses: new Set(['paid', 'rejected', 'failed', 'cancelled'])
    },

    async init() {
      this.state.root = this.findRoot();
      this.injectStyles();
      this.collectRows();
      if (!this.state.rows.length) return;

      this.renderBootState('Loading manual claims…');

      try {
        this.state.apiBase = await this.resolveApiBase();
      } catch (err) {
        this.renderBootState('Manual claims API unavailable.');
        console.error('[manual-claims] resolveApiBase failed', err);
        return;
      }

      await this.refresh();
      this.bind();
    },

    findRoot() {
      return (
        document.querySelector('[data-manual-claims-root]') ||
        document.querySelector('[data-offchain-unclaimed-root]') ||
        document.querySelector('.offchain-unclaimed') ||
        document.querySelector('.storage-offchain-unclaimed') ||
        document.querySelector('main') ||
        document.body
      );
    },

    collectRows() {
      const rows = [];
      const seen = new Set();

      const explicitRows = Array.from(document.querySelectorAll('[data-manual-claim-row]'));
      explicitRows.forEach((el) => {
        const flow = (el.getAttribute('data-manual-claim-flow') || '').trim();
        if (!flow) return;
        const row = this.buildRowFromElement(el, flow);
        if (row) {
          rows.push(row);
          seen.add(el);
        }
      });

      const buttons = Array.from(document.querySelectorAll('button, a'));
      buttons.forEach((btn) => {
        if (seen.has(btn)) return;
        const txt = this.cleanText(btn.textContent || '');
        if (!/claim\s*now/i.test(txt) && !/fuel/i.test(txt)) return;

        const card = this.findRowContainer(btn);
        if (!card || seen.has(card)) return;

        const title = this.extractTitleFromCard(card);
        const flow = this.detectFlowFromTitle(title) || (btn.getAttribute('data-manual-claim-flow') || '').trim();
        if (!flow) return;

        const row = this.buildRowFromElement(card, flow, btn);
        if (row) {
          rows.push(row);
          seen.add(card);
        }
      });

      this.state.rows = rows;
    },

    buildRowFromElement(container, flow, preferredBtn) {
      const button =
        preferredBtn ||
        container.querySelector('[data-manual-claim-button]') ||
        Array.from(container.querySelectorAll('button, a')).find((el) => {
          const t = this.cleanText(el.textContent || '');
          return /claim\s*now/i.test(t) || /fuel/i.test(t);
        });

      if (!button) return null;

      const title = this.extractTitleFromCard(container);
      const amountText = this.extractAmountText(container);
      const statusHost =
        container.querySelector('[data-manual-claim-status]') ||
        this.createStatusHost(container);

      const historyHost =
        container.querySelector('[data-manual-claim-history]') ||
        this.createHistoryHost(container);

      button.setAttribute('data-manual-claim-bound', '1');
      button.setAttribute('data-manual-claim-flow', flow);

      return {
        flow,
        container,
        button,
        title,
        statusHost,
        historyHost,
        amountText,
      };
    },

    findRowContainer(btn) {
      return (
        btn.closest('[data-manual-claim-row]') ||
        btn.closest('.claim-row') ||
        btn.closest('.offchain-row') ||
        btn.closest('.storage-row') ||
        btn.closest('.card') ||
        btn.closest('section > div') ||
        btn.parentElement
      );
    },

    extractTitleFromCard(card) {
      const explicit =
        card.getAttribute('data-manual-claim-title') ||
        card.querySelector('[data-manual-claim-title]')?.textContent ||
        card.querySelector('.claim-title')?.textContent ||
        card.querySelector('.row-title')?.textContent ||
        '';
      if (explicit && this.cleanText(explicit)) return this.cleanText(explicit);

      const textNodes = [];
      Array.from(card.querySelectorAll('*')).forEach((el) => {
        const txt = this.cleanText(el.textContent || '');
        if (!txt) return;
        if (/claim\s*now/i.test(txt)) return;
        if (/^\d+(\.\d+)?$/.test(txt)) return;
        if (txt.length > 80) return;
        textNodes.push(txt);
      });

      return textNodes[0] || '';
    },

    detectFlowFromTitle(title) {
      const t = this.cleanText(title);
      if (!t) return '';
      for (const rule of this.cfg.flowMapByTitle) {
        if (rule.test.test(t)) return rule.flow;
      }
      return '';
    },

    extractAmountText(card) {
      const explicit =
        card.getAttribute('data-manual-claim-amount') ||
        card.querySelector('[data-manual-claim-amount]')?.textContent ||
        card.querySelector('.claim-amount')?.textContent ||
        card.querySelector('.amount')?.textContent ||
        '';
      const cleaned = this.extractNumeric(explicit);
      if (cleaned) return cleaned;

      const texts = Array.from(card.querySelectorAll('*'))
        .map((el) => this.cleanText(el.textContent || ''))
        .filter(Boolean);

      for (const txt of texts) {
        const found = this.extractNumeric(txt);
        if (found) return found;
      }
      return '0';
    },

    extractNumeric(input) {
      const s = String(input || '').replace(/,/g, ' ').trim();
      const m = s.match(/(\d+(?:\.\d+)?)/);
      return m ? m[1] : '';
    },

    cleanText(input) {
      return String(input || '').replace(/\s+/g, ' ').trim();
    },

    createStatusHost(container) {
      const host = document.createElement('div');
      host.className = 'mc-status-host';
      host.setAttribute('data-manual-claim-status', '1');
      container.appendChild(host);
      return host;
    },

    createHistoryHost(container) {
      const host = document.createElement('div');
      host.className = 'mc-history-host';
      host.setAttribute('data-manual-claim-history', '1');
      container.appendChild(host);
      return host;
    },

    injectStyles() {
      if (document.getElementById('mc-helper-styles')) return;
      const style = document.createElement('style');
      style.id = 'mc-helper-styles';
      style.textContent = [
        '.mc-status-host{margin-top:8px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;}',
        '.mc-history-host{margin-top:8px;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;opacity:.92;}',
        '.mc-chip{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;border:1px solid rgba(50,255,160,.28);background:rgba(6,18,16,.72);color:#d7ffe9;font-size:12px;line-height:1.2;}',
        '.mc-chip.is-pending{border-color:rgba(255,200,70,.40);color:#ffe8a3;}',
        '.mc-chip.is-paid{border-color:rgba(80,210,255,.38);color:#d8f6ff;}',
        '.mc-chip.is-rejected,.mc-chip.is-failed,.mc-chip.is-cancelled{border-color:rgba(255,100,120,.35);color:#ffd2d8;}',
        '.mc-chip.is-info{border-color:rgba(120,160,255,.32);color:#dbe6ff;}',
        '.mc-mini{font-size:11px;opacity:.9;}',
        '.mc-link{color:inherit;text-decoration:none;border-bottom:1px dotted currentColor;}',
        '.mc-btn-busy{opacity:.66;pointer-events:none;}',
        '.mc-btn-disabled{opacity:.55;pointer-events:none;}'
      ].join('');
      document.head.appendChild(style);
    },

    renderBootState(message) {
      this.state.rows.forEach((row) => {
        row.statusHost.innerHTML = '';
        row.historyHost.innerHTML = '';
        row.statusHost.appendChild(this.makeChip(message, 'is-info'));
      });
    },

    makeChip(text, klass) {
      const el = document.createElement('span');
      el.className = 'mc-chip' + (klass ? (' ' + klass) : '');
      el.textContent = text;
      return el;
    },

    makeMini(text) {
      const el = document.createElement('span');
      el.className = 'mc-mini';
      el.textContent = text;
      return el;
    },

    async resolveApiBase() {
      for (const base of this.cfg.apiCandidates) {
        try {
          const url = base.replace(/\/+$/, '') + '/history.php?limit=1';
          const res = await fetch(url, {
            method: 'GET',
            credentials: 'include',
            headers: { 'Accept': 'application/json' }
          });

          const text = await res.text();
          const json = this.safeJson(text);
          if (json && typeof json === 'object' && Object.prototype.hasOwnProperty.call(json, 'ok')) {
            return base.replace(/\/+$/, '');
          }
        } catch (err) {
        }
      }
      throw new Error('No manual-claims API candidate responded with JSON.');
    },

    safeJson(text) {
      try {
        return JSON.parse(text);
      } catch (err) {
        return null;
      }
    },

    async refresh() {
      const history = await this.loadHistory();
      this.state.history = history;
      this.state.pendingByFlow = this.buildPendingMap(history);
      this.render();
    },

    buildPendingMap(items) {
      const map = Object.create(null);
      items.forEach((item) => {
        const flow = String(item.flow_type || '').trim();
        if (!flow) return;
        const status = String(item.status || '').trim();
        if (this.cfg.pendingStatuses.has(status)) {
          if (!map[flow]) map[flow] = [];
          map[flow].push(item);
        }
      });
      return map;
    },

    async loadHistory() {
      if (!this.state.apiBase) return [];
      const url = this.state.apiBase + '/history.php?limit=100';
      const res = await fetch(url, {
        method: 'GET',
        credentials: 'include',
        headers: { 'Accept': 'application/json' }
      });
      const json = this.safeJson(await res.text());
      if (!json || typeof json !== 'object') return [];
      if (json.ok !== true) {
        if (json.code === 'AUTH_REQUIRED') return [];
        return [];
      }
      return Array.isArray(json.items) ? json.items : [];
    },

    bind() {
      this.state.rows.forEach((row) => {
        if (row.button.__mcBound) return;
        row.button.__mcBound = true;

        row.button.addEventListener('click', async (ev) => {
          ev.preventDefault();
          ev.stopPropagation();

          const flow = row.flow;
          if (!flow) return;

          if (this.state.busyByFlow[flow]) return;

          const pending = this.state.pendingByFlow[flow] || [];
          if (pending.length > 0) {
            this.renderRow(row);
            return;
          }

          const amount = this.resolveRequestAmount(row);
          if (!amount || Number(amount) <= 0) {
            this.flashRow(row, 'Nothing available to request.', 'is-rejected');
            return;
          }

          const ok = window.confirm(
            'Submit manual request for ' +
              this.cfg.labels[flow] +
              ' amount ' +
              amount +
              '?'
          );
          if (!ok) return;

          await this.submitRequest(row, amount);
        });
      });
    },

    resolveRequestAmount(row) {
      const explicitBtn = row.button.getAttribute('data-manual-claim-amount');
      const explicitRow = row.container.getAttribute('data-manual-claim-amount');
      const explicit = this.extractNumeric(explicitBtn || explicitRow || '');
      if (explicit) return explicit;

      const liveText = this.extractAmountText(row.container);
      row.amountText = liveText || row.amountText || '0';
      return row.amountText || '0';
    },

    resolveCsrfToken() {
      const selectors = [
        '[data-csrf-token]',
        'input[name="csrf_token"]',
        'input[name="_csrf"]',
        'meta[name="csrf-token"]',
        'meta[name="csrf_token"]'
      ];
      for (const sel of selectors) {
        const el = document.querySelector(sel);
        if (!el) continue;
        const val =
          el.getAttribute('data-csrf-token') ||
          el.getAttribute('value') ||
          el.getAttribute('content') ||
          '';
        if (val) return val;
      }
      if (window.CSRF_TOKEN) return String(window.CSRF_TOKEN);
      if (window.__CSRF_TOKEN__) return String(window.__CSRF_TOKEN__);
      return '';
    },

    async submitRequest(row, amount) {
      const flow = row.flow;
      const csrf = this.resolveCsrfToken();
      if (!csrf) {
        this.flashRow(row, 'Missing CSRF token.', 'is-rejected');
        return;
      }

      this.state.busyByFlow[flow] = true;
      row.button.classList.add('mc-btn-busy');
      this.flashRow(row, 'Submitting request…', 'is-info');

      try {
        const res = await fetch(this.state.apiBase + '/request.php', {
          method: 'POST',
          credentials: 'include',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          },
          body: JSON.stringify({
            flow_type: flow,
            amount: amount,
            csrf_token: csrf
          })
        });

        const json = this.safeJson(await res.text());
        if (!json || typeof json !== 'object') {
          throw new Error('Non-JSON response from request.php');
        }

        if (json.ok !== true) {
          const msg = json.message || json.code || 'Request failed.';
          this.flashRow(row, msg, 'is-rejected');
          return;
        }

        await this.refresh();
        this.flashRow(
          row,
          'Request submitted: ' + (json.request_uid || ''),
          'is-pending'
        );
      } catch (err) {
        console.error('[manual-claims] submitRequest failed', err);
        this.flashRow(row, 'Request failed. Please retry.', 'is-rejected');
      } finally {
        this.state.busyByFlow[flow] = false;
        row.button.classList.remove('mc-btn-busy');
      }
    },

    render() {
      this.state.rows.forEach((row) => this.renderRow(row));
    },

    renderRow(row) {
      const flow = row.flow;
      const items = this.state.history.filter((it) => String(it.flow_type || '') === flow);
      const latest = items[0] || null;
      const pending = (this.state.pendingByFlow[flow] || [])[0] || null;

      row.statusHost.innerHTML = '';
      row.historyHost.innerHTML = '';

      if (pending) {
        row.statusHost.appendChild(
          this.makeChip(
            'Pending: ' + this.prettyStatus(pending.status),
            'is-pending'
          )
        );
        row.statusHost.appendChild(
          this.makeMini((pending.request_uid || '') + ' · ' + (pending.amount_display || pending.amount_units || '0'))
        );
        row.button.classList.add('mc-btn-disabled');
        row.button.setAttribute('aria-disabled', 'true');
      } else {
        row.button.classList.remove('mc-btn-disabled');
        row.button.removeAttribute('aria-disabled');
      }

      if (!pending && latest) {
        row.statusHost.appendChild(
          this.makeChip(
            'Latest: ' + this.prettyStatus(latest.status),
            this.statusClass(latest.status)
          )
        );
        row.statusHost.appendChild(
          this.makeMini((latest.request_uid || '') + ' · ' + (latest.amount_display || latest.amount_units || '0'))
        );
      }

      if (latest) {
        const line = [];
        if (latest.created_at) line.push('Requested ' + latest.created_at);
        if (latest.proof_tx_hash) line.push(this.linkTx('Proof', latest.proof_tx_hash));
        if (latest.payout_tx_hash) line.push(this.linkTx('Payout', latest.payout_tx_hash));

        if (line.length) {
          const wrap = document.createElement('div');
          wrap.className = 'mc-mini';
          line.forEach((part, idx) => {
            if (typeof part === 'string') {
              wrap.appendChild(document.createTextNode(part));
            } else {
              wrap.appendChild(part);
            }
            if (idx < line.length - 1) {
              wrap.appendChild(document.createTextNode(' · '));
            }
          });
          row.historyHost.appendChild(wrap);
        }

        if (latest.reject_reason && latest.status === 'rejected') {
          const reject = document.createElement('div');
          reject.className = 'mc-mini';
          reject.textContent = 'Reason: ' + latest.reject_reason;
          row.historyHost.appendChild(reject);
        }
      }

      if (!latest && !pending) {
        row.statusHost.appendChild(this.makeChip('Ready to request', 'is-info'));
      }
    },

    flashRow(row, text, klass) {
      row.statusHost.innerHTML = '';
      row.statusHost.appendChild(this.makeChip(text, klass || 'is-info'));
    },

    prettyStatus(status) {
      const s = String(status || '').replace(/_/g, ' ').trim();
      return s.replace(/\b\w/g, (m) => m.toUpperCase());
    },

    statusClass(status) {
      const s = String(status || '').trim();
      if (this.cfg.pendingStatuses.has(s)) return 'is-pending';
      if (s === 'paid') return 'is-paid';
      if (s === 'rejected' || s === 'failed' || s === 'cancelled') return 'is-rejected';
      return 'is-info';
    },

    linkTx(label, hash) {
      const a = document.createElement('a');
      a.className = 'mc-link';
      a.target = '_blank';
      a.rel = 'noopener noreferrer';
      a.href = 'https://tonviewer.com/transaction/' + encodeURIComponent(hash);
      a.textContent = label;
      return a;
    }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => MOD.init());
  } else {
    MOD.init();
  }

  window.StorageManualClaims = MOD;
})();
