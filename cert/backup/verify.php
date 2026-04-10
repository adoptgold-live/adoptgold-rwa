<?php
/**
 * /rwa/cert/verify.php
 * FINAL VERIFY (DEMO)
 * - Same format for both certs
 * - Theme differs by UID prefix (HC- => Health, else Gold)
 *
 * LOCKS:
 * - Include order: /rwa/inc/rwa-session.php -> /dashboard/inc/session-user.php
 * - Must include /rwa/inc/rwa-topbar-nav.php + /rwa/inc/rwa-bottom-nav.php
 * - No /dashboard/inc/gt.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../inc/rwa-session.php';
require_once __DIR__ . '/../../dashboard/inc/session-user.php';
require_once __DIR__ . '/../inc/rwa-topbar-nav.php';

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$uid = isset($_GET['uid']) ? trim((string)$_GET['uid']) : '';
if ($uid === '') $uid = 'GC-' . gmdate('Ymd') . '-SAMPLE00';

$isHealth = (stripos($uid, 'HC-') === 0);
$type = $isHealth ? 'Health Cert' : 'Gold Cert';
$code = $isHealth ? 'RLIFE-HEALTH' : 'RK92-EMA';
$theme = $isHealth ? 'pinkgold' : 'premiumgold';

$verifyUrl = 'https://adoptgold.app/rwa/cert/verify.php?uid=' . rawurlencode($uid);
$issuedUtc = gmdate('Y-m-d H:i:s') . ' UTC';

$issuer = 'Blockchain Group Ltd. (Hong Kong) · RWA Standard Organisation (RSO)';
$holder = 'Sample Holder';
$asset = $isHealth ? 'Health Responsibility Adoption' : 'Gold Mining Responsibility Adoption';
$assetId = $isHealth ? 'RLIFE-HEALTH-DEMO' : 'RK92-EMA-DEMO';
$unitRule = $isHealth ? 'Health Responsibility Unit (demo)' : '1g Gold mined = 1 Mining Responsibility Unit (demo)';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>RWA · Verify</title>
  <style>
    :root{
      --bg:#07060a;
      --ink:rgba(245,245,255,.92);
      --muted:rgba(210,205,255,.68);
      --line:rgba(140,100,255,.18);
      --r:18px; --pad:14px;
      --pink:#ff77c8;
      --gold:#ffd36b;
    }
    body{
      margin:0;
      background:
        radial-gradient(900px 540px at 20% 0%, rgba(140,100,255,.18), transparent 60%),
        radial-gradient(900px 540px at 90% 20%, rgba(80,160,255,.12), transparent 60%),
        var(--bg);
      color:var(--ink);
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono","Courier New", monospace;
    }
    .wrap{ max-width:980px; margin:0 auto; padding:14px 12px 10px; }
    .card{
      border:1px solid var(--line);
      background: rgba(10,8,16,.72);
      border-radius: var(--r);
      padding: var(--pad);
      box-shadow: 0 0 22px rgba(140,100,255,.08);
    }
    .badge{
      display:inline-flex; align-items:center; gap:8px;
      padding:6px 10px; border-radius:999px;
      border:1px solid rgba(140,100,255,.28);
      background: rgba(20,16,30,.50);
      font-size:12px;
    }
    .dot{ width:8px; height:8px; border-radius:50%; background: <?= $isHealth ? 'var(--pink)' : 'var(--gold)' ?>; box-shadow:0 0 0 3px rgba(255,211,107,.12); }
    .title{ font-size:18px; font-weight:900; margin-top:10px; }
    .grid{ display:grid; grid-template-columns:1fr; gap:12px; margin-top:12px; }
    @media (min-width: 880px){ .grid{ grid-template-columns:1.2fr .8fr; } }
    .kv{ border:1px dashed rgba(140,100,255,.18); border-radius:14px; padding:10px 12px; background: rgba(0,0,0,.18); }
    .k{ color: rgba(240,240,255,.90); font-weight:900; font-size:11px; }
    .v{ margin-top:6px; color: var(--muted); word-break: break-word; }
    .btn{
      display:inline-flex; align-items:center; justify-content:center;
      padding:10px 12px; border-radius:14px;
      border:1px solid rgba(140,100,255,.25);
      background: rgba(20,16,30,.55);
      color: var(--ink);
      text-decoration:none;
      font-size:12px;
      font-weight:900;
      margin-top:10px;
    }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <div class="badge"><span class="dot"></span> VERIFIED (DEMO)</div>
      <div class="title"><?= h($type) ?> · Verification</div>

      <div class="grid">
        <div>
          <div class="kv"><div class="k">CERT UID</div><div class="v"><?= h($uid) ?></div></div>
          <div class="kv" style="margin-top:10px;"><div class="k">RWA CODE</div><div class="v"><?= h($code) ?></div></div>
          <div class="kv" style="margin-top:10px;"><div class="k">ISSUED AT (UTC)</div><div class="v"><?= h($issuedUtc) ?></div></div>
          <div class="kv" style="margin-top:10px;"><div class="k">ISSUER</div><div class="v"><?= h($issuer) ?></div></div>
          <div class="kv" style="margin-top:10px;"><div class="k">HOLDER</div><div class="v"><?= h($holder) ?></div></div>
          <div class="kv" style="margin-top:10px;"><div class="k">ASSET</div><div class="v"><?= h($asset) ?></div></div>
          <div class="kv" style="margin-top:10px;"><div class="k">ASSET ID</div><div class="v"><?= h($assetId) ?></div></div>
          <div class="kv" style="margin-top:10px;"><div class="k">UNIT RULE</div><div class="v"><?= h($unitRule) ?></div></div>

          <a class="btn" href="/rwa/cert/pdf.php?uid=<?= h(rawurlencode($uid)) ?>" target="_blank" rel="noopener">OPEN PDF (QR INSIDE)</a>
          <a class="btn" href="/rwa/cert/" style="margin-left:8px;">BACK TO ISSUE</a>
        </div>

        <div>
          <div class="kv">
            <div class="k">VERIFY URL</div>
            <div class="v"><?= h($verifyUrl) ?></div>
          </div>
          <div class="kv" style="margin-top:10px;">
            <div class="k">NOTE</div>
            <div class="v">This is a marketing demo certificate. Not a commodity, not a financial product, no yield promise.</div>
          </div>
        </div>

      </div>
    </div>
  </div>

  <script src="/dashboard/inc/poado-i18n.js?v=1"></script>
  <?php require_once __DIR__ . '/../inc/rwa-bottom-nav.php'; ?>
</body>
</html>
