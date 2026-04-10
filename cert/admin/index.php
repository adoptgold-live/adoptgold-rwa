<?php
declare(strict_types=1);

/**
 * POAdo RWA Cert Engine
 * Admin Control Hub
 *
 * File:
 * /rwa/cert/admin/index.php
 *
 * Purpose:
 * - Canonical admin navigation layer for RWA Cert admin operations
 * - Entry hub for royalty dashboard and ledgers
 */

require_once $_SERVER['DOCUMENT_ROOT'].'/dashboard/inc/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/dashboard/inc/session-user.php';

$wallet = get_wallet_session();
if (!$wallet) {
    header('Location: /rwa/index.php');
    exit;
}

db_connect();
$pdo = $GLOBALS['pdo'];

$stmt = $pdo->prepare("
    SELECT id, wallet, nickname, role, is_active, is_admin, is_senior
    FROM users
    WHERE wallet = :wallet
    LIMIT 1
");
$stmt->execute([':wallet' => $wallet]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (
    !$user ||
    (int)($user['is_active'] ?? 0) !== 1 ||
    (empty($user['is_admin']) && empty($user['is_senior']))
) {
    die('Admin access only');
}

$cards = [
    [
        'title' => 'Royalty Dashboard',
        'path'  => '/rwa/cert/admin/royalty-dashboard.php',
        'desc'  => 'Financial control center for total royalties, pools, pending claims, history, and pipeline actions.',
        'tag'   => 'Core Control',
    ],
    [
        'title' => 'Holder Claims',
        'path'  => '/rwa/cert/admin/holder-claims.php',
        'desc'  => 'Inspect and settle the full holder claim ledger across the system.',
        'tag'   => 'Holder Pool',
    ],
    [
        'title' => 'ACE Claims',
        'path'  => '/rwa/cert/admin/ace-claims.php',
        'desc'  => 'Inspect and settle ACE claims weighted by RK92-EMA sales.',
        'tag'   => 'ACE Pool',
    ],
    [
        'title' => 'Gold Packet Vault',
        'path'  => '/rwa/cert/admin/gold-packet.php',
        'desc'  => 'Review vaulted Gold Packet rows and distribute settled batches.',
        'tag'   => 'Gold Packet',
    ],
    [
        'title' => 'Treasury Retained',
        'path'  => '/rwa/cert/admin/treasury-retained.php',
        'desc'  => 'Review retained treasury rows and reconcile accounting records.',
        'tag'   => 'Treasury',
    ],
];

$stats = [
    'royalty_events' => '0',
    'holder_claims' => '0',
    'ace_claims' => '0',
    'gold_packet_rows' => '0',
    'treasury_rows' => '0',
];

try {
    $stats['royalty_events'] = (string)($pdo->query("SELECT COUNT(*) FROM poado_rwa_royalty_events_v2")->fetchColumn() ?: '0');
    $stats['holder_claims'] = (string)($pdo->query("SELECT COUNT(*) FROM poado_rwa_holder_claims")->fetchColumn() ?: '0');
    $stats['ace_claims'] = (string)($pdo->query("SELECT COUNT(*) FROM poado_rwa_ace_claims")->fetchColumn() ?: '0');
    $stats['gold_packet_rows'] = (string)($pdo->query("SELECT COUNT(*) FROM poado_rwa_gold_packet_claims")->fetchColumn() ?: '0');
    $stats['treasury_rows'] = (string)($pdo->query("SELECT COUNT(*) FROM poado_rwa_treasury_retained")->fetchColumn() ?: '0');
} catch (Throwable $e) {
    // Keep safe fallback zeroes if any ledger table is not ready yet.
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>RWA Cert Admin Hub · POAdo</title>
<style>
:root{
    --bg:#050505;
    --panel:#101010;
    --panel2:#0b0b0b;
    --line:#6f5b1d;
    --gold:#d4af37;
    --soft:#f3d77a;
    --text:#f5e7b8;
    --muted:#aa9a68;
    --green:#69ff8e;
}
*{box-sizing:border-box}
body{
    margin:0;
    background:var(--bg);
    color:var(--text);
    font-family:Arial,Helvetica,sans-serif;
}
.wrap{
    width:min(1240px,95vw);
    margin:20px auto 40px;
}
.topbar{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    margin-bottom:18px;
}
.title{
    font-size:28px;
    font-weight:700;
    color:var(--gold);
}
.sub{
    font-size:13px;
    color:var(--muted);
}
.hero{
    border:1px solid var(--line);
    border-radius:18px;
    padding:18px;
    background:linear-gradient(180deg,#121212,#0b0b0b);
    margin-bottom:18px;
}
.hero-grid{
    display:grid;
    grid-template-columns:1.3fr .7fr;
    gap:16px;
    align-items:center;
}
.hero-box{
    border:1px solid #342a12;
    border-radius:14px;
    padding:14px;
    background:#0b0b0b;
}
.hero-title{
    font-size:18px;
    font-weight:700;
    color:var(--soft);
    margin-bottom:8px;
}
.stats{
    display:grid;
    grid-template-columns:repeat(5,1fr);
    gap:12px;
    margin-bottom:18px;
}
.stat{
    border:1px solid var(--line);
    border-radius:14px;
    padding:16px;
    background:linear-gradient(180deg,var(--panel),var(--panel2));
}
.stat-k{
    font-size:12px;
    text-transform:uppercase;
    letter-spacing:.08em;
    color:var(--muted);
    margin-bottom:8px;
}
.stat-v{
    font-size:24px;
    font-weight:700;
    color:var(--soft);
}
.cards{
    display:grid;
    grid-template-columns:repeat(2,1fr);
    gap:16px;
}
.card{
    border:1px solid var(--line);
    border-radius:16px;
    padding:18px;
    background:linear-gradient(180deg,#111,#0a0a0a);
    display:flex;
    flex-direction:column;
    gap:10px;
}
.tag{
    display:inline-block;
    width:max-content;
    padding:4px 8px;
    border:1px solid #4d4018;
    border-radius:999px;
    color:var(--soft);
    font-size:12px;
}
.card h2{
    margin:0;
    font-size:20px;
    color:var(--gold);
}
.card p{
    margin:0;
    color:var(--muted);
    line-height:1.55;
    font-size:13px;
}
.actions{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-top:6px;
}
.btn{
    display:inline-block;
    text-decoration:none;
    padding:10px 14px;
    border-radius:10px;
    border:1px solid var(--line);
    color:var(--text);
    background:linear-gradient(180deg,#1a1a1a,#111);
}
.btn.primary{
    background:linear-gradient(180deg,#f0cf61,#cda52d);
    color:#111;
    border-color:#b89018;
    font-weight:700;
}
.note{
    margin-top:18px;
    border:1px solid #2f2610;
    border-radius:14px;
    padding:14px;
    color:var(--muted);
    background:#0b0b0b;
    font-size:13px;
    line-height:1.6;
}
.quick{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-top:10px;
}
@media (max-width:1000px){
    .hero-grid{grid-template-columns:1fr}
    .stats{grid-template-columns:repeat(2,1fr)}
    .cards{grid-template-columns:1fr}
}
@media (max-width:640px){
    .stats{grid-template-columns:1fr}
}
</style>
</head>
<body>
<div class="wrap">

    <div class="topbar">
        <div>
            <div class="title">RWA Cert Admin Hub</div>
            <div class="sub">Canonical admin navigation layer for the RWA certificate engine</div>
        </div>
        <div class="sub">
            <?=htmlspecialchars((string)($user['nickname'] ?? 'Admin'), ENT_QUOTES, 'UTF-8')?> ·
            <?=htmlspecialchars((string)$user['wallet'], ENT_QUOTES, 'UTF-8')?>
        </div>
    </div>

    <div class="hero">
        <div class="hero-grid">
            <div class="hero-box">
                <div class="hero-title">Royalty Operations Control</div>
                <div class="sub">
                    This hub connects the full royalty control flow:
                    marketplace sale → royalty event ledger → Holder / ACE / Gold Packet / Treasury allocation
                    → settlement → reconciliation → dashboard review.
                </div>
                <div class="quick">
                    <a class="btn primary" href="/rwa/cert/admin/royalty-dashboard.php">Open Royalty Dashboard</a>
                    <a class="btn" href="/rwa/cert/admin/holder-claims.php">Holder Claims</a>
                    <a class="btn" href="/rwa/cert/admin/ace-claims.php">ACE Claims</a>
                </div>
            </div>
            <div class="hero-box">
                <div class="hero-title">Current Admin Scope</div>
                <div class="sub">Admin-only pages are protected by wallet session plus admin / senior role check.</div>
                <div class="quick" style="margin-top:12px;">
                    <span class="tag">Royalty</span>
                    <span class="tag">Claims</span>
                    <span class="tag">Vault</span>
                    <span class="tag">Treasury</span>
                </div>
            </div>
        </div>
    </div>

    <div class="stats">
        <div class="stat">
            <div class="stat-k">Royalty Events</div>
            <div class="stat-v"><?=htmlspecialchars($stats['royalty_events'], ENT_QUOTES, 'UTF-8')?></div>
        </div>
        <div class="stat">
            <div class="stat-k">Holder Claims</div>
            <div class="stat-v"><?=htmlspecialchars($stats['holder_claims'], ENT_QUOTES, 'UTF-8')?></div>
        </div>
        <div class="stat">
            <div class="stat-k">ACE Claims</div>
            <div class="stat-v"><?=htmlspecialchars($stats['ace_claims'], ENT_QUOTES, 'UTF-8')?></div>
        </div>
        <div class="stat">
            <div class="stat-k">Gold Packet Rows</div>
            <div class="stat-v"><?=htmlspecialchars($stats['gold_packet_rows'], ENT_QUOTES, 'UTF-8')?></div>
        </div>
        <div class="stat">
            <div class="stat-k">Treasury Rows</div>
            <div class="stat-v"><?=htmlspecialchars($stats['treasury_rows'], ENT_QUOTES, 'UTF-8')?></div>
        </div>
    </div>

    <div class="cards">
        <?php foreach ($cards as $card): ?>
            <div class="card">
                <span class="tag"><?=htmlspecialchars($card['tag'], ENT_QUOTES, 'UTF-8')?></span>
                <h2><?=htmlspecialchars($card['title'], ENT_QUOTES, 'UTF-8')?></h2>
                <p><?=htmlspecialchars($card['desc'], ENT_QUOTES, 'UTF-8')?></p>
                <div class="actions">
                    <a class="btn primary" href="<?=htmlspecialchars($card['path'], ENT_QUOTES, 'UTF-8')?>">Open</a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="note">
        Canonical admin pages under this hub:
        <br>• `/rwa/cert/admin/royalty-dashboard.php`
        <br>• `/rwa/cert/admin/holder-claims.php`
        <br>• `/rwa/cert/admin/ace-claims.php`
        <br>• `/rwa/cert/admin/gold-packet.php`
        <br>• `/rwa/cert/admin/treasury-retained.php`
    </div>

</div>
</body>
</html>