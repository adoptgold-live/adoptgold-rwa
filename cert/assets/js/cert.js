(function () {
  'use strict';

  const CFG = window.POADO_CERT || {};
  const API = CFG.api || {};

  const el = {
    searchInput: document.getElementById('certSearchInput'),
    searchBtn: document.getElementById('certSearchBtn'),
    reloadBtn: document.getElementById('certReloadBtn'),
    console: document.getElementById('certConsole'),

    sumGenesisCount: document.getElementById('sumGenesisCount'),
    sumGenesisWeight: document.getElementById('sumGenesisWeight'),
    sumGreenCount: document.getElementById('sumGreenCount'),
    sumGoldCount: document.getElementById('sumGoldCount'),
    sumBlueCount: document.getElementById('sumBlueCount'),
    sumBlackCount: document.getElementById('sumBlackCount'),

    sumSecondaryCount: document.getElementById('sumSecondaryCount'),
    sumSecondaryWeight: document.getElementById('sumSecondaryWeight'),
    sumHealthCount: document.getElementById('sumHealthCount'),
    sumTravelCount: document.getElementById('sumTravelCount'),
    sumPropertyCount: document.getElementById('sumPropertyCount'),

    activeIndustryText: document.getElementById('activeIndustryText'),
    issueBlueBtn: document.getElementById('issueBlueBtn'),
    issueBlackBtn: document.getElementById('issueBlackBtn'),
    blueLockNote: document.getElementById('blueLockNote'),
    blackLockNote: document.getElementById('blackLockNote'),

    issueButtons: Array.from(document.querySelectorAll('.issue-btn[data-cert-type]')),
  };

  const state = {
    loadingSummary: false,
    issuing: false,
    currentIndustry: String(CFG.currentIndustry || '').toLowerCase(),
    currentRole: String(CFG.currentRole || ''),
    csrfToken: String(CFG.csrfToken || ''),
  };

  function logLine(message, level) {
    if (!el.console) return;
    const row = document.createElement('div');
    row.className = 'logline';
    const ts = new Date();
    const stamp =
      String(ts.getHours()).padStart(2, '0') + ':' +
      String(ts.getMinutes()).padStart(2, '0') + ':' +
      String(ts.getSeconds()).padStart(2, '0');

    let prefix = '[INFO]';
    if (level === 'ok') prefix = '[OK]';
    else if (level === 'warn') prefix = '[WARN]';
    else if (level === 'error') prefix = '[ERROR]';
    else if (level === 'boot') prefix = '[BOOT]';

    row.textContent = `${stamp} ${prefix} ${message}`;
    el.console.prepend(row);
  }

  function setText(node, value) {
    if (node) node.textContent = String(value);
  }

  function n(v) {
    const num = Number(v);
    return Number.isFinite(num) ? num : 0;
  }

  async function fetchJson(url, options) {
    const res = await fetch(url, options || {});
    const text = await res.text();

    if (!text || text.trim() === '') {
      throw new Error('Empty response from server.');
    }

    const trimmed = text.trim();
    if (trimmed.startsWith('<')) {
      throw new Error('Server returned HTML instead of JSON.');
    }

    let data;
    try {
      data = JSON.parse(text);
    } catch (err) {
      throw new Error('Invalid JSON response.');
    }

    if (!res.ok || data.ok === false) {
      const msg =
        (data && (data.message || data.error)) ||
        `Request failed with HTTP ${res.status}`;
      throw new Error(msg);
    }

    return data;
  }

  function applySummary(summary) {
    const genesis = summary && summary.genesis ? summary.genesis : {};
    const secondary = summary && summary.secondary ? summary.secondary : {};

    setText(el.sumGenesisCount, n(genesis.count_total));
    setText(el.sumGenesisWeight, n(genesis.weight_total));
    setText(el.sumGreenCount, n(genesis.green_count));
    setText(el.sumGoldCount, n(genesis.gold_count));
    setText(el.sumBlueCount, n(genesis.blue_count));
    setText(el.sumBlackCount, n(genesis.black_count));

    setText(el.sumSecondaryCount, n(secondary.count_total));
    setText(el.sumSecondaryWeight, n(secondary.weight_total));
    setText(el.sumHealthCount, n(secondary.health_count));
    setText(el.sumTravelCount, n(secondary.travel_count));
    setText(el.sumPropertyCount, n(secondary.property_count));
  }

  function applyActivation(activation) {
    const blueUnlocked = !!(activation && activation.blue_unlocked);
    const blackUnlocked = !!(activation && activation.black_unlocked);

    if (el.issueBlueBtn) {
      el.issueBlueBtn.disabled = !blueUnlocked;
    }
    if (el.issueBlackBtn) {
      el.issueBlackBtn.disabled = !blackUnlocked;
    }

    if (el.blueLockNote) {
      el.blueLockNote.textContent = blueUnlocked
        ? 'Unlocked. Minimum 10 Green certs reached.'
        : 'Locked until user has issued at least 10 Green certs.';
    }

    if (el.blackLockNote) {
      el.blackLockNote.textContent = blackUnlocked
        ? 'Unlocked. Minimum 1 Gold cert reached.'
        : 'Locked until user has issued at least 1 Gold cert.';
    }
  }

  function normalizeIndustry(v) {
    return String(v || '').trim().toLowerCase();
  }

  function isSecondaryType(type) {
    return ['health', 'travel', 'property'].includes(type);
  }

  function validateSecondaryIndustry(type) {
    const current = normalizeIndustry(state.currentIndustry);
    return current !== '' && current === type;
  }

  function issuePayload(certType) {
    const fd = new FormData();
    fd.append('cert_type', certType);
    if (state.csrfToken) {
      fd.append('csrf_token', state.csrfToken);
    }
    return fd;
  }

  async function loadSummary() {
    if (state.loadingSummary || !API.list) return;

    state.loadingSummary = true;
    logLine('Loading owner summary from cert list API...', 'info');

    try {
      const data = await fetchJson(API.list, {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json',
        },
      });

      applySummary(data.summary || {});
      applyActivation(data.activation || {});

      if (data.user && typeof data.user === 'object') {
        if (el.activeIndustryText && state.currentIndustry) {
          setText(el.activeIndustryText, state.currentIndustry);
        }
      }

      logLine('Summary loaded successfully.', 'ok');
    } catch (err) {
      logLine(`Failed to load summary: ${err.message}`, 'error');
    } finally {
      state.loadingSummary = false;
    }
  }

  function renderSearchResultLines(items) {
    if (!Array.isArray(items) || items.length === 0) {
      logLine('No matching cert found for current owner.', 'warn');
      return;
    }

    logLine(`Search returned ${items.length} row(s).`, 'ok');

    items.slice(0, 10).forEach((row, index) => {
      const uid = row && row.uid ? row.uid : '-';
      const type = row && row.type ? row.type : '-';
      const status = row && row.status ? row.status : '-';
      const weight = row && row.weight ? row.weight : 0;
      logLine(
        `#${index + 1} UID=${uid} | TYPE=${type} | STATUS=${status} | WEIGHT=${weight}`,
        'info'
      );
    });
  }

  async function runSearch() {
    if (!API.search) {
      logLine('Search API is not configured.', 'error');
      return;
    }

    const q = el.searchInput ? String(el.searchInput.value || '').trim() : '';
    const url = new URL(API.search, window.location.origin);

    if (q !== '') {
      url.searchParams.set('q', q);
    }

    logLine(q !== '' ? `Searching my certs for "${q}"...` : 'Loading my certs...', 'info');

    try {
      const data = await fetchJson(url.toString(), {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json',
        },
      });

      if (data.summary) {
        logLine(
          `Matched count=${n(data.summary.matched_count)} | weight=${n(data.summary.matched_weight)}.`,
          'ok'
        );
      }

      renderSearchResultLines(data.items || []);
    } catch (err) {
      logLine(`Search failed: ${err.message}`, 'error');
    }
  }

  async function issueCert(certType) {
    if (!API.issue) {
      logLine('Issue API is not configured.', 'error');
      return;
    }
    if (state.issuing) {
      logLine('Another issue request is already in progress.', 'warn');
      return;
    }

    const type = String(certType || '').trim().toLowerCase();
    if (!type) {
      logLine('Missing cert type.', 'error');
      return;
    }

    if (isSecondaryType(type) && !validateSecondaryIndustry(type)) {
      logLine(
        `Secondary cert blocked. Active industry "${state.currentIndustry || 'not selected'}" does not match "${type}".`,
        'warn'
      );
      return;
    }

    state.issuing = true;
    logLine(`Submitting issue request for ${type} cert...`, 'info');

    const clickedBtn = el.issueButtons.find((btn) => btn.dataset.certType === type);
    if (clickedBtn) clickedBtn.disabled = true;

    try {
      const data = await fetchJson(API.issue, {
        method: 'POST',
        credentials: 'same-origin',
        body: issuePayload(type),
      });

      const uid =
        (data && data.uid) ||
        (data && data.item && data.item.uid) ||
        (data && data.cert_uid) ||
        '';

      const status =
        (data && data.status) ||
        (data && data.item && data.item.status) ||
        'payment_pending';

      logLine(
        `Issue created successfully${uid ? ` | UID=${uid}` : ''} | STATUS=${status}.`,
        'ok'
      );

      if (data.payment || (data.item && data.item.payment)) {
        const pay = data.payment || data.item.payment || {};
        const asset = pay.asset || '-';
        const amount = pay.amount || '-';
        const ref = pay.payment_ref || '-';
        logLine(`Payment payload ready | ASSET=${asset} | AMOUNT=${amount} | REF=${ref}`, 'info');
      }

      await loadSummary();
    } catch (err) {
      logLine(`Issue failed for ${type}: ${err.message}`, 'error');
    } finally {
      state.issuing = false;
      if (clickedBtn) {
        const isLockedBlue = type === 'blue' && el.issueBlueBtn && el.issueBlueBtn.disabled;
        const isLockedBlack = type === 'black' && el.issueBlackBtn && el.issueBlackBtn.disabled;
        if (!isLockedBlue && !isLockedBlack) {
          clickedBtn.disabled = false;
        }
      }
    }
  }

  function bindIssueButtons() {
    el.issueButtons.forEach((btn) => {
      btn.addEventListener('click', function () {
        const certType = String(btn.dataset.certType || '').trim().toLowerCase();
        issueCert(certType);
      });
    });
  }

  function bindSearch() {
    if (el.searchBtn) {
      el.searchBtn.addEventListener('click', runSearch);
    }

    if (el.reloadBtn) {
      el.reloadBtn.addEventListener('click', loadSummary);
    }

    if (el.searchInput) {
      el.searchInput.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter') {
          ev.preventDefault();
          runSearch();
        }
      });
    }
  }

  function init() {
    logLine('Cert JS initialized.', 'boot');

    if (el.activeIndustryText && state.currentIndustry) {
      setText(el.activeIndustryText, state.currentIndustry);
    }

    bindSearch();
    bindIssueButtons();
    loadSummary();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }
})();