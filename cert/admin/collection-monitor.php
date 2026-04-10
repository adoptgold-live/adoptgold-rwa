<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/admin/collection-monitor.php
 * Version: v1.0.0-20260329
 * Changelog:
 * - new admin-only live monitor for RWA Cert collection
 * - shows env-locked collection / owner / treasury / metadata
 * - shows live TON balances via TON Center when available
 * - shows cert status counts and latest cert rows from poado_rwa_certs
 * - keeps standalone RWA shell, mobile-first, EN + 中文 switcher
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

if (!function_exists('session_user_id') || (int)session_user_id() <= 0) {
    header('Location: /rwa/index.php?m=login_required');
    exit;
}

function h(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function envv(string $name, string $default = ''): string
{
    $v = getenv($name);
    if ($v === false || $v === null || trim((string)$v) === '') {
        return $default;
    }
    return trim((string)$v);
}

function short_addr(string $addr, int $left = 8, int $right = 8): string
{
    $addr = trim($addr);
    if ($addr === '' || strlen($addr) <= ($left + $right + 3)) {
        return $addr;
    }
    return substr($addr, 0, $left) . '...' . substr($addr, -$right);
}

function is_assoc_array($value): bool
{
    return is_array($value) && array_keys($value) !== range(0, count($value) - 1);
}

function http_json_get(string $url, array $headers = [], int $timeout = 12): ?array
{
    $headerLines = ["Accept: application/json"];
    foreach ($headers as $k => $v) {
        if (is_int($k)) {
            $headerLines[] = (string)$v;
        } else {
            $headerLines[] = $k . ': ' . $v;
        }
    }

    $ctx = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'timeout'       => $timeout,
            'ignore_errors' => true,
            'header'        => implode("\r\n", $headerLines),
        ],
    ]);

    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false || $raw === '') {
        return null;
    }

    $json = json_decode($raw, true);
    return is_array($json) ? $json : null;
}

function nano_to_ton_string($nano, int $decimals = 6): string
{
    if (!is_numeric((string)$nano)) {
        return '—';
    }
    $n = (string)$nano;
    $neg = false;
    if (str_starts_with($n, '-')) {
        $neg = true;
        $n = substr($n, 1);
    }
    $n = ltrim($n, '0');
    if ($n === '') {
        return '0';
    }
    if (strlen($n) <= 9) {
        $n = str_pad($n, 10, '0', STR_PAD_LEFT);
    }
    $int = substr($n, 0, -9);
    $frac = substr($n, -9);
    $frac = rtrim(substr($frac, 0, $decimals), '0');
    $out = ($int === '' ? '0' : $int) . ($frac !== '' ? ('.' . $frac) : '');
    return $neg ? ('-' . $out) : $out;
}

function ton_balance_lookup(string $address): array
{
    $base = rtrim(envv('TONCENTER_BASE', ''), '/');
    $apiKey = envv('TONCENTER_API_KEY', '');
    $result = [
        'ok'     => false,
        'nano'   => null,
        'ton'    => '—',
        'source' => '',
        'error'  => '',
    ];

    if ($address === '' || $base === '') {
        $result['error'] = 'TONCENTER_BASE not configured';
        return $result;
    }

    $url = $base . '/api/v2/getAddressBalance?address=' . rawurlencode($address);
    $headers = [];
    if ($apiKey !== '') {
        $headers['X-API-Key'] = $apiKey;
    }

    $json = http_json_get($url, $headers, 12);
    if (!$json) {
        $result['error'] = 'No JSON response';
        return $result;
    }

    if (!empty($json['ok']) && isset($json['result']) && is_scalar($json['result'])) {
        $nano = (string)$json['result'];
        $result['ok'] = true;
        $result['nano'] = $nano;
        $result['ton'] = nano_to_ton_string($nano, 6);
        $result['source'] = 'toncenter-v2';
        return $result;
    }

    if (isset($json['balance']) && is_scalar($json['balance'])) {
        $nano = (string)$json['balance'];
        $result['ok'] = true;
        $result['nano'] = $nano;
        $result['ton'] = nano_to_ton_string($nano, 6);
        $result['source'] = 'toncenter-alt';
        return $result;
    }

    $result['error'] = 'Unsupported balance response';
    return $result;
}

function current_lang(): string
{
    if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'zh'], true)) {
        $lang = (string)$_GET['lang'];
        setcookie('poado_lang', $lang, time() + 31536000, '/');
        return $lang;
    }
    if (!empty($_COOKIE['poado_lang']) && in_array($_COOKIE['poado_lang'], ['en', 'zh'], true)) {
        return (string)$_COOKIE['poado_lang'];
    }
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
    return str_contains($accept, 'zh') ? 'zh' : 'en';
}

$lang = current_lang();

$T = [
    'en' => [
        'title' => 'RWA Cert Collection Monitor',
        'subtitle' => 'Admin-only live operations panel',
        'back' => 'Back',
        'admin_only' => 'Admin Only',
        'denied' => 'Access denied. Admin role required.',
        'summary' => 'Live Summary',
        'collection' => 'Collection',
        'owner' => 'Owner',
        'treasury' => 'Treasury',
        'metadata' => 'Metadata URI',
        'rpc' => 'TON RPC',
        'balances' => 'Live TON Balances',
        'collection_balance' => 'Collection Balance',
        'treasury_balance' => 'Treasury Balance',
        'owner_balance' => 'Owner Balance',
        'status_counts' => 'Cert Status Counts',
        'issued' => 'Issued',
        'minted' => 'Minted',
        'revoked' => 'Revoked',
        'total' => 'Total',
        'latest' => 'Latest Certificates',
        'uid' => 'Cert UID',
        'type' => 'Type',
        'wallet' => 'Wallet',
        'nft' => 'NFT Item',
        'status' => 'Status',
        'issued_at' => 'Issued',
        'minted_at' => 'Minted',
        'quick' => 'Quick Links',
        'open_tonviewer' => 'Open Collection in Tonviewer',
        'open_getgems' => 'Open Collection in Getgems',
        'refresh' => 'Refresh',
        'auto' => 'Auto refresh',
        'seconds' => 'seconds',
        'last_check' => 'Last Check',
        'source' => 'Source',
        'error' => 'Error',
        'footer' => '© 2025 Blockchain Group Ltd. (Hong Kong) · RWA Standard Organisation (RSO). All rights reserved.',
        'na' => 'N/A',
    ],
    'zh' => [
        'title' => 'RWA 证书合集监控台',
        'subtitle' => '仅管理员可访问的实时运维面板',
        'back' => '返回',
        'admin_only' => '仅管理员',
        'denied' => '拒绝访问。需要管理员角色。',
        'summary' => '实时摘要',
        'collection' => '合集地址',
        'owner' => '拥有者',
        'treasury' => '金库地址',
        'metadata' => '元数据 URI',
        'rpc' => 'TON RPC',
        'balances' => '实时 TON 余额',
        'collection_balance' => '合集余额',
        'treasury_balance' => '金库余额',
        'owner_balance' => '拥有者余额',
        'status_counts' => '证书状态统计',
        'issued' => '已签发',
        'minted' => '已铸造',
        'revoked' => '已撤销',
        'total' => '总数',
        'latest' => '最新证书',
        'uid' => '证书编号',
        'type' => '类型',
        'wallet' => '钱包',
        'nft' => 'NFT 项目',
        'status' => '状态',
        'issued_at' => '签发时间',
        'minted_at' => '铸造时间',
        'quick' => '快捷链接',
        'open_tonviewer' => '在 Tonviewer 打开合集',
        'open_getgems' => '在 Getgems 打开合集',
        'refresh' => '刷新',
        'auto' => '自动刷新',
        'seconds' => '秒',
        'last_check' => '最后检查',
        'source' => '来源',
        'error' => '错误',
        'footer' => '© 2025 Blockchain Group Ltd. (Hong Kong) · RWA Standard Organisation (RSO). All rights reserved.',
        'na' => '无',
    ],
];
$t = $T[$lang];

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO && function_exists('rwa_db')) {
    $pdo = rwa_db();
}
if (!$pdo instanceof PDO) {
    http_response_code(500);
    echo 'DB not available';
    exit;
}

$userId = (int)session_user_id();
$me = [
    'id' => $userId,
    'role' => '',
    'wallet_address' => '',
    'nickname' => '',
];

$stmtMe = $pdo->prepare('SELECT id, role, wallet_address, nickname FROM users WHERE id = ? LIMIT 1');
$stmtMe->execute([$userId]);
$rowMe = $stmtMe->fetch(PDO::FETCH_ASSOC);
if (is_array($rowMe)) {
    $me['role'] = (string)($rowMe['role'] ?? '');
    $me['wallet_address'] = (string)($rowMe['wallet_address'] ?? '');
    $me['nickname'] = (string)($rowMe['nickname'] ?? '');
}

$isAdmin = strtolower(trim($me['role'])) === 'admin';

$collectionAddress = envv('RWA_CERT_COLLECTION_ADDRESS');
$ownerAddress = envv('RWA_CERT_COLLECTION_OWNER');
$treasuryAddress = envv('RWA_CERT_COLLECTION_TREASURY');
$contentUri = envv('RWA_CERT_COLLECTION_CONTENT_URI');
$rpcUrl = envv('TON_RPC_URL', 'https://mainnet-v4.tonhubapi.com');

$collectionBal = ton_balance_lookup($collectionAddress);
$ownerBal = ton_balance_lookup($ownerAddress);
$treasuryBal = ton_balance_lookup($treasuryAddress);

$statusCounts = [
    'issued' => 0,
    'minted' => 0,
    'revoked' => 0,
    'total' => 0,
];

$sqlCounts = "SELECT status, COUNT(*) AS c FROM poado_rwa_certs GROUP BY status";
foreach ($pdo->query($sqlCounts) as $r) {
    $status = strtolower((string)($r['status'] ?? ''));
    $count = (int)($r['c'] ?? 0);
    if (isset($statusCounts[$status])) {
        $statusCounts[$status] = $count;
    }
    $statusCounts['total'] += $count;
}

$stmtLatest = $pdo->query("
    SELECT
        cert_uid,
        rwa_type,
        ton_wallet,
        nft_item_address,
        status,
        issued_at,
        minted_at
    FROM poado_rwa_certs
    ORDER BY id DESC
    LIMIT 12
");
$latestRows = $stmtLatest ? $stmtLatest->fetchAll(PDO::FETCH_ASSOC) : [];

$tonviewerCollection = $collectionAddress !== '' ? ('https://tonviewer.com/' . rawurlencode($collectionAddress)) : '';
$getgemsCollection = $collectionAddress !== '' ? ('https://getgems.io/collection/' . rawurlencode($collectionAddress)) : '';
$lastCheck = date('d/m/Y H:i:s');
?>
<!doctype html>
<html lang="<?= h($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="color-scheme" content="dark">
<meta name="theme-color" content="#120b1f">
<title><?= h($t['title']) ?></title>
<style>
:root{
  --bg:#090611;
  --bg2:#161026;
  --panel:#1a1330;
  --panel2:#22193e;
  --line:rgba(188,152,255,.24);
  --text:#f4efff;
  --muted:#bfb2df;
  --ok:#78f0cb;
  --warn:#ffd86b;
  --err:#ff8ca8;
  --accent:#b888ff;
  --accent2:#7de1db;
  --shadow:0 14px 40px rgba(0,0,0,.32);
}
*{box-sizing:border-box}
html,body{
  margin:0;
  padding:0;
  background:
    radial-gradient(circle at top left, rgba(184,136,255,.12), transparent 26%),
    linear-gradient(180deg, var(--bg2), var(--bg));
  color:var(--text);
  font-family:Inter,ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;
  min-height:100%;
}
a{color:inherit}
.shell{
  width:min(1180px,100%);
  margin:0 auto;
  padding:18px 14px 100px;
}
.topbar{
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:10px;
  margin:6px 0 14px;
}
.back-btn,.lang-btn,.action-btn{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-height:44px;
  padding:0 16px;
  border-radius:16px;
  border:1px solid var(--line);
  text-decoration:none;
  background:rgba(255,255,255,.03);
  color:var(--text);
  font-weight:700;
}
.langs{display:flex;gap:8px;flex-wrap:wrap}
.hero{
  border:1px solid var(--line);
  border-radius:28px;
  padding:20px;
  background:linear-gradient(180deg, rgba(43,33,75,.92), rgba(25,19,48,.92));
  box-shadow:var(--shadow);
}
.hero-top{
  display:flex;
  justify-content:space-between;
  align-items:flex-start;
  gap:14px;
  flex-wrap:wrap;
}
.hero h1{
  margin:0 0 6px;
  font-size:30px;
  line-height:1.1;
  font-weight:900;
  letter-spacing:-.02em;
}
.hero p{
  margin:0;
  color:var(--muted);
}
.badge{
  display:inline-flex;
  align-items:center;
  gap:8px;
  padding:10px 14px;
  border-radius:999px;
  border:1px solid rgba(120,240,203,.28);
  background:rgba(120,240,203,.08);
  color:#d8fff3;
  font-weight:800;
}
.grid{
  display:grid;
  grid-template-columns:repeat(12,minmax(0,1fr));
  gap:14px;
  margin-top:14px;
}
.card{
  grid-column:span 12;
  border:1px solid var(--line);
  border-radius:24px;
  background:linear-gradient(180deg, rgba(35,26,63,.92), rgba(24,18,44,.96));
  box-shadow:var(--shadow);
  padding:18px;
}
.card h2{
  margin:0 0 14px;
  font-size:18px;
  font-weight:900;
}
.kv{
  display:grid;
  gap:12px;
}
.kv-row{
  padding:12px 14px;
  border-radius:16px;
  background:rgba(255,255,255,.03);
  border:1px solid rgba(255,255,255,.06);
}
.kv-label{
  font-size:12px;
  text-transform:uppercase;
  letter-spacing:.08em;
  color:var(--muted);
  margin-bottom:5px;
}
.kv-value{
  font-size:15px;
  line-height:1.45;
  word-break:break-word;
}
.mono{
  font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
}
.stats{
  display:grid;
  grid-template-columns:repeat(2,minmax(0,1fr));
  gap:12px;
}
.stat{
  border-radius:18px;
  padding:16px;
  border:1px solid rgba(255,255,255,.08);
  background:rgba(255,255,255,.03);
}
.stat .label{
  color:var(--muted);
  font-size:12px;
  text-transform:uppercase;
  letter-spacing:.08em;
}
.stat .value{
  margin-top:8px;
  font-size:28px;
  font-weight:900;
}
.stat.ok{border-color:rgba(120,240,203,.22)}
.stat.warn{border-color:rgba(255,216,107,.22)}
.stat.err{border-color:rgba(255,140,168,.22)}
.links{
  display:flex;
  flex-wrap:wrap;
  gap:10px;
}
.table-wrap{
  overflow:auto;
  border-radius:18px;
  border:1px solid rgba(255,255,255,.08);
}
table{
  width:100%;
  border-collapse:collapse;
  min-width:860px;
}
th,td{
  padding:12px 14px;
  text-align:left;
  border-bottom:1px solid rgba(255,255,255,.07);
  vertical-align:top;
}
th{
  color:var(--muted);
  font-size:12px;
  text-transform:uppercase;
  letter-spacing:.08em;
  background:rgba(255,255,255,.03);
}
.status-pill{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-width:90px;
  padding:7px 10px;
  border-radius:999px;
  font-size:12px;
  font-weight:800;
  text-transform:uppercase;
}
.status-issued{background:rgba(255,216,107,.08); color:#fff1bf; border:1px solid rgba(255,216,107,.24);}
.status-minted{background:rgba(120,240,203,.08); color:#d8fff3; border:1px solid rgba(120,240,203,.24);}
.status-revoked{background:rgba(255,140,168,.08); color:#ffdbe4; border:1px solid rgba(255,140,168,.24);}
.meta{
  display:flex;
  flex-wrap:wrap;
  gap:8px 14px;
  color:var(--muted);
  font-size:13px;
  margin-top:10px;
}
.footer{
  margin-top:18px;
  text-align:center;
  color:#d7cfee;
  opacity:.9;
  font-size:13px;
  line-height:1.5;
}
.denied{
  width:min(760px,100%);
  margin:40px auto;
  padding:28px 20px;
  border-radius:24px;
  border:1px solid rgba(255,140,168,.26);
  background:rgba(255,140,168,.08);
  color:#ffdbe4;
  box-shadow:var(--shadow);
}
@media (min-width: 900px){
  .col-7{grid-column:span 7}
  .col-5{grid-column:span 5}
  .col-4{grid-column:span 4}
  .col-8{grid-column:span 8}
}
@media (max-width: 640px){
  .shell{padding:14px 12px 96px}
  .hero h1{font-size:26px}
  .topbar{flex-direction:column;align-items:stretch}
  .stats{grid-template-columns:1fr}
}
</style>
</head>
<body>
<?php if (!$isAdmin): ?>
  <div class="denied">
    <div class="badge"><?= h($t['admin_only']) ?></div>
    <h1 style="margin:12px 0 8px; font-size:28px;"><?= h($t['title']) ?></h1>
    <p style="margin:0; font-size:16px;"><?= h($t['denied']) ?></p>
  </div>
<?php else: ?>
<?php @include $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-topbar-nav.php'; ?>

<div class="shell">
  <div class="topbar">
    <a class="back-btn" href="/rwa/login-select.php"><?= h($t['back']) ?></a>
    <div class="langs">
      <a class="lang-btn" href="/rwa/cert/admin/collection-monitor.php?lang=en">English</a>
      <a class="lang-btn" href="/rwa/cert/admin/collection-monitor.php?lang=zh">中文</a>
      <a class="action-btn" href="/rwa/cert/admin/collection-monitor.php?lang=<?= h($lang) ?>&r=<?= time() ?>"><?= h($t['refresh']) ?></a>
    </div>
  </div>

  <section class="hero">
    <div class="hero-top">
      <div>
        <h1><?= h($t['title']) ?></h1>
        <p><?= h($t['subtitle']) ?></p>
      </div>
      <div class="badge"><?= h($t['admin_only']) ?></div>
    </div>
    <div class="meta">
      <div><?= h($t['last_check']) ?>: <strong><?= h($lastCheck) ?></strong></div>
      <div><?= h($t['rpc']) ?>: <span class="mono"><?= h($rpcUrl) ?></span></div>
      <div>User: <strong><?= h($me['nickname'] !== '' ? $me['nickname'] : ('#' . $me['id'])) ?></strong></div>
      <div>Role: <strong><?= h($me['role']) ?></strong></div>
    </div>
  </section>

  <div class="grid">
    <section class="card col-7">
      <h2><?= h($t['summary']) ?></h2>
      <div class="kv">
        <div class="kv-row">
          <div class="kv-label"><?= h($t['collection']) ?></div>
          <div class="kv-value mono"><?= h($collectionAddress !== '' ? $collectionAddress : $t['na']) ?></div>
        </div>
        <div class="kv-row">
          <div class="kv-label"><?= h($t['owner']) ?></div>
          <div class="kv-value mono"><?= h($ownerAddress !== '' ? $ownerAddress : $t['na']) ?></div>
        </div>
        <div class="kv-row">
          <div class="kv-label"><?= h($t['treasury']) ?></div>
          <div class="kv-value mono"><?= h($treasuryAddress !== '' ? $treasuryAddress : $t['na']) ?></div>
        </div>
        <div class="kv-row">
          <div class="kv-label"><?= h($t['metadata']) ?></div>
          <div class="kv-value"><a href="<?= h($contentUri) ?>" target="_blank" rel="noopener noreferrer"><?= h($contentUri !== '' ? $contentUri : $t['na']) ?></a></div>
        </div>
      </div>
    </section>

    <section class="card col-5">
      <h2><?= h($t['quick']) ?></h2>
      <div class="links">
        <?php if ($tonviewerCollection !== ''): ?>
          <a class="action-btn" href="<?= h($tonviewerCollection) ?>" target="_blank" rel="noopener noreferrer"><?= h($t['open_tonviewer']) ?></a>
        <?php endif; ?>
        <?php if ($getgemsCollection !== ''): ?>
          <a class="action-btn" href="<?= h($getgemsCollection) ?>" target="_blank" rel="noopener noreferrer"><?= h($t['open_getgems']) ?></a>
        <?php endif; ?>
      </div>
      <div class="meta" style="margin-top:16px;">
        <div><?= h($t['auto']) ?>: <strong>30 <?= h($t['seconds']) ?></strong></div>
      </div>
    </section>

    <section class="card col-8">
      <h2><?= h($t['balances']) ?></h2>
      <div class="stats">
        <div class="stat ok">
          <div class="label"><?= h($t['collection_balance']) ?></div>
          <div class="value"><?= h($collectionBal['ton']) ?> TON</div>
          <div class="meta">
            <div><?= h($t['source']) ?>: <?= h($collectionBal['source'] !== '' ? $collectionBal['source'] : $t['na']) ?></div>
            <?php if (!$collectionBal['ok']): ?><div><?= h($t['error']) ?>: <?= h($collectionBal['error']) ?></div><?php endif; ?>
          </div>
        </div>
        <div class="stat ok">
          <div class="label"><?= h($t['treasury_balance']) ?></div>
          <div class="value"><?= h($treasuryBal['ton']) ?> TON</div>
          <div class="meta">
            <div><?= h($t['source']) ?>: <?= h($treasuryBal['source'] !== '' ? $treasuryBal['source'] : $t['na']) ?></div>
            <?php if (!$treasuryBal['ok']): ?><div><?= h($t['error']) ?>: <?= h($treasuryBal['error']) ?></div><?php endif; ?>
          </div>
        </div>
        <div class="stat warn">
          <div class="label"><?= h($t['owner_balance']) ?></div>
          <div class="value"><?= h($ownerBal['ton']) ?> TON</div>
          <div class="meta">
            <div><?= h($t['source']) ?>: <?= h($ownerBal['source'] !== '' ? $ownerBal['source'] : $t['na']) ?></div>
            <?php if (!$ownerBal['ok']): ?><div><?= h($t['error']) ?>: <?= h($ownerBal['error']) ?></div><?php endif; ?>
          </div>
        </div>
      </div>
    </section>

    <section class="card col-4">
      <h2><?= h($t['status_counts']) ?></h2>
      <div class="stats">
        <div class="stat warn">
          <div class="label"><?= h($t['issued']) ?></div>
          <div class="value"><?= (int)$statusCounts['issued'] ?></div>
        </div>
        <div class="stat ok">
          <div class="label"><?= h($t['minted']) ?></div>
          <div class="value"><?= (int)$statusCounts['minted'] ?></div>
        </div>
        <div class="stat err">
          <div class="label"><?= h($t['revoked']) ?></div>
          <div class="value"><?= (int)$statusCounts['revoked'] ?></div>
        </div>
        <div class="stat">
          <div class="label"><?= h($t['total']) ?></div>
          <div class="value"><?= (int)$statusCounts['total'] ?></div>
        </div>
      </div>
    </section>

    <section class="card col-12">
      <h2><?= h($t['latest']) ?></h2>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th><?= h($t['uid']) ?></th>
              <th><?= h($t['type']) ?></th>
              <th><?= h($t['wallet']) ?></th>
              <th><?= h($t['nft']) ?></th>
              <th><?= h($t['status']) ?></th>
              <th><?= h($t['issued_at']) ?></th>
              <th><?= h($t['minted_at']) ?></th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$latestRows): ?>
            <tr>
              <td colspan="7"><?= h($t['na']) ?></td>
            </tr>
          <?php else: ?>
            <?php foreach ($latestRows as $r): ?>
              <?php
                $status = strtolower((string)($r['status'] ?? ''));
                $pillClass = 'status-issued';
                if ($status === 'minted') $pillClass = 'status-minted';
                if ($status === 'revoked') $pillClass = 'status-revoked';
              ?>
              <tr>
                <td class="mono"><?= h((string)($r['cert_uid'] ?? '')) ?></td>
                <td><?= h((string)($r['rwa_type'] ?? '')) ?></td>
                <td class="mono" title="<?= h((string)($r['ton_wallet'] ?? '')) ?>"><?= h(short_addr((string)($r['ton_wallet'] ?? ''))) ?></td>
                <td class="mono" title="<?= h((string)($r['nft_item_address'] ?? '')) ?>"><?= h(short_addr((string)($r['nft_item_address'] ?? ''))) ?></td>
                <td><span class="status-pill <?= h($pillClass) ?>"><?= h($status !== '' ? $status : $t['na']) ?></span></td>
                <td><?= h((string)($r['issued_at'] ?? '')) ?></td>
                <td><?= h((string)($r['minted_at'] ?? '')) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div>

  <div class="footer"><?= h($t['footer']) ?></div>
</div>

<?php @include $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-bottom-nav.php'; ?>
<script src="/rwa/inc/core/poado-i18n.js"></script>
<script>
(function () {
  'use strict';
  setTimeout(function () {
    const url = new URL(window.location.href);
    url.searchParams.set('r', String(Date.now()));
    window.location.replace(url.toString());
  }, 30000);
})();
</script>
<?php endif; ?>
</body>
</html>
