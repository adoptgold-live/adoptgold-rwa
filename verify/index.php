<?php
declare(strict_types=1);

$docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? '/var/www/html/public'), '/');
$coreBootstrap = $docRoot . '/rwa/inc/core/bootstrap.php';
if (is_file($coreBootstrap)) {
    require_once $coreBootstrap;
}
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>RWA Verify</title>
  <link rel="stylesheet" href="/rwa/assets/css/verify.css">
</head>
<body>
  <main class="rv-shell">
    <section class="rv-card rv-hero">
      <div class="rv-badge">RWA VERIFY</div>
      <h1>TON Jetton + RWA UID Verifier</h1>
      <p>Verify locked token masters, metadata integrity, and canonical RWA Cert UID structure.</p>
    </section>

    <section class="rv-card">
      <form id="rvForm" class="rv-form" autocomplete="off">
        <label class="rv-label" for="rvQuery">Query</label>
        <input id="rvQuery" name="q" class="rv-input" type="text" placeholder="WEMS / EMA / EMX / EMS / USDT / jetton master / cert uid">
        <div class="rv-actions">
          <button type="submit" class="rv-btn rv-btn-primary">Verify</button>
          <button type="button" id="rvBtnWems" class="rv-btn">wEMS</button>
          <button type="button" id="rvBtnEma" class="rv-btn">EMA</button>
          <button type="button" id="rvBtnEmx" class="rv-btn">EMX</button>
          <button type="button" id="rvBtnEms" class="rv-btn">EMS</button>
          <button type="button" id="rvBtnUsdt" class="rv-btn">USDT</button>
        </div>
      </form>
    </section>

    <section class="rv-grid">
      <article class="rv-card">
        <h2>Supported inputs</h2>
        <ul class="rv-list">
          <li>Locked token symbols: EMA, EMX, EMS, WEMS, USDT</li>
          <li>Locked TON jetton masters and raw masters</li>
          <li>Canonical RWA Cert UID format</li>
        </ul>
      </article>

      <article class="rv-card">
        <h2>Metadata mode</h2>
        <p><code>RWA_VERIFY_METADATA_MODE</code> defaults to <strong>soft</strong>.</p>
        <p>Set it to <strong>strict</strong> if you want verification to fail when metadata JSON is missing or mismatched.</p>
      </article>
    </section>

    <section id="rvResultWrap" class="rv-card rv-result is-empty">
      <div class="rv-result-head">
        <h2>Result</h2>
        <span id="rvStatusPill" class="rv-pill">idle</span>
      </div>
      <pre id="rvResult" class="rv-pre">Awaiting query.</pre>
    </section>
  </main>

  <script src="/rwa/assets/js/verify.js"></script>
</body>
</html>
