<?php
declare(strict_types=1);

/**
 * /rwa/cron/mining-claim-settlement.php
 * Safe claim settlement with row lock
 */

require_once __DIR__ . '/../inc/core/bootstrap.php';

date_default_timezone_set('UTC');

function out(string $msg): void
{
    echo '[' . gmdate('c') . '] ' . $msg . PHP_EOL;
}

function send_wems_mock(string $wallet, float $amount): string
{
    return 'MOCK_TX_' . substr(hash('sha256', $wallet . '|' . $amount . '|' . microtime(true)), 0, 20);
}

db_connect();
$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) {
    out('DB_FAIL');
    exit(1);
}

$sel = $pdo->query("
    SELECT id
    FROM poado_wems_claim_requests
    WHERE claim_status = 'approved'
    ORDER BY requested_at ASC, id ASC
    LIMIT 20
");

$ids = $sel->fetchAll(PDO::FETCH_COLUMN) ?: [];
$sent = 0;
$failed = 0;
$skipped = 0;

foreach ($ids as $id) {
    $id = (int)$id;

    try {
        $pdo->beginTransaction();

        $lock = $pdo->prepare("
            SELECT *
            FROM poado_wems_claim_requests
            WHERE id = ?
            FOR UPDATE
        ");
        $lock->execute([$id]);
        $row = $lock->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $pdo->rollBack();
            $skipped++;
            continue;
        }

        if ((string)$row['claim_status'] !== 'approved') {
            $pdo->rollBack();
            $skipped++;
            continue;
        }

        $userId = (int)$row['user_id'];
        $wallet = (string)$row['wallet'];
        $requestUid = (string)$row['request_uid'];
        $dest = (string)$row['destination_wallet'];
        $amount = round((float)$row['amount_wems'], 9);

        if ($amount <= 0) {
            $bad = $pdo->prepare("
                UPDATE poado_wems_claim_requests
                SET claim_status = 'failed',
                    reject_reason = 'INVALID_AMOUNT',
                    processed_at = UTC_TIMESTAMP()
                WHERE id = ?
            ");
            $bad->execute([$id]);
            $pdo->commit();
            $failed++;
            continue;
        }

        // Replace later with real TON sender
        $txHash = send_wems_mock($dest, $amount);

        $updReq = $pdo->prepare("
            UPDATE poado_wems_claim_requests
            SET
              claim_status = 'sent',
              tx_hash = ?,
              processed_at = UTC_TIMESTAMP()
            WHERE id = ?
        ");
        $updReq->execute([$txHash, $id]);

        $insLedger = $pdo->prepare("
            INSERT INTO poado_mining_ledger (
                user_id,
                wallet,
                entry_type,
                amount_wems,
                ref_code,
                ref_date,
                meta
            ) VALUES (?, ?, 'claim_debit', ?, ?, ?, ?)
        ");
        $insLedger->execute([
            $userId,
            $wallet,
            -$amount,
            $requestUid,
            gmdate('Y-m-d'),
            json_encode([
                'destination_wallet' => $dest,
                'tx_hash' => $txHash,
                'claim_request_uid' => $requestUid,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ]);

        $updProfile = $pdo->prepare("
            UPDATE poado_miner_profiles
            SET total_claimed_wems = total_claimed_wems + ?
            WHERE user_id = ?
        ");
        $updProfile->execute([$amount, $userId]);

        $pdo->commit();
        $sent++;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $failed++;
        out('claim settlement error id=' . $id . ' err=' . $e->getMessage());
    }
}

out('mining-claim-settlement done sent=' . $sent . ' skipped=' . $skipped . ' failed=' . $failed);
