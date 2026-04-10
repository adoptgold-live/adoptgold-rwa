(function () {
  'use strict';

  const els = {
    amount: document.getElementById('amount'),
    csrf: document.getElementById('csrf'),
    commitRef: document.getElementById('commitRef'),
    txHash: document.getElementById('txHash'),
    btnPrepare: document.getElementById('btnPrepare'),
    btnVerify: document.getElementById('btnVerify'),
    btnAutoVerify: document.getElementById('btnAutoVerify'),
    btnStopAutoVerify: document.getElementById('btnStopAutoVerify'),
    btnCopyPrepareCurl: document.getElementById('btnCopyPrepareCurl'),
    btnCopyVerifyCurl: document.getElementById('btnCopyVerifyCurl'),
    btnCopyJson: document.getElementById('btnCopyJson'),
    btnCopyRaw: document.getElementById('btnCopyRaw'),
    reqView: document.getElementById('reqView'),
    resView: document.getElementById('resView'),
    logView: document.getElementById('logView')
  };

  const state = {
    lastJson: null,
    lastRaw: '',
    autoTimer: null,
    autoMs: 5000,
    autoOn: false
  };

  function log(msg) {
    const t = new Date().toLocaleTimeString();
    els.logView.textContent += `[${t}] ${msg}\n`;
    els.logView.scrollTop = els.logView.scrollHeight;
  }

  function setReq(obj) {
    els.reqView.textContent = JSON.stringify(obj, null, 2);
  }

  function setRes(raw, json) {
    state.lastRaw = raw || '';
    state.lastJson = json || null;

    if (json) {
      els.resView.textContent = JSON.stringify(json, null, 2);
    } else {
      els.resView.textContent = raw || '';
    }
  }

  async function copyText(text) {
    await navigator.clipboard.writeText(text || '');
  }

  function buildPreparePayload() {
    return {
      action: 'prepare',
      amount: (els.amount.value || '').trim(),
      csrf: (els.csrf.value || '').trim()
    };
  }

  function buildVerifyPayload() {
    const p = {
      action: 'verify',
      commit_ref: (els.commitRef.value || '').trim(),
      csrf: (els.csrf.value || '').trim()
    };
    const tx = (els.txHash.value || '').trim();
    if (tx) p.tx_hash = tx;
    return p;
  }

  function toForm(payload) {
    const body = new URLSearchParams();
    Object.keys(payload).forEach((k) => {
      if (payload[k] !== undefined && payload[k] !== null) {
        body.append(k, String(payload[k]));
      }
    });
    return body;
  }

  function buildCurl(payload) {
    const parts = Object.keys(payload).map((k) => {
      return `--data-urlencode ${JSON.stringify(`${k}=${payload[k]}`)}`;
    });
    return [
      'curl -i',
      '-X POST',
      JSON.stringify(window.location.origin + '/rwa/api/storage/commit.php'),
      ...parts
    ].join(' \\\n  ');
  }

  async function send(payload) {
    setReq(payload);

    const res = await fetch('/rwa/api/storage/commit.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'Accept': 'application/json'
      },
      body: toForm(payload).toString(),
      credentials: 'same-origin'
    });

    const raw = await res.text();
    let json = null;

    try {
      json = JSON.parse(raw);
    } catch (e) {
      json = null;
    }

    setRes(raw, json);

    if (!json) {
      log(`HTTP ${res.status} · NON_JSON_RESPONSE`);
      return { ok: false, nonJson: true, status: res.status, raw };
    }

    log(`HTTP ${res.status} · ${json.ok ? 'OK' : 'FAIL'} · ${json.status || json.error || 'NO_STATUS'}`);
    return { ok: !!json.ok, status: res.status, json, raw };
  }

  async function doPrepare() {
    const payload = buildPreparePayload();
    log('Preparing commit...');
    const out = await send(payload);

    if (out.ok && out.json) {
      const ref = out.json.commit_ref || '';
      if (ref) {
        els.commitRef.value = ref;
        log(`Prepared commit_ref: ${ref}`);
      }
    }
  }

  async function doVerify() {
    const payload = buildVerifyPayload();
    if (!payload.commit_ref) {
      log('Commit ref required.');
      return;
    }
    log(`Verifying ${payload.commit_ref}...`);
    const out = await send(payload);

    if (out.ok && out.json) {
      const status = out.json.status || '';
      if (status === 'CONFIRMED' || status === 'ALREADY_CONFIRMED') {
        stopAuto();
      }
    }
  }

  function startAuto() {
    if (state.autoOn) return;
    if (!(els.commitRef.value || '').trim()) {
      log('Prepare first before auto verify.');
      return;
    }

    state.autoOn = true;
    log('Auto verify started (5s).');

    const tick = async () => {
      if (!state.autoOn) return;
      await doVerify();
      if (!state.autoOn) return;
      state.autoTimer = setTimeout(tick, state.autoMs);
    };

    state.autoTimer = setTimeout(tick, state.autoMs);
  }

  function stopAuto() {
    state.autoOn = false;
    if (state.autoTimer) {
      clearTimeout(state.autoTimer);
      state.autoTimer = null;
    }
    log('Auto verify stopped.');
  }

  els.btnPrepare.addEventListener('click', doPrepare);
  els.btnVerify.addEventListener('click', doVerify);
  els.btnAutoVerify.addEventListener('click', startAuto);
  els.btnStopAutoVerify.addEventListener('click', stopAuto);

  els.btnCopyPrepareCurl.addEventListener('click', async () => {
    await copyText(buildCurl(buildPreparePayload()));
    log('Prepare curl copied.');
  });

  els.btnCopyVerifyCurl.addEventListener('click', async () => {
    await copyText(buildCurl(buildVerifyPayload()));
    log('Verify curl copied.');
  });

  els.btnCopyJson.addEventListener('click', async () => {
    await copyText(JSON.stringify(state.lastJson || {}, null, 2));
    log('JSON copied.');
  });

  els.btnCopyRaw.addEventListener('click', async () => {
    await copyText(state.lastRaw || '');
    log('Raw response copied.');
  });

  log('Commit tester ready.');
})();