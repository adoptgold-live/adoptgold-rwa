<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/json.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/swap/inc/swap-helpers.php';

$user = swap_require_admin_or_agent();
$role = strtolower(trim((string)($user['role'] ?? '')));
$userId = (int)($user['id'] ?? 0);

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
$workDate  = trim((string)($data['work_date'] ?? ''));
$hours     = (float)($data['hours_worked'] ?? 0);
$note      = trim((string)($data['note'] ?? ''));

if ($workerUid === '' || $workDate === '' || $hours <= 0) {
    fail_json('Missing required fields', 422);
}

/* basic validation */
if ($hours > 24) {
    fail_json('Invalid hours', 422);
}

$pdo = swap_db();

/* check worker */
$stmt = $pdo->prepare("SELECT * FROM rwa_hr_workers WHERE worker_uid = :uid LIMIT 1");
$stmt->execute([':uid' => $workerUid]);
$worker = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$worker) {
    fail_json('Worker not found', 404);
}

/* agent restriction */
if ($role === 'agent' && !swap_can_access_project((string)$worker['project_key'], $user)) {
    fail_json('Not authorized for this project', 403);
}

/* prevent duplicate per day */
$dup = $pdo->prepare("
    SELECT id FROM rwa_hr_work_logs
    WHERE worker_uid = :uid
      AND work_date = :work_date
    LIMIT 1
");
$dup->execute([
    ':uid' => $workerUid,
    ':work_date' => $workDate
]);

if ($dup->fetch()) {
    fail_json('Work already logged for this date', 409);
}

/* insert */
$ins = $pdo->prepare("
INSERT INTO rwa_hr_work_logs (
  worker_uid,
  project_key,
  work_date,
  hours_worked,
  approval_status,
  note,
  created_by,
  created_at
) VALUES (
  :worker_uid,
  :project_key,
  :work_date,
  :hours,
  'approved',
  :note,
  :created_by,
  NOW()
)
");

$ins->execute([
    ':worker_uid' => $workerUid,
    ':project_key' => (string)$worker['project_key'],
    ':work_date' => $workDate,
    ':hours' => $hours,
    ':note' => $note !== '' ? $note : null,
    ':created_by' => $userId
]);

/* calculate yearly hours */
$year = date('Y');
$sum = $pdo->prepare("
SELECT SUM(hours_worked)
FROM rwa_hr_work_logs
WHERE worker_uid = :uid
  AND YEAR(work_date) = :yr
  AND approval_status = 'approved'
");
$sum->execute([
    ':uid' => $workerUid,
    ':yr' => $year
]);
$totalHours = (float)($sum->fetchColumn() ?: 0);

/* update worker yearly stats */
$upd = $pdo->prepare("
UPDATE rwa_hr_workers
SET
  hours_contributed_year = :hrs,
  last_work_log_at = NOW(),
  updated_at = NOW()
WHERE worker_uid = :uid
LIMIT 1
");
$upd->execute([
    ':hrs' => $totalHours,
    ':uid' => $workerUid
]);

json_ok([
    'worker_uid' => $workerUid,
    'logged_hours' => $hours,
    'year_total_hours' => $totalHours,
    'certs_available' => floor($totalHours / 10),
    'message' => 'Work hours logged'
]);