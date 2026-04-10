<?php
declare(strict_types=1);

/**
 * RWA Cert — One-shot audit
 * Path: /rwa/cert/audit-cert.php
 * Usage: browser or CLI
 */

header('Content-Type: application/json; charset=utf-8');

$root = $_SERVER['DOCUMENT_ROOT'] ?? '/var/www/html/public';
$base = $root . '/rwa/cert';

function ok($k, $v=true){ return [$k => ['ok'=>true,'data'=>$v]]; }
function fail($k, $e){ return [$k => ['ok'=>false,'error'=>$e]]; }

$out = [
  'ok' => true,
  'ts' => gmdate('c'),
  'checks' => []
];

/* ---------------------------------------------------------
 * 1. Core files existence
 * --------------------------------------------------------- */
$coreFiles = [
  'index.php','cert.js','cert.css','pdf.php','verify.php',
  'api/issue.php','api/confirm-payment.php','api/verify-status.php',
  'api/mint-init.php','api/mint-verify.php','api/repair-nft.php',
  'api/_bootstrap.php','api/_image-bundle.php','api/_meta-image-map.php',
  'api/_pdf-template-map.php','api/_render-pdf-html.php',
  'api/_qr-map-resolver.php','api/_qr-local.php',
  'cron/mint-watcher.php','cron/repair-nft.php','cron/cdn-sync.php','cron/current-sync.php'
];

foreach ($coreFiles as $f) {
  $p = $base . '/' . $f;
  $out['checks']['files'][$f] = is_file($p)
    ? ['ok'=>true]
    : ['ok'=>false,'error'=>'MISSING'];
  if (!is_file($p)) $out['ok'] = false;
}

/* ---------------------------------------------------------
 * 2. PHP syntax check (key files)
 * --------------------------------------------------------- */
$phpFiles = [
  'api/issue.php','api/confirm-payment.php','api/verify-status.php',
  'api/mint-init.php','api/mint-verify.php','api/repair-nft.php',
  'api/_bootstrap.php','api/_image-bundle.php','api/_render-pdf-html.php'
];

foreach ($phpFiles as $f) {
  $p = $base . '/' . $f;
  if (!is_file($p)) continue;
  $cmd = "php -l " . escapeshellarg($p) . " 2>&1";
  $res = shell_exec($cmd) ?? '';
  $out['checks']['syntax'][$f] = (strpos($res, 'No syntax errors') !== false)
    ? ['ok'=>true]
    : ['ok'=>false,'error'=>trim($res)];
  if (strpos($res, 'No syntax errors') === false) $out['ok'] = false;
}

/* ---------------------------------------------------------
 * 3. QR engine check
 * --------------------------------------------------------- */
try {
  require_once $root . '/rwa/cert/api/_qr-local.php';
  $png = qr_png_data_uri('test://qr', 128, 4);
  $out['checks']['qr'] = (strpos($png, 'data:image/png;base64,') === 0)
    ? ['ok'=>true]
    : ['ok'=>false,'error'=>'INVALID_QR_OUTPUT'];
} catch (Throwable $e) {
  $out['checks']['qr'] = ['ok'=>false,'error'=>$e->getMessage()];
  $out['ok'] = false;
}

/* ---------------------------------------------------------
 * 4. Metadata & verify sample scan
 * --------------------------------------------------------- */
$metaDir = $root . '/rwa/metadata/cert';
if (is_dir($metaDir)) {
  $uids = array_values(array_filter(scandir($metaDir), function($v){
    return $v !== '.' && $v !== '..' && strpos($v, '-') !== false;
  }));
  $uids = array_slice($uids, 0, 3);

  foreach ($uids as $uid) {
    $m = "$metaDir/$uid.json";
    $img = "$metaDir/$uid.png";
    $v = "$metaDir/$uid/verify/verify.json";

    $out['checks']['artifacts'][$uid] = [
      'metadata' => is_file($m),
      'image' => is_file($img),
      'verify_json' => is_file($v)
    ];
  }
} else {
  $out['checks']['artifacts'] = ['ok'=>false,'error'=>'META_DIR_MISSING'];
  $out['ok'] = false;
}

/* ---------------------------------------------------------
 * 5. DB schema check (basic)
 * --------------------------------------------------------- */
try {
  require_once $root . '/rwa/cert/api/_bootstrap.php';
  $pdo = $GLOBALS['pdo'] ?? null;

  if ($pdo instanceof PDO) {
    $tables = ['poado_rwa_certs','poado_rwa_cert_payments','poado_rwa_royalty_events'];
    foreach ($tables as $t) {
      $q = $pdo->query("SHOW TABLES LIKE '$t'");
      $out['checks']['db'][$t] = $q && $q->fetch()
        ? ['ok'=>true]
        : ['ok'=>false,'error'=>'TABLE_MISSING'];
      if (!$q || !$q->fetch()) $out['ok'] = false;
    }
  } else {
    $out['checks']['db'] = ['ok'=>false,'error'=>'PDO_NOT_AVAILABLE'];
    $out['ok'] = false;
  }
} catch (Throwable $e) {
  $out['checks']['db'] = ['ok'=>false,'error'=>$e->getMessage()];
  $out['ok'] = false;
}

/* ---------------------------------------------------------
 * 6. TON env check
 * --------------------------------------------------------- */
$envFile = '/var/www/secure/.env';
$envOk = is_file($envFile) && strpos(file_get_contents($envFile) ?: '', 'TONCENTER_API_KEY') !== false;
$out['checks']['env']['TONCENTER_API_KEY'] = $envOk ? ['ok'=>true] : ['ok'=>false,'error'=>'MISSING'];

/* ---------------------------------------------------------
 * 7. Result
 * --------------------------------------------------------- */
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);