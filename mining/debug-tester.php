<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/mining/debug-tester.php
 * Standalone Mining Debug Tester
 * - No topbar/footer
 * - No login/session write
 * - Same-origin endpoint tester for mining APIs
 */
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <title>Mining Debug Tester</title>
  <style>
    :root{
      --bg:#06060b;
      --panel:#100a18;
      --panel2:#150d21;
      --fg:#efe7ff;
      --mut:#bda9df;
      --ok:#34d399;
      --err:#fb7185;
      --warn:#fbbf24;
      --line:rgba(168,85,247,.25);
      --shadow:0 18px 40px rgba(0,0,0,.55);
    }
    *{box-sizing:border-box}
    html,body{margin:0;background:radial-gradient(900px 520px at 18% 0%, rgba(168,85,247,.18), transparent 60%),var(--bg);color:var(--fg);font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
    .wrap{max-width:1280px;margin:0 auto;padding:16px 14px 40px}
    .top{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;margin-bottom:14px}
    .title h1{margin:0;font-size:22px;letter-spacing:.06em}
    .title .sub{margin-top:6px;color:var(--mut);font-size:12px;line-height:1.6}
    .lang{display:flex;gap:8px;align-items:center}
    .lang button{
      border:1px solid var(--line);background:rgba(0,0,0,.25);color:var(--fg);
      padding:8px 12px;border-radius:10px;cursor:pointer;font-weight:800
    }
    .lang button.active{background:rgba(168,85,247,.22)}
    .grid{display:grid;grid-template-columns:360px 1fr;gap:14px}
    @media (max-width: 980px){.grid{grid-template-columns:1fr}}
    .card{
      border:1px solid var(--line);border-radius:16px;background:linear-gradient(180deg, rgba(124,58,237,.10), rgba(0,0,0,.35));
      box-shadow:var(--shadow);overflow:hidden
    }
    .hd{padding:12px 14px;border-bottom:1px solid rgba(168,85,247,.18);display:flex;justify-content:space-between;gap:10px;align-items:center}
    .hd .k{font-size:12px;font-weight:1000;letter-spacing:.08em}
    .hd .r{font-size:12px;color:var(--mut)}
    .bd{padding:14px}
    .stack{display:grid;gap:10px}
    .btnRow{display:flex;gap:10px;flex-wrap:wrap}
    .btn{
      appearance:none;border:1px solid var(--line);background:rgba(124,58,237,.18);color:var(--fg);
      padding:11px 13px;border-radius:12px;cursor:pointer;font-weight:1000;letter-spacing:.04em
    }
    .btn.ok{border-color:rgba(52,211,153,.28);background:rgba(52,211,153,.12)}
    .btn.err{border-color:rgba(251,113,133,.28);background:rgba(251,113,133,.12)}
    .btn.warn{border-color:rgba(251,191,36,.28);background:rgba(251,191,36,.12)}
    .btn:disabled{opacity:.55;cursor:not-allowed}
    .field{display:grid;gap:6px}
    .field label{font-size:12px;color:var(--mut)}
    .input, .select{
      width:100%;border-radius:12px;border:1px solid rgba(255,255,255,.08);background:#0d0a13;color:var(--fg);padding:11px 12px
    }
    .mini{border:1px solid rgba(168,85,247,.18);border-radius:12px;background:rgba(0,0,0,.28);padding:12px}
    .mini .k{font-size:12px;color:var(--mut);margin-bottom:8px}
    .mini .v{font-size:14px;font-weight:900;word-break:break-word}
    .statusbar{
      border:1px solid rgba(255,255,255,.08);border-radius:12px;background:rgba(0,0,0,.28);padding:12px;
      display:grid;gap:8px
    }
    .badge{
      display:inline-flex;align-items:center;border-radius:999px;padding:6px 10px;font-size:12px;font-weight:1000;border:1px solid rgba(255,255,255,.10)
    }
    .badge.ok{color:#d1fae5;border-color:rgba(52,211,153,.3);background:rgba(52,211,153,.12)}
    .badge.err{color:#ffe4ea;border-color:rgba(251,113,133,.3);background:rgba(251,113,133,.12)}
    .badge.warn{color:#fff3c4;border-color:rgba(251,191,36,.3);background:rgba(251,191,36,.12)}
    .panelGrid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    @media (max-width: 980px){.panelGrid{grid-template-columns:1fr}}
    pre{
      margin:0;white-space:pre-wrap;word-break:break-word;background:#07060a;border:1px solid rgba(255,255,255,.06);
      border-radius:12px;padding:12px;min-height:180px;max-height:420px;overflow:auto;color:#d8cef2
    }
    table{width:100%;border-collapse:collapse;font-size:12px}
    th,td{padding:8px 10px;border-bottom:1px dashed rgba(255,255,255,.08);vertical-align:top;text-align:left}
    th{color:var(--mut);font-weight:900}
    .mut{color:var(--mut)}
    .oktxt{color:var(--ok)}
    .errtxt{color:var(--err)}
    .warntxt{color:var(--warn)}
    .footerNote{margin-top:12px;color:var(--mut);font-size:12px;line-height:1.7}
  </style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div class="title">
      <h1 data-i18n="title">Mining Debug Tester</h1>
      <div class="sub" data-i18n="subtitle">
        Standalone tester for mining core APIs. Uses same-origin browser session. If you are logged in, protected endpoints will use your live cookies.
      </div>
    </div>
    <div class="lang">
      <button type="button" id="langEn" class="active">EN</button>
      <button type="button" id="langZh">中</button>
    </div>
  </div>

  <div class="grid">
    <div class="card">
      <div class="hd">
        <div class="k" data-i18n="control_panel">CONTROL PANEL</div>
        <div class="r" data-i18n="same_origin">same-origin</div>
      </div>
      <div class="bd">
        <div class="stack">
          <div class="statusbar">
            <div><span id="sessionBadge" class="badge warn">SESSION UNKNOWN</span></div>
            <div id="diagLine" class="mut">Waiting first request…</div>
            <div id="lastAction" class="mut">No action yet.</div>
          </div>

          <div class="field">
            <label for="endpointSelect" data-i18n="endpoint">Endpoint</label>
            <select id="endpointSelect" class="select">
              <option value="/rwa/api/mining/status.php">status.php</option>
              <option value="/rwa/api/mining/start.php">start.php</option>
              <option value="/rwa/api/mining/tick.php">tick.php</option>
              <option value="/rwa/api/mining/stop.php">stop.php</option>
              <option value="/rwa/api/mining/ledger.php">ledger.php</option>
              <option value="/rwa/api/mining/heartbeat.php">heartbeat.php</option>
            </select>
          </div>

          <div class="field">
            <label for="methodSelect" data-i18n="method">Method</label>
            <select id="methodSelect" class="select">
              <option value="GET">GET</option>
              <option value="POST">POST</option>
            </select>
          </div>

          <div class="field">
            <label for="bodyInput" data-i18n="post_body">POST Body (URL encoded)</label>
            <input id="bodyInput" class="input" placeholder="amount=1&destination_wallet=EQ..." />
          </div>

          <div class="field">
            <label for="loopSelect" data-i18n="loop_action">Loop Action</label>
            <select id="loopSelect" class="select">
              <option value="status">status loop</option>
              <option value="tick">tick loop</option>
              <option value="heartbeat">heartbeat loop</option>
            </select>
          </div>

          <div class="field">
            <label for="intervalInput" data-i18n="interval_ms">Loop Interval (ms)</label>
            <input id="intervalInput" class="input" value="10000" />
          </div>

          <div class="btnRow">
            <button class="btn ok" id="btnRun">RUN REQUEST</button>
            <button class="btn" id="btnStartLoop">START LOOP</button>
            <button class="btn err" id="btnStopLoop">STOP LOOP</button>
          </div>

          <div class="btnRow">
            <button class="btn ok" id="btnStatus">STATUS</button>
            <button class="btn ok" id="btnStart">START</button>
            <button class="btn warn" id="btnTick">TICK</button>
            <button class="btn err" id="btnStop">STOP</button>
            <button class="btn" id="btnLedger">LEDGER</button>
            <button class="btn" id="btnHeartbeat">HEARTBEAT</button>
          </div>

          <div class="mini">
            <div class="k" data-i18n="quick_diagnosis">QUICK DIAGNOSIS</div>
            <div class="v" id="quickDiagnosis">No response yet.</div>
          </div>

          <div class="mini">
            <div class="k" data-i18n="current_runtime">CURRENT RUNTIME SNAPSHOT</div>
            <table>
              <tr><th>is_mining</th><td id="rtMining">—</td></tr>
              <tr><th>tier</th><td id="rtTier">—</td></tr>
              <tr><th>multiplier</th><td id="rtMultiplier">—</td></tr>
              <tr><th>daily_mined</th><td id="rtDaily">—</td></tr>
              <tr><th>total_mined</th><td id="rtTotal">—</td></tr>
              <tr><th>unclaimed</th><td id="rtUnclaimed">—</td></tr>
              <tr><th>battery_pct</th><td id="rtBattery">—</td></tr>
              <tr><th>rate/tick</th><td id="rtRate">—</td></tr>
              <tr><th>error</th><td id="rtError">—</td></tr>
            </table>
          </div>
        </div>

        <div class="footerNote" data-i18n="note">
          This tester does not write session by itself. Protected mining endpoints still require a valid browser session and wallet-bound eligible user.
        </div>
      </div>
    </div>

    <div class="stack">
      <div class="card">
        <div class="hd">
          <div class="k" data-i18n="raw_response">RAW RESPONSE</div>
          <div class="r" id="rawMeta">—</div>
        </div>
        <div class="bd">
          <pre id="rawBox">Waiting…</pre>
        </div>
      </div>

      <div class="panelGrid">
        <div class="card">
          <div class="hd">
            <div class="k" data-i18n="parsed_json">PARSED JSON</div>
            <div class="r" id="jsonMeta">—</div>
          </div>
          <div class="bd">
            <pre id="jsonBox">Waiting…</pre>
          </div>
        </div>

        <div class="card">
          <div class="hd">
            <div class="k" data-i18n="debug_log">DEBUG LOG</div>
            <div class="r" id="loopState">loop: idle</div>
          </div>
          <div class="bd">
            <pre id="logBox">Tester ready.</pre>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="hd">
          <div class="k" data-i18n="contract_expectation">API CONTRACT EXPECTATION</div>
          <div class="r">mining core</div>
        </div>
        <div class="bd">
          <table>
            <tr><th>status.php</th><td>Must return clean JSON. Should show `NO_SESSION` only if not logged in.</td></tr>
            <tr><th>start.php</th><td>Must return `ok:true` and `is_mining:1` when eligible and logged in.</td></tr>
            <tr><th>tick.php</th><td>Must not return `INVALID_JSON`. If mining not started, expect `MINING_NOT_RUNNING`.</td></tr>
            <tr><th>stop.php</th><td>Must return clean JSON and set `is_mining:0`.</td></tr>
            <tr><th>ledger.php</th><td>Must return rows array, total mined, and storage-linked unclaimed wEMS.</td></tr>
            <tr><th>heartbeat.php</th><td>May be separate new engine path. Useful to compare against `tick.php` behavior.</td></tr>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(() => {
  const I18N = {
    en: {
      title: 'Mining Debug Tester',
      subtitle: 'Standalone tester for mining core APIs. Uses same-origin browser session. If you are logged in, protected endpoints will use your live cookies.',
      control_panel: 'CONTROL PANEL',
      same_origin: 'same-origin',
      endpoint: 'Endpoint',
      method: 'Method',
      post_body: 'POST Body (URL encoded)',
      loop_action: 'Loop Action',
      interval_ms: 'Loop Interval (ms)',
      quick_diagnosis: 'QUICK DIAGNOSIS',
      current_runtime: 'CURRENT RUNTIME SNAPSHOT',
      note: 'This tester does not write session by itself. Protected mining endpoints still require a valid browser session and wallet-bound eligible user.',
      raw_response: 'RAW RESPONSE',
      parsed_json: 'PARSED JSON',
      debug_log: 'DEBUG LOG',
      contract_expectation: 'API CONTRACT EXPECTATION'
    },
    zh: {
      title: 'Mining 调试测试器',
      subtitle: '独立测试页面，用于 Mining 核心 API。同源请求会带上浏览器当前会话；如果你已登录，受保护接口会使用你的实时 Cookie。',
      control_panel: '控制面板',
      same_origin: '同源',
      endpoint: '接口',
      method: '方法',
      post_body: 'POST 参数（URL 编码）',
      loop_action: '循环动作',
      interval_ms: '循环间隔（毫秒）',
      quick_diagnosis: '快速诊断',
      current_runtime: '当前运行快照',
      note: '此测试页本身不会写入会话。受保护的 Mining 接口仍然需要有效登录会话、已绑定钱包和可挖矿资格。',
      raw_response: '原始响应',
      parsed_json: '解析后的 JSON',
      debug_log: '调试日志',
      contract_expectation: 'API 合约预期'
    }
  };

  let lang = 'en';
  let loopTimer = null;

  const $ = (id) => document.getElementById(id);

  const el = {
    endpoint: $('endpointSelect'),
    method: $('methodSelect'),
    body: $('bodyInput'),
    interval: $('intervalInput'),
    loopSelect: $('loopSelect'),
    btnRun: $('btnRun'),
    btnStartLoop: $('btnStartLoop'),
    btnStopLoop: $('btnStopLoop'),
    btnStatus: $('btnStatus'),
    btnStart: $('btnStart'),
    btnTick: $('btnTick'),
    btnStop: $('btnStop'),
    btnLedger: $('btnLedger'),
    btnHeartbeat: $('btnHeartbeat'),
    rawBox: $('rawBox'),
    rawMeta: $('rawMeta'),
    jsonBox: $('jsonBox'),
    jsonMeta: $('jsonMeta'),
    logBox: $('logBox'),
    loopState: $('loopState'),
    sessionBadge: $('sessionBadge'),
    diagLine: $('diagLine'),
    quickDiagnosis: $('quickDiagnosis'),
    lastAction: $('lastAction'),
    rtMining: $('rtMining'),
    rtTier: $('rtTier'),
    rtMultiplier: $('rtMultiplier'),
    rtDaily: $('rtDaily'),
    rtTotal: $('rtTotal'),
    rtUnclaimed: $('rtUnclaimed'),
    rtBattery: $('rtBattery'),
    rtRate: $('rtRate'),
    rtError: $('rtError'),
    langEn: $('langEn'),
    langZh: $('langZh')
  };

  function applyLang(next) {
    lang = next;
    document.documentElement.lang = next === 'zh' ? 'zh' : 'en';
    document.querySelectorAll('[data-i18n]').forEach(node => {
      const key = node.getAttribute('data-i18n');
      if (I18N[lang] && I18N[lang][key]) node.textContent = I18N[lang][key];
    });
    el.langEn.classList.toggle('active', lang === 'en');
    el.langZh.classList.toggle('active', lang === 'zh');
  }

  function log(msg) {
    const now = new Date().toISOString();
    el.logBox.textContent = `[${now}] ${msg}\n` + el.logBox.textContent;
  }

  function setSessionBadge(kind, text) {
    el.sessionBadge.className = 'badge ' + kind;
    el.sessionBadge.textContent = text;
  }

  function pretty(obj) {
    try { return JSON.stringify(obj, null, 2); } catch(e) { return String(obj); }
  }

  function updateRuntime(d) {
    el.rtMining.textContent = typeof d.is_mining !== 'undefined' ? String(d.is_mining) : '—';
    el.rtTier.textContent = d.tier || d.tier_code || '—';
    el.rtMultiplier.textContent = typeof d.multiplier !== 'undefined' ? String(d.multiplier) : '—';
    el.rtDaily.textContent = typeof d.daily_mined_wems !== 'undefined' ? String(d.daily_mined_wems) : '—';
    el.rtTotal.textContent = typeof d.total_mined_wems !== 'undefined' ? String(d.total_mined_wems) : '—';
    el.rtUnclaimed.textContent = typeof d.storage_unclaimed_wems !== 'undefined' ? String(d.storage_unclaimed_wems) : '—';
    el.rtBattery.textContent = typeof d.battery_pct !== 'undefined' ? String(d.battery_pct) : '—';
    el.rtRate.textContent = typeof d.rate_wems_per_tick !== 'undefined' ? String(d.rate_wems_per_tick) : '—';
    el.rtError.textContent = d.error || d.message || '—';
  }

  function diagnose(raw, parsed, endpoint) {
    if (!raw.trim()) return 'EMPTY_RESPONSE';
    if (raw.trim().startsWith('<')) return 'SERVER_RETURNED_HTML';
    if (!parsed) return 'INVALID_JSON';
    if (parsed.error === 'NO_SESSION') return 'NO_SESSION';
    if (parsed.error === 'MINING_NOT_RUNNING') return 'MINING_NOT_RUNNING';
    if (parsed.error === 'MINING_LOCKED') return 'MINING_LOCKED';
    if (parsed.ok === true) {
      if (endpoint.includes('/start.php') && Number(parsed.is_mining || 0) !== 1) return 'START_OK_BUT_NOT_RUNNING';
      return 'OK';
    }
    return parsed.error || 'UNKNOWN_ERROR';
  }

  async function runRequest(endpoint, method = 'GET', bodyText = '') {
    const started = performance.now();
    let raw = '';
    let parsed = null;
    let statusCode = 0;

    try {
      const opts = {
        method,
        credentials: 'include',
        cache: 'no-store',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      };

      if (method === 'POST') {
        opts.headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
        opts.body = bodyText;
      }

      const res = await fetch(endpoint, opts);
      statusCode = res.status;
      raw = await res.text();

      try { parsed = JSON.parse(raw); } catch (e) { parsed = null; }

      const elapsed = Math.round(performance.now() - started);
      el.rawMeta.textContent = `${method} · HTTP ${statusCode} · ${elapsed} ms`;
      el.rawBox.textContent = raw || '(empty)';
      el.jsonMeta.textContent = parsed ? 'valid JSON' : 'invalid JSON';
      el.jsonBox.textContent = parsed ? pretty(parsed) : '(failed to parse JSON)';
      el.lastAction.textContent = `${method} ${endpoint}`;

      const diag = diagnose(raw, parsed, endpoint);
      el.quickDiagnosis.textContent = diag;
      el.diagLine.textContent = `Diagnosis: ${diag}`;

      if (diag === 'NO_SESSION') {
        setSessionBadge('warn', 'NO SESSION');
      } else if (diag === 'OK') {
        setSessionBadge('ok', 'SESSION / JSON OK');
      } else if (diag === 'INVALID_JSON' || diag === 'SERVER_RETURNED_HTML') {
        setSessionBadge('err', 'BROKEN RESPONSE');
      } else {
        setSessionBadge('warn', 'RESPONSE WITH ERROR');
      }

      if (parsed) updateRuntime(parsed);

      log(`${method} ${endpoint} => HTTP ${statusCode} · ${diag}`);
      return { raw, parsed, statusCode, diagnosis: diag };
    } catch (e) {
      const elapsed = Math.round(performance.now() - started);
      el.rawMeta.textContent = `${method} · FETCH ERROR · ${elapsed} ms`;
      el.rawBox.textContent = String(e.message || e);
      el.jsonMeta.textContent = 'no JSON';
      el.jsonBox.textContent = '(fetch failed)';
      el.quickDiagnosis.textContent = 'FETCH_ERROR';
      el.diagLine.textContent = 'Diagnosis: FETCH_ERROR';
      setSessionBadge('err', 'FETCH ERROR');
      log(`${method} ${endpoint} => FETCH_ERROR ${String(e.message || e)}`);
      return { raw: '', parsed: null, statusCode: 0, diagnosis: 'FETCH_ERROR' };
    }
  }

  function endpointForAction(action) {
    if (action === 'status') return '/rwa/api/mining/status.php';
    if (action === 'tick') return '/rwa/api/mining/tick.php';
    if (action === 'heartbeat') return '/rwa/api/mining/heartbeat.php';
    return '/rwa/api/mining/status.php';
  }

  function stopLoop() {
    if (loopTimer) {
      clearInterval(loopTimer);
      loopTimer = null;
    }
    el.loopState.textContent = 'loop: idle';
    log('Loop stopped');
  }

  function startLoop() {
    stopLoop();
    const action = el.loopSelect.value;
    const endpoint = endpointForAction(action);
    const interval = Math.max(1000, parseInt(el.interval.value || '10000', 10) || 10000);
    const method = action === 'status' ? 'GET' : 'POST';

    el.loopState.textContent = `loop: ${action} @ ${interval}ms`;
    log(`Loop started: ${action} every ${interval}ms`);

    loopTimer = setInterval(() => {
      runRequest(endpoint, method, '');
    }, interval);
  }

  el.btnRun.addEventListener('click', () => {
    runRequest(el.endpoint.value, el.method.value, el.body.value.trim());
  });

  el.btnStartLoop.addEventListener('click', startLoop);
  el.btnStopLoop.addEventListener('click', stopLoop);

  el.btnStatus.addEventListener('click', () => runRequest('/rwa/api/mining/status.php', 'GET', ''));
  el.btnStart.addEventListener('click', () => runRequest('/rwa/api/mining/start.php', 'POST', ''));
  el.btnTick.addEventListener('click', () => runRequest('/rwa/api/mining/tick.php', 'POST', ''));
  el.btnStop.addEventListener('click', () => runRequest('/rwa/api/mining/stop.php', 'POST', ''));
  el.btnLedger.addEventListener('click', () => runRequest('/rwa/api/mining/ledger.php', 'GET', ''));
  el.btnHeartbeat.addEventListener('click', () => runRequest('/rwa/api/mining/heartbeat.php', 'POST', ''));

  el.langEn.addEventListener('click', () => applyLang('en'));
  el.langZh.addEventListener('click', () => applyLang('zh'));

  applyLang('en');
  log('Tester ready');
  runRequest('/rwa/api/mining/status.php', 'GET', '');
})();
</script>
</body>
</html>
