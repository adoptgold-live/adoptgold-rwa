(function () {
  'use strict';

  const CFG = {
    apiBase: (window.MANUAL_CLAIMS_PANEL && window.MANUAL_CLAIMS_PANEL.apiBase) || '/rwa/api/storage/manual-claims',
    csrfToken: (window.MANUAL_CLAIMS_PANEL && window.MANUAL_CLAIMS_PANEL.csrfToken) || '',
    tonviewerTxBase: 'https://tonviewer.com/transaction/',
    pendingStatuses: new Set(['requested', 'approved', 'proof_submitted']),
    i18n: (window.MANUAL_CLAIMS_PANEL && window.MANUAL_CLAIMS_PANEL.i18n) || {},
    initialLang: (window.MANUAL_CLAIMS_PANEL && window.MANUAL_CLAIMS_PANEL.initialLang) || 'en'
  };

  const STATE = {
    rows: [],
    items: [],
    latestByFlow: Object.create(null),
    pendingByFlow: Object.create(null),
    els: {},
    busy: false,
    lang: CFG.initialLang === 'zh' ? 'zh' : 'en'
  };

  function q(sel, root) {
    return (root || document).querySelector(sel);
  }

  function qa(sel, root) {
    return Array.from((root || document).querySelectorAll(sel));
  }

  function t(key) {
    const dict = CFG.i18n[STATE.lang] || CFG.i18n.en || {};
    return dict[key] || key;
  }

  function safeJson(text) {
    try {
      return JSON.parse(text);
    } catch (err) {
      return null;
    }
  }

  function escapeHtml(v) {
    return String(v == null ? '' : v)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function setStatus(msg, isError) {
    if (!STATE.els.statusBar) return;
    STATE.els.statusBar.textContent = msg || '';
    STATE.els.statusBar.style.color = isError ? '#ffd2d8' : '#a4cdb8';
  }

  function getCsrf() {
    if (CFG.csrfToken) return CFG.csrfToken;
    const meta = q('meta[name="csrf-token"],meta[name="csrf_token"]');
    if (meta && meta.content) return meta.content;
    const input = q('input[name="csrf_token"]');
    return input ? input.value : '';
  }

  function makeChip(text, klass) {
    return '<span class="mcp-chip ' + (klass || 'is-info') + '">' + escapeHtml(text) + '</span>';
  }

  function badge(status) {
    return '<span class="mcp-badge ' + escapeHtml(status) + '">' + escapeHtml(status) + '</span>';
  }

  function txLink(hash, label) {
    if (!hash) return '';
    return '<a class="mcp-link" target="_blank" rel="noopener noreferrer" href="' +
      CFG.tonviewerTxBase + encodeURIComponent(hash) + '">' + escapeHtml(label || 'TX') + '</a>';
  }

  function prettyStatus(status) {
    const s = String(status || '').replace(/_/g, ' ').trim();
    return s.replace(/\b\w/g, function (m) { return m.toUpperCase(); });
  }

  function statusClass(status) {
    if (CFG.pendingStatuses.has(status)) return 'is-pending';
    if (status === 'paid') return 'is-paid';
    if (status === 'rejected' || status === 'failed' || status === 'cancelled') return 'is-rejected';
    return 'is-info';
  }

  function cacheRows() {
    STATE.rows = qa('[data-manual-claim-row]').map(function (row) {
      return {
        root: row,
        flow: row.getAttribute('data-manual-claim-flow') || '',
        titleEl: q('[data-manual-claim-title]', row),
        amountEl: q('[data-manual-claim-amount]', row),
        button: q('[data-manual-claim-button]', row),
        statusEl: q('[data-manual-claim-status]', row),
        historyEl: q('[data-manual-claim-history]', row)
      };
    }).filter(function (x) {
      return x.flow && x.button;
    });
  }

  function cacheEls() {
    STATE.els.root = q('[data-manual-claims-panel-root]');
    if (!STATE.els.root) return false;

    STATE.els.refresh = q('[data-manual-claims-refresh]', STATE.els.root);
    STATE.els.statusBar = q('[data-manual-claims-panel-status]', STATE.els.root);
    STATE.els.historyBody = q('[data-manual-claims-history-body]', STATE.els.root);
    STATE.els.historyFlow = q('[data-history-flow]', STATE.els.root);
    STATE.els.langEn = q('#mcpLangEnBtn');
    STATE.els.langZh = q('#mcpLangZhBtn');

    return true;
  }

  async function loadHistory() {
    const flow = STATE.els.historyFlow && STATE.els.historyFlow.value
      ? ('?flow_type=' + encodeURIComponent(STATE.els.historyFlow.value) + '&limit=100')
      : '?limit=100';

    const res = await fetch(CFG.apiBase + '/history.php' + flow, {
      credentials: 'include',
      headers: { 'Accept': 'application/json' }
    });

    const data = safeJson(await res.text());
    if (!data || data.ok !== true) {
      throw new Error(data && (data.message || data.code) || 'History load failed');
    }
    return Array.isArray(data.items) ? data.items : [];
  }

  function rebuildMaps() {
    STATE.latestByFlow = Object.create(null);
    STATE.pendingByFlow = Object.create(null);

    STATE.items.forEach(function (item) {
      const flow = String(item.flow_type || '');
      if (!flow) return;

      if (!STATE.latestByFlow[flow]) {
        STATE.latestByFlow[flow] = item;
      }
      if (CFG.pendingStatuses.has(String(item.status || ''))) {
        STATE.pendingByFlow[flow] = item;
      }
    });
  }

  function applyLanguage() {
    document.documentElement.setAttribute('lang', STATE.lang);

    qa('[data-i18n]').forEach(function (el) {
      const key = el.getAttribute('data-i18n');
      if (!key) return;
      el.textContent = t(key);
    });

    qa('[data-manual-claim-title]').forEach(function (el) {
      const txt = el.getAttribute(STATE.lang === 'zh' ? 'data-title-zh' : 'data-title-en');
      if (txt) el.textContent = txt;
    });

    qa('[data-manual-claim-button]').forEach(function (el) {
      const txt = el.getAttribute(STATE.lang === 'zh' ? 'data-button-zh' : 'data-button-en');
      if (txt) el.textContent = txt;
    });

    if (STATE.els.historyFlow) {
      qa('option', STATE.els.historyFlow).forEach(function (opt) {
        const txt = opt.getAttribute(STATE.lang === 'zh' ? 'data-text-zh' : 'data-text-en');
        if (txt) opt.textContent = txt;
      });
    }

    if (STATE.els.langEn) STATE.els.langEn.classList.toggle('is-active', STATE.lang === 'en');
    if (STATE.els.langZh) STATE.els.langZh.classList.toggle('is-active', STATE.lang === 'zh');

    renderRows();
    renderTable();
    setStatus(t('loaded_rows').replace('request(s)', String(STATE.items.length)), false);
  }

  function renderRows() {
    STATE.rows.forEach(function (row) {
      const latest = STATE.latestByFlow[row.flow] || null;
      const pending = STATE.pendingByFlow[row.flow] || null;

      row.statusEl.innerHTML = '';
      row.historyEl.innerHTML = '';

      if (pending) {
        row.statusEl.innerHTML =
          makeChip(t('pending') + ': ' + prettyStatus(pending.status), 'is-pending') +
          '<span class="mcp-mini">' + escapeHtml(pending.request_uid || '') + '</span>';
        row.button.classList.add('mcp-btn-disabled');
        row.button.setAttribute('aria-disabled', 'true');
      } else if (latest) {
        row.statusEl.innerHTML =
          makeChip(t('latest') + ': ' + prettyStatus(latest.status), statusClass(latest.status)) +
          '<span class="mcp-mini">' + escapeHtml(latest.request_uid || '') + '</span>';
        row.button.classList.remove('mcp-btn-disabled');
        row.button.removeAttribute('aria-disabled');
      } else {
        row.statusEl.innerHTML = makeChip(t('ready_to_request'), 'is-info');
        row.button.classList.remove('mcp-btn-disabled');
        row.button.removeAttribute('aria-disabled');
      }

      if (latest) {
        const parts = [];
        if (latest.created_at) parts.push(t('requested') + ' ' + escapeHtml(latest.created_at));
        if (latest.proof_tx_hash) parts.push(txLink(latest.proof_tx_hash, 'Proof'));
        if (latest.payout_tx_hash) parts.push(txLink(latest.payout_tx_hash, 'Payout'));
        if (latest.reject_reason && latest.status === 'rejected') parts.push('Reason: ' + escapeHtml(latest.reject_reason));
        row.historyEl.innerHTML = parts.join(' · ');
      }
    });
  }

  function renderTable() {
    if (!STATE.els.historyBody) return;

    if (!STATE.items.length) {
      STATE.els.historyBody.innerHTML = '<tr><td colspan="7" class="mcp-empty">' + escapeHtml(t('no_requests')) + '</td></tr>';
      return;
    }

    STATE.els.historyBody.innerHTML = STATE.items.map(function (item) {
      const proof = [];
      const payout = [];
      if (item.proof_tx_hash) proof.push(txLink(item.proof_tx_hash, 'Proof TX'));
      if (item.payout_tx_hash) payout.push(txLink(item.payout_tx_hash, 'Payout TX'));

      return ''
        + '<tr>'
        +   '<td>' + escapeHtml(item.request_uid || '') + '</td>'
        +   '<td>' + escapeHtml(item.flow_type || '') + '</td>'
        +   '<td>' + escapeHtml(item.amount_display || item.amount_units || '0') + '</td>'
        +   '<td>' + badge(item.status || '') + '</td>'
        +   '<td>' + (proof.join(' · ') || '<span class="mcp-mini">—</span>') + '</td>'
        +   '<td>' + (payout.join(' · ') || '<span class="mcp-mini">—</span>') + '</td>'
        +   '<td>' + escapeHtml(item.created_at || '') + '</td>'
        + '</tr>';
    }).join('');
  }

  async function refresh() {
    if (STATE.busy) return;
    STATE.busy = true;
    setStatus(t('loading_panel'));

    try {
      STATE.items = await loadHistory();
      rebuildMaps();
      renderRows();
      renderTable();
      setStatus(t('loaded_rows').replace('request(s)', String(STATE.items.length)));
    } catch (err) {
      console.error(err);
      STATE.items = [];
      rebuildMaps();
      renderRows();
      renderTable();
      setStatus(String(err.message || err), true);
    } finally {
      STATE.busy = false;
    }
  }

  function resolveAmount(row) {
    const txt = row.amountEl ? String(row.amountEl.textContent || '').replace(/,/g, '').trim() : '0';
    const m = txt.match(/(\d+(?:\.\d+)?)/);
    return m ? m[1] : '0';
  }

  async function submitRequest(row) {
    if (STATE.pendingByFlow[row.flow]) {
      renderRows();
      return;
    }

    const amount = resolveAmount(row);
    if (!amount || Number(amount) <= 0) {
      row.statusEl.innerHTML = makeChip(t('nothing_available'), 'is-rejected');
      return;
    }

    const ok = window.confirm(t('confirm_submit') + ' ' + amount + '?');
    if (!ok) return;

    const csrf = getCsrf();
    if (!csrf) {
      row.statusEl.innerHTML = makeChip(t('missing_csrf'), 'is-rejected');
      return;
    }

    row.button.classList.add('mcp-btn-busy');
    row.statusEl.innerHTML = makeChip(t('submitting'), 'is-info');

    try {
      const res = await fetch(CFG.apiBase + '/request.php', {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        body: JSON.stringify({
          flow_type: row.flow,
          amount: amount,
          csrf_token: csrf
        })
      });

      const data = safeJson(await res.text());
      if (!data || data.ok !== true) {
        throw new Error(data && (data.message || data.code) || 'Request submission failed');
      }

      await refresh();
      row.statusEl.innerHTML =
        makeChip(t('submitted'), 'is-pending') +
        '<span class="mcp-mini">' + escapeHtml(data.request_uid || '') + '</span>';
    } catch (err) {
      console.error(err);
      row.statusEl.innerHTML = makeChip(String(err.message || err), 'is-rejected');
    } finally {
      row.button.classList.remove('mcp-btn-busy');
    }
  }

  function bind() {
    if (STATE.els.refresh) {
      STATE.els.refresh.addEventListener('click', refresh);
    }

    if (STATE.els.historyFlow) {
      STATE.els.historyFlow.addEventListener('change', refresh);
    }

    if (STATE.els.langEn) {
      STATE.els.langEn.addEventListener('click', function () {
        STATE.lang = 'en';
        applyLanguage();
      });
    }

    if (STATE.els.langZh) {
      STATE.els.langZh.addEventListener('click', function () {
        STATE.lang = 'zh';
        applyLanguage();
      });
    }

    STATE.rows.forEach(function (row) {
      if (row.button.__mcpBound) return;
      row.button.__mcpBound = true;
      row.button.addEventListener('click', function (ev) {
        ev.preventDefault();
        ev.stopPropagation();
        submitRequest(row);
      });
    });
  }

  function init() {
    if (!cacheEls()) return;
    cacheRows();
    bind();
    applyLanguage();
    refresh();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  window.StorageManualClaimsPanel = {
    refresh: refresh
  };
})();
