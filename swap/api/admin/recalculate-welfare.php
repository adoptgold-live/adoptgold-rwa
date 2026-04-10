<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/json.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/swap/inc/swap-helpers.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/swap/inc/welfare-helpers.php';

$user = swap_require_admin_or_agent();
$role = strtolower(trim((string)($user['role'] ?? '')));

function fail_json(string $m, int $c = 400): never {
    json_error($m, $c);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    fail_json('Method not allowed', 405);
}

$raw = file_get_contents('php://input');
$data = json_decode((string)$raw, true);
if (!is_array($data)) $data = $_POST;

$workerUid = trim((string)($data['worker_uid'] ?? ''));
if ($workerUid === '') {
    fail_json('worker_uid required', 422);
}

$pdo = swap_db();

$stmt = $pdo->prepare("SELECT * FROM rwa_hr_workers WHERE worker_uid = :uid LIMIT 1");
$stmt->execute([':uid' => $workerUid]);
$worker = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$worker) {
    fail_json('Worker not found', 404);
}

if ($role === 'agent' && !swap_can_access_project((string)$worker['project_key'], $user)) {
    fail_json('Not authorized for this project', 403);
}

$calc = swap_welfare_calculate($worker, $pdo);

$upd = $pdo->prepare("
    UPDATE rwa_hr_workers
    SET welfare_score = :score,
        welfare_band = :band,
        deployable_status = :deployable,
        risk_level = :risk,
        next_action = :next_action,
        last_welfare_check_at = NOW(),
        updated_at = NOW()
    WHERE worker_uid = :uid
    LIMIT 1
");
$upd->execute([
    ':score' => $calc['welfare_score'],
    ':band' => $calc['welfare_band'],
    ':deployable' => $calc['deployable_status'],
    ':risk' => $calc['risk_level'],
    ':next_action' => $calc['next_action'],
    ':uid' => $workerUid,
]);

$log = $pdo->prepare("
    INSERT INTO rwa_hr_welfare_logs (
      worker_uid,
      project_key,
      welfare_score,
      welfare_band,
      deployable_status,
      risk_level,
      rule_hits_json
    ) VALUES (
      :worker_uid,
      :project_key,
      :score,
      :band,
      :deployable,
      :risk,
      :rule_hits_json
    )
");
$log->execute([
    ':worker_uid' => $workerUid,
    ':project_key' => (string)$worker['project_key'],
    ':score' => $calc['welfare_score'],
    ':band' => $calc['welfare_band'],
    ':deployable' => $calc['deployable_status'],
    ':risk' => $calc['risk_level'],
    ':rule_hits_json' => json_encode($calc['rule_hits'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
]);

json_ok([
    'worker_uid' => $workerUid,
    'welfare_score' => $calc['welfare_score'],
    'welfare_band' => $calc['welfare_band'],
    'deployable_status' => $calc['deployable_status'],
    'risk_level' => $calc['risk_level'],
    'next_action' => $calc['next_action'],
    'rule_hits' => $calc['rule_hits'],
]);