<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cron/royalty-claim-queue.php
 *
 * Locked rules:
 * - full KYC required
 * - canonical KYC field = users.is_fully_verified
 * - user pays gas
 * - treasury fee = 0.10 TON
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';

date_default_timezone_set('Asia/Kuala_Lumpur');

function rcq_pdo(): PDO
{
    if (function_exists('db')) return db();
    if (function_exists('rwa_db')) return rwa_db();
    if (($GLOBALS['pdo'] ?? null) instanceof PDO) return $GLOBALS['pdo'];
    throw new RuntimeException('PDO_NOT_AVAILABLE');
}

function rcq_log(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

function rcq_user_kyc(PDO $pdo, int $userId): bool
{
    if ($userId <= 0) return false;

    $st = $pdo->prepare("
        SELECT COALESCE(is_fully_verified, 0) AS ok
        FROM users
        WHERE id = :id
        LIMIT 1
    ");
    $st->execute([':id' => $userId]);
    return (int)$st->fetchColumn() === 1;
}

try {
    $pdo = rcq_pdo();

    $claims = $pdo->query("
        SELECT id, claim_ref, owner_user_id, amount_ton, status
        FROM poado_rwa_royalty_claims
        WHERE status = 'pending'
        ORDER BY id ASC
        LIMIT 200
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (!$claims) {
        rcq_log('No pending claims.');
        exit(0);
    }

    $queue = $pdo->prepare("
        UPDATE poado_rwa_royalty_claims
        SET status = :status,
            kyc_verified = :kyc_verified,
            updated_at = NOW(),
            meta_json = JSON_SET(COALESCE(meta_json, '{}'), '$.queue_at', :queue_at)
        WHERE id = :id
    ");

    $queued = 0;
    $rejected = 0;

    foreach ($claims as $c) {
        $id = (int)$c['id'];
        $userId = (int)$c['owner_user_id'];
        $kycOk = rcq_user_kyc($pdo, $userId);

        if ($kycOk) {
            $queue->execute([
                ':status' => 'queued',
                ':kyc_verified' => 1,
                ':queue_at' => date('c'),
                ':id' => $id,
            ]);
            rcq_log("Queued claim_ref={$c['claim_ref']} amount_ton={$c['amount_ton']}");
            $queued++;
        } else {
            $queue->execute([
                ':status' => 'kyc_required',
                ':kyc_verified' => 0,
                ':queue_at' => date('c'),
                ':id' => $id,
            ]);
            rcq_log("KYC required claim_ref={$c['claim_ref']}");
            $rejected++;
        }
    }

    rcq_log("Done. queued={$queued} kyc_required={$rejected}");
    exit(0);
} catch (Throwable $e) {
    rcq_log('ERROR: ' . $e->getMessage());
    exit(1);
}
