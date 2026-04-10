(function () {
  'use strict';

  const CFG = {
    apiBase: (window.MANUAL_CLAIMS_ANOMALIES && window.MANUAL_CLAIMS_ANOMALIES.apiBase) || '/rwa/api/storage/manual-claims',
    daysDefault: Number((window.MANUAL_CLAIMS_ANOMALIES && window.MANUAL_CLAIMS_ANOMALIES.daysDefault) || 7) || 7
  };

  const STATE = {
    items: [],
    logs: [],
    autoRows: [],
    busy: false,
    els: {}
  };

  function $(id) {
    return document.getElementById(id);
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

  function safeJson(text) {
    try {
      return JSON.parse(text);
    } catch (err) {
      return null;
    }
  }

  function buildQuery() {
    const p = new URLSearchParams();
    if (STATE.els.flowType.value) p.set('flow_type', STATE.els.flowType.value);
    if (STATE.els.userId.value.trim()) p.set('user_id', STATE.els.userId.value.trim());
    p.set('limit', '500');
    return p.toString();
  }

  function hoursOld(dateStr) {
    if (!dateStr) return null;
    const ts = Date.parse(String(dateStr).replace(' ', 'T') + 'Z');
    if (!Number.isFinite(ts)) return null;
    return Math.max(0, (Date.now() - ts) / 3600000);
  }

  function tdEmpty(cols) {
    return '<tr><td colspan="' + cols + '" class="mca-empty">No rows found.</td></tr>';
  }

  async function loadAdminList() {
    const res = await fetch(CFG.apiBase + '/admin-list.php?' + buildQuery(), {
      credentials: 'include',
      headers: { 'Accept': 'application/json' }
    });
    const data = safeJson(await res.text());
    if (!data || data.ok !== true) {
      throw new Error(data && (data.message || data.code) || 'admin-list failed');
    }
    return Array.isArray(data.items) ? data.items : [];
  }

  async function loadCronLogs() {
    const days = Number(STATE.els.days.value || CFG.daysDefault) || CFG.daysDefault;
    const url = CFG.apiBase + '/admin-list.php?limit=1'; // ping only, keeps same auth boundary
    const ping = await fetch(url, {
      credentials: 'include',
      headers: { 'Accept': 'application/json' }
    });
    const pingData = safeJson(await ping.text());
    if (!pingData || pingData.ok !== true) {
      throw new Error(pingData && (pingData.message || pingData.code) || 'admin-list ping failed');
    }

    const logUrl = '/rwa/api/common/manual-claims-cron-log-proxy.php?days=' + encodeURIComponent(String(days));
    try {
      const res = await fetch(logUrl, {
        credentials: 'include',
        headers: { 'Accept': 'application/json' }
      });
      const data = safeJson(await res.text());
      if (data && data.ok === true && Array.isArray(data.items)) {
        return data.items;
      }
    } catch (err) {
    }
    return [];
  }

  function computeViews() {
    const approvedStale = [];
    const proofStale = [];
    const autoRows = [];
    const days = Number(STATE.els.days.value || CFG.daysDefault) || CFG.daysDefault;
    const daysHours = days * 24;

    STATE.items.forEach((item) => {
      const createdHours = hoursOld(item.created_at);
      const approvedHours = hoursOld(item.approved_at || item.created_at);
      const proofHours = hoursOld(item.created_at);

      if (item.status === 'approved' && approvedHours !== null && approvedHours >= 48) {
        if (!STATE.els.flowType.value || item.flow_type === STATE.els.flowType.value) {
          if (!STATE.els.userId.value.trim() || String(item.user_id) === STATE.els.userId.value.trim()) {
            approvedStale.push(Object.assign({}, item, { hours_old: approvedHours }));
          }
        }
      }

      if (item.status === 'proof_submitted' && proofHours !== null && proofHours >= 72) {
        if (!STATE.els.flowType.value || item.flow_type === STATE.els.flowType.value) {
          if (!STATE.els.userId.value.trim() || String(item.user_id) === STATE.els.userId.value.trim()) {
            proofStale.push(Object.assign({}, item, { hours_old: proofHours }));
          }
        }
      }

      const released = item.status === 'cancelled' || item.status === 'rejected' || item.status === 'failed';
      const reserveSignal = (item.meta && typeof item.meta === 'object' && item.meta.reserve_status) || '';
      const recentEnough = createdHours !== null && createdHours <= daysHours;

      if (recentEnough && (released || item.status === 'paid' || reserveSignal)) {
        autoRows.push(item);
      }
    });

    return { approvedStale, proofStale, autoRows };
  }

  function renderSummary(approvedStale, proofStale, autoRows, logs) {
    STATE.els.approvedStaleCount.textContent = String(approvedStale.length);
    STATE.els.proofStaleCount.textContent = String(proofStale.length);
    STATE.els.autoCancelledCount.textContent = String(autoRows.filter((x) => x.status === 'cancelled').length);
    STATE.els.cronErrorCount.textContent = String(logs.length);
  }

  function renderApproved(rows) {
    if (!rows.length) {
      STATE.els.approvedBody.innerHTML = tdEmpty(8);
      return;
    }
    STATE.els.approvedBody.innerHTML = rows.map((x) => {
      const claim = [
        x.claim_ref ? ('Ref: ' + escapeHtml(x.claim_ref)) : '',
        x.claim_nonce ? ('Nonce: ' + escapeHtml(x.claim_nonce)) : ''
      ].filter(Boolean).join('<br>');
      return ''
        + '<tr>'
        +   '<td>' + escapeHtml(x.request_uid) + '</td>'
        +   '<td>' + escapeHtml(x.user_id) + '</td>'
        +   '<td>' + escapeHtml(x.flow_type) + '</td>'
        +   '<td>' + escapeHtml(x.amount_display || x.amount_units || '0') + '</td>'
        +   '<td>' + escapeHtml((x.hours_old || 0).toFixed(1)) + '</td>'
        +   '<td>' + (claim || '—') + '</td>'
        +   '<td>' + escapeHtml(x.approved_at || '') + '</td>'
        +   '<td>' + escapeHtml(x.created_at || '') + '</td>'
        + '</tr>';
    }).join('');
  }

  function renderProof(rows) {
    if (!rows.length) {
      STATE.els.proofBody.innerHTML = tdEmpty(8);
      return;
    }
    STATE.els.proofBody.innerHTML = rows.map((x) => {
      return ''
        + '<tr>'
        +   '<td>' + escapeHtml(x.request_uid) + '</td>'
        +   '<td>' + escapeHtml(x.user_id) + '</td>'
        +   '<td>' + escapeHtml(x.flow_type) + '</td>'
        +   '<td>' + escapeHtml(x.amount_display || x.amount_units || '0') + '</td>'
        +   '<td>' + escapeHtml((x.hours_old || 0).toFixed(1)) + '</td>'
        +   '<td>' + (x.proof_tx_hash ? '<a class="mca-link" target="_blank" rel="noopener noreferrer" href="https://tonviewer.com/transaction/' + encodeURIComponent(x.proof_tx_hash) + '">Proof TX</a>' : '—') + '</td>'
        +   '<td>' + (x.payout_tx_hash ? '<a class="mca-link" target="_blank" rel="noopener noreferrer" href="https://tonviewer.com/transaction/' + encodeURIComponent(x.payout_tx_hash) + '">Payout TX</a>' : '—') + '</td>'
        +   '<td>' + escapeHtml(x.created_at || '') + '</td>'
        + '</tr>';
    }).join('');
  }

  function renderAuto(rows) {
    if (!rows.length) {
      STATE.els.autoBody.innerHTML = tdEmpty(8);
      return;
    }
    STATE.els.autoBody.innerHTML = rows.map((x) => {
      const reserveStatus = (x.meta && typeof x.meta === 'object' && x.meta.reserve_status) || '';
      return ''
        + '<tr>'
        +   '<td>' + escapeHtml(x.request_uid) + '</td>'
        +   '<td>' + escapeHtml(x.user_id) + '</td>'
        +   '<td>' + escapeHtml(x.flow_type) + '</td>'
        +   '<td>' + escapeHtml(x.status || '') + '</td>'
        +   '<td>' + escapeHtml(reserveStatus || '—') + '</td>'
        +   '<td>' + escapeHtml((x.meta && x.meta.reserve_released_at) || '') + '</td>'
        +   '<td>' + escapeHtml((x.meta && x.meta.reserve_consumed_at) || '') + '</td>'
        +   '<td>' + escapeHtml(x.updated_at || x.created_at || '') + '</td>'
        + '</tr>';
    }).join('');
  }

  function renderLogs(rows) {
    if (!rows.length) {
      STATE.els.logBody.innerHTML = tdEmpty(4);
      return;
    }
    STATE.els.logBody.innerHTML = rows.map((x) => {
      let ctx = x.context || x.meta || {};
      if (typeof ctx === 'string') {
        try { ctx = JSON.parse(ctx); } catch (err) {}
      }
      return ''
        + '<tr>'
        +   '<td>' + escapeHtml(x.created_at || x.timestamp || '') + '</td>'
        +   '<td>' + escapeHtml(x.error_code || x.code || '') + '</td>'
        +   '<td>' + escapeHtml(x.public_hint || x.hint || '') + '</td>'
        +   '<td><pre class="mca-context">' + escapeHtml(typeof ctx === 'string' ? ctx : JSON.stringify(ctx || {}, null, 2)) + '</pre></td>'
        + '</tr>';
    }).join('');
  }

  async function refresh() {
    if (STATE.busy) return;
    STATE.busy = true;
    setStatus('Loading anomaly dashboard…');

    try {
      STATE.items = await loadAdminList();
      STATE.logs = await loadCronLogs();
      const views = computeViews();
      renderSummary(views.approvedStale, views.proofStale, views.autoRows, STATE.logs);
      renderApproved(views.approvedStale);
      renderProof(views.proofStale);
      renderAuto(views.autoRows);
      renderLogs(STATE.logs);
      setStatus('Loaded anomaly dashboard.');
    } catch (err) {
      console.error(err);
      STATE.items = [];
      STATE.logs = [];
      renderSummary([], [], [], []);
      renderApproved([]);
      renderProof([]);
      renderAuto([]);
      renderLogs([]);
      setStatus(String(err.message || err), true);
    } finally {
      STATE.busy = false;
    }
  }

  function bind() {
    STATE.els.refreshBtn.addEventListener('click', refresh);
    STATE.els.resetBtn.addEventListener('click', function () {
      STATE.els.flowType.value = '';
      STATE.els.userId.value = '';
      STATE.els.days.value = String(CFG.daysDefault);
      refresh();
    });
  }

  function cache() {
    STATE.els.approvedStaleCount = $('mcaApprovedStaleCount');
    STATE.els.proofStaleCount = $('mcaProofStaleCount');
    STATE.els.autoCancelledCount = $('mcaAutoCancelledCount');
    STATE.els.cronErrorCount = $('mcaCronErrorCount');

    STATE.els.flowType = $('mcaFlowType');
    STATE.els.userId = $('mcaUserId');
    STATE.els.days = $('mcaDays');
    STATE.els.refreshBtn = $('mcaRefreshBtn');
    STATE.els.resetBtn = $('mcaResetBtn');
    STATE.els.statusBar = $('mcaStatusBar');

    STATE.els.approvedBody = $('mcaApprovedBody');
    STATE.els.proofBody = $('mcaProofBody');
    STATE.els.autoBody = $('mcaAutoBody');
    STATE.els.logBody = $('mcaLogBody');
  }

  function init() {
    cache();
    bind();
    refresh();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
