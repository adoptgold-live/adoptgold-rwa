<?php
/**
 * /rwa/cert/index.php
 * Final DEMO Issuer: Health Cert + Green Cert + Gold Cert (QR + Verify + PDF)
 *
 * - NO DB writes
 * - NO local file storage
 * - QR points to /rwa/cert/verify.php?uid=...
 * - PDF via /rwa/cert/pdf.php?uid=...
 */

declare(strict_types=1);

/* =========================
   1) Mandatory includes (LOCKED ORDER)
========================= */
require_once __DIR__ . '/../inc/rwa-session.php';
require_once __DIR__ . '/../../dashboard/inc/session-user.php';
require_once __DIR__ . '/../../dashboard/inc/qr.php';

/* =========================
   2) Session + CSRF
========================= */
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

function rwa_csrf_get(): string {
  if (empty($_SESSION['rwa_csrf_token']) || !is_string($_SESSION['rwa_csrf_token'])) {
    $_SESSION['rwa_csrf_token'] = bin2hex(random_bytes(16));
  }
  return $_SESSION['rwa_csrf_token'];
}
function rwa_csrf_verify(?string $token): bool {
  $sess = $_SESSION['rwa_csrf_token'] ?? '';
  return is_string($token) && is_string($sess) && hash_equals($sess, $token);
}

/* =========================
   3) Helpers
========================= */
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function gen_uid(string $prefix): string {
  return $prefix . '-' . gmdate('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}

function verify_url_for_uid(string $uid): string {
  return 'https://adoptgold.app/rwa/cert/verify.php?uid=' . rawurlencode($uid);
}

function issue_sample(string $type, ?string $fixedUid = null): array {
  $nowUtc = gmdate('Y-m-d H:i:s') . ' UTC';
  $issuer = 'Blockchain Group Ltd. (Hong Kong) · RWA Standard Organisation (RSO)';

  if ($type === 'health') {
    return [
      'cert_type'   => 'Health Cert',
      'cert_code'   => 'RLIFE-HEALTH',
      'cert_uid'    => $fixedUid ?: gen_uid('HC'),
      'issued_at'   => $nowUtc,
      'issuer'      => $issuer,
      'holder_name' => 'Sample Holder',
      'unit_rule'   => 'Health Responsibility Unit (demo)',
      'memo'        => 'Sample health issuance for marketing / UI demo (non-financial, non-investment).',
      'accent'      => 'health',
      'status'      => 'issued (sample)',
    ];
  }

  if ($type === 'green') {
    return [
      'cert_type'   => 'Green Cert',
      'cert_code'   => 'RCO2C-EMA',
      'cert_uid'    => $fixedUid ?: gen_uid('GCN'),
      'issued_at'   => $nowUtc,
      'issuer'      => $issuer,
      'holder_name' => 'Sample Holder',
      'unit_rule'   => '1 Carbon Block = 1 Green Responsibility Unit (demo)',
      'memo'        => 'Sample green issuance for marketing / UI demo (non-financial, non-investment).',
      'accent'      => 'green',
      'status'      => 'issued (sample)',
    ];
  }

  return [
    'cert_type'   => 'Gold Cert',
    'cert_code'   => 'RK92-EMA',
    'cert_uid'    => $fixedUid ?: gen_uid('GC'),
    'issued_at'   => $nowUtc,
    'issuer'      => $issuer,
    'holder_name' => 'Sample Holder',
    'unit_rule'   => '1g Gold mined = 1 Mining Responsibility Unit (demo)',
    'memo'        => 'Sample gold issuance for marketing / UI demo (non-financial, non-investment).',
    'accent'      => 'gold',
    'status'      => 'issued (sample)',
  ];
}

/* =========================
   4) Actions
========================= */
$csrfToken = rwa_csrf_get();
$certs = [];
$err = '';

$uidView = '';
if (isset($_GET['uid']))  $uidView = trim((string)$_GET['uid']);
if ($uidView === '' && isset($_GET['view'])) $uidView = trim((string)$_GET['view']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $csrf = $_POST['csrf_token'] ?? null;
  if (!rwa_csrf_verify(is_string($csrf) ? $csrf : null)) {
    http_response_code(400);
    $err = 'Invalid session (CSRF). Please refresh and try again.';
  } else {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'issue_health') {
      $certs[] = issue_sample('health');
    } elseif ($action === 'issue_green') {
      $certs[] = issue_sample('green');
    } elseif ($action === 'issue_gold') {
      $certs[] = issue_sample('gold');
    } elseif ($action === 'issue_both') {
      $certs[] = issue_sample('health');
      $certs[] = issue_sample('green');
      $certs[] = issue_sample('gold');
    }
  }
}

if ($uidView !== '' && empty($certs)) {
  if (stripos($uidView, 'HC-') === 0) {
    $certs[] = issue_sample('health', $uidView);
  } elseif (stripos($uidView, 'GCN-') === 0) {
    $certs[] = issue_sample('green', $uidView);
  } else {
    $certs[] = issue_sample('gold', $uidView);
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"/>
  <title>RWA · Certificate Issuer</title>

  <style>
    :root{
      --bg:#07060a;
      --card:#0d0b14;
      --ink:rgba(245,245,255,.92);
      --muted:rgba(210,205,255,.68);
      --line:rgba(140,100,255,.18);

      --health:#ff9fc6;
      --green:#36ff9a;
      --gold:#ffd36b;
      --danger:#ff4d6d;

      --r:18px;
      --pad:14px;
    }

    *{ box-sizing:border-box; }

    body{
      margin:0;
      background:
        radial-gradient(900px 540px at 20% 0%, rgba(140,100,255,.18), transparent 60%),
        radial-gradient(900px 540px at 90% 20%, rgba(80,160,255,.12), transparent 60%),
        var(--bg);
      color:var(--ink);
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    }

    .wrap{ max-width:1100px; margin:0 auto; padding:14px 12px 90px; }

    .hero{
      border:1px solid var(--line);
      background: linear-gradient(180deg, rgba(255,255,255,.03), rgba(255,255,255,.015));
      border-radius: var(--r);
      padding: var(--pad);
      box-shadow: 0 0 0 1px rgba(255,255,255,.02), 0 18px 55px rgba(0,0,0,.45);
    }
    .hero .t{ font-size:16px; font-weight:900; letter-spacing:.2px; }
    .hero .s{ margin-top:6px; font-size:12px; color:var(--muted); line-height:1.45; }

    .grid{
      display:grid;
      grid-template-columns:1fr;
      gap:12px;
      margin-top:12px;
    }
    @media (min-width: 980px){
      .grid{ grid-template-columns: 380px 1fr; }
    }

    .card{
      border:1px solid var(--line);
      background: rgba(10,8,16,.72);
      border-radius: var(--r);
      padding: var(--pad);
      box-shadow: 0 0 22px rgba(140,100,255,.08);
    }

    .sec-title{
      font-weight:900;
      font-size:13px;
      letter-spacing:.2px;
    }

    .btn-row{
      display:flex;
      gap:10px;
      flex-wrap:wrap;
      margin-top:12px;
    }

    .btn{
      appearance:none;
      border:1px solid rgba(140,100,255,.25);
      background: linear-gradient(180deg, rgba(140,100,255,.16), rgba(20,16,30,.65));
      color:var(--ink);
      border-radius: 14px;
      padding: 12px 14px;
      font-weight: 900;
      cursor:pointer;
      min-width: 140px;
      text-align:center;
      text-decoration:none;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:8px;
    }
    .btn:active{ transform: translateY(1px); }
    .btn.health{ border-color: rgba(255,159,198,.35); box-shadow: 0 0 0 1px rgba(255,159,198,.08); }
    .btn.green{ border-color: rgba(54,255,154,.35); box-shadow: 0 0 0 1px rgba(54,255,154,.08); }
    .btn.gold{ border-color: rgba(255,211,107,.35); box-shadow: 0 0 0 1px rgba(255,211,107,.08); }
    .btn.small{ min-width:unset; padding:10px 12px; font-size:12px; }

    .note{
      margin-top:10px;
      font-size:12px;
      color:var(--muted);
      line-height:1.5;
    }

    .err{
      margin-top:10px;
      font-size:12px;
      color:var(--danger);
      border:1px solid rgba(255,77,109,.35);
      background: rgba(255,77,109,.06);
      padding:10px 12px;
      border-radius: 14px;
    }

    .cert{
      border:1px solid var(--line);
      border-radius: 20px;
      background: linear-gradient(180deg, rgba(255,255,255,.03), rgba(255,255,255,.015));
      padding: 16px;
      position: relative;
      overflow:hidden;
    }
    .cert:before{
      content:"";
      position:absolute; inset:-40px;
      background:
        radial-gradient(420px 240px at 15% 20%, rgba(140,100,255,.20), transparent 65%),
        radial-gradient(420px 240px at 85% 30%, rgba(80,160,255,.12), transparent 65%);
      pointer-events:none;
    }
    .cert-inner{
      position:relative;
      z-index:1;
      display:grid;
      grid-template-columns:1fr;
      gap:12px;
    }
    @media (min-width: 860px){
      .cert-inner{ grid-template-columns: 1.2fr 0.8fr; }
    }

    .badge{
      display:inline-flex;
      align-items:center;
      gap:8px;
      padding: 6px 10px;
      border-radius: 999px;
      border:1px solid rgba(140,100,255,.28);
      background: rgba(20,16,30,.50);
      font-size:12px;
      color: rgba(255,255,255,.92);
      white-space:nowrap;
    }
    .dot{
      width:8px; height:8px; border-radius:50%;
      background: var(--green);
      box-shadow: 0 0 0 3px rgba(54,255,154,.12);
    }

    .cert-title{
      font-size:18px;
      font-weight:900;
      letter-spacing:.3px;
      margin: 8px 0 0;
    }

    .cert-meta{
      margin-top:10px;
      display:grid;
      grid-template-columns:1fr;
      gap:8px;
      font-size:12px;
      color: var(--muted);
      line-height:1.35;
    }
    @media (min-width: 640px){
      .cert-meta{ grid-template-columns: 1fr 1fr; }
    }

    .kv{
      border:1px dashed rgba(140,100,255,.18);
      border-radius: 14px;
      padding: 10px 12px;
      background: rgba(0,0,0,.18);
    }
    .k{ color: rgba(240,240,255,.90); font-weight:900; font-size:11px; }
    .v{ margin-top:6px; color: var(--muted); word-break: break-word; }

    .qrbox{
      border:1px solid rgba(140,100,255,.22);
      border-radius: 18px;
      background: rgba(0,0,0,.22);
      padding: 12px;
    }
    .qrimg{ width:100%; max-width:320px; margin:0 auto; display:block; }
    .qrtext{
      margin-top:10px;
      font-size:11px;
      color: var(--muted);
      word-break: break-all;
      line-height:1.35;
    }

    .accent-health{ border-color: rgba(255,159,198,.28); box-shadow: 0 0 0 1px rgba(255,159,198,.06), 0 0 26px rgba(255,159,198,.06); }
    .accent-green{ border-color: rgba(54,255,154,.28); box-shadow: 0 0 0 1px rgba(54,255,154,.06), 0 0 26px rgba(54,255,154,.06); }
    .accent-gold{ border-color: rgba(255,211,107,.28); box-shadow: 0 0 0 1px rgba(255,211,107,.06), 0 0 26px rgba(255,211,107,.06); }

    .disclaimer{
      margin-top:10px;
      font-size:11px;
      color: rgba(210,205,255,.58);
      line-height:1.45;
      border-top: 1px solid rgba(140,100,255,.12);
      padding-top: 10px;
    }
  </style>
</head>
<body>

<?php require_once __DIR__ . '/../inc/rwa-topbar-nav.php'; ?>

<div class="wrap">

  <div class="hero">
    <div class="t">RWA Certificate Engine (Final Demo)</div>
    <div class="s">
      Same premium certificate format for <b>Health</b>, <b>Green</b>, and <b>Gold</b>.<br>
      QR → Verify page · PDF includes embedded QR · Perfect for PPT screenshots.
    </div>
  </div>

  <div class="grid">

    <div class="card">
      <div class="sec-title">Issue Samples</div>

      <form method="post" class="btn-row">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>"/>
        <button class="btn health" name="action" value="issue_health" type="submit">ISSUE HEALTH</button>
        <button class="btn green"  name="action" value="issue_green"  type="submit">ISSUE GREEN</button>
        <button class="btn gold"   name="action" value="issue_gold"   type="submit">ISSUE GOLD</button>
        <button class="btn"        name="action" value="issue_both"   type="submit">ISSUE BOTH</button>
      </form>

      <?php if ($err !== ''): ?>
        <div class="err"><?= h($err) ?></div>
      <?php endif; ?>

      <div class="note">
        <b>Issuer:</b> Blockchain Group Ltd. (Hong Kong) · RWA Standard Organisation (RSO)<br>
        <b>Disclaimer:</b> Marketing demo certificate. Not a commodity, not a financial product, no yield promise.
      </div>
    </div>

    <div class="card">
      <div class="sec-title">Issued Certificates</div>

      <?php if (empty($certs)): ?>
        <div class="note" style="margin-top:10px;">
          No certificates yet. Issue one on the left.
        </div>
      <?php else: ?>

        <div style="display:flex; flex-direction:column; gap:12px; margin-top:12px;">
          <?php foreach ($certs as $c): ?>
            <?php
              $uid = (string)($c['cert_uid'] ?? '');
              $verifyUrl = verify_url_for_uid($uid);
              $qr = function_exists('poado_qr_svg_data_uri') ? poado_qr_svg_data_uri($verifyUrl, 320, 10) : '';
              $accent = (string)($c['accent'] ?? 'gold');
              $accentClass =
                $accent === 'health' ? 'accent-health' :
                ($accent === 'green' ? 'accent-green' : 'accent-gold');
            ?>

            <div class="cert <?= $accentClass ?>">
              <div class="cert-inner">

                <div>
                  <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:10px;">
                    <div>
                      <div class="badge"><span class="dot"></span> SAMPLE ISSUED</div>
                      <div class="cert-title"><?= h($c['cert_type'] ?? 'Certificate') ?></div>
                    </div>
                    <div class="badge"><?= h($c['cert_code'] ?? '') ?></div>
                  </div>

                  <div class="cert-meta">
                    <div class="kv">
                      <div class="k">CERT UID</div>
                      <div class="v"><?= h($uid) ?></div>
                    </div>
                    <div class="kv">
                      <div class="k">ISSUED (UTC)</div>
                      <div class="v"><?= h($c['issued_at'] ?? '') ?></div>
                    </div>
                    <div class="kv">
                      <div class="k">ISSUER</div>
                      <div class="v"><?= h($c['issuer'] ?? '') ?></div>
                    </div>
                    <div class="kv">
                      <div class="k">HOLDER</div>
                      <div class="v"><?= h($c['holder_name'] ?? '') ?></div>
                    </div>
                    <div class="kv" style="grid-column:1 / -1;">
                      <div class="k">UNIT RULE</div>
                      <div class="v"><?= h($c['unit_rule'] ?? '') ?></div>
                    </div>
                    <div class="kv" style="grid-column:1 / -1;">
                      <div class="k">MEMO</div>
                      <div class="v"><?= h($c['memo'] ?? '') ?></div>
                    </div>
                  </div>

                  <div class="disclaimer">
                    Responsibility adoption proof (marketing demo). Not commodity, not financial product, no yield promise.
                  </div>
                </div>

                <div class="qrbox">
                  <?php if ($qr !== ''): ?>
                    <img class="qrimg" alt="QR" src="<?= h($qr) ?>"/>
                  <?php else: ?>
                    <div class="err">QR helper missing: poado_qr_svg_data_uri() not found.</div>
                  <?php endif; ?>

                  <div class="qrtext">
                    <b>Verify URL:</b><br>
                    <?= h($verifyUrl) ?>
                  </div>

                  <div class="btn-row" style="margin-top:10px;">
                    <a class="btn small" href="<?= h($verifyUrl) ?>" target="_blank" rel="noopener">OPEN VERIFY</a>
                    <a class="btn small" href="/rwa/cert/pdf.php?uid=<?= h(rawurlencode($uid)) ?>">OPEN PDF</a>
                  </div>
                </div>

              </div>
            </div>

          <?php endforeach; ?>
        </div>

      <?php endif; ?>
    </div>
  </div>
</div>

<script src="/dashboard/inc/poado-i18n.js?v=1"></script>
<?php require_once __DIR__ . '/../inc/rwa-bottom-nav.php'; ?>
</body>
</html>