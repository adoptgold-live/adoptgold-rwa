<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/rtrip/index.php
 * Travel RWA Dashboard
 * Rule baseline:
 * - 1 KM = 1 Travel Responsibility Unit
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
$currentIndustry = 'TRAVEL';

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
<title>Travel RWA · POAdo</title>
<style>
:root{
  --bg:#0a0710;
  --bg2:#120b18;
  --panel:#121018;
  --panel2:#19131f;
  --line:rgba(255,82,82,.18);
  --line2:rgba(255,255,255,.06);
  --text:#f8f4ff;
  --muted:#d3c8e6;
  --title:#fff0f0;
  --accent:#ff5252;
  --accent2:#ff2b2b;
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
    radial-gradient(circle at top, rgba(255,82,82,.08), transparent 24%),
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
  color:#eadff0;
  font-size:13px;
  line-height:1.7;
}
.metaGrid,.statsGrid,.unitGrid,.routeGrid{
  display:grid;
  grid-template-columns:repeat(3,1fr);
  gap:12px;
}
.metaCard,.statCard,.unitCard,.routeCard,.panelCard{
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
  color:#fff5f5;
  font-size:15px;
  font-weight:900;
  word-break:break-word;
}
.big{
  font-size:36px;
  line-height:1;
  color:#fff1f1;
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
  color:#fff3f3;
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
  border:1px solid rgba(255,82,82,.24);
  background:rgba(255,82,82,.10);
  margin-bottom:12px;
}
.note{
  margin-top:14px;
  border:1px solid var(--line2);
  border-radius:16px;
  background:rgba(255,255,255,.02);
  padding:14px;
  color:#eadff0;
  font-size:13px;
  line-height:1.7;
}
.footer{
  margin-top:14px;
  text-align:center;
  color:#c9bdd4;
  font-size:12px;
  line-height:1.7;
  padding-bottom:6px;
}
@media (max-width: 1100px){
  .heroInner,.metaGrid,.statsGrid,.unitGrid,.routeGrid{grid-template-columns:1fr}
}
</style>
</head>
<body>

<div class="page">

  <section class="hero">
    <div class="heroInner">
      <div>
        <div class="kicker">SECONDARY RWA · TRAVEL</div>
        <h1 class="title">Travel RWA Dashboard</h1>
        <div class="sub">
          Travel responsibility dashboard based on <b>1 KM = 1 Travel Responsibility Unit</b>.
          This page is the travel-industry operating view for distance, route responsibility, mobility contribution, and future travel certification logic.
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
      <div class="t">TRAVEL RESPONSIBILITY OVERVIEW</div>
      <div class="r">1 KM = 1 Unit</div>
    </div>
    <div class="sectionBd">
      <div class="statsGrid">
        <div class="statCard">
          <div class="k">TOTAL KM TRACKED</div>
          <div class="v big">0</div>
        </div>
        <div class="statCard">
          <div class="k">TOTAL TRAVEL UNITS</div>
          <div class="v big">0</div>
        </div>
        <div class="statCard">
          <div class="k">ACTIVE ROUTES</div>
          <div class="v big">0</div>
        </div>
      </div>
    </div>
  </section>

  <section class="section">
    <div class="sectionHd">
      <div class="t">TRAVEL UNIT MODEL</div>
      <div class="r">Mobility · Destination · Route</div>
    </div>
    <div class="sectionBd">
      <div class="unitGrid">
        <div class="unitCard">
          <div class="unitIcon">✈️</div>
          <div class="k">DISTANCE UNIT</div>
          <div class="v">Every 1 KM of travel = 1 Travel Responsibility Unit</div>
        </div>
        <div class="unitCard">
          <div class="unitIcon">🧭</div>
          <div class="k">ROUTE RECORD</div>
          <div class="v">Track route count, inter-city / inter-country logic, and future route proof.</div>
        </div>
        <div class="unitCard">
          <div class="unitIcon">🎫</div>
          <div class="k">CERT DIRECTION</div>
          <div class="v">Future Travel RWA certs can be mapped to verified distance or verified journey packages.</div>
        </div>
      </div>
    </div>
  </section>

  <section class="section">
    <div class="sectionHd">
      <div class="t">ROUTE DASHBOARD</div>
      <div class="r">UI-first placeholders</div>
    </div>
    <div class="sectionBd">
      <div class="routeGrid">
        <div class="routeCard">
          <div class="k">TODAY DISTANCE</div>
          <div class="v">0 KM</div>
        </div>
        <div class="routeCard">
          <div class="k">THIS MONTH DISTANCE</div>
          <div class="v">0 KM</div>
        </div>
        <div class="routeCard">
          <div class="k">LAST VERIFIED ROUTE</div>
          <div class="v">No verified route yet</div>
        </div>
      </div>

      <div class="note">
        Suggested next data blocks for Travel RWA:
        Origin · Destination · Route Type · Distance KM · Estimated Travel Unit · Verified Travel Unit · Travel Cert Status · Sustainability / carbon-offset linkage.
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