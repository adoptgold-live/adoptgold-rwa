<?php
declare(strict_types=1);

$uid = trim((string)($_GET['uid'] ?? $_GET['cert_uid'] ?? ''));
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>NFT Guard · RWA Cert</title>
  <style>
    :root{--bg:#0a0d14;--panel:#141a26;--line:#2b344a;--text:#edf2ff;--muted:#99a5c5;--ok:#38d97a;--bad:#ff5a77;--warn:#f0c15a}
    *{box-sizing:border-box} body{margin:0;background:var(--bg);color:var(--text);font:14px/1.5 system-ui,sans-serif}
    .wrap{max-width:1080px;margin:0 auto;padding:20px}
    .card{background:var(--panel);border:1px solid var(--line);border-radius:16px;padding:16px;margin-bottom:14px}
    .grid{display:grid;grid-template-columns:1.2fr .8fr;gap:14px}
    @media (max-width:900px){.grid{grid-template-columns:1fr}}
    .pill{display:inline-block;padding:8px 12px;border-radius:999px;border:1px solid var(--line)}
    .ok{color:var(--ok)} .bad{color:var(--bad)} .warn{color:var(--warn)} .muted{color:var(--muted)}
    .mono{font-family:ui-monospace,monospace}
    .box{min-height:280px;display:flex;align-items:center;justify-content:center;border:1px dashed var(--line);border-radius:14px}
    .kv{display:grid;grid-template-columns:160px 1fr;gap:8px 12px}
    img{max-width:100%;height:auto;border-radius:12px}
    pre{margin:0;white-space:pre-wrap;word-break:break-word;background:#0d1220;border:1px solid var(--line);border-radius:12px;padding:12px}
    .btn{display:inline-block;padding:10px 14px;border:1px solid var(--line);border-radius:12px;text-decoration:none;color:var(--text);margin-right:8px}
  </style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1 style="margin:0 0 8px">NFT Guard</h1>
    <div class="muted">verify.json is the single source of truth for NFT health.</div>
    <div style="margin-top:12px"><span id="state" class="pill warn">Loading…</span></div>
  </div>

  <div class="grid">
    <div class="card">
      <div class="box" id="preview">Waiting for verify truth…</div>
    </div>
    <div class="card">
      <div class="kv">
        <div>Cert UID</div><div id="uid" class="mono"><?= htmlspecialchars($uid, ENT_QUOTES, 'UTF-8') ?: '—' ?></div>
        <div>Healthy</div><div id="healthy">—</div>
        <div>Artifact Ready</div><div id="ready">—</div>
        <div>Authority</div><div id="authority">—</div>
        <div>Engine</div><div id="engine">—</div>
        <div>SHA1</div><div id="sha1" class="mono">—</div>
        <div>Size</div><div id="size" class="mono">—</div>
        <div>Fallback</div><div id="fallback">—</div>
      </div>
      <div style="margin-top:14px">
        <a class="btn" id="verifyBtn" href="#">Verify</a>
        <a class="btn" id="mintBtn" href="#">Mint</a>
        <a class="btn" href="" onclick="location.reload();return false;">Refresh</a>
      </div>
    </div>
  </div>

  <div class="card">
    <pre id="raw">Loading…</pre>
  </div>
</div>

<script>
(function(){
  const qs = new URLSearchParams(location.search);
  const uid = qs.get('uid') || qs.get('cert_uid') || <?= json_encode($uid, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
  const $ = (id) => document.getElementById(id);
  $('verifyBtn').href = '/rwa/cert/verify.php?uid=' + encodeURIComponent(uid || '');
  $('mintBtn').href   = '/rwa/cert/mint.php?uid='   + encodeURIComponent(uid || '');

  if (!uid) {
    $('state').className = 'pill bad';
    $('state').textContent = 'Missing cert UID';
    $('raw').textContent = 'uid or cert_uid is required';
    return;
  }

  fetch('/rwa/cert/api/_nft-guard.php?uid=' + encodeURIComponent(uid) + '&_=' + Date.now(), {
    credentials: 'same-origin',
    headers: { 'Accept':'application/json' }
  })
  .then(async (r) => {
    const j = await r.json().catch(() => null);
    if (!r.ok || !j) throw new Error((j && (j.error || j.message)) || ('HTTP_' + r.status));
    return j;
  })
  .then((j) => {
    const healthy = j.healthy === true || j.nft_healthy === true;
    const ready   = j.artifact_ready === true;
    $('state').className = 'pill ' + (healthy && ready ? 'ok' : 'warn');
    $('state').textContent = healthy && ready ? 'NFT Guard PASS' : 'NFT Guard CHECK';
    $('uid').textContent = j.cert_uid || uid;
    $('healthy').textContent = healthy ? 'YES' : 'NO';
    $('ready').textContent = ready ? 'YES' : 'NO';
    $('authority').textContent = j.image_authority || '—';
    $('engine').textContent = j.compose_engine || '—';
    $('sha1').textContent = j.final_sha1 || '—';
    $('size').textContent = String(j.final_size || '—');
    $('fallback').textContent = j.used_fallback_placeholder ? 'YES' : 'NO';
    const imageUrl = j.image_url || j.final_image_url || '';
    $('preview').innerHTML = imageUrl ? '<img src="' + String(imageUrl).replace(/"/g,'&quot;') + '" alt="NFT Preview">' : '<div class="muted">No preview image</div>';
    $('raw').textContent = JSON.stringify(j, null, 2);
  })
  .catch((e) => {
    $('state').className = 'pill bad';
    $('state').textContent = e.message || 'LOAD_FAILED';
    $('raw').textContent = String(e && e.stack || e);
  });
})();
</script>
</body>
</html>
