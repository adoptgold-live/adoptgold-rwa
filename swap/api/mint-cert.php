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

function mint_fail(string $m, int $c = 400): never {
    json_error($m, $c);
}

function mint_uid(PDO $pdo): string
{
    do {
        $uid = swap_uid('CERT');
        $s = $pdo->prepare("SELECT 1 FROM rwa_hr_cert_logs WHERE cert_uid = :uid LIMIT 1");
        $s->execute([':uid' => $uid]);
        $exists = (bool)$s->fetchColumn();
    } while ($exists);
    return $uid;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    mint_fail('Method not allowed', 405);
}

$raw = file_get_contents('php://input');
$data = json_decode((string)$raw, true);
if (!is_array($data)) {
    $data = $_POST;
}

$workerUid = trim((string)($data['worker_uid'] ?? ''));
$certCount = (int)($data['cert_count'] ?? 1);

if ($workerUid === '') {
    mint_fail('worker_uid required', 422);
}
if ($certCount < 1) {
    mint_fail('cert_count must be at least 1', 422);
}
if ($certCount > 100) {
    mint_fail('cert_count too large', 422);
}

$pdo = swap_db();

$pdo->beginTransaction();

try {
    $stmt = $pdo->prepare("
        SELECT *
        FROM rwa_hr_workers
        WHERE worker_uid = :uid
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([':uid' => $workerUid]);
    $worker = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$worker) {
        throw new RuntimeException('Worker not found');
    }

    if ($role === 'agent' && !swap_can_access_project((string)$worker['project_key'], $user)) {
        throw new RuntimeException('Not authorized for this project');
    }

    $projectKey = (string)($worker['project_key'] ?? '');
    if ($projectKey === '') {
        throw new RuntimeException('Worker project missing');
    }

    $year = (int)date('Y');

    // Current-year approved hours
    $sumStmt = $pdo->prepare("
        SELECT COALESCE(SUM(hours_worked), 0)
        FROM rwa_hr_work_logs
        WHERE worker_uid = :uid
          AND YEAR(work_date) = :yr
          AND approval_status = 'approved'
    ");
    $sumStmt->execute([
        ':uid' => $workerUid,
        ':yr' => $year,
    ]);
    $yearTotalHours = (float)$sumStmt->fetchColumn();

    // Current-year minted/used hours
    $usedStmt = $pdo->prepare("
        SELECT COALESCE(SUM(hours_used), 0)
        FROM rwa_hr_cert_logs
        WHERE worker_uid = :uid
          AND benefit_year = :yr
          AND cert_status = 'issued'
    ");
    $usedStmt->execute([
        ':uid' => $workerUid,
        ':yr' => $year,
    ]);
    $yearUsedHours = (float)$usedStmt->fetchColumn();

    $availableHours = $yearTotalHours - $yearUsedHours;
    if ($availableHours < 0) {
        $availableHours = 0;
    }

    $hoursPerCert = 10.0;
    $emaPerCert = 100.0;

    $requiredHours = $hoursPerCert * $certCount;
    $requiredEma = $emaPerCert * $certCount;

    if ($availableHours < $requiredHours) {
        throw new RuntimeException('Not enough available hours for mint');
    }

    // Placeholder EMA balance/integration
    // Replace with canonical EMA balance source later.
    $emaBalance = null;

    // 1) If workers table has a local cached field, use it
    if (array_key_exists('ema_balance', $worker) && $worker['ema_balance'] !== null && $worker['ema_balance'] !== '') {
        $emaBalance = (float)$worker['ema_balance'];
    }

    // 2) Fallback: allow admin/agent to proceed only if explicit bypass not set to false
    // For production, replace this with storage/wallet balance check.
    if ($emaBalance === null) {
        // Soft placeholder so the API is usable during staged rollout.
        // Change this to hard fail once EMA balance source is wired.
        $emaBalance = 999999999.0;
    }

    if ($emaBalance < $requiredEma) {
        throw new RuntimeException('Not enough EMA$ balance');
    }

    // Upsert yearly contribution ledger
    $yearStarts = sprintf('%04d-01-01 00:00:00', $year);
    $yearExpires = sprintf('%04d-12-31 23:59:59', $year);

    $ledgerStmt = $pdo->prepare("
        SELECT id, year_total_hours, year_used_hours, year_remaining_hours, certs_minted
        FROM rwa_hr_contribution_years
        WHERE worker_uid = :uid AND benefit_year = :yr
        LIMIT 1
        FOR UPDATE
    ");
    $ledgerStmt->execute([
        ':uid' => $workerUid,
        ':yr' => $year,
    ]);
    $ledger = $ledgerStmt->fetch(PDO::FETCH_ASSOC);

    if (!$ledger) {
        $insLedger = $pdo->prepare("
            INSERT INTO rwa_hr_contribution_years (
                worker_uid,
                project_key,
                benefit_year,
                year_total_hours,
                year_used_hours,
                year_remaining_hours,
                certs_minted,
                starts_at,
                expires_at,
                status,
                created_at,
                updated_at
            ) VALUES (
                :worker_uid,
                :project_key,
                :benefit_year,
                :year_total_hours,
                0,
                :year_remaining_hours,
                0,
                :starts_at,
                :expires_at,
                'active',
                NOW(),
                NOW()
            )
        ");
        $insLedger->execute([
            ':worker_uid' => $workerUid,
            ':project_key' => $projectKey,
            ':benefit_year' => $year,
            ':year_total_hours' => $yearTotalHours,
            ':year_remaining_hours' => $availableHours,
            ':starts_at' => $yearStarts,
            ':expires_at' => $yearExpires,
        ]);

        $ledgerStmt->execute([
            ':uid' => $workerUid,
            ':yr' => $year,
        ]);
        $ledger = $ledgerStmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$ledger) {
        throw new RuntimeException('Failed to initialize yearly contribution ledger');
    }

    $newUsed = ((float)$ledger['year_used_hours']) + $requiredHours;
    $newRemaining = $yearTotalHours - $newUsed;
    if ($newRemaining < 0) {
        throw new RuntimeException('Available hours changed, please retry');
    }
    $newCertsMinted = ((int)$ledger['certs_minted']) + $certCount;

    // Insert cert logs (1 row per cert)
    $issued = [];
    $insCert = $pdo->prepare("
        INSERT INTO rwa_hr_cert_logs (
            cert_uid,
            worker_uid,
            project_key,
            benefit_year,
            hours_used,
            units_issued,
            ema_price,
            cert_status,
            issued_at,
            created_at,
            updated_at
        ) VALUES (
            :cert_uid,
            :worker_uid,
            :project_key,
            :benefit_year,
            :hours_used,
            1.00,
            :ema_price,
            'issued',
            NOW(),
            NOW(),
            NOW()
        )
    ");

    for ($i = 0; $i < $certCount; $i++) {
        $certUid = mint_uid($pdo);
        $insCert->execute([
            ':cert_uid' => $certUid,
            ':worker_uid' => $workerUid,
            ':project_key' => $projectKey,
            ':benefit_year' => $year,
            ':hours_used' => $hoursPerCert,
            ':ema_price' => $emaPerCert,
        ]);
        $issued[] = $certUid;
    }

    // Update yearly ledger
    $updLedger = $pdo->prepare("
        UPDATE rwa_hr_contribution_years
        SET
            year_total_hours = :year_total_hours,
            year_used_hours = :year_used_hours,
            year_remaining_hours = :year_remaining_hours,
            certs_minted = :certs_minted,
            updated_at = NOW()
        WHERE id = :id
        LIMIT 1
    ");
    $updLedger->execute([
        ':year_total_hours' => $yearTotalHours,
        ':year_used_hours' => $newUsed,
        ':year_remaining_hours' => $newRemaining,
        ':certs_minted' => $newCertsMinted,
        ':id' => (int)$ledger['id'],
    ]);

    // Optional worker summary cache fields if present in schema
    $updates = [];
    $params = [
        ':worker_uid' => $workerUid,
    ];

    // Always update updated_at / next_action
    $updates[] = "updated_at = NOW()";
    $updates[] = "next_action = :next_action";
    $params[':next_action'] = 'Certificate minted successfully';

    // Best-effort optional cached counters
    $workerColumns = array_keys($worker);

    if (in_array('hours_contributed_year', $workerColumns, true)) {
        $updates[] = "hours_contributed_year = :hours_contributed_year";
        $params[':hours_contributed_year'] = $yearTotalHours;
    }
    if (in_array('hours_used_year', $workerColumns, true)) {
        $updates[] = "hours_used_year = :hours_used_year";
        $params[':hours_used_year'] = $newUsed;
    }
    if (in_array('hours_remaining_year', $workerColumns, true)) {
        $updates[] = "hours_remaining_year = :hours_remaining_year";
        $params[':hours_remaining_year'] = $newRemaining;
    }
    if (in_array('certs_minted_total', $workerColumns, true)) {
        $curr = (int)($worker['certs_minted_total'] ?? 0);
        $updates[] = "certs_minted_total = :certs_minted_total";
        $params[':certs_minted_total'] = $curr + $certCount;
    }

    if (count($updates) > 0) {
        $sql = "UPDATE rwa_hr_workers SET " . implode(', ', $updates) . " WHERE worker_uid = :worker_uid LIMIT 1";
        $updWorker = $pdo->prepare($sql);
        $updWorker->execute($params);
    }

    // Placeholder EMA deduction log point
    // Replace with canonical storage/ledger deduction once wired.
    // For now, no local deduction table write is performed here.

    $pdo->commit();

    json_ok([
        'worker_uid' => $workerUid,
        'cert_count' => $certCount,
        'cert_uids' => $issued,
        'hours_per_cert' => $hoursPerCert,
        'ema_per_cert' => $emaPerCert,
        'required_hours' => $requiredHours,
        'required_ema' => $requiredEma,
        'year' => $year,
        'year_total_hours' => $yearTotalHours,
        'year_used_hours' => $newUsed,
        'year_remaining_hours' => $newRemaining,
        'message' => 'Certificate minted successfully',
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    mint_fail($e->getMessage(), 400);
}