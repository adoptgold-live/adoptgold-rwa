<?php
declare(strict_types=1);

/**
 * /rwa/api/storage/unclaimed-wems.php
 * Source of truth: mining totals → Storage display
 */

require $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/rwa-session.php';
require $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/core/bootstrap.php';
require $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/core/session-user.php';

header('Content-Type: application/json');

function ok($d){ echo json_encode(['ok'=>true]+$d); exit; }
function fail($m,$c=400){ http_response_code($c); echo json_encode(['ok'=>false,'message'=>$m]); exit; }

db_connect();
$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) fail('DB_FAIL',500);

$user = session_user();
if (!$user) fail('NO_SESSION',401);

$uid = (int)($user['id'] ?? 0);
if ($uid <= 0) fail('INVALID_USER',403);

$stmt = $pdo->prepare("
SELECT 
 total_mined_wems,
 total_binding_wems,
 total_node_bonus_wems,
 total_claimed_wems
FROM poado_miner_profiles
WHERE user_id = ?
LIMIT 1
");
$stmt->execute([$uid]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
  ok([
    'unclaimed_wems' => 0,
    'source' => 'miner_profiles_empty'
  ]);
}

$total =
  (float)$row['total_mined_wems']
+ (float)$row['total_binding_wems']
+ (float)$row['total_node_bonus_wems'];

$claimed = (float)$row['total_claimed_wems'];

$unclaimed = max(0, $total - $claimed);

ok([
  'unclaimed_wems' => round($unclaimed, 9),
  'source' => 'poado_miner_profiles',
  'ts' => date('c')
]);
