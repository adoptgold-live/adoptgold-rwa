<?php
declare(strict_types=1);

// /rwa/swap/api/employer/alerts.php
// SWAP 2.0 Employer Alerts API
// v1.0.0-20260326

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/swap/inc/swap-helpers.php';

header('Content-Type: application/json; charset=utf-8');

$userId = function_exists('session_user_id') ? (int) session_user_id() : 0;
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'message' => 'LOGIN_REQUIRED'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$user = function_exists('session_user') ? session_user() : null;
if (!is_array($user)) {
    $user = [];
}

$pdo = function_exists('swap_db') ? swap_db() : ($GLOBALS['pdo'] ?? null);
if (!$pdo instanceof PDO) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'DB_NOT_READY'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$projectKey = trim((string)($user['project_key'] ?? ''));
if ($projectKey === '') {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'message' => 'NO_PROJECT_ASSIGNED'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
if ($limit < 1) $limit = 20;
if ($limit > 100) $limit = 100;

$sql = "
SELECT
  worker_uid,
  full_name,
  risk_level,
  worker_status,
  deployable_status,
  next_action,
  welfare_score,
  welfare_band,
  mobile_e164,
  country_name,
  state_name,
  area_name
FROM rwa_hr_workers
WHERE project_key = :pk
  AND (
    LOWER(COALESCE(risk_level,'')) IN ('high','critical')
    OR LOWER(COALESCE(deployable_status,'')) = 'no'
    OR LOWER(COALESCE(worker_status,'')) IN ('pending_fomema','pending_permit','non_compliant')
  )
ORDER BY
  CASE LOWER(COALESCE(risk_level,'')) WHEN 'critical' THEN 0 WHEN 'high' THEN 1 ELSE 2 END,
  welfare_score ASC,
  worker_uid DESC
LIMIT {$limit}
";

$stmt = $pdo->prepare($sql);
$stmt->execute([':pk' => $projectKey]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$out = [];
foreach ($rows as $r) {
    $digits = preg_replace('/\D+/', '', (string)($r['mobile_e164'] ?? ''));
    $waLink = $digits !== '' ? ('https://wa.me/' . $digits) : '';

    $locationBits = array_filter([
        (string)($r['country_name'] ?? ''),
        (string)($r['state_name'] ?? ''),
        (string)($r['area_name'] ?? ''),
    ], fn($v) => trim((string)$v) !== '');

    $out[] = [
        'worker_uid'         => (string)($r['worker_uid'] ?? ''),
        'full_name'          => (string)($r['full_name'] ?? ''),
        'risk_level'         => (string)($r['risk_level'] ?? ''),
        'worker_status'      => (string)($r['worker_status'] ?? ''),
        'deployable_status'  => (string)($r['deployable_status'] ?? ''),
        'next_action'        => (string)($r['next_action'] ?? ''),
        'welfare_score'      => $r['welfare_score'] ?? null,
        'welfare_band'       => (string)($r['welfare_band'] ?? ''),
        'location'           => $locationBits ? implode(' · ', $locationBits) : '',
        'mobile_e164'        => $digits,
        'whatsapp_link'      => $waLink,
        'worker_url'         => '/rwa/swap/employer/worker.php?worker_uid=' . rawurlencode((string)($r['worker_uid'] ?? '')),
    ];
}

echo json_encode([
    'ok' => true,
    'project_key' => $projectKey,
    'count' => count($out),
    'items' => $out,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);