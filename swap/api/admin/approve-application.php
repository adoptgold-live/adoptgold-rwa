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

function make_worker_uid(PDO $pdo): string
{
    do {
        $uid = swap_uid('WKR');
        $stmt = $pdo->prepare("SELECT 1 FROM rwa_hr_workers WHERE worker_uid = :uid LIMIT 1");
        $stmt->execute([':uid' => $uid]);
        $exists = (bool)$stmt->fetchColumn();
    } while ($exists);

    return $uid;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    fail_out('Method not allowed', 405);
}

$csrf = (string)($_POST['csrf'] ?? '');
$csrfOk = false;
if (function_exists('csrf_check')) {
    try {
        $r = csrf_check('swap_admin_approve', $csrf);
        $csrfOk = ($r !== false);
    } catch (Throwable $e) {
        $csrfOk = false;
    }
} else {
    $csrfOk = ($csrf !== '');
}
if (!$csrfOk) {
    fail_out('Invalid request token', 403);
}

$requestUid = trim((string)($_POST['request_uid'] ?? ''));
if ($requestUid === '') {
    fail_out('Request UID required', 422);
}

$pdo = swap_db();
$pdo->beginTransaction();

try {
    $stmt = $pdo->prepare("
        SELECT *
        FROM rwa_hr_job_requests
        WHERE request_uid = :request_uid
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([':request_uid' => $requestUid]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$req) {
        throw new RuntimeException('Application not found');
    }

    $projectKey = trim((string)($req['project_key'] ?? ''));
    if ($role === 'agent' && $projectKey !== '' && !swap_can_access_project($projectKey, $user)) {
        throw new RuntimeException('Not authorized for this project');
    }

    // Prevent duplicate worker creation for same request
    $alreadyApproved = in_array((string)$req['application_status'], ['approved', 'assigned'], true);

    // Soft check if worker already exists by passport
    $passportNo = trim((string)($req['passport_no'] ?? ''));
    $workerCheck = $pdo->prepare("
        SELECT worker_uid
        FROM rwa_hr_workers
        WHERE passport_no = :passport_no
        ORDER BY id DESC
        LIMIT 1
    ");
    $workerCheck->execute([':passport_no' => $passportNo]);
    $existingWorkerUid = (string)($workerCheck->fetchColumn() ?: '');

    if ($existingWorkerUid === '' && !$alreadyApproved) {
        $workerUid = make_worker_uid($pdo);

        $ins = $pdo->prepare("
            INSERT INTO rwa_hr_workers (
              worker_uid,
              project_key,
              full_name,
              passport_no,
              nationality,
              nationality_other,
              gender,
              date_of_birth,
              mobile_country_code,
              mobile_number,
              mobile_e164,
              whatsapp_url,
              tg_username,
              sector,
              worker_status,
              status_stage,
              next_action,
              memo_text,
              memo_updated_at,
              memo_updated_by,
              created_at,
              updated_at
            ) VALUES (
              :worker_uid,
              :project_key,
              :full_name,
              :passport_no,
              :nationality,
              :nationality_other,
              :gender,
              :date_of_birth,
              :mobile_country_code,
              :mobile_number,
              :mobile_e164,
              :whatsapp_url,
              :tg_username,
              :sector,
              'pending_docs',
              'approved',
              'Wait for assignment / compliance onboarding',
              :memo_text,
              NOW(),
              :memo_updated_by,
              NOW(),
              NOW()
            )
        ");
        $ins->execute([
            ':worker_uid' => $workerUid,
            ':project_key' => $projectKey !== '' ? $projectKey : null,
            ':full_name' => (string)($req['full_name'] ?? ''),
            ':passport_no' => $passportNo,
            ':nationality' => (string)($req['nationality'] ?? ''),
            ':nationality_other' => ((string)($req['nationality_other'] ?? '') !== '') ? (string)$req['nationality_other'] : null,
            ':gender' => (string)($req['gender'] ?? ''),
            ':date_of_birth' => (string)($req['date_of_birth'] ?? ''),
            ':mobile_country_code' => (string)($req['mobile_country_code'] ?? ''),
            ':mobile_number' => (string)($req['mobile_number'] ?? ''),
            ':mobile_e164' => (string)($req['mobile_e164'] ?? ''),
            ':whatsapp_url' => ((string)($req['whatsapp_url'] ?? '') !== '') ? (string)$req['whatsapp_url'] : null,
            ':tg_username' => ((string)($req['tg_username'] ?? '') !== '') ? (string)$req['tg_username'] : null,
            ':sector' => (string)($req['preferred_industry'] ?? ''),
            ':memo_text' => ((string)($req['memo_text'] ?? '') !== '') ? (string)$req['memo_text'] : null,
            ':memo_updated_by' => $userId > 0 ? $userId : null,
        ]);
    }

    $upd = $pdo->prepare("
        UPDATE rwa_hr_job_requests
        SET
          application_status = 'approved',
          status_stage = 'approved',
          next_action = 'Wait for assignment / compliance onboarding',
          memo_updated_at = NOW(),
          memo_updated_by = :memo_updated_by,
          updated_at = NOW()
        WHERE request_uid = :request_uid
        LIMIT 1
    ");
    $upd->execute([
        ':memo_updated_by' => $userId > 0 ? $userId : null,
        ':request_uid' => $requestUid,
    ]);

    $pdo->commit();

    header('Location: /rwa/swap/admin/applications.php?status=approved');
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fail_out($e->getMessage(), 400);
}