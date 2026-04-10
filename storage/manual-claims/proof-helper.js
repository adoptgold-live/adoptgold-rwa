(function () {
  'use strict';

  const CFG = {
    apiBase: (window.MANUAL_CLAIMS_PROOF && window.MANUAL_CLAIMS_PROOF.apiBase) || '/rwa/api/storage/manual-claims',
    csrfToken: (window.MANUAL_CLAIMS_PROOF && window.MANUAL_CLAIMS_PROOF.csrfToken) || '',
    contractAddress: (window.MANUAL_CLAIMS_PROOF && window.MANUAL_CLAIMS_PROOF.contractAddress) || '',
    signerPublicKey: (window.MANUAL_CLAIMS_PROOF && window.MANUAL_CLAIMS_PROOF.signerPublicKey) || '',
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

  function statusBadge(status) {
    return '<span class="mch-badge ' + escapeHtml(status) + '">' + escapeHtml(status) + '</span>';
  }

  function rowActionBtn(uid) {
    return '<button type="button" class="mch-btn mch-select-row" data-uid="' + escapeHtml(uid) + '">Select</button>';
  }

  function txLink(hash, label) {
    if (!hash) return '';
    return '<a class="mch-link" href="' + CFG.tonviewerTxBase + encodeURIComponent(hash) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(label || 'TX') + '</a>';
  }

  async function loadList() {
    if (STATE.busy) return;
    STATE.busy = true;
    setStatus('Loading proof-eligible requests…');

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

      let items = Array.isArray(data.items) ? data.items : [];
      items = items.filter((item) => {
        const proofRequired = Number(item.proof_required || 0) === 1;
        return proofRequired || String(item.status || '') === 'proof_submitted';
      });

      STATE.items = items;
      renderTable();
      if (STATE.selected) {
        const found = STATE.items.find((x) => x.request_uid === STATE.selected.request_uid);
        if (found) {
          STATE.selected = found;
        } else {
          STATE.selected = null;
        }
      }
      renderSelected();
      setStatus('Loaded ' + STATE.items.length + ' proof item(s).');
    } catch (err) {
      console.error(err);
      STATE.items = [];
      renderTable();
      renderSelected();
      setStatus(String(err.message || err), true);
    } finally {
      STATE.busy = false;
    }
  }

  function renderTable() {
    const body = STATE.els.tableBody;
    if (!body) return;

    if (!STATE.items.length) {
      body.innerHTML = '<tr><td colspan="7" class="mch-empty">No proof-eligible rows found.</td></tr>';
      return;
    }

    body.innerHTML = STATE.items.map((item) => {
      const recipient = [item.recipient_owner || '', item.wallet_address || ''].filter(Boolean).map(escapeHtml).join('<br>');
      const claim = [
        item.claim_ref ? ('Ref: ' + escapeHtml(item.claim_ref)) : '',
        item.claim_nonce ? ('Nonce: ' + escapeHtml(item.claim_nonce)) : ''
      ].filter(Boolean).join('<br>');

      return ''
        + '<tr>'
        +   '<td><strong>' + escapeHtml(item.request_uid) + '</strong></td>'
        +   '<td>' + escapeHtml(item.flow_type) + '</td>'
        +   '<td>' + escapeHtml(item.amount_display || item.amount_units || '0') + '<br><span class="mch-mini">' + escapeHtml(item.amount_units || '') + ' units</span></td>'
        +   '<td>' + (recipient || '<span class="mch-mini">—</span>') + '</td>'
        +   '<td>' + statusBadge(item.status || '') + '</td>'
        +   '<td>' + (claim || '<span class="mch-mini">—</span>') + '</td>'
        +   '<td>' + rowActionBtn(item.request_uid) + '</td>'
        + '</tr>';
    }).join('');
  }

  function normalizeRef(v) {
    return String(v || '').trim();
  }

  function defaultValidUntil() {
    return Math.floor(Date.now() / 1000) + 600;
  }

  function generateClaimRef() {
    const d = new Date();
    const yyyy = d.getUTCFullYear();
    const mm = String(d.getUTCMonth() + 1).padStart(2, '0');
    const dd = String(d.getUTCDate()).padStart(2, '0');
    const rnd = Math.random().toString(16).slice(2, 10).toUpperCase().padEnd(8, '0').slice(0, 8);
    return 'CLM-' + yyyy + mm + dd + '-' + rnd;
  }

  function generateClaimNonce() {
    return String(Math.floor(Date.now() / 1000));
  }

  async function sha256Hex(input) {
    const enc = new TextEncoder().encode(String(input || ''));
    const buf = await crypto.subtle.digest('SHA-256', enc);
    return Array.from(new Uint8Array(buf)).map((b) => b.toString(16).padStart(2, '0')).join('');
  }

  async function updatePayloadPreview() {
    if (!STATE.selected) {
      STATE.els.payloadPreview.textContent = '{}';
      return;
    }

    const claimRef = normalizeRef(STATE.els.claimRef.value);
    const claimNonce = String(STATE.els.claimNonce.value || '').trim();
    const validUntil = String(STATE.els.validUntil.value || '').trim();
    const proofContract = String(STATE.els.proofContract.value || '').trim();
    const claimRefHash = claimRef ? await sha256Hex(claimRef) : '';

    const payload = {
      request_uid: STATE.selected.request_uid,
      flow_type: STATE.selected.flow_type,
      contract_address: CFG.contractAddress || proofContract || STATE.selected.proof_contract || '',
      signer_public_key: CFG.signerPublicKey || '',
      recipient_owner: STATE.selected.recipient_owner || '',
      amount_units: String(STATE.selected.amount_units || ''),
      claim_ref: claimRef,
      claim_ref_hash: claimRefHash ? ('0x' + claimRefHash) : '',
      claim_nonce: claimNonce,
      valid_until: validUntil,
      status: STATE.selected.status || '',
      proof_required: Number(STATE.selected.proof_required || 0),
      existing_proof_tx_hash: STATE.selected.proof_tx_hash || ''
    };

    STATE.els.payloadPreview.textContent = JSON.stringify(payload, null, 2);
  }

  function fillSelectedMeta(item) {
    STATE.els.metaRequestUid.textContent = item.request_uid || '';
    STATE.els.metaFlowType.textContent = item.flow_type || '';
    STATE.els.metaRecipient.textContent = item.recipient_owner || item.wallet_address || '';
    STATE.els.metaAmountUnits.textContent = String(item.amount_units || '');

    STATE.els.claimRef.value = item.claim_ref || '';
    STATE.els.claimNonce.value = item.claim_nonce || '';
    STATE.els.validUntil.value = defaultValidUntil();
    STATE.els.proofContract.value = item.proof_contract || CFG.contractAddress || '';
    STATE.els.proofRecordContract.value = item.proof_contract || CFG.contractAddress || '';
    STATE.els.proofTxHash.value = item.proof_tx_hash || '';
  }

  function renderSelected() {
    if (!STATE.selected) {
      STATE.els.selectedEmpty.hidden = false;
      STATE.els.payloadWrap.hidden = true;
      return;
    }

    STATE.els.selectedEmpty.hidden = true;
    STATE.els.payloadWrap.hidden = false;
    fillSelectedMeta(STATE.selected);
    updatePayloadPreview();
  }

  function selectRow(uid) {
    const found = STATE.items.find((x) => x.request_uid === uid) || null;
    STATE.selected = found;
    renderSelected();
  }

  async function copyPayload() {
    const text = STATE.els.payloadPreview.textContent || '{}';
    try {
      await navigator.clipboard.writeText(text);
      setStatus('Payload JSON copied.');
    } catch (err) {
      setStatus('Copy failed.', true);
    }
  }

  async function markProof(ev) {
    ev.preventDefault();
    if (!STATE.selected) return;

    const proofTxHash = String(STATE.els.proofTxHash.value || '').trim();
    const proofContract = String(STATE.els.proofRecordContract.value || '').trim();
    if (!proofTxHash) {
      setStatus('proof_tx_hash is required.', true);
      return;
    }

    STATE.els.markProofBtn.disabled = true;
    setStatus('Marking proof submitted…');

    try {
      const res = await fetch(CFG.apiBase + '/admin-mark-proof.php', {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        body: JSON.stringify({
          request_uid: STATE.selected.request_uid,
          proof_tx_hash: proofTxHash,
          proof_contract: proofContract,
          csrf_token: getCsrf()
        })
      });

      const data = await res.json();
      if (!data || data.ok !== true) {
        throw new Error(data && (data.message || data.code) || 'admin-mark-proof failed');
      }

      setStatus('Proof marked: ' + STATE.selected.request_uid);
      await loadList();
      selectRow(STATE.selected.request_uid);
    } catch (err) {
      console.error(err);
      setStatus(String(err.message || err), true);
    } finally {
      STATE.els.markProofBtn.disabled = false;
    }
  }

  function bind() {
    STATE.els.refreshBtn.addEventListener('click', loadList);
    STATE.els.resetBtn.addEventListener('click', function () {
      STATE.els.status.value = 'approved';
      STATE.els.flowType.value = '';
      STATE.els.requestUid.value = '';
      STATE.els.userId.value = '';
      STATE.els.limit.value = '100';
      loadList();
    });

    STATE.els.tableBody.addEventListener('click', function (ev) {
      const btn = ev.target.closest('.mch-select-row');
      if (!btn) return;
      selectRow(btn.getAttribute('data-uid'));
    });

    STATE.els.generateRefBtn.addEventListener('click', function () {
      STATE.els.claimRef.value = generateClaimRef();
      updatePayloadPreview();
    });

    STATE.els.generateNonceBtn.addEventListener('click', function () {
      STATE.els.claimNonce.value = generateClaimNonce();
      updatePayloadPreview();
    });

    STATE.els.generateValidBtn.addEventListener('click', function () {
      STATE.els.validUntil.value = defaultValidUntil();
      updatePayloadPreview();
    });

    STATE.els.copyPayloadBtn.addEventListener('click', copyPayload);

    ['input', 'change'].forEach((evt) => {
      STATE.els.payloadForm.addEventListener(evt, updatePayloadPreview);
    });

    STATE.els.proofRecordForm.addEventListener('submit', markProof);
  }

  function cache() {
    STATE.els.status = $('mchStatus');
    STATE.els.flowType = $('mchFlowType');
    STATE.els.requestUid = $('mchRequestUid');
    STATE.els.userId = $('mchUserId');
    STATE.els.limit = $('mchLimit');
    STATE.els.refreshBtn = $('mchRefreshBtn');
    STATE.els.resetBtn = $('mchResetBtn');
    STATE.els.statusBar = $('mchStatusBar');
    STATE.els.tableBody = $('mchTableBody');

    STATE.els.selectedEmpty = $('mchSelectedEmpty');
    STATE.els.payloadWrap = $('mchPayloadWrap');
    STATE.els.metaRequestUid = $('mchMetaRequestUid');
    STATE.els.metaFlowType = $('mchMetaFlowType');
    STATE.els.metaRecipient = $('mchMetaRecipient');
    STATE.els.metaAmountUnits = $('mchMetaAmountUnits');

    STATE.els.payloadForm = $('mchPayloadForm');
    STATE.els.claimRef = $('mchClaimRef');
    STATE.els.claimNonce = $('mchClaimNonce');
    STATE.els.validUntil = $('mchValidUntil');
    STATE.els.proofContract = $('mchProofContract');
    STATE.els.generateRefBtn = $('mchGenerateRefBtn');
    STATE.els.generateNonceBtn = $('mchGenerateNonceBtn');
    STATE.els.generateValidBtn = $('mchGenerateValidBtn');
    STATE.els.copyPayloadBtn = $('mchCopyPayloadBtn');
    STATE.els.payloadPreview = $('mchPayloadPreview');

    STATE.els.proofRecordForm = $('mchProofRecordForm');
    STATE.els.proofTxHash = $('mchProofTxHash');
    STATE.els.proofRecordContract = $('mchProofRecordContract');
    STATE.els.markProofBtn = $('mchMarkProofBtn');
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
