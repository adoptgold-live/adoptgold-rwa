<?php
declare(strict_types=1);

/**
 * POAdo RWA Cert Engine
 * Admin Cert Monitor
 *
 * File:
 * /rwa/cert/admin/cert-monitor.php
 *
 * Purpose:
 * - Monitor certificate lifecycle across the full cert engine
 * - Admin / senior only
 * - Uses shared admin nav _nav.php
 */

require_once $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/dashboard/inc/session-user.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/dashboard/inc/bootstrap.php';

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

function cm_safe_scalar(PDO $pdo, string $sql, $fallback = '0'): string {
    try {
        $v = $pdo->query($sql)->fetchColumn();
        if ($v === false || $v === null || $v === '') return (string)$fallback;
        return (string)$v;
    } catch (Throwable $e) {
        return (string)$fallback;
    }
}

function cm_json_decode_array(?string $json): array {
    if (!$json) return [];
    $arr = json_decode($json, true);
    return is_array($arr) ? $arr : [];
}

$statusCounts = [];
try {
    $q = $pdo->query("
        SELECT status, COUNT(*) AS c
        FROM poado_rwa_certs
        GROUP BY status
        ORDER BY status ASC
    ");
    $statusCounts = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $statusCounts = [];
}

$stats = [
    'total'            => cm_safe_scalar($pdo, "SELECT COUNT(*) FROM poado_rwa_certs"),
    'initiated'        => cm_safe_scalar($pdo, "SELECT COUNT(*) FROM poado_rwa_certs WHERE status='initiated'"),
    'payment_pending'  => cm_safe_scalar($pdo, "SELECT COUNT(*) FROM poado_rwa_certs WHERE status='payment_pending'"),
    'paid'             => cm_safe_scalar($pdo, "SELECT COUNT(*) FROM poado_rwa_certs WHERE status='paid'"),
    'mint_pending'     => cm_safe_scalar($pdo, "SELECT COUNT(*) FROM poado_rwa_certs WHERE status='mint_pending'"),
    'minted'           => cm_safe_scalar($pdo, "SELECT COUNT(*) FROM poado_rwa_certs WHERE status='minted'"),
    'listed'           => cm_safe_scalar($pdo, "SELECT COUNT(*) FROM poado_rwa_certs WHERE status='listed'"),
    'revoked'          => cm_safe_scalar($pdo, "SELECT COUNT(*) FROM poado_rwa_certs WHERE status='revoked'"),
];

$typeCounts = [];
try {
    $q = $pdo->query("
        SELECT cert_type, COUNT(*) AS c
        FROM poado_rwa_certs
        GROUP BY cert_type
        ORDER BY cert_type ASC
    ");
    $typeCounts = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $typeCounts = [];
}

$rows = [];
try {
    $sql = "
        SELECT
            id,
            cert_uid,
            cert_type,
            status,
            owner_user_id,
            meta,
            created_at,
            updated_at
        FROM poado_rwa_certs
        ORDER BY id DESC
        LIMIT 100
    ";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $rows = [];
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Cert Monitor · POAdo RWA</title>
<style>
body{
    background:#000;
    color:#e8d9a5;
    font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;
    margin:0;
}
.wrap{
    max-width:1280px;
    margin:auto;
    padding:20px;
}
.topbar{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:12px;
    margin-bottom:16px;
}
.title{
    font-size:24px;
    color:#d4af37;
    margin-bottom:6px;
}
.sub{
    color:#9f8d55;
    font-size:13px;
}
.grid{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:14px;
    margin-bottom:18px;
}
.card{
    background:#0b0b0b;
    border:1px solid #6f5b1d;
    border-radius:10px;
    padding:16px;
}
.k{
    color:#9f8d55;
    font-size:12px;
    text-transform:uppercase;
    letter-spacing:.06em;
}
.v{
    color:#f0d782;
    font-size:24px;
    margin-top:8px;
    font-weight:700;
}
.panel{
    background:#0b0b0b;
    border:1px solid #6f5b1d;
    border-radius:10px;
    padding:16px;
    margin-bottom:18px;
}
.panel h2{
    margin:0 0 12px 0;
    color:#d4af37;
    font-size:16px;
}
.flow{
    display:grid;
    grid-template-columns:repeat(7,1fr);
    gap:10px;
}
.step{
    border:1px solid #4e3e13;
    border-radius:10px;
    background:#111;
    padding:12px;
    text-align:center;
}
.step .name{
    font-size:12px;
    color:#aa975e;
    min-height:34px;
}
.step .num{
    font-size:22px;
    color:#f3d77a;
    font-weight:700;
    margin-top:8px;
}
.mini-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:16px;
}
.table-wrap{
    overflow:auto;
    border:1px solid #2a2413;
    border-radius:10px;
}
table{
    width:100%;
    border-collapse:collapse;
    min-width:1100px;
}
th,td{
    padding:9px 10px;
    border-bottom:1px solid #1c1c1c;
    text-align:left;
    font-size:13px;
    vertical-align:top;
}
th{
    color:#d4af37;
    background:#111;
}
.badge{
    display:inline-block;
    padding:4px 8px;
    border-radius:999px;
    border:1px solid #6f5b1d;
    font-size:12px;
    white-space:nowrap;
}
.badge.initiated{color:#ddd0a0}
.badge.payment_pending{color:#ffcf66}
.badge.paid{color:#8fd1ff}
.badge.mint_pending{color:#f7a0ff}
.badge.minted{color:#6cff6c}
.badge.listed{color:#7affb9}
.badge.revoked{color:#ff7a7a}
.meta{
    font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
    font-size:11px;
    color:#bfb07a;
    white-space:pre-wrap;
    word-break:break-word;
    max-width:360px;
}
.note{
    color:#9f8d55;
    font-size:12px;
    line-height:1.6;
}
@media (max-width:1100px){
    .grid{grid-template-columns:repeat(2,1fr)}
    .flow{grid-template-columns:repeat(3,1fr)}
    .mini-grid{grid-template-columns:1fr}
}
@media (max-width:640px){
    .grid,.flow{grid-template-columns:1fr}
}
</style>
</head>
<body>

<?php require $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/rwa-topbar-nav.php'; ?>

<div class="wrap">

    <?php $admin_nav_active = 'hub'; ?>
    <?php require __DIR__.'/_nav.php'; ?>

    <div class="topbar">
        <div>
            <div class="title">Certificate Monitor</div>
            <div class="sub">Live lifecycle visibility for initiated → payment_pending → paid → mint_pending → minted → listed → revoked</div>
        </div>
        <div class="sub">
            <?= htmlspecialchars((string)($user['nickname'] ?? 'Admin'), ENT_QUOTES, 'UTF-8') ?>
            ·
            <?= htmlspecialchars((string)$user['wallet'], ENT_QUOTES, 'UTF-8') ?>
        </div>
    </div>

    <div class="grid">
        <div class="card">
            <div class="k">Total Cert Rows</div>
            <div class="v"><?= htmlspecialchars($stats['total'], ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        <div class="card">
            <div class="k">Minted</div>
            <div class="v"><?= htmlspecialchars($stats['minted'], ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        <div class="card">
            <div class="k">Listed</div>
            <div class="v"><?= htmlspecialchars($stats['listed'], ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        <div class="card">
            <div class="k">Revoked</div>
            <div class="v"><?= htmlspecialchars($stats['revoked'], ENT_QUOTES, 'UTF-8') ?></div>
        </div>
    </div>

    <div class="panel">
        <h2>Lifecycle Pipeline</h2>
        <div class="flow">
            <div class="step"><div class="name">initiated</div><div class="num"><?= htmlspecialchars($stats['initiated'], ENT_QUOTES, 'UTF-8') ?></div></div>
            <div class="step"><div class="name">payment_pending</div><div class="num"><?= htmlspecialchars($stats['payment_pending'], ENT_QUOTES, 'UTF-8') ?></div></div>
            <div class="step"><div class="name">paid</div><div class="num"><?= htmlspecialchars($stats['paid'], ENT_QUOTES, 'UTF-8') ?></div></div>
            <div class="step"><div class="name">mint_pending</div><div class="num"><?= htmlspecialchars($stats['mint_pending'], ENT_QUOTES, 'UTF-8') ?></div></div>
            <div class="step"><div class="name">minted</div><div class="num"><?= htmlspecialchars($stats['minted'], ENT_QUOTES, 'UTF-8') ?></div></div>
            <div class="step"><div class="name">listed</div><div class="num"><?= htmlspecialchars($stats['listed'], ENT_QUOTES, 'UTF-8') ?></div></div>
            <div class="step"><div class="name">revoked</div><div class="num"><?= htmlspecialchars($stats['revoked'], ENT_QUOTES, 'UTF-8') ?></div></div>
        </div>
    </div>

    <div class="mini-grid">
        <div class="panel">
            <h2>Status Breakdown</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Status</th>
                        <th>Count</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$statusCounts): ?>
                        <tr><td colspan="2">No rows</td></tr>
                    <?php else: ?>
                        <?php foreach ($statusCounts as $r): ?>
                            <tr>
                                <td><span class="badge <?= htmlspecialchars((string)$r['status'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$r['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td><?= htmlspecialchars((string)$r['c'], ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="panel">
            <h2>Cert Type Breakdown</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Cert Type</th>
                        <th>Count</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$typeCounts): ?>
                        <tr><td colspan="2">No rows</td></tr>
                    <?php else: ?>
                        <?php foreach ($typeCounts as $r): ?>
                            <tr>
                                <td><?= htmlspecialchars((string)$r['cert_type'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string)$r['c'], ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="panel">
        <h2>Latest 100 Certificate Rows</h2>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Cert UID</th>
                    <th>Cert Type</th>
                    <th>Status</th>
                    <th>Owner User ID</th>
                    <th>Mint / Market Trace</th>
                    <th>Created</th>
                    <th>Updated</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="8">No certificate rows found.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <?php $meta = cm_json_decode_array($row['meta'] ?? ''); ?>
                        <?php
                            $trace = [
                                'payment_verified'   => $meta['payment_verified'] ?? null,
                                'paid_at'            => $meta['paid_at'] ?? null,
                                'mint_ready'         => $meta['mint_ready'] ?? null,
                                'nft_item_address'   => $meta['mint']['nft_item_address'] ?? null,
                                'tx_hash'            => $meta['mint']['tx_hash'] ?? null,
                                'market_eligible'    => $meta['market']['eligible'] ?? null,
                                'vault'              => $meta['vault'] ?? null,
                            ];
                        ?>
                        <tr>
                            <td><?= htmlspecialchars((string)$row['id'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)$row['cert_uid'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)$row['cert_type'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><span class="badge <?= htmlspecialchars((string)$row['status'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$row['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td><?= htmlspecialchars((string)$row['owner_user_id'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><div class="meta"><?= htmlspecialchars(json_encode($trace, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), ENT_QUOTES, 'UTF-8') ?></div></td>
                            <td><?= htmlspecialchars((string)$row['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)$row['updated_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="note" style="margin-top:12px;">
            This monitor is read-only. It helps debug lifecycle bottlenecks such as payment not confirmed, mint not finalized, vault not attached, or market payload not yet ready.
        </div>
    </div>

</div>

<?php require $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/rwa-bottom-nav.php'; ?>

</body>
</html>