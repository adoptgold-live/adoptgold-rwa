<?php
// /var/www/html/public/rwa/admin/i18n.php
// v1.0.20260314-rwa-admin-i18n-editor
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';

$user = session_user();
if (empty($user['id'])) {
    header('Location: /rwa/index.php');
    exit;
}

$pdo = function_exists('rwa_db') ? rwa_db() : ($GLOBALS['pdo'] ?? null);
if (!$pdo) {
    http_response_code(500);
    echo 'DB not ready';
    exit;
}

$wallet = strtolower(trim((string)($user['wallet_address'] ?? '')));
$ADMIN_WALLET = strtolower('0xE10dBB454971Bb9A485C6D9A0891E3d7369108d6');

if ($wallet !== $ADMIN_WALLET) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$lang = isset($_GET['lang']) ? trim((string)$_GET['lang']) : 'en';
if ($lang === '') $lang = 'en';

$csrf = function_exists('csrf_token') ? csrf_token('rwa_i18n_admin') : '';

function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>RWA Admin I18N</title>
<link rel="stylesheet" href="/rwa/assets/css/rwa-design-system.css">
<style>
.rwa-admin-wrap{max-width:1180px;margin:14px auto 92px;padding:0 12px;color:#efe7ff;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
.rwa-admin-card{background:linear-gradient(180deg,#0a0713,#07040f);border:1px solid rgba(173,92,255,.34);border-radius:18px;box-shadow:0 0 22px rgba(173,92,255,.10);padding:14px;margin-bottom:12px}
.rwa-admin-h{font-weight:900;letter-spacing:.6px;color:#c89bff;font-size:16px;margin:0 0 10px}
.rwa-admin-sub{font-size:12px;color:rgba(239,231,255,.76);line-height:1.35;margin:0 0 10px}
.rwa-admin-row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.rwa-admin-in,.rwa-admin-sel,.rwa-admin-ta{
  background:#050308;border:1px solid rgba(255,215,0,.25);color:#fff;border-radius:14px;
  padding:10px 12px;outline:none;min-height:42px;font-size:13px
}
.rwa-admin-in:focus,.rwa-admin-sel:focus,.rwa-admin-ta:focus{
  box-shadow:0 0 0 3px rgba(173,92,255,.22);border-color:#ad5cff
}
.rwa-admin-sel option{background:#050308;color:#fff}
.rwa-admin-ta{min-height:92px;width:100%;resize:vertical}
.rwa-admin-btn{
  border:1px solid rgba(173,92,255,.45);background:rgba(173,92,255,.09);color:#efe7ff;
  border-radius:14px;padding:10px 14px;font-weight:900;cursor:pointer;min-height:42px
}
.rwa-admin-btn:hover{filter:brightness(1.06)}
.rwa-admin-btn.gold{border-color:rgba(255,215,0,.45);background:rgba(255,215,0,.08);color:#ffe9a6}
.rwa-admin-btn.red{border-color:rgba(255,90,90,.5);background:rgba(255,90,90,.08);color:#ffd1d1}
.rwa-admin-btn.ghost{background:transparent}
.rwa-admin-msg{
  margin-top:10px;border-radius:14px;padding:10px 12px;border:1px solid rgba(173,92,255,.28);
  background:rgba(173,92,255,.08);font-size:12px;display:none
}
.rwa-admin-msg.err{border-color:rgba(255,90,90,.35);background:rgba(255,90,90,.08)}
.rwa-admin-grid{display:grid;grid-template-columns:1.05fr .95fr;gap:12px}
@media(max-width:980px){.rwa-admin-grid{grid-template-columns:1fr}}
.rwa-admin-table{width:100%;border-collapse:separate;border-spacing:0 10px}
.rwa-admin-tr{background:rgba(5,3,8,.72);border:1px solid rgba(173,92,255,.24)}
.rwa-admin-tr td{padding:10px 10px;vertical-align:top;font-size:12px}
.rwa-admin-pill{
  display:inline-block;padding:4px 10px;border-radius:999px;border:1px solid rgba(173,92,255,.45);
  font-weight:900;font-size:11px;color:#fff
}
.rwa-admin-pill.off{border-color:rgba(255,90,90,.45);color:#ffb3b3}
.rwa-admin-mono{word-break:break-word;font-size:12px}
.rwa-admin-actions{display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap}
.rwa-admin-highlight{outline:2px solid rgba(173,92,255,.28);box-shadow:0 0 0 3px rgba(173,92,255,.10)}
.rwa-admin-small{font-size:11px;color:rgba(239,231,255,.62)}
.rwa-admin-split{display:grid;grid-template-columns:1fr 1fr;gap:10px}
@media(max-width:700px){.rwa-admin-split{grid-template-columns:1fr}}
</style>
<script src="/rwa/inc/core/poado-i18n.js"></script>
</head>
<body>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-topbar-nav.php'; ?>

<div class="rwa-admin-wrap">

  <div class="rwa-admin-card">
    <div class="rwa-admin-h">RWA ADMIN · MANUAL TRANSLATION OVERRIDES</div>
    <div class="rwa-admin-sub">
      Runs <b>after</b> translator replacement layer (exact phrase replacement).<br>
      Storage: <b>wems_db.poado_i18n_terms</b> · API: <b>/rwa/api/admin/i18n.php</b>
    </div>

    <div class="rwa-admin-row">
      <select id="lang" class="rwa-admin-sel" style="min-width:160px">
        <?php
        $langs = ['en','ms','zh-CN','zh-TW','id','th','vi','tl','my','km','ja','ko','hi','bn','ta','ur','ar','fa','tr','ru','uk','pl','de','fr','es','pt','nl','it','sv','no','da','fi','el'];
        foreach ($langs as $l) {
            $sel = ($l === $lang) ? 'selected' : '';
            echo '<option value="'.h($l).'" '.$sel.'>'.h($l).'</option>';
        }
        ?>
      </select>

      <input id="q" class="rwa-admin-in" style="flex:1;min-width:240px" placeholder="Search (term_key / value / notes)">
      <button id="btnSearch" class="rwa-admin-btn">SEARCH</button>
      <button id="btnClear" class="rwa-admin-btn ghost">CLEAR</button>
      <button id="btnExport" class="rwa-admin-btn gold">EXPORT JSON</button>
    </div>

    <div id="msg" class="rwa-admin-msg"></div>
  </div>

  <div class="rwa-admin-grid">

    <div class="rwa-admin-card">
      <div class="rwa-admin-h">ADD / UPDATE TERM</div>

      <input type="hidden" id="editing_id" value="">
      <input type="hidden" id="csrf_token" value="<?= h($csrf) ?>">

      <div class="rwa-admin-split" style="margin-bottom:10px;">
        <input id="term_key" class="rwa-admin-in" placeholder="term_key (exact phrase)">
        <input id="term_value" class="rwa-admin-in" placeholder="term_value (replacement)">
      </div>

      <div class="rwa-admin-row" style="margin-bottom:10px;">
        <input id="notes" class="rwa-admin-in" style="flex:1;min-width:220px" placeholder="notes (optional)">
      </div>

      <div class="rwa-admin-row">
        <select id="is_active" class="rwa-admin-sel" style="min-width:140px">
          <option value="1">ACTIVE</option>
          <option value="0">DISABLED</option>
        </select>

        <button id="btnSave" class="rwa-admin-btn gold">SAVE</button>
        <button id="btnCancel" class="rwa-admin-btn ghost" style="display:none;">CANCEL EDIT</button>

        <div class="rwa-admin-small" id="editHint" style="margin-left:auto;display:none;">
          Editing mode enabled
        </div>
      </div>

      <div style="margin-top:14px;border-top:1px dashed rgba(255,215,0,.25);padding-top:12px;">
        <div class="rwa-admin-h" style="font-size:14px;margin-bottom:8px;">BULK PASTE (FAST)</div>
        <div class="rwa-admin-small" style="margin-bottom:8px;">
          One per line: <b>term_key[TAB]term_value</b> or CSV <b>term_key,term_value</b>. Optional third: notes.
        </div>
        <textarea id="bulk" class="rwa-admin-ta" placeholder="Example:
ACCEPT BOOKING	接受预约
Deal	成交
Mining	挖矿"></textarea>
        <div class="rwa-admin-row" style="margin-top:10px;">
          <button id="btnBulk" class="rwa-admin-btn">IMPORT BULK (UPSERT)</button>
          <button id="btnBulkClear" class="rwa-admin-btn ghost">CLEAR BULK</button>
        </div>
      </div>
    </div>

    <div class="rwa-admin-card">
      <div class="rwa-admin-h">TERMS</div>
      <div class="rwa-admin-small" id="loaded">Loaded: 0</div>
      <table class="rwa-admin-table" id="tbl"></table>
    </div>

  </div>
</div>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-bottom-nav.php'; ?>

<script>
(function(){
  const $ = (id)=>document.getElementById(id);
  const API = '/rwa/api/admin/i18n.php';
  const state = { rows: [], selectedRowEl: null };

  function setMsg(text, isErr){
    const m = $('msg');
    if (!text){
      m.style.display='none';
      m.textContent='';
      m.classList.remove('err');
      return;
    }
    m.style.display='block';
    m.textContent=text;
    if (isErr) m.classList.add('err'); else m.classList.remove('err');
  }

  function escapeHtml(s){
    return String(s || '')
      .replaceAll('&','&amp;')
      .replaceAll('<','&lt;')
      .replaceAll('>','&gt;')
      .replaceAll('"','&quot;')
      .replaceAll("'","&#039;");
  }

  function clearEditUI(){
    $('editing_id').value='';
    $('btnSave').textContent='SAVE';
    $('btnCancel').style.display='none';
    $('editHint').style.display='none';
    if (state.selectedRowEl){
      state.selectedRowEl.classList.remove('rwa-admin-highlight');
      state.selectedRowEl=null;
    }
  }

  function enterEdit(row, rowEl){
    clearEditUI();
    $('editing_id').value=String(row.id);
    $('term_key').value=row.term_key||'';
    $('term_value').value=row.term_value||'';
    $('notes').value=row.notes||'';
    $('is_active').value=String(row.is_active);

    $('btnSave').textContent='UPDATE';
    $('btnCancel').style.display='inline-block';
    $('editHint').style.display='block';

    state.selectedRowEl=rowEl;
    rowEl.classList.add('rwa-admin-highlight');
    $('term_key').scrollIntoView({behavior:'smooth', block:'center'});
    setMsg('Editing term ID #' + row.id);
  }

  async function load(){
    setMsg('Loading...');
    const lang = $('lang').value.trim();
    const q = $('q').value.trim();

    const u = new URL(API, window.location.origin);
    u.searchParams.set('mode','list');
    u.searchParams.set('lang', lang);
    if (q) u.searchParams.set('q', q);

    let js;
    try{
      const r = await fetch(u.toString(), {credentials:'same-origin'});
      js = await r.json();
    }catch(e){
      setMsg('Load failed: network error', true);
      return;
    }

    if (!js || !js.ok){
      setMsg((js && js.error) ? js.error : 'Load failed', true);
      return;
    }

    state.rows = js.rows || [];
    $('loaded').textContent = 'Loaded: ' + state.rows.length;

    const tbl = $('tbl');
    tbl.innerHTML='';
    clearEditUI();

    if (!state.rows.length){
      setMsg('No terms found.');
      return;
    }

    setMsg('');

    state.rows.forEach(row=>{
      const tr = document.createElement('tr');
      tr.className='rwa-admin-tr';
      tr.innerHTML = `
        <td style="width:34%">
          <div class="rwa-admin-mono"><b>${escapeHtml(row.term_key||'')}</b></div>
          <div class="rwa-admin-small">lang: ${escapeHtml(row.lang||'')}</div>
          ${row.updated_at ? `<div class="rwa-admin-small">updated: ${escapeHtml(row.updated_at)}</div>` : ''}
        </td>
        <td style="width:34%">
          <div class="rwa-admin-mono">${escapeHtml(row.term_value||'')}</div>
          ${row.notes ? `<div class="rwa-admin-small" style="margin-top:6px;">${escapeHtml(row.notes)}</div>` : ''}
        </td>
        <td style="width:12%">
          ${row.is_active==1 ? `<span class="rwa-admin-pill">ACTIVE</span>` : `<span class="rwa-admin-pill off">OFF</span>`}
        </td>
        <td style="width:20%">
          <div class="rwa-admin-actions">
            <button class="rwa-admin-btn" data-toggle>${row.is_active==1 ? 'DISABLE' : 'ENABLE'}</button>
            <button class="rwa-admin-btn" data-edit>EDIT</button>
            <button class="rwa-admin-btn red" data-del>DELETE</button>
          </div>
        </td>
      `;
      tr.querySelector('[data-edit]').addEventListener('click', ()=>enterEdit(row,tr));
      tr.querySelector('[data-del]').addEventListener('click', ()=>del(row.id, row.term_key));
      tr.querySelector('[data-toggle]').addEventListener('click', ()=>toggle(row));
      tbl.appendChild(tr);
    });
  }

  async function save(){
    const lang = $('lang').value.trim();
    const term_key = $('term_key').value.trim();
    const term_value = $('term_value').value.trim();
    const notes = $('notes').value.trim();
    const is_active = $('is_active').value;
    const id = $('editing_id').value.trim();
    const csrf_token = $('csrf_token').value;

    if (!term_key){ setMsg('term_key is required', true); $('term_key').focus(); return; }
    if (!term_value){ setMsg('term_value is required', true); $('term_value').focus(); return; }

    const fd = new FormData();
    fd.append('mode','upsert');
    fd.append('csrf_token', csrf_token);
    fd.append('lang', lang);
    fd.append('term_key', term_key);
    fd.append('term_value', term_value);
    fd.append('notes', notes);
    fd.append('is_active', is_active);
    if (id) fd.append('id', id);

    setMsg(id ? 'Updating...' : 'Saving...');

    let js;
    try{
      const r = await fetch(API, {method:'POST', body:fd, credentials:'same-origin'});
      js = await r.json();
    }catch(e){
      setMsg('Save failed: network error', true);
      return;
    }

    if (!js || !js.ok){
      setMsg((js && js.error) ? js.error : 'Save failed', true);
      return;
    }

    setMsg(js.msg || 'Saved');
    $('term_key').value='';
    $('term_value').value='';
    $('notes').value='';
    $('is_active').value='1';
    clearEditUI();
    await load();
  }

  async function toggle(row){
    $('editing_id').value = String(row.id);
    $('term_key').value = row.term_key || '';
    $('term_value').value = row.term_value || '';
    $('notes').value = row.notes || '';
    $('is_active').value = (row.is_active==1 ? '0' : '1');
    await save();
  }

  async function del(id, label){
    if (!confirm('Delete term: ' + (label || '#'+id) + ' ?')) return;

    const fd = new FormData();
    fd.append('mode','delete');
    fd.append('csrf_token', $('csrf_token').value);
    fd.append('id', String(id));

    setMsg('Deleting...');

    let js;
    try{
      const r = await fetch(API, {method:'POST', body:fd, credentials:'same-origin'});
      js = await r.json();
    }catch(e){
      setMsg('Delete failed: network error', true);
      return;
    }

    if (!js || !js.ok){
      setMsg((js && js.error) ? js.error : 'Delete failed', true);
      return;
    }

    setMsg('Deleted');
    clearEditUI();
    await load();
  }

  function exportJson(){
    const lang = $('lang').value.trim();
    const u = new URL(API, window.location.origin);
    u.searchParams.set('lang', lang);

    fetch(u.toString(), {credentials:'same-origin'})
      .then(r=>r.json())
      .then(js=>{
        if (!js || !js.ok) throw new Error(js && js.error ? js.error : 'Export failed');
        const blob = new Blob([JSON.stringify(js.dict || {}, null, 2)], {type:'application/json'});
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'rwa-i18n-' + lang + '.json';
        a.click();
        URL.revokeObjectURL(a.href);
        setMsg('Exported JSON for ' + lang);
      })
      .catch(e=>setMsg(String(e.message||e), true));
  }

  async function bulkImport(){
    const raw = $('bulk').value || '';
    const lines = raw.split('\n').map(l=>l.trim()).filter(Boolean);
    if (!lines.length){ setMsg('Bulk paste is empty', true); return; }

    const lang = $('lang').value.trim();
    const csrf_token = $('csrf_token').value;

    let ok=0, fail=0;
    setMsg('Importing ' + lines.length + ' lines...');

    for (const line of lines){
      let parts = line.split('\t');
      if (parts.length < 2) parts = line.split(',');
      const k = (parts[0]||'').trim();
      const v = (parts[1]||'').trim();
      const notes = (parts[2]||'').trim();
      if (!k || !v){ fail++; continue; }

      const fd = new FormData();
      fd.append('mode','upsert');
      fd.append('csrf_token', csrf_token);
      fd.append('lang', lang);
      fd.append('term_key', k);
      fd.append('term_value', v);
      fd.append('notes', notes);
      fd.append('is_active', '1');

      try{
        const r = await fetch(API, {method:'POST', body:fd, credentials:'same-origin'});
        const js = await r.json();
        if (js && js.ok) ok++; else fail++;
      }catch(e){
        fail++;
      }
    }

    setMsg('Bulk import done. OK=' + ok + ' FAIL=' + fail, fail>0);
    await load();
  }

  $('btnSearch').addEventListener('click', load);
  $('btnClear').addEventListener('click', ()=>{ $('q').value=''; clearEditUI(); load(); });
  $('btnSave').addEventListener('click', save);
  $('btnCancel').addEventListener('click', ()=>{ clearEditUI(); setMsg('Edit canceled'); });
  $('lang').addEventListener('change', ()=>{ clearEditUI(); load(); });
  $('q').addEventListener('keydown', (e)=>{ if (e.key==='Enter') load(); });
  $('btnExport').addEventListener('click', exportJson);
  $('btnBulk').addEventListener('click', bulkImport);
  $('btnBulkClear').addEventListener('click', ()=>{ $('bulk').value=''; setMsg('Bulk cleared'); });

  load();
})();
</script>
</body>
</html>