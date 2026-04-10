<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/rprop/index.php
 * Property RWA Dashboard
 * Rule baseline:
 * - 1 SQ FT build-up = 1 Property Responsibility Unit
 */

require $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/session-user.php';
require $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/bootstrap.php';

db_connect();
$pdo = $GLOBALS['pdo'] ?? null;

function h(?string $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$nickname = 'Guest';
$wallet = '';
$walletShort = 'SESSION: NONE';
$coreRole = '';
$currentIndustry = 'PROPERTY';

if (isset($GLOBALS['poado_user']) && is_array($GLOBALS['poado_user'])) {
    $u = $GLOBALS['poado_user'];
    $nickname = trim((string)($u['nickname'] ?? 'Guest')) ?: 'Guest';
    $wallet = trim((string)($u['wallet'] ?? ($u['wallet_address'] ?? '')));
}

if ($wallet !== '') {
    $walletShort = substr($wallet, 0, 6) . '...' . substr($wallet, -4);
}

if ($pdo && $wallet !== '') {
    try {
        $st = $pdo->prepare("
            SELECT id, role
            FROM users
            WHERE wallet = ?
               OR wallet_address = ?
            LIMIT 1
        ");
        $st->execute([$wallet, $wallet]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $coreRole = trim((string)($row['role'] ?? ''));
    } catch (Throwable $e) {
        $coreRole = '';
    }
}

require $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-topbar-nav.php';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Property RWA · POAdo</title>
<style>
:root{
  --bg:#070910;
  --bg2:#0d1220;
  --panel:#101423;
  --panel2:#161b2d;
  --line:rgba(65,105,225,.20);
  --line2:rgba(255,255,255,.06);
  --text:#f7f9ff;
  --muted:#c8d2f0;
  --title:#eef3ff;
  --accent:#4169E1;
  --accent2:#5a7cff;
  --gold:#f6d36b;
  --ok:#76ffba;
  --shadow:0 24px 56px rgba(0,0,0,.42);
}
*{box-sizing:border-box}
html,body{
  margin:0;
  padding:0;
  min-height:100%;
  background:
    radial-gradient(circle at top, rgba(65,105,225,.08), transparent 24%),
    linear-gradient(180deg,var(--bg),var(--bg2));
  color:var(--text);
  font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
}
body{
  padding:calc(env(safe-area-inset-top,0px)) 0 calc(84px + env(safe-area-inset-bottom,0px)) 0;
}
.page{
  width:min(1280px,100%);
  margin:0 auto;
  padding:14px 12px;
}
.hero,.section{
  border:1px solid var(--line);
  border-radius:24px;
  overflow:hidden;
  background:
    linear-gradient(180deg, rgba(255,255,255,.02), rgba(255,255,255,.008)),
    linear-gradient(180deg,var(--panel),var(--panel2));
  box-shadow:var(--shadow);
}
.heroInner{
  display:grid;
  grid-template-columns:1.1fr .9fr;
  gap:14px;
  padding:18px;
}
.kicker{
  color:var(--gold);
  font-size:12px;
  letter-spacing:.18em;
  margin-bottom:10px;
}
.title{
  margin:0 0 8px;
  font-size:30px;
  line-height:1.12;
  font-weight:1000;
  color:var(--title);
}
.sub{
  color:#dce6ff;
  font-size:13px;
  line-height:1.7;
}
.metaGrid,.statsGrid,.unitGrid,.assetGrid{
  display:grid;
  grid-template-columns:repeat(3,1fr);
  gap:12px;
}
.metaCard,.statCard,.unitCard,.assetCard{
  border:1px solid var(--line);
  border-radius:18px;
  background:rgba(255,255,255,.02);
  padding:14px;
}
.k{
  color:var(--muted);
  font-size:11px;
  letter-spacing:.12em;
  margin-bottom:6px;
}
.v{
  color:#f5f8ff;
  font-size:15px;
  font-weight:900;
  word-break:break-word;
}
.big{
  font-size:36px;
  line-height:1;
  color:#eef3ff;
}
.section{margin-top:14px}
.sectionHd{
  padding:14px 16px;
  border-bottom:1px solid var(--line2);
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
}
.sectionHd .t{
  font-size:15px;
  font-weight:1000;
  letter-spacing:.10em;
  color:#eef3ff;
}
.sectionHd .r{
  color:var(--muted);
  font-size:12px;
}
.sectionBd{padding:16px}
.unitIcon{
  width:54px;height:54px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  font-size:24px;
  color:#fff;
  border:1px solid rgba(65,105,225,.24);
  background:rgba(65,105,225,.10);
  margin-bottom:12px;
}
.note{
  margin-top:14px;
  border:1px solid var(--line2);
  border-radius:16px;
  background:rgba(255,255,255,.02);
  padding:14px;
  color:#dce6ff;
  font-size:13px;
  line-height:1.7;
}
.footer{
  margin-top:14px;
  text-align:center;
  color:#c1cae5;
  font-size:12px;
  line-height:1.7;
  padding-bottom:6px;
}
@media (max-width: 1100px){
  .heroInner,.metaGrid,.statsGrid,.unitGrid,.assetGrid{grid-template-columns:1fr}
}
</style>
</head>
<body>

<div class="page">

  <section class="hero">
    <div class="heroInner">
      <div>
        <div class="kicker">SECONDARY RWA · PROPERTY</div>
        <h1 class="title">Property RWA Dashboard</h1>
        <div class="sub">
          Property responsibility dashboard based on <b>1 SQ FT build-up = 1 Property Responsibility Unit</b>.
          This page is the property-industry operating view for build-up area, asset scale, property contribution, and future property certification logic.
        </div>
      </div>

      <div class="metaGrid">
        <div class="metaCard">
          <div class="k">USER</div>
          <div class="v"><?= h($nickname) ?></div>
        </div>
        <div class="metaCard">
          <div class="k">CORE ROLE</div>
          <div class="v"><?= h($coreRole !== '' ? $coreRole : 'NONE') ?></div>
        </div>
        <div class="metaCard">
          <div class="k">INDUSTRY</div>
          <div class="v"><?= h($currentIndustry) ?></div>
        </div>
      </div>
    </div>
  </section>

  <section class="section">
    <div class="sectionHd">
      <div class="t">PROPERTY RESPONSIBILITY OVERVIEW</div>
      <div class="r">1 SQ FT = 1 Unit</div>
    </div>
    <div class="sectionBd">
      <div class="statsGrid">
        <div class="statCard">
          <div class="k">TOTAL BUILD-UP AREA</div>
          <div class="v big">0</div>
        </div>
        <div class="statCard">
          <div class="k">TOTAL PROPERTY UNITS</div>
          <div class="v big">0</div>
        </div>
        <div class="statCard">
          <div class="k">ACTIVE ASSETS</div>
          <div class="v big">0</div>
        </div>
      </div>
    </div>
  </section>

  <section class="section">
    <div class="sectionHd">
      <div class="t">PROPERTY UNIT MODEL</div>
      <div class="r">Area · Asset · Build-up</div>
    </div>
    <div class="sectionBd">
      <div class="unitGrid">
        <div class="unitCard">
          <div class="unitIcon">🏢</div>
          <div class="k">AREA UNIT</div>
          <div class="v">Every 1 SQ FT of build-up = 1 Property Responsibility Unit</div>
        </div>
        <div class="unitCard">
          <div class="unitIcon">📐</div>
          <div class="k">ASSET SCALE</div>
          <div class="v">Track built-up area, property type, occupancy / usage status, and future asset proof.</div>
        </div>
        <div class="unitCard">
          <div class="unitIcon">🧾</div>
          <div class="k">CERT DIRECTION</div>
          <div class="v">Future Property RWA certs can be mapped to verified square-foot contribution or asset-class packages.</div>
        </div>
      </div>
    </div>
  </section>

  <section class="section">
    <div class="sectionHd">
      <div class="t">ASSET DASHBOARD</div>
      <div class="r">UI-first placeholders</div>
    </div>
    <div class="sectionBd">
      <div class="assetGrid">
        <div class="assetCard">
          <div class="k">THIS MONTH AREA</div>
          <div class="v">0 SQ FT</div>
        </div>
        <div class="assetCard">
          <div class="k">LATEST ASSET TYPE</div>
          <div class="v">No asset linked yet</div>
        </div>
        <div class="assetCard">
          <div class="k">LAST VERIFIED PROPERTY</div>
          <div class="v">No verified property yet</div>
        </div>
      </div>

      <div class="note">
        Suggested next data blocks for Property RWA:
        Asset Name · Property Type · Built-up SQ FT · Land / Lease category · Usage Type · Estimated Property Unit · Verified Property Unit · Property Cert Status.
      </div>
    </div>
  </section>

  <div class="footer">
    © 2025 Blockchain Group Ltd. (Hong Kong) · RWA Standard Organisation (RSO)
  </div>
</div>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-bottom-nav.php'; ?>
<script src="/dashboard/inc/poado-i18n.js?v=1"></script>
</body>
</html>