(function () {
  'use strict';

  const CFG = {
    apiBase: (window.MANUAL_CLAIMS_REPORT && window.MANUAL_CLAIMS_REPORT.apiBase) || '/rwa/api/storage/manual-claims',
    tonviewerTxBase: 'https://tonviewer.com/transaction/'
  };

  const STATE = {
    items: [],
    selected: null,
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

  function safeJson(text) {
    try {
      return JSON.parse(text);
    } catch (err) {
      return null;
    }
  }

  function setStatus(msg, isError) {
    if (!STATE.els.statusBar) return;
    STATE.els.statusBar.textContent = msg || '';
    STATE.els.statusBar.style.color = isError ? '#ffd2d8' : '#a4cdb8';
  }

  function buildQuery() {
    const p = new URLSearchParams();
    if (STATE.els.status.value) p.set('status', STATE.els.status.value);
    if (STATE.els.flowType.value) p.set('flow_type', STATE.els.flowType.value);
    if (STATE.els.requestUid.value.trim()) p.set('request_uid', STATE.els.requestUid.value.trim());
    if (STATE.els.userId.value.trim()) p.set('user_id', STATE.els.userId.value.trim());
    if (STATE.els.limit.value) p.set('limit', STATE.els.limit.value);
    return p.toString();
  }

  function badge(status) {
    return '<span class="mcr-badge ' + escapeHtml(status) + '">' + escapeHtml(status) + '</span>';
  }

  function txLink(hash, label) {
    if (!hash) return '';
    return '<a class="mcr-link" href="' + CFG.tonviewerTxBase + encodeURIComponent(hash) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(label || 'TX') + '</a>';
  }

  function rowHtml(item) {
    const claim = [
      item.claim_ref ? ('Ref: ' + escapeHtml(item.claim_ref)) : '',
      item.claim_nonce ? ('Nonce: ' + escapeHtml(item.claim_nonce)) : ''
    ].filter(Boolean).join('<br>');

    const proof = [
      item.proof_contract ? ('Contract: ' + escapeHtml(item.proof_contract)) : '',
      item.proof_tx_hash ? txLink(item.proof_tx_hash, 'Proof TX') : ''
    ].filter(Boolean).join('<br>');

    const payout = [
      item.payout_wallet ? ('Wallet: ' + escapeHtml(item.payout_wallet)) : '',
      item.payout_tx_hash ? txLink(item.payout_tx_hash, 'Payout TX') : ''
    ].filter(Boolean).join('<br>');

    return ''
      + '<tr class="mcr-select-row" data-uid="' + escapeHtml(item.request_uid) + '">'
      +   '<td><strong>' + escapeHtml(item.request_uid) + '</strong></td>'
      +   '<td>' + escapeHtml(item.user_id) + '</td>'
      +   '<td>' + escapeHtml(item.flow_type) + '</td>'
      +   '<td>' + escapeHtml(item.settle_token || '') + '</td>'
      +   '<td>' + escapeHtml(item.amount_display || item.amount_units || '0') + '<br><span class="mcr-mini">' + escapeHtml(item.amount_units || '') + ' units</span></td>'
      +   '<td>' + badge(item.status || '') + '</td>'
      +   '<td>' + (claim || '<span class="mcr-mini">—</span>') + '</td>'
      +   '<td>' + (proof || '<span class="mcr-mini">—</span>') + '</td>'
      +   '<td>' + (payout || '<span class="mcr-mini">—</span>') + '</td>'
      +   '<td>' + escapeHtml(item.approved_at || '') + '</td>'
      +   '<td>' + escapeHtml(item.paid_at || '') + '</td>'
      +   '<td>' + escapeHtml(item.created_at || '') + '</td>'
      + '</tr>';
  }

  function updateSummary() {
    const counts = {
      requested: 0,
      approved: 0,
      proof_submitted: 0,
      paid: 0,
      rejected: 0
    };

    STATE.items.forEach((item) => {
      const s = String(item.status || '');
      if (Object.prototype.hasOwnProperty.call(counts, s)) {
        counts[s] += 1;
      }
    });

    STATE.els.requestedCount.textContent = String(counts.requested);
    STATE.els.approvedCount.textContent = String(counts.approved);
    STATE.els.proofCount.textContent = String(counts.proof_submitted);
    STATE.els.paidCount.textContent = String(counts.paid);
    STATE.els.rejectedCount.textContent = String(counts.rejected);
  }

  function renderTable() {
    if (!STATE.els.tableBody) return;
    if (!STATE.items.length) {
      STATE.els.tableBody.innerHTML = '<tr><td colspan="12" class="mcr-empty">No rows found.</td></tr>';
      return;
    }
    STATE.els.tableBody.innerHTML = STATE.items.map(rowHtml).join('');
  }

  function setText(el, value) {
    if (!el) return;
    el.textContent = String(value == null ? '' : value);
  }

  function setHtml(el, value) {
    if (!el) return;
    el.innerHTML = value || '';
  }

  function renderSelected() {
    if (!STATE.selected) {
      STATE.els.selectedEmpty.hidden = false;
      STATE.els.detailWrap.hidden = true;
      return;
    }

    const x = STATE.selected;
    STATE.els.selectedEmpty.hidden = true;
    STATE.els.detailWrap.hidden = false;

    setText(STATE.els.metaRequestUid, x.request_uid);
    setText(STATE.els.metaFlowType, x.flow_type);
    setText(STATE.els.metaUserId, x.user_id);
    setHtml(STATE.els.metaStatus, badge(x.status || ''));
    setText(STATE.els.metaWallet, x.wallet_address || '');
    setText(STATE.els.metaRecipient, x.recipient_owner || '');
    setText(STATE.els.metaRequestToken, x.request_token || '');
    setText(STATE.els.metaSettleToken, x.settle_token || '');
    setText(STATE.els.metaAmountDisplay, x.amount_display || '');
    setText(STATE.els.metaAmountUnits, x.amount_units || '');
    setText(STATE.els.metaClaimRef, x.claim_ref || '');
    setText(STATE.els.metaClaimNonce, x.claim_nonce || '');
    setText(STATE.els.metaProofContract, x.proof_contract || '');
    setHtml(STATE.els.metaProofTx, x.proof_tx_hash ? txLink(x.proof_tx_hash, x.proof_tx_hash) : '—');
    setText(STATE.els.metaPayoutWallet, x.payout_wallet || '');
    setHtml(STATE.els.metaPayoutTx, x.payout_tx_hash ? txLink(x.payout_tx_hash, x.payout_tx_hash) : '—');
    setText(STATE.els.metaApprovedAt, x.approved_at || '');
    setText(STATE.els.metaPaidAt, x.paid_at || '');
    setText(STATE.els.metaRejectedAt, x.rejected_at || '');
    setText(STATE.els.metaCreatedAt, x.created_at || '');

    let metaObj = x.meta;
    if (typeof metaObj === 'string') {
      try {
        metaObj = JSON.parse(metaObj);
      } catch (err) {
        metaObj = x.meta;
      }
    }
    STATE.els.metaJson.textContent = typeof metaObj === 'string'
      ? metaObj
      : JSON.stringify(metaObj || {}, null, 2);
  }

  function selectRow(uid) {
    STATE.selected = STATE.items.find((x) => x.request_uid === uid) || null;
    renderSelected();
  }

  async function loadList() {
    if (STATE.busy) return;
    STATE.busy = true;
    setStatus('Loading report…');

    try {
      const url = CFG.apiBase + '/admin-list.php' + (buildQuery() ? ('?' + buildQuery()) : '');
      const res = await fetch(url, {
        credentials: 'include',
        headers: { 'Accept': 'application/json' }
      });

      const data = safeJson(await res.text());
      if (!data || data.ok !== true) {
        throw new Error(data && (data.message || data.code) || 'admin-list failed');
      }

      STATE.items = Array.isArray(data.items) ? data.items : [];
      renderTable();
      updateSummary();

      if (STATE.selected) {
        const found = STATE.items.find((x) => x.request_uid === STATE.selected.request_uid);
        STATE.selected = found || null;
      }
      renderSelected();

      setStatus('Loaded ' + STATE.items.length + ' row(s).');
    } catch (err) {
      console.error(err);
      STATE.items = [];
      renderTable();
      updateSummary();
      STATE.selected = null;
      renderSelected();
      setStatus(String(err.message || err), true);
    } finally {
      STATE.busy = false;
    }
  }

  function bind() {
    STATE.els.refreshBtn.addEventListener('click', loadList);
    STATE.els.resetBtn.addEventListener('click', function () {
      STATE.els.status.value = '';
      STATE.els.flowType.value = '';
      STATE.els.requestUid.value = '';
      STATE.els.userId.value = '';
      STATE.els.limit.value = '100';
      loadList();
    });

    STATE.els.tableBody.addEventListener('click', function (ev) {
      const tr = ev.target.closest('.mcr-select-row');
      if (!tr) return;
      selectRow(tr.getAttribute('data-uid'));
    });
  }

  function cache() {
    STATE.els.status = $('mcrStatus');
    STATE.els.flowType = $('mcrFlowType');
    STATE.els.requestUid = $('mcrRequestUid');
    STATE.els.userId = $('mcrUserId');
    STATE.els.limit = $('mcrLimit');
    STATE.els.refreshBtn = $('mcrRefreshBtn');
    STATE.els.resetBtn = $('mcrResetBtn');
    STATE.els.statusBar = $('mcrStatusBar');
    STATE.els.tableBody = $('mcrTableBody');

    STATE.els.requestedCount = $('mcrRequestedCount');
    STATE.els.approvedCount = $('mcrApprovedCount');
    STATE.els.proofCount = $('mcrProofCount');
    STATE.els.paidCount = $('mcrPaidCount');
    STATE.els.rejectedCount = $('mcrRejectedCount');

    STATE.els.selectedEmpty = $('mcrSelectedEmpty');
    STATE.els.detailWrap = $('mcrDetailWrap');
    STATE.els.metaRequestUid = $('mcrMetaRequestUid');
    STATE.els.metaFlowType = $('mcrMetaFlowType');
    STATE.els.metaUserId = $('mcrMetaUserId');
    STATE.els.metaStatus = $('mcrMetaStatus');
    STATE.els.metaWallet = $('mcrMetaWallet');
    STATE.els.metaRecipient = $('mcrMetaRecipient');
    STATE.els.metaRequestToken = $('mcrMetaRequestToken');
    STATE.els.metaSettleToken = $('mcrMetaSettleToken');
    STATE.els.metaAmountDisplay = $('mcrMetaAmountDisplay');
    STATE.els.metaAmountUnits = $('mcrMetaAmountUnits');
    STATE.els.metaClaimRef = $('mcrMetaClaimRef');
    STATE.els.metaClaimNonce = $('mcrMetaClaimNonce');
    STATE.els.metaProofContract = $('mcrMetaProofContract');
    STATE.els.metaProofTx = $('mcrMetaProofTx');
    STATE.els.metaPayoutWallet = $('mcrMetaPayoutWallet');
    STATE.els.metaPayoutTx = $('mcrMetaPayoutTx');
    STATE.els.metaApprovedAt = $('mcrMetaApprovedAt');
    STATE.els.metaPaidAt = $('mcrMetaPaidAt');
    STATE.els.metaRejectedAt = $('mcrMetaRejectedAt');
    STATE.els.metaCreatedAt = $('mcrMetaCreatedAt');
    STATE.els.metaJson = $('mcrMetaJson');
  }

  function init() {
    cache();
    bind();
    loadList();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
