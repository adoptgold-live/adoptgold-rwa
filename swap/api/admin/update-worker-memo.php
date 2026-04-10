<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/swap/inc/swap-helpers.php';

$user = swap_require_admin_or_agent();
$userId = (int)($user['id'] ?? 0);
$role = strtolower(trim((string)($user['role'] ?? '')));

function fail_out(string $msg, int $code = 400): never {
    http_response_code($code);
    exit($msg);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    fail_out('Method not allowed', 405);
}

$workerUid = trim((string)($_POST['worker_uid'] ?? ''));
$memoText = trim((string)($_POST['memo_text'] ?? ''));

if ($workerUid === '') {
    fail_out('Worker UID required', 422);
}

$pdo = swap_db();

$stmt = $pdo->prepare("
    SELECT worker_uid, project_key
    FROM rwa_hr_workers
    WHERE worker_uid = :worker_uid
    LIMIT 1
");
$stmt->execute([':worker_uid' => $workerUid]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    fail_out('Worker not found', 404);
}

$projectKey = trim((string)($row['project_key'] ?? ''));
if ($role === 'agent' && $projectKey !== '' && !swap_can_access_project($projectKey, $user)) {
    fail_out('Not authorized for this project', 403);
}

$upd = $pdo->prepare("
    UPDATE rwa_hr_workers
    SET
      memo_text = :memo_text,
      memo_updated_at = NOW(),
      memo_updated_by = :memo_updated_by,
      updated_at = NOW()
    WHERE worker_uid = :worker_uid
    LIMIT 1
");
$upd->execute([
    ':memo_text' => $memoText !== '' ? $memoText : null,
    ':memo_updated_by' => $userId > 0 ? $userId : null,
    ':worker_uid' => $workerUid,
]);

header('Location: /rwa/swap/admin/workers.php');
exit;