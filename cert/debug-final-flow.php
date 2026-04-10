<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/debug-final-flow.php
 * Version: v3.0.0-20260408-current-pipeline-readonly-mutate
 *
 * PURPOSE
 * - Current-pipeline final flow debugger for standalone RWA Cert
 * - Covers:
 *   issue.php
 *   issue-pay.php
 *   confirm-payment.php
 *   verify-status.php
 *   cert-detail.php
 *   queue-summary.php
 *   _nft-guard.php
 *   mint-init.php
 *   mint-init.php
 *   mint-verify.php
 *
 * MODES
 * - READ-ONLY AUDIT MODE (default and recommended)
 * - MUTATING TEST MODE (only when explicitly chosen)
 *
 * CURRENT PIPELINE
 * - issue -> issue-pay -> confirm-payment -> verify-status
 * - finalize -> mint-init handoff
 * - mint-verify = final mint authority
 * - verify-status = read model / queue authority
 */

header('Content-Type: text/html; charset=utf-8');

$bootstrap = '/var/www/html/public/rwa/inc/core/bootstrap.php';
if (is_file($bootstrap)) {
    require_once $bootstrap;
}

function dbg_h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function dbg_json($v): string {
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}
function dbg_env(string $key, string $default = ''): string {
    $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    return is_string($v) && trim($v) !== '' ? trim($v) : $default;
}
function dbg_site_url(): string {
    return rtrim(dbg_env('APP_BASE_URL', 'https://adoptgold.app'), '/');
}
function dbg_is_canonical_uid(string $uid): bool {
    return (bool)preg_match('/^([A-Z0-9]+(?:-[A-Z0-9]+)+)-(\d{8})-([A-Z0-9]{8})$/', strtoupper(trim($uid)));
}
function dbg_forward_cookie_headers(): array {
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    if (!empty($_SERVER['HTTP_COOKIE'])) {
        $headers[] = 'Cookie: ' . $_SERVER['HTTP_COOKIE'];
    }
    if (!empty($_SERVER['HTTP_USER_AGENT'])) {
        $headers[] = 'User-Agent: ' . $_SERVER['HTTP_USER_AGENT'];
    }
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        $headers[] = 'X-Requested-With: ' . $_SERVER['HTTP_X_REQUESTED_WITH'];
    } else {
        $headers[] = 'X-Requested-With: XMLHttpRequest';
    }
    return $headers;
}
function dbg_post_json(string $url, array $payload): array {
    $ch = curl_init($url);
    if ($ch === false) return ['ok' => false, 'curl_error' => 'CURL_INIT_FAILED'];
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => dbg_forward_cookie_headers(),
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = is_string($raw) ? json_decode($raw, true) : null;
    return [
        'http_code' => $code,
        'curl_error' => $err,
        'raw' => is_string($raw) ? $raw : '',
        'json' => is_array($json) ? $json : null,
    ];
}
function dbg_get_json(string $url): array {
    $ch = curl_init($url);
    if ($ch === false) return ['ok' => false, 'curl_error' => 'CURL_INIT_FAILED'];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => array_filter(array_map(
            static fn(string $h): ?string => str_starts_with($h, 'Content-Type:') ? null : $h,
            dbg_forward_cookie_headers()
        )),
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = is_string($raw) ? json_decode($raw, true) : null;
    return [
        'http_code' => $code,
        'curl_error' => $err,
        'raw' => is_string($raw) ? $raw : '',
        'json' => is_array($json) ? $json : null,
    ];
}
function dbg_db(): ?PDO {
    try {
        if (function_exists('rwa_db')) {
            $pdo = rwa_db();
            if ($pdo instanceof PDO) return $pdo;
        }
        if (function_exists('db')) {
            $pdo = db();
            if ($pdo instanceof PDO) return $pdo;
        }
        if (($GLOBALS['pdo'] ?? null) instanceof PDO) return $GLOBALS['pdo'];
        if (($GLOBALS['db'] ?? null) instanceof PDO) return $GLOBALS['db'];
    } catch (Throwable) {}
    return null;
}
function dbg_find_cert(PDO $pdo, string $certUid): ?array {
    $st = $pdo->prepare("SELECT * FROM poado_rwa_certs WHERE cert_uid = :uid LIMIT 1");
    $st->execute([':uid' => $certUid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}
function dbg_find_payment(PDO $pdo, string $certUid): ?array {
    $st = $pdo->prepare("SELECT * FROM poado_rwa_cert_payments WHERE cert_uid = :uid ORDER BY id DESC LIMIT 1");
    $st->execute([':uid' => $certUid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}
function dbg_boolish($v): bool {
    if (is_bool($v)) return $v;
    if (is_int($v) || is_float($v)) return ((int)$v) !== 0;
    $s = strtolower(trim((string)$v));
    return in_array($s, ['1','true','yes','y','on'], true);
}
function dbg_extract_cert_uid(?array $json, string $fallback = ''): string {
    if (!is_array($json)) return trim($fallback);
    $uid = trim((string)($json['cert_uid'] ?? $json['uid'] ?? $json['cert'] ?? $fallback));
    return $uid;
}
function dbg_file_status(string $path): array {
    return [
        'path' => $path,
        'exists' => is_file($path),
        'bytes' => is_file($path) ? (int)filesize($path) : 0,
        'mtime' => is_file($path) ? date('Y-m-d H:i:s', (int)filemtime($path)) : '',
    ];
}
function dbg_result_ok(?array $result): bool {
    if (!is_array($result)) return false;
    $json = $result['json'] ?? null;
    return is_array($json) && (($json['ok'] ?? null) === true);
}
function dbg_resolve_row_from_verify_status(?array $json): ?array {
    if (!is_array($json)) return null;
    if (isset($json['row']) && is_array($json['row'])) return $json['row'];
    if (isset($json['rows']) && is_array($json['rows']) && !empty($json['rows'][0]) && is_array($json['rows'][0])) return $json['rows'][0];
    return null;
}
function dbg_strict_final_minted_from_row(?array $row): bool {
    if (!is_array($row)) return false;
    return ((int)($row['nft_minted'] ?? 0) === 1)
        && trim((string)($row['nft_item_address'] ?? '')) !== ''
        && trim((string)($row['minted_at'] ?? '')) !== ''
        && trim((string)($row['queue_bucket'] ?? '')) === 'issued';
}
function dbg_strict_final_minted_from_db(?array $row): bool {
    if (!is_array($row)) return false;
    return ((int)($row['nft_minted'] ?? 0) === 1)
        && strtolower(trim((string)($row['status'] ?? ''))) === 'minted'
        && trim((string)($row['nft_item_address'] ?? '')) !== ''
        && trim((string)($row['minted_at'] ?? '')) !== '';
}
function dbg_read_model_summary(?array $verifyJson): array {
    $row = dbg_resolve_row_from_verify_status($verifyJson);
    return [
        'row_present' => is_array($row),
        'queue_bucket' => (string)($row['queue_bucket'] ?? ''),
        'flow_state' => (string)($row['flow_state'] ?? ''),
        'mint_status' => (string)($row['mint_status'] ?? ''),
        'payment_ready' => dbg_boolish($row['payment_ready'] ?? false),
        'artifact_ready' => dbg_boolish($row['artifact_ready'] ?? false),
        'nft_healthy' => dbg_boolish($row['nft_healthy'] ?? false),
        'nft_minted' => (int)($row['nft_minted'] ?? 0),
        'nft_item_address' => (string)($row['nft_item_address'] ?? ''),
        'minted_at' => (string)($row['minted_at'] ?? ''),
        'strict_final_minted' => dbg_strict_final_minted_from_row($row),
    ];
}
function dbg_mutating_allowed(string $mode): bool {
    return $mode === 'mutate';
}

$base = dbg_site_url();

$coreFiles = [
    '/var/www/html/public/rwa/cert/index.php',
    '/var/www/html/public/rwa/cert/cert.css',
    '/var/www/html/public/rwa/cert/cert.js',
    '/var/www/html/public/rwa/cert/cert-actions.js',
    '/var/www/html/public/rwa/cert/cert-router.js',
    '/var/www/html/public/rwa/cert/shared/config.js',
    '/var/www/html/public/rwa/cert/shared/dom.js',
    '/var/www/html/public/rwa/cert/shared/http.js',
    '/var/www/html/public/rwa/cert/shared/state.js',
    '/var/www/html/public/rwa/cert/shared/logger.js',
    '/var/www/html/public/rwa/cert/shared/events.js',
    '/var/www/html/public/rwa/cert/shared/guards.js',
    '/var/www/html/public/rwa/cert/modules/payment.js',
    '/var/www/html/public/rwa/cert/modules/mint-init.js',
    '/var/www/html/public/rwa/cert/modules/mint-verify.js',
    '/var/www/html/public/rwa/cert/modules/minted.js',
    '/var/www/html/public/rwa/cert/api/issue.php',
    '/var/www/html/public/rwa/cert/api/issue-pay.php',
    '/var/www/html/public/rwa/cert/api/confirm-payment.php',
    '/var/www/html/public/rwa/cert/api/verify-status.php',
    '/var/www/html/public/rwa/cert/api/cert-detail.php',
    '/var/www/html/public/rwa/cert/api/queue-summary.php',
    '/var/www/html/public/rwa/cert/api/_nft-guard.php',
    '/var/www/html/public/rwa/cert/api/mint-init.php',
    '/var/www/html/public/rwa/cert/api/mint-init.php',
    '/var/www/html/public/rwa/cert/api/mint-verify.php',
];

$fileChecks = array_map('dbg_file_status', $coreFiles);

$defaults = [
    'wallet' => $_POST['wallet'] ?? '',
    'owner_user_id' => $_POST['owner_user_id'] ?? '',
    'rwa_type' => $_POST['rwa_type'] ?? 'red',
    'family' => $_POST['family'] ?? 'secondary',
    'rwa_code' => $_POST['rwa_code'] ?? 'RTRIP-EMA',
    'cert_uid' => $_POST['cert_uid'] ?? '',
    'csrf' => $_POST['csrf'] ?? '',
    'run_step' => $_POST['run_step'] ?? '',
    'autorun' => $_POST['autorun'] ?? '',
    'mode' => $_POST['mode'] ?? 'readonly',
];

$results = [];
$resolvedCertUid = trim((string)$defaults['cert_uid']);
$flowSummary = [];
$mode = in_array((string)$defaults['mode'], ['readonly', 'mutate'], true) ? (string)$defaults['mode'] : 'readonly';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $issuePayload = [
        'wallet' => (string)$defaults['wallet'],
        'ton_wallet' => (string)$defaults['wallet'],
        'owner_user_id' => (string)$defaults['owner_user_id'],
        'rwa_type' => (string)$defaults['rwa_type'],
        'family' => (string)$defaults['family'],
        'rwa_code' => (string)$defaults['rwa_code'],
        'reuse_issued' => 1,
        'cert_uid' => (string)$defaults['cert_uid'],
        'uid' => (string)$defaults['cert_uid'],
        'cert' => (string)$defaults['cert_uid'],
        'csrf' => (string)$defaults['csrf'],
    ];

    $step = (string)$defaults['run_step'];
    if ($step === '' && (string)$defaults['autorun'] === '1') {
        $step = 'audit_pipeline';
    }

    if ($step === 'queue_summary') {
        $results['queue_summary'] = dbg_get_json($base . '/rwa/cert/api/queue-summary.php');
    }

    if ($step === 'issue' && dbg_mutating_allowed($mode)) {
        $results['issue'] = dbg_post_json($base . '/rwa/cert/api/issue.php', $issuePayload);
        $resolvedCertUid = dbg_extract_cert_uid($results['issue']['json'] ?? null, $resolvedCertUid);
    }

    if ($step === 'issue_pay' && dbg_mutating_allowed($mode)) {
        $results['issue_pay'] = dbg_post_json($base . '/rwa/cert/api/issue-pay.php', $issuePayload);
        $resolvedCertUid = dbg_extract_cert_uid($results['issue_pay']['json'] ?? null, $resolvedCertUid);
    }

    if ($step === 'confirm_payment' && dbg_mutating_allowed($mode)) {
        $results['confirm_payment'] = dbg_post_json($base . '/rwa/cert/api/confirm-payment.php', [
            'cert_uid' => (string)$defaults['cert_uid'],
            'wallet' => (string)$defaults['wallet'],
            'owner_user_id' => (string)$defaults['owner_user_id'],
            'csrf' => (string)$defaults['csrf'],
        ]);
    }

    if ($step === 'cert_detail') {
        $results['cert_detail'] = dbg_get_json($base . '/rwa/cert/api/cert-detail.php?cert_uid=' . rawurlencode((string)$defaults['cert_uid']));
    }

    if ($step === 'verify_status') {
        $qs = http_build_query([
            'cert_uid' => (string)$defaults['cert_uid'],
            'wallet' => (string)$defaults['wallet'],
            'owner_user_id' => (string)$defaults['owner_user_id'],
        ]);
        $results['verify_status'] = dbg_get_json($base . '/rwa/cert/api/verify-status.php?' . $qs);
    }

    if ($step === 'nft_guard') {
        $results['nft_guard'] = dbg_get_json($base . '/rwa/cert/api/_nft-guard.php?uid=' . rawurlencode((string)$defaults['cert_uid']));
    }

    if ($step === 'finalize') {
        $results['finalize'] = dbg_get_json($base . '/rwa/cert/api/mint-init.php?uid=' . rawurlencode((string)$defaults['cert_uid']));
    }

    if ($step === 'mint_init') {
        $results['mint_init'] = dbg_get_json($base . '/rwa/cert/api/mint-init.php?cert_uid=' . rawurlencode((string)$defaults['cert_uid']));
    }

    if ($step === 'mint_verify') {
        $results['mint_verify'] = dbg_get_json($base . '/rwa/cert/api/mint-verify.php?cert_uid=' . rawurlencode((string)$defaults['cert_uid']));
    }

    if ($step === 'audit_pipeline') {
        $results['queue_summary_before'] = dbg_get_json($base . '/rwa/cert/api/queue-summary.php');

        if ($resolvedCertUid !== '') {
            $results['cert_detail'] = dbg_get_json($base . '/rwa/cert/api/cert-detail.php?cert_uid=' . rawurlencode($resolvedCertUid));
            $results['verify_status'] = dbg_get_json($base . '/rwa/cert/api/verify-status.php?cert_uid=' . rawurlencode($resolvedCertUid));
            $results['nft_guard'] = dbg_get_json($base . '/rwa/cert/api/_nft-guard.php?uid=' . rawurlencode($resolvedCertUid));
            $results['finalize'] = dbg_get_json($base . '/rwa/cert/api/mint-init.php?uid=' . rawurlencode($resolvedCertUid));
            $results['mint_init'] = dbg_get_json($base . '/rwa/cert/api/mint-init.php?cert_uid=' . rawurlencode($resolvedCertUid));
            $results['mint_verify'] = dbg_get_json($base . '/rwa/cert/api/mint-verify.php?cert_uid=' . rawurlencode($resolvedCertUid));
        }

        $results['queue_summary_after'] = dbg_get_json($base . '/rwa/cert/api/queue-summary.php');
    }

    if ($step === 'full_flow' && dbg_mutating_allowed($mode)) {
        $results['queue_summary_before'] = dbg_get_json($base . '/rwa/cert/api/queue-summary.php');

        $results['issue'] = dbg_post_json($base . '/rwa/cert/api/issue.php', $issuePayload);
        $resolvedCertUid = dbg_extract_cert_uid($results['issue']['json'] ?? null, $resolvedCertUid);

        $issuePayPayload = $issuePayload;
        $issuePayPayload['cert_uid'] = $resolvedCertUid;
        $issuePayPayload['uid'] = $resolvedCertUid;
        $issuePayPayload['cert'] = $resolvedCertUid;

        $results['issue_pay'] = dbg_post_json($base . '/rwa/cert/api/issue-pay.php', $issuePayPayload);
        $resolvedCertUid = dbg_extract_cert_uid($results['issue_pay']['json'] ?? null, $resolvedCertUid);

        $results['confirm_payment'] = dbg_post_json($base . '/rwa/cert/api/confirm-payment.php', [
            'cert_uid' => $resolvedCertUid,
            'wallet' => (string)$defaults['wallet'],
            'owner_user_id' => (string)$defaults['owner_user_id'],
            'csrf' => (string)$defaults['csrf'],
        ]);

        $results['cert_detail'] = dbg_get_json($base . '/rwa/cert/api/cert-detail.php?cert_uid=' . rawurlencode($resolvedCertUid));
        $results['verify_status'] = dbg_get_json($base . '/rwa/cert/api/verify-status.php?cert_uid=' . rawurlencode($resolvedCertUid));
        $results['nft_guard'] = dbg_get_json($base . '/rwa/cert/api/_nft-guard.php?uid=' . rawurlencode($resolvedCertUid));
        $results['finalize'] = dbg_get_json($base . '/rwa/cert/api/mint-init.php?uid=' . rawurlencode($resolvedCertUid));
        $results['mint_init'] = dbg_get_json($base . '/rwa/cert/api/mint-init.php?cert_uid=' . rawurlencode($resolvedCertUid));
        $results['mint_verify'] = dbg_get_json($base . '/rwa/cert/api/mint-verify.php?cert_uid=' . rawurlencode($resolvedCertUid));
        $results['queue_summary_after'] = dbg_get_json($base . '/rwa/cert/api/queue-summary.php');
    }

    $verifyJson = $results['verify_status']['json'] ?? null;
    $detailJson = $results['cert_detail']['json'] ?? null;
    $finalizeJson = $results['finalize']['json'] ?? null;
    $mintInitJson = $results['mint_init']['json'] ?? null;
    $mintVerifyJson = $results['mint_verify']['json'] ?? null;

    $readModel = dbg_read_model_summary($verifyJson);

    $flowSummary = [
        'mode' => $mode,
        'resolved_cert_uid' => $resolvedCertUid,
        'canonical_uid' => dbg_is_canonical_uid($resolvedCertUid),
        'issue_ok' => dbg_result_ok($results['issue'] ?? null),
        'issue_pay_ok' => dbg_result_ok($results['issue_pay'] ?? null),
        'confirm_payment_ok' => dbg_result_ok($results['confirm_payment'] ?? null),
        'cert_detail_ok' => dbg_result_ok($results['cert_detail'] ?? null),
        'verify_status_ok' => dbg_result_ok($results['verify_status'] ?? null),
        'nft_guard_http_ok' => (($results['nft_guard']['http_code'] ?? 0) === 200),
        'finalize_ok' => dbg_result_ok($results['finalize'] ?? null),
        'mint_init_ok' => dbg_result_ok($results['mint_init'] ?? null),
        'mint_verify_ok' => dbg_result_ok($results['mint_verify'] ?? null),

        'verify_queue_bucket' => $readModel['queue_bucket'],
        'verify_flow_state' => $readModel['flow_state'],
        'verify_mint_status' => $readModel['mint_status'],
        'verify_payment_ready' => $readModel['payment_ready'],
        'verify_artifact_ready' => $readModel['artifact_ready'],
        'verify_nft_healthy' => $readModel['nft_healthy'],
        'verify_nft_minted' => $readModel['nft_minted'],
        'verify_nft_item_address' => $readModel['nft_item_address'],
        'verify_minted_at' => $readModel['minted_at'],
        'verify_strict_final_minted' => $readModel['strict_final_minted'],

        'detail_queue_bucket' => (string)($detailJson['detail']['queue_bucket'] ?? ''),
        'detail_payment_status' => (string)($detailJson['detail']['payment']['status'] ?? ''),
        'detail_payment_verified' => (int)($detailJson['detail']['payment']['verified'] ?? 0),

        'finalize_mint_ready' => dbg_boolish($finalizeJson['mint_ready'] ?? false),
        'finalize_nft_ready' => dbg_boolish($finalizeJson['nft_ready'] ?? false),
        'finalize_payload_present' => trim((string)($finalizeJson['mint_request']['payload_b64'] ?? '')) !== '',

        'mint_init_mint_ready' => dbg_boolish($mintInitJson['mint_ready'] ?? false),
        'mint_init_payload_present' => trim((string)($mintInitJson['payload_b64'] ?? '')) !== '',

        'mint_verify_verified' => dbg_boolish($mintVerifyJson['verified'] ?? false),
        'mint_verify_minted' => dbg_boolish($mintVerifyJson['minted'] ?? false),
    ];
}

$pdo = dbg_db();
$dbCert = ($pdo && $resolvedCertUid !== '') ? dbg_find_cert($pdo, $resolvedCertUid) : null;
$dbPayment = ($pdo && $resolvedCertUid !== '') ? dbg_find_payment($pdo, $resolvedCertUid) : null;

$dbStrictMinted = dbg_strict_final_minted_from_db($dbCert);

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>RWA Cert Current Pipeline Debug</title>
<style>
  body{margin:0;background:#0b1020;color:#e8eefc;font:14px/1.45 system-ui,sans-serif}
  .wrap{max-width:1360px;margin:0 auto;padding:20px}
  .card{background:#121933;border:1px solid #263056;border-radius:16px;padding:16px;margin-bottom:16px}
  h1,h2,h3{margin:0 0 12px}
  .grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
  .grid3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px}
  @media (max-width:1000px){.grid,.grid3{grid-template-columns:1fr}}
  label{display:block;margin:10px 0 6px;color:#aeb9dc}
  input,select,button,textarea{
    width:100%;box-sizing:border-box;border-radius:10px;border:1px solid #33406d;
    background:#0d1430;color:#fff;padding:10px
  }
  .row{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}
  @media (max-width:900px){.row{grid-template-columns:1fr}}
  .actions{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}
  @media (max-width:1100px){.actions{grid-template-columns:repeat(2,1fr)}}
  button{cursor:pointer}
  pre{white-space:pre-wrap;word-break:break-word;background:#0a1128;border:1px solid #263056;border-radius:12px;padding:12px;overflow:auto}
  table{width:100%;border-collapse:collapse}
  th,td{border-bottom:1px solid #263056;padding:8px;text-align:left;vertical-align:top}
  .ok{color:#61e294}
  .bad{color:#ff7b91}
  .warn{color:#ffd36b}
  .muted{color:#98a6d8}
  .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
  .pill{display:inline-block;padding:4px 8px;border-radius:999px;border:1px solid #33406d;background:#0d1430}
  .small{font-size:12px}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>RWA Cert Current Pipeline Debug</h1>
    <div class="muted">Current-pipeline debugger: issue → issue-pay → confirm-payment → verify-status → finalize → mint-init → mint-verify</div>
  </div>

  <form method="post" class="card" id="debugForm">
    <div class="row">
      <div>
        <label>Wallet</label>
        <input name="wallet" value="<?= dbg_h((string)$defaults['wallet']) ?>" placeholder="UQ... or 0:...">
      </div>
      <div>
        <label>Owner User ID</label>
        <input name="owner_user_id" value="<?= dbg_h((string)$defaults['owner_user_id']) ?>" placeholder="numeric users.id">
      </div>
      <div>
        <label>Cert UID</label>
        <input name="cert_uid" value="<?= dbg_h((string)$defaults['cert_uid']) ?>" placeholder="existing cert uid for audit/finalize/mint-init/mint-verify">
      </div>
    </div>

    <div class="row">
      <div>
        <label>RWA Type</label>
        <select name="rwa_type">
          <?php foreach (['green','blue','black','gold','pink','red','royal_blue','yellow'] as $v): ?>
            <option value="<?= dbg_h($v) ?>"<?= ((string)$defaults['rwa_type'] === $v ? ' selected' : '') ?>><?= dbg_h($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Family</label>
        <select name="family">
          <?php foreach (['genesis','secondary','tertiary'] as $fam): ?>
            <option value="<?= dbg_h($fam) ?>"<?= ((string)$defaults['family'] === $fam ? ' selected' : '') ?>><?= dbg_h($fam) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>RWA Code</label>
        <select name="rwa_code">
          <?php foreach (['RCO2C-EMA','RH2O-EMA','RBLACK-EMA','RK92-EMA','RLIFE-EMA','RTRIP-EMA','RPROP-EMA','RHRD-EMA'] as $code): ?>
            <option value="<?= dbg_h($code) ?>"<?= ((string)$defaults['rwa_code'] === $code ? ' selected' : '') ?>><?= dbg_h($code) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="row">
      <div>
        <label>Mode</label>
        <select name="mode">
          <option value="readonly"<?= $mode === 'readonly' ? ' selected' : '' ?>>READ-ONLY AUDIT</option>
          <option value="mutate"<?= $mode === 'mutate' ? ' selected' : '' ?>>MUTATING TEST</option>
        </select>
      </div>
      <div>
        <label>CSRF (optional)</label>
        <input name="csrf" value="<?= dbg_h((string)$defaults['csrf']) ?>">
      </div>
      <div>
        <label>Site Base</label>
        <input value="<?= dbg_h($base) ?>" disabled>
      </div>
    </div>

    <div class="actions" style="margin-top:14px">
      <button type="submit" name="run_step" value="queue_summary">Run queue-summary.php</button>
      <button type="submit" name="run_step" value="cert_detail">Run cert-detail.php</button>
      <button type="submit" name="run_step" value="verify_status">Run verify-status.php</button>
      <button type="submit" name="run_step" value="nft_guard">Run _nft-guard.php</button>
      <button type="submit" name="run_step" value="finalize">Run mint-init.php</button>
      <button type="submit" name="run_step" value="mint_init">Run mint-init.php</button>
      <button type="submit" name="run_step" value="mint_verify">Run mint-verify.php</button>
      <button type="submit" name="run_step" value="audit_pipeline" style="background:#173a22">Run AUDIT PIPELINE</button>

      <button type="submit" name="run_step" value="issue" style="background:#5c3f17">Run issue.php</button>
      <button type="submit" name="run_step" value="issue_pay" style="background:#5c3f17">Run issue-pay.php</button>
      <button type="submit" name="run_step" value="confirm_payment" style="background:#5c3f17">Run confirm-payment.php</button>
      <button type="submit" name="run_step" value="full_flow" style="background:#7b1f1f">Run FULL FLOW</button>
      <button type="submit" name="autorun" value="1" style="background:#22408b">AUTO RUN AUDIT</button>
    </div>

    <div class="small warn" style="margin-top:10px;">
      READ-ONLY AUDIT is safe. MUTATING TEST will call live write endpoints when selected.
    </div>
  </form>

  <div class="card">
    <button type="button" id="copyAllBtn" style="max-width:240px">Copy All Results</button>
  </div>

  <div class="grid3">
    <div class="card">
      <h2>Resolved Cert UID</h2>
      <div class="mono <?= $resolvedCertUid !== '' ? 'ok' : 'bad' ?>"><?= dbg_h($resolvedCertUid !== '' ? $resolvedCertUid : '(none)') ?></div>
      <div class="small muted" style="margin-top:8px;">Canonical UID: <span class="<?= dbg_is_canonical_uid($resolvedCertUid) ? 'ok' : 'bad' ?>"><?= dbg_is_canonical_uid($resolvedCertUid) ? 'YES' : 'NO' ?></span></div>
      <div class="small muted">Mode: <span class="pill"><?= dbg_h(strtoupper($mode)) ?></span></div>
    </div>
    <div class="card">
      <h2>DB Connectivity</h2>
      <div class="<?= $pdo ? 'ok' : 'bad' ?>"><?= $pdo ? 'CONNECTED' : 'NOT CONNECTED' ?></div>
      <div class="small muted" style="margin-top:8px;">Strict final minted from DB: <span class="<?= $dbStrictMinted ? 'ok' : 'bad' ?>"><?= $dbStrictMinted ? 'YES' : 'NO' ?></span></div>
    </div>
    <div class="card">
      <h2>Flow Summary</h2>
      <pre id="flowSummaryBox"><?= dbg_h(dbg_json($flowSummary)) ?></pre>
    </div>
  </div>

  <div class="card">
    <h2>Core File Coverage</h2>
    <table>
      <thead>
        <tr><th>Path</th><th>Exists</th><th>Bytes</th><th>Modified</th></tr>
      </thead>
      <tbody>
      <?php foreach ($fileChecks as $fc): ?>
        <tr>
          <td class="mono"><?= dbg_h($fc['path']) ?></td>
          <td class="<?= $fc['exists'] ? 'ok' : 'bad' ?>"><?= $fc['exists'] ? 'YES' : 'NO' ?></td>
          <td><?= dbg_h((string)$fc['bytes']) ?></td>
          <td><?= dbg_h((string)$fc['mtime']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="grid">
    <div class="card">
      <h2>DB Cert Row</h2>
      <pre id="dbCertBox"><?= dbg_h(dbg_json($dbCert)) ?></pre>
    </div>
    <div class="card">
      <h2>DB Payment Row</h2>
      <pre id="dbPaymentBox"><?= dbg_h(dbg_json($dbPayment)) ?></pre>
    </div>
  </div>

  <?php foreach ($results as $name => $result): ?>
    <div class="card result-block">
      <h2><?= dbg_h($name) ?></h2>
      <div>HTTP: <span class="mono"><?= dbg_h((string)($result['http_code'] ?? '')) ?></span></div>
      <div>cURL Error: <span class="mono"><?= dbg_h((string)($result['curl_error'] ?? '')) ?></span></div>
      <h3>JSON</h3>
      <pre><?= dbg_h(dbg_json($result['json'] ?? null)) ?></pre>
      <h3>Raw</h3>
      <pre><?= dbg_h((string)($result['raw'] ?? '')) ?></pre>
    </div>
  <?php endforeach; ?>
</div>

<script>
(function(){
  const btn = document.getElementById('copyAllBtn');
  if (btn) {
    btn.addEventListener('click', async () => {
      const blocks = Array.from(document.querySelectorAll('.result-block, #dbCertBox, #dbPaymentBox, #flowSummaryBox'));
      const text = blocks.map(el => el.innerText).join('\n\n--------------------\n\n');
      try {
        await navigator.clipboard.writeText(text);
        btn.textContent = 'Copied';
        setTimeout(() => btn.textContent = 'Copy All Results', 1200);
      } catch (e) {
        alert('Copy failed');
      }
    });
  }
})();
</script>
</body>
</html>
