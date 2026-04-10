(function () {
  'use strict';

  const CFG = {
    apiBase: (window.MANUAL_CLAIMS_ADMIN && window.MANUAL_CLAIMS_ADMIN.apiBase) || '/rwa/api/storage/manual-claims',
    csrfToken: (window.MANUAL_CLAIMS_ADMIN && window.MANUAL_CLAIMS_ADMIN.csrfToken) || '',
    tonviewerTxBase: 'https://tonviewer.com/transaction/',
    statusActions: {
      requested: ['approve', 'reject'],
      approved: ['markProof', 'markPaid'],
      proof_submitted: ['markPaid']
    }
  };

  const STATE = {
    items: [],
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

  function getCsrf() {
    if (CFG.csrfToken) return CFG.csrfToken;
    const meta = document.querySelector('meta[name="csrf-token"],meta[name="csrf_token"]');
    if (meta && meta.content) return meta.content;
    const input = document.querySelector('input[name="csrf_token"]');
    return input ? input.value : '';
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

  async function loadList() {
    if (STATE.busy) return;
    STATE.busy = true;
    setStatus('Loading requests…');

    try {
      const url = CFG.apiBase + '/admin-list.php' + (buildQuery() ? ('?' + buildQuery()) : '');
      const res = await fetch(url, {
        credentials: 'include',
        headers: { 'Accept': 'application/json' }
      });
      const data = await res.json();
      if (!data || data.ok !== true) {
        throw new Error(data && (data.message || data.code) || 'admin-list failed');
      }
      STATE.items = Array.isArray(data.items) ? data.items : [];
      renderTable();
      setStatus('Loaded ' + STATE.items.length + ' request(s).');
    } catch (err) {
      console.error(err);
      STATE.items = [];
      renderTable();
      setStatus(String(err.message || err), true);
    } finally {
      STATE.busy = false;
    }
  }

  function badge(status) {
    return '<span class="mca-badge ' + escapeHtml(status) + '">' + escapeHtml(status) + '</span>';
  }

  function txLink(hash) {
    if (!hash) return '';
    return '<a class="mca-link" href="' + CFG.tonviewerTxBase + encodeURIComponent(hash) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(hash.slice(0, 10) + '…') + '</a>';
  }

  function renderActions(item) {
    const actions = CFG.statusActions[item.status] || [];
    if (!actions.length) return '<span class="mca-mini">—</span>';

    return '<div class="mca-row-actions">' + actions.map((action) => {
      if (action === 'approve') {
        return '<button type="button" class="mca-btn mca-action" data-action="approve" data-uid="' + escapeHtml(item.request_uid) + '">Approve</button>';
      }
      if (action === 'reject') {
        return '<button type="button" class="mca-btn mca-btn-danger mca-action" data-action="reject" data-uid="' + escapeHtml(item.request_uid) + '">Reject</button>';
      }
      if (action === 'markProof') {
        return '<button type="button" class="mca-btn mca-action" data-action="markProof" data-uid="' + escapeHtml(item.request_uid) + '">Mark Proof</button>';
      }
      if (action === 'markPaid') {
        return '<button type="button" class="mca-btn mca-action" data-action="markPaid" data-uid="' + escapeHtml(item.request_uid) + '">Mark Paid</button>';
      }
      return '';
    }).join('') + '</div>';
  }

  function renderTable() {
    const body = STATE.els.tableBody;
    if (!body) return;

    if (!STATE.items.length) {
      body.innerHTML = '<tr><td colspan="12" class="mca-empty">No rows found.</td></tr>';
      return;
    }

    body.innerHTML = STATE.items.map((item) => {
      const recipient = [item.wallet_address || '', item.recipient_owner || ''].filter(Boolean).map(escapeHtml).join('<br>');
      const claimInfo = [
        item.claim_ref ? ('Ref: ' + escapeHtml(item.claim_ref)) : '',
        item.claim_nonce ? ('Nonce: ' + escapeHtml(item.claim_nonce)) : ''
      ].filter(Boolean).join('<br>');
      const proofInfo = [
        item.proof_contract ? ('Contract: ' + escapeHtml(item.proof_contract)) : '',
        item.proof_tx_hash ? txLink(item.proof_tx_hash) : ''
      ].filter(Boolean).join('<br>');
      const payoutInfo = [
        item.payout_wallet ? ('Wallet: ' + escapeHtml(item.payout_wallet)) : '',
        item.payout_tx_hash ? txLink(item.payout_tx_hash) : ''
      ].filter(Boolean).join('<br>');

      return ''
        + '<tr>'
        +   '<td><div class="mca-uid">' + escapeHtml(item.request_uid) + '</div><div class="mca-mini">' + escapeHtml(item.source_bucket || '') + '</div></td>'
        +   '<td>' + escapeHtml(item.user_id) + '</td>'
        +   '<td>' + escapeHtml(item.flow_type) + '</td>'
        +   '<td>' + escapeHtml(item.settle_token) + '</td>'
        +   '<td>' + escapeHtml(item.amount_display) + '<br><span class="mca-mini">' + escapeHtml(item.amount_units) + ' units</span></td>'
        +   '<td>' + (recipient || '<span class="mca-mini">—</span>') + '</td>'
        +   '<td>' + badge(item.status) + '</td>'
        +   '<td>' + (claimInfo || '<span class="mca-mini">—</span>') + '</td>'
        +   '<td>' + (proofInfo || '<span class="mca-mini">—</span>') + '</td>'
        +   '<td>' + (payoutInfo || '<span class="mca-mini">—</span>') + '</td>'
        +   '<td>' + escapeHtml(item.created_at || '') + '</td>'
        +   '<td>' + renderActions(item) + '</td>'
        + '</tr>';
    }).join('');
  }

  function findItem(uid) {
    return STATE.items.find((x) => x.request_uid === uid) || null;
  }

  function openModal(action, uid) {
    const item = findItem(uid);
    if (!item) return;

    STATE.els.actionType.value = action;
    STATE.els.actionRequestUid.value = uid;
    STATE.els.modalMeta.innerHTML =
      '<div class="mca-mini">Request: ' + escapeHtml(uid) + ' · Flow: ' + escapeHtml(item.flow_type) + ' · Status: ' + escapeHtml(item.status) + '</div>';

    let title = 'Action';
    let fields = '';

    if (action === 'approve') {
      title = 'Approve Request';
      fields = ''
        + fieldInput('claim_nonce', 'Claim Nonce', 'number', item.claim_nonce || '')
        + fieldInput('claim_ref', 'Claim Ref', 'text', item.claim_ref || '', 'full')
        + fieldSelect('proof_required', 'Proof Required', [
            { value: '0', label: '0' },
            { value: '1', label: '1' }
          ], String(item.proof_required ? 1 : 0))
        + fieldInput('proof_contract', 'Proof Contract', 'text', item.proof_contract || '', 'full');
    } else if (action === 'reject') {
      title = 'Reject Request';
      fields = fieldTextarea('reason', 'Reject Reason', '', 'full');
    } else if (action === 'markProof') {
      title = 'Mark Proof Submitted';
      fields = ''
        + fieldInput('proof_tx_hash', 'Proof TX Hash', 'text', item.proof_tx_hash || '', 'full')
        + fieldInput('proof_contract', 'Proof Contract', 'text', item.proof_contract || '', 'full');
    } else if (action === 'markPaid') {
      title = 'Mark Paid';
      fields = ''
        + fieldInput('payout_tx_hash', 'Payout TX Hash', 'text', item.payout_tx_hash || '', 'full')
        + fieldInput('payout_wallet', 'Payout Wallet', 'text', item.payout_wallet || '', 'full');
    }

    STATE.els.modalTitle.textContent = title;
    STATE.els.formFields.innerHTML = fields;
    STATE.els.modal.hidden = false;
  }

  function closeModal() {
    STATE.els.modal.hidden = true;
    STATE.els.modalForm.reset();
    STATE.els.formFields.innerHTML = '';
  }

  function fieldInput(name, label, type, value, extraClass) {
    return ''
      + '<label class="mca-field ' + (extraClass || '') + '">'
      +   '<span>' + escapeHtml(label) + '</span>'
      +   '<input type="' + escapeHtml(type) + '" name="' + escapeHtml(name) + '" value="' + escapeHtml(value) + '">'
      + '</label>';
  }

  function fieldSelect(name, label, options, selected) {
    return ''
      + '<label class="mca-field">'
      +   '<span>' + escapeHtml(label) + '</span>'
      +   '<select name="' + escapeHtml(name) + '">'
      +     options.map((opt) => {
              const sel = String(opt.value) === String(selected) ? ' selected' : '';
              return '<option value="' + escapeHtml(opt.value) + '"' + sel + '>' + escapeHtml(opt.label) + '</option>';
            }).join('')
      +   '</select>'
      + '</label>';
  }

  function fieldTextarea(name, label, value, extraClass) {
    return ''
      + '<label class="mca-field ' + (extraClass || '') + '">'
      +   '<span>' + escapeHtml(label) + '</span>'
      +   '<textarea class="mca-textarea" name="' + escapeHtml(name) + '">' + escapeHtml(value || '') + '</textarea>'
      + '</label>';
  }

  function serializeForm(form) {
    const fd = new FormData(form);
    const out = {};
    fd.forEach((v, k) => { out[k] = String(v); });
    return out;
  }

  async function submitModal(ev) {
    ev.preventDefault();

    const action = STATE.els.actionType.value;
    const uid = STATE.els.actionRequestUid.value;
    if (!action || !uid) return;

    const map = {
      approve: 'admin-approve.php',
      reject: 'admin-reject.php',
      markProof: 'admin-mark-proof.php',
      markPaid: 'admin-mark-paid.php'
    };
    const endpoint = map[action];
    if (!endpoint) return;

    const payload = serializeForm(STATE.els.modalForm);
    payload.request_uid = uid;
    payload.csrf_token = getCsrf();

    setStatus('Submitting ' + action + '…');
    STATE.els.submitBtn.disabled = true;

    try {
      const res = await fetch(CFG.apiBase + '/' + endpoint, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        body: JSON.stringify(payload)
      });

      const data = await res.json();
      if (!data || data.ok !== true) {
        throw new Error(data && (data.message || data.code) || 'Request failed');
      }

      closeModal();
      await loadList();
      setStatus(action + ' success: ' + uid);
    } catch (err) {
      console.error(err);
      setStatus(String(err.message || err), true);
    } finally {
      STATE.els.submitBtn.disabled = false;
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
      const btn = ev.target.closest('.mca-action');
      if (!btn) return;
      openModal(btn.getAttribute('data-action'), btn.getAttribute('data-uid'));
    });

    STATE.els.modalClose.addEventListener('click', closeModal);
    STATE.els.cancelBtn.addEventListener('click', closeModal);
    STATE.els.modal.addEventListener('click', function (ev) {
      if (ev.target === STATE.els.modal) closeModal();
    });
    STATE.els.modalForm.addEventListener('submit', submitModal);
  }

  function cache() {
    STATE.els.status = $('mcaStatus');
    STATE.els.flowType = $('mcaFlowType');
    STATE.els.requestUid = $('mcaRequestUid');
    STATE.els.userId = $('mcaUserId');
    STATE.els.limit = $('mcaLimit');
    STATE.els.refreshBtn = $('mcaRefreshBtn');
    STATE.els.resetBtn = $('mcaResetBtn');
    STATE.els.statusBar = $('mcaStatusBar');
    STATE.els.tableBody = $('mcaTableBody');

    STATE.els.modal = $('mcaModal');
    STATE.els.modalTitle = $('mcaModalTitle');
    STATE.els.modalMeta = $('mcaModalMeta');
    STATE.els.modalClose = $('mcaModalClose');
    STATE.els.modalForm = $('mcaModalForm');
    STATE.els.formFields = $('mcaFormFields');
    STATE.els.cancelBtn = $('mcaCancelBtn');
    STATE.els.submitBtn = $('mcaSubmitBtn');
    STATE.els.actionType = $('mcaActionType');
    STATE.els.actionRequestUid = $('mcaActionRequestUid');
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
