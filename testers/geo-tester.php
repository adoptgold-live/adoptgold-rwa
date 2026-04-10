<?php
declare(strict_types=1);

/**
 * AdoptGold RWA
 * GEO Tester
 * File: /var/www/html/public/rwa/testers/geo-tester.php
 * Version: v1.0.20260315
 */

function h(?string $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>RWA GEO Tester</title>
<style>
:root{
  --bg:#0d0816;
  --bg2:#17102a;
  --panel:#1b122a;
  --panel2:#24173b;
  --line:rgba(178,108,255,.15);
  --line2:rgba(255,255,255,.08);
  --text:#f6f1ff;
  --muted:#c7bbdf;
  --purple:#b26cff;
  --purple2:#7d45ff;
  --gold:#f5d97b;
  --green:#42ff9d;
  --red:#ff6f8e;
  --warn:#ffcf68;
}
*{box-sizing:border-box}
html,body{
  margin:0;
  min-height:100%;
  background:
    radial-gradient(circle at top right, rgba(178,108,255,.10), transparent 22%),
    radial-gradient(circle at top left, rgba(255,111,142,.06), transparent 18%),
    linear-gradient(180deg,var(--bg),var(--bg2));
  color:var(--text);
  font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
}
.page{
  width:min(1280px, calc(100% - 20px));
  margin:14px auto 24px;
}
.hero{
  border:1px solid var(--line);
  border-radius:20px;
  background:
    linear-gradient(180deg, rgba(255,255,255,.03), rgba(255,255,255,.01)),
    linear-gradient(180deg,var(--panel),var(--panel2));
  padding:16px 18px;
  margin-bottom:14px;
}
.hero h1{
  margin:0;
  font-size:22px;
  color:var(--gold);
}
.hero p{
  margin:8px 0 0;
  color:var(--muted);
  line-height:1.6;
  font-size:13px;
}
.grid{
  display:grid;
  grid-template-columns:1fr;
  gap:14px;
}
@media (min-width: 1080px){
  .grid{
    grid-template-columns:420px 1fr;
    align-items:start;
  }
}
.panel{
  border:1px solid var(--line);
  border-radius:20px;
  overflow:hidden;
  background:
    linear-gradient(180deg, rgba(255,255,255,.03), rgba(255,255,255,.01)),
    linear-gradient(180deg,var(--panel),var(--panel2));
}
.panelHead{
  padding:14px 16px;
  border-bottom:1px solid var(--line2);
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
}
.panelTitle{
  font-size:16px;
  font-weight:800;
  letter-spacing:.04em;
}
.badge{
  display:inline-flex;
  align-items:center;
  gap:7px;
  min-height:30px;
  padding:0 10px;
  border-radius:999px;
  border:1px solid rgba(255,255,255,.10);
  background:rgba(255,255,255,.04);
  font-size:11px;
  font-weight:800;
}
.dot{
  width:9px;height:9px;border-radius:50%;
  box-shadow:0 0 8px currentColor;
}
.badge.ok{color:#d9ffeb;border-color:rgba(66,255,157,.22);background:rgba(66,255,157,.08)}
.badge.ok .dot{background:var(--green);color:var(--green)}
.badge.warn{color:#ffeab7;border-color:rgba(255,207,104,.22);background:rgba(255,207,104,.08)}
.badge.warn .dot{background:var(--warn);color:var(--warn)}
.badge.err{color:#ffd5de;border-color:rgba(255,111,142,.22);background:rgba(255,111,142,.08)}
.badge.err .dot{background:var(--red);color:var(--red)}
.panelBody{padding:14px 16px 16px}
.stack{display:grid;gap:12px}
.label{
  font-size:12px;
  color:var(--muted);
  margin-bottom:6px;
}
.input,.select,.btn{
  width:100%;
  min-height:48px;
  border-radius:14px;
  font-size:15px;
}
.input,.select{
  border:1px solid rgba(255,255,255,.10);
  background:#080911;
  color:#fff;
  padding:0 14px;
  outline:none;
}
.input:focus,.select:focus{
  border-color:rgba(245,217,123,.28);
  box-shadow:0 0 0 3px rgba(245,217,123,.08);
}
.select{appearance:none}
.btn{
  border:0;
  cursor:pointer;
  font-weight:800;
}
.btnPrimary{background:linear-gradient(180deg,var(--purple),var(--purple2));color:#fff}
.btnDark{background:#141120;color:#fff;border:1px solid rgba(255,255,255,.10)}
.btnGold{background:linear-gradient(180deg,#f5d97b,#d7b259);color:#2b1e05}
.btnRow{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:12px;
}
@media (max-width: 560px){
  .btnRow{grid-template-columns:1fr}
}
.statusBox{
  min-height:84px;
  border-radius:14px;
  border:1px solid rgba(255,255,255,.10);
  background:#090912;
  color:#fff;
  padding:12px;
  line-height:1.6;
  white-space:pre-wrap;
  word-break:break-word;
}
.statusBox.ok{
  border-color:rgba(66,255,157,.22);
  background:rgba(14,37,24,.70);
  color:#d7ffea;
}
.statusBox.warn{
  border-color:rgba(255,207,104,.22);
  background:rgba(52,36,8,.62);
  color:#ffe9b5;
}
.statusBox.err{
  border-color:rgba(255,111,142,.22);
  background:rgba(52,15,23,.66);
  color:#ffd7df;
}
.kv{
  display:grid;
  grid-template-columns:1fr;
  gap:12px;
}
@media (min-width: 760px){
  .kv{grid-template-columns:repeat(2,minmax(0,1fr))}
}
.card{
  border:1px solid rgba(255,255,255,.08);
  border-radius:16px;
  background:rgba(0,0,0,.16);
  padding:12px;
}
.card .k{
  font-size:11px;
  color:var(--muted);
}
.card .v{
  margin-top:7px;
  font-size:15px;
  font-weight:800;
  word-break:break-all;
}
.pre{
  margin:0;
  border-radius:16px;
  border:1px solid rgba(255,255,255,.08);
  background:#090912;
  padding:14px;
  overflow:auto;
  max-height:620px;
  font-size:12px;
  line-height:1.55;
}
.tableWrap{
  overflow:auto;
  border:1px solid rgba(255,255,255,.08);
  border-radius:16px;
  background:#090912;
}
table{
  width:100%;
  border-collapse:collapse;
  min-width:720px;
}
th,td{
  text-align:left;
  padding:10px 12px;
  border-bottom:1px solid rgba(255,255,255,.06);
  font-size:12px;
  vertical-align:top;
}
th{
  color:var(--gold);
  position:sticky;
  top:0;
  background:#120f1c;
  z-index:1;
}
.flag{
  display:inline-flex;
  width:22px;
  height:16px;
  border-radius:4px;
  overflow:hidden;
  border:1px solid rgba(255,255,255,.14);
  background:#1c1530;
  vertical-align:middle;
}
.flag img{
  width:100%;
  height:100%;
  object-fit:cover;
  display:block;
}
.muted{color:var(--muted)}
.small{font-size:12px;color:var(--muted);line-height:1.6}
</style>
</head>
<body>
<div class="page">
  <section class="hero">
    <h1>RWA GEO TESTER</h1>
    <p>
      Standalone tester for RWA GEO APIs. This page does not require login and does not use topbar/footer.
      It is intended to verify endpoint reachability, response JSON shape, flags, country ordering, prefixes, states, and areas.
    </p>
  </section>

  <section class="grid">
    <section class="panel">
      <div class="panelHead">
        <div class="panelTitle">TEST CONTROLS</div>
        <div class="badge warn" id="overallBadge"><span class="dot"></span> READY</div>
      </div>
      <div class="panelBody">
        <div class="stack">
          <div>
            <div class="label">COUNTRY</div>
            <select class="select" id="countryCode"></select>
          </div>

          <div>
            <div class="label">PREFIX</div>
            <select class="select" id="prefixCode"></select>
          </div>

          <div>
            <div class="label">STATE / PROVINCE</div>
            <select class="select" id="stateId"></select>
          </div>

          <div>
            <div class="label">AREA / REGION</div>
            <select class="select" id="areaId"></select>
          </div>

          <div class="btnRow">
            <button class="btn btnPrimary" type="button" id="runAllBtn">RUN ALL TESTS</button>
            <button class="btn btnDark" type="button" id="reloadGeoBtn">RELOAD GEO</button>
          </div>

          <div class="btnRow">
            <button class="btn btnGold" type="button" id="testStatesBtn">TEST STATES</button>
            <button class="btn btnGold" type="button" id="testAreasBtn">TEST AREAS</button>
          </div>

          <div>
            <div class="label">STATUS</div>
            <div class="statusBox warn" id="statusBox">Ready.</div>
          </div>

          <div class="small">
            Flag path rule in tester:
            <br>
            <strong>/dashboard/assets/flags/{iso2}.png</strong>
          </div>
        </div>
      </div>
    </section>

    <section class="panel">
      <div class="panelHead">
        <div class="panelTitle">RESULTS</div>
        <div class="badge warn" id="jsonBadge"><span class="dot"></span> WAITING</div>
      </div>
      <div class="panelBody">
        <div class="stack">
          <div class="kv">
            <div class="card">
              <div class="k">COUNTRIES</div>
              <div class="v" id="countriesCount">0</div>
            </div>
            <div class="card">
              <div class="k">PREFIXES</div>
              <div class="v" id="prefixesCount">0</div>
            </div>
            <div class="card">
              <div class="k">STATES</div>
              <div class="v" id="statesCount">0</div>
            </div>
            <div class="card">
              <div class="k">AREAS</div>
              <div class="v" id="areasCount">0</div>
            </div>
          </div>

          <div class="tableWrap">
            <table>
              <thead>
                <tr>
                  <th>#</th>
                  <th>TYPE</th>
                  <th>VALUE</th>
                  <th>LABEL</th>
                  <th>FLAG</th>
                  <th>RAW EXTRA</th>
                </tr>
              </thead>
              <tbody id="previewTbody">
                <tr><td colspan="6" class="muted">No data loaded yet.</td></tr>
              </tbody>
            </table>
          </div>

          <pre class="pre" id="jsonOutput">Waiting...</pre>
        </div>
      </div>
    </section>
  </section>
</div>

<script>
(() => {
  'use strict';

  const GEO = {
    countries: '/rwa/api/geo/countries.php',
    prefixes:  '/rwa/api/geo/prefixes.php',
    states:    '/rwa/api/geo/states.php',
    areas:     '/rwa/api/geo/areas.php'
  };

  const state = {
    countries: [],
    prefixes: [],
    states: [],
    areas: [],
    lastPayloads: {}
  };

  const el = {
    overallBadge: document.getElementById('overallBadge'),
    jsonBadge: document.getElementById('jsonBadge'),
    statusBox: document.getElementById('statusBox'),
    countryCode: document.getElementById('countryCode'),
    prefixCode: document.getElementById('prefixCode'),
    stateId: document.getElementById('stateId'),
    areaId: document.getElementById('areaId'),
    runAllBtn: document.getElementById('runAllBtn'),
    reloadGeoBtn: document.getElementById('reloadGeoBtn'),
    testStatesBtn: document.getElementById('testStatesBtn'),
    testAreasBtn: document.getElementById('testAreasBtn'),
    countriesCount: document.getElementById('countriesCount'),
    prefixesCount: document.getElementById('prefixesCount'),
    statesCount: document.getElementById('statesCount'),
    areasCount: document.getElementById('areasCount'),
    previewTbody: document.getElementById('previewTbody'),
    jsonOutput: document.getElementById('jsonOutput')
  };

  function setBadge(node, tone, text) {
    node.className = 'badge ' + tone;
    node.innerHTML = '<span class="dot"></span> ' + text;
  }

  function setStatus(text, tone = 'warn') {
    el.statusBox.className = 'statusBox ' + tone;
    el.statusBox.textContent = text;
  }

  function normalizeList(payload) {
    if (Array.isArray(payload)) return payload;
    if (!payload || typeof payload !== 'object') return [];
    if (Array.isArray(payload.items)) return payload.items;
    if (Array.isArray(payload.rows)) return payload.rows;
    if (Array.isArray(payload.data)) return payload.data;
    if (Array.isArray(payload.list)) return payload.list;
    return [];
  }

  async function fetchJson(url) {
    const res = await fetch(url, {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    });

    const text = await res.text();
    let json = null;
    try { json = JSON.parse(text); } catch (e) {}

    if (!res.ok) {
      throw new Error('HTTP ' + res.status + ' @ ' + url + '\n\n' + text.slice(0, 500));
    }

    if (!json || typeof json !== 'object' && !Array.isArray(json)) {
      throw new Error('Invalid JSON @ ' + url + '\n\n' + text.slice(0, 500));
    }

    return json;
  }

  function lowerIso(v) {
    return String(v || '').trim().toLowerCase();
  }

  function flagUrl(iso2) {
    const iso = lowerIso(iso2);
    return iso ? ('/dashboard/assets/flags/' + iso + '.png') : '';
  }

  function countryLabel(item) {
    return String(item.name_en || item.name || item.label || item.iso2 || '');
  }

  function prefixLabel(item) {
    const prefix = String(item.prefix || item.code || item.dial_code || '');
    const name = String(item.name_en || item.name || item.iso2 || '');
    return prefix ? (prefix + ' · ' + name) : name;
  }

  function stateLabel(item) {
    return String(item.name_en || item.name || item.label || item.name_local || '');
  }

  function areaLabel(item) {
    return String(item.name_en || item.name || item.label || item.name_local || '');
  }

  function renderSelect(selectEl, items, valueKey, labelFn, selected = '', blankText = 'Select') {
    const frag = document.createDocumentFragment();

    const blank = document.createElement('option');
    blank.value = '';
    blank.textContent = blankText;
    frag.appendChild(blank);

    items.forEach(item => {
      const op = document.createElement('option');
      op.value = String(item[valueKey] ?? '');
      op.textContent = labelFn(item);
      frag.appendChild(op);
    });

    selectEl.innerHTML = '';
    selectEl.appendChild(frag);
    selectEl.value = String(selected ?? '');
  }

  function renderPreview() {
    const rows = [];

    state.countries.slice(0, 8).forEach((item, i) => {
      rows.push({
        no: i + 1,
        type: 'country',
        value: String(item.iso2 || ''),
        label: countryLabel(item),
        iso2: String(item.iso2 || ''),
        extra: JSON.stringify(item)
      });
    });

    state.prefixes.slice(0, 8).forEach((item, i) => {
      rows.push({
        no: rows.length + 1,
        type: 'prefix',
        value: String(item.prefix || item.code || item.dial_code || ''),
        label: prefixLabel(item),
        iso2: String(item.iso2 || item.country_code || ''),
        extra: JSON.stringify(item)
      });
    });

    state.states.slice(0, 6).forEach((item) => {
      rows.push({
        no: rows.length + 1,
        type: 'state',
        value: String(item.id || ''),
        label: stateLabel(item),
        iso2: '',
        extra: JSON.stringify(item)
      });
    });

    state.areas.slice(0, 6).forEach((item) => {
      rows.push({
        no: rows.length + 1,
        type: 'area',
        value: String(item.id || ''),
        label: areaLabel(item),
        iso2: '',
        extra: JSON.stringify(item)
      });
    });

    if (!rows.length) {
      el.previewTbody.innerHTML = '<tr><td colspan="6" class="muted">No data loaded yet.</td></tr>';
      return;
    }

    el.previewTbody.innerHTML = rows.map(r => {
      const url = flagUrl(r.iso2);
      return `
        <tr>
          <td>${r.no}</td>
          <td>${escapeHtml(r.type)}</td>
          <td>${escapeHtml(r.value)}</td>
          <td>${escapeHtml(r.label)}</td>
          <td>${url ? `<span class="flag"><img src="${escapeHtml(url)}" alt="${escapeHtml(r.iso2)}" onerror="this.closest('.flag').style.display='none'"></span>` : '-'}</td>
          <td>${escapeHtml(r.extra)}</td>
        </tr>
      `;
    }).join('');
  }

  function escapeHtml(v) {
    return String(v)
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function refreshCounts() {
    el.countriesCount.textContent = String(state.countries.length);
    el.prefixesCount.textContent = String(state.prefixes.length);
    el.statesCount.textContent = String(state.states.length);
    el.areasCount.textContent = String(state.areas.length);
  }

  function dumpJson() {
    const out = {
      endpoints: GEO,
      selected: {
        country: el.countryCode.value,
        prefix: el.prefixCode.value,
        state_id: el.stateId.value,
        area_id: el.areaId.value
      },
      counts: {
        countries: state.countries.length,
        prefixes: state.prefixes.length,
        states: state.states.length,
        areas: state.areas.length
      },
      payloads: state.lastPayloads
    };
    el.jsonOutput.textContent = JSON.stringify(out, null, 2);
  }

  async function loadCountries() {
    const payload = await fetchJson(GEO.countries);
    state.lastPayloads.countries = payload;
    state.countries = normalizeList(payload);

    renderSelect(el.countryCode, state.countries, 'iso2', countryLabel, 'MY', 'Select Country');
  }

  async function loadPrefixes() {
    const payload = await fetchJson(GEO.prefixes);
    state.lastPayloads.prefixes = payload;
    state.prefixes = normalizeList(payload);

    renderSelect(el.prefixCode, state.prefixes, 'prefix', prefixLabel, '+60', 'Select Prefix');
  }

  async function loadStates(countryCode) {
    const payload = await fetchJson(GEO.states + '?country=' + encodeURIComponent(countryCode));
    state.lastPayloads.states = payload;
    state.states = normalizeList(payload);

    renderSelect(el.stateId, state.states, 'id', stateLabel, '', 'Select State');
  }

  async function loadAreas(countryCode, stateId) {
    const payload = await fetchJson(GEO.areas + '?country=' + encodeURIComponent(countryCode) + '&state_id=' + encodeURIComponent(stateId));
    state.lastPayloads.areas = payload;
    state.areas = normalizeList(payload);

    renderSelect(el.areaId, state.areas, 'id', areaLabel, '', 'Select Area');
  }

  async function runAll() {
    try {
      setStatus('Loading countries and prefixes...', 'warn');
      setBadge(el.overallBadge, 'warn', 'RUNNING');
      setBadge(el.jsonBadge, 'warn', 'FETCHING');

      await loadCountries();
      await loadPrefixes();

      const country = el.countryCode.value || 'MY';
      setStatus('Loading states for country: ' + country, 'warn');
      await loadStates(country);

      const stateId = el.stateId.value || '';
      if (stateId) {
        setStatus('Loading areas for state: ' + stateId, 'warn');
        await loadAreas(country, stateId);
      } else {
        state.areas = [];
        state.lastPayloads.areas = { note: 'No state selected yet.' };
        renderSelect(el.areaId, [], 'id', areaLabel, '', 'Select Area');
      }

      refreshCounts();
      renderPreview();
      dumpJson();

      setStatus('GEO APIs loaded successfully.', 'ok');
      setBadge(el.overallBadge, 'ok', 'PASS');
      setBadge(el.jsonBadge, 'ok', 'JSON OK');
    } catch (err) {
      refreshCounts();
      renderPreview();
      dumpJson();

      setStatus(String(err && err.message ? err.message : err), 'err');
      setBadge(el.overallBadge, 'err', 'FAIL');
      setBadge(el.jsonBadge, 'err', 'ERROR');
    }
  }

  async function testStatesOnly() {
    try {
      const country = el.countryCode.value || 'MY';
      setStatus('Testing states endpoint for country=' + country, 'warn');
      await loadStates(country);
      refreshCounts();
      renderPreview();
      dumpJson();
      setStatus('States loaded successfully.', 'ok');
      setBadge(el.overallBadge, 'ok', 'STATES OK');
    } catch (err) {
      setStatus(String(err && err.message ? err.message : err), 'err');
      setBadge(el.overallBadge, 'err', 'STATES FAIL');
    }
  }

  async function testAreasOnly() {
    try {
      const country = el.countryCode.value || 'MY';
      const stateId = el.stateId.value || '';
      if (!stateId) {
        throw new Error('Please select a state first.');
      }
      setStatus('Testing areas endpoint for country=' + country + ', state_id=' + stateId, 'warn');
      await loadAreas(country, stateId);
      refreshCounts();
      renderPreview();
      dumpJson();
      setStatus('Areas loaded successfully.', 'ok');
      setBadge(el.overallBadge, 'ok', 'AREAS OK');
    } catch (err) {
      setStatus(String(err && err.message ? err.message : err), 'err');
      setBadge(el.overallBadge, 'err', 'AREAS FAIL');
    }
  }

  el.runAllBtn.addEventListener('click', runAll);
  el.reloadGeoBtn.addEventListener('click', runAll);
  el.testStatesBtn.addEventListener('click', testStatesOnly);
  el.testAreasBtn.addEventListener('click', testAreasOnly);

  el.countryCode.addEventListener('change', async () => {
    try {
      await loadStates(el.countryCode.value || 'MY');
      state.areas = [];
      renderSelect(el.areaId, [], 'id', areaLabel, '', 'Select Area');
      refreshCounts();
      renderPreview();
      dumpJson();
      setStatus('Country changed. States reloaded.', 'ok');
    } catch (err) {
      setStatus(String(err && err.message ? err.message : err), 'err');
    }
  });

  el.stateId.addEventListener('change', async () => {
    try {
      const stateId = el.stateId.value || '';
      if (!stateId) {
        state.areas = [];
        renderSelect(el.areaId, [], 'id', areaLabel, '', 'Select Area');
        refreshCounts();
        renderPreview();
        dumpJson();
        return;
      }
      await loadAreas(el.countryCode.value || 'MY', stateId);
      refreshCounts();
      renderPreview();
      dumpJson();
      setStatus('State changed. Areas reloaded.', 'ok');
    } catch (err) {
      setStatus(String(err && err.message ? err.message : err), 'err');
    }
  });

  runAll();
})();
</script>
</body>
</html>