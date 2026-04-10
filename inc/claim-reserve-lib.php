<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/inc/claim-reserve-lib.php
 * Claim reserve lock for Storage-owned wEMS claims
 */

if (defined('POADO_CLAIM_RESERVE_LIB_LOADED')) {
    return;
}
define('POADO_CLAIM_RESERVE_LIB_LOADED', true);

function poado_claim_available_wems(PDO $pdo, int $userId): float
{
    $st = $pdo->prepare("
        SELECT COALESCE(unclaim_wems, 0)
        FROM rwa_storage_balances
        WHERE user_id = ?
        LIMIT 1
    ");
    $st->execute([$userId]);
    return round((float)($st->fetchColumn() ?: 0), 9);
}

function poado_claim_reserved_wems(PDO $pdo, int $userId): float
{
    $st = $pdo->prepare("
        SELECT COALESCE(SUM(amount_wems), 0)
        FROM poado_wems_claim_requests
        WHERE user_id = ?
          AND claim_status IN ('pending','approved')
    ");
    $st->execute([$userId]);
    return round((float)($st->fetchColumn() ?: 0), 9);
}

function poado_claim_free_wems(PDO $pdo, int $userId): float
{
    $available = poado_claim_available_wems($pdo, $userId);
    $reserved  = poado_claim_reserved_wems($pdo, $userId);
    return round(max(0, $available - $reserved), 9);
}

/**
 * Creates one locked pending claim request.
 * Requires caller to already validate KYC + wallet + amount > 0.
 */
function poado_create_claim_reserve(
    PDO $pdo,
    int $userId,
    string $wallet,
    string $destinationWallet,
    float $amount
): array {
    $amount = round($amount, 9);
    if ($amount <= 0) {
        throw new RuntimeException('INVALID_AMOUNT');
    }

    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare("
            SELECT user_id, unclaim_wems
            FROM rwa_storage_balances
            WHERE user_id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $lock->execute([$userId]);
        $bal = $lock->fetch(PDO::FETCH_ASSOC);

        if (!$bal) {
            throw new RuntimeException('NO_STORAGE_BALANCE');
        }

        $free = poado_claim_free_wems($pdo, $userId);
        if ($amount > $free) {
            throw new RuntimeException('INSUFFICIENT_FREE_BALANCE');
        }

        // Prevent exact-duplicate spam for same user/amount/destination still open
        $dup = $pdo->prepare("
            SELECT request_uid
            FROM poado_wems_claim_requests
            WHERE user_id = ?
              AND destination_wallet = ?
              AND amount_wems = ?
              AND claim_status IN ('pending','approved')
            ORDER BY id DESC
            LIMIT 1
        ");
        $dup->execute([$userId, $destinationWallet, $amount]);
        $existing = $dup->fetchColumn();
        if ($existing) {
            $pdo->commit();
            return [
                'request_uid' => (string)$existing,
                'amount_wems' => $amount,
                'claim_status' => 'pending',
                'duplicate' => true,
            ];
        }

        $requestUid = 'CLM-WEMS-' . gmdate('YmdHis') . '-' . substr(bin2hex(random_bytes(6)), 0, 12);

        $ins = $pdo->prepare("
            INSERT INTO poado_wems_claim_requests (
                user_id,
                wallet,
                request_uid,
                amount_wems,
                claim_status,
                destination_wallet,
                requested_at,
                meta
            ) VALUES (?, ?, ?, ?, 'pending', ?, UTC_TIMESTAMP(), ?)
        ");
        $ins->execute([
            $userId,
            $wallet,
            $requestUid,
            $amount,
            $destinationWallet,
            json_encode([
                'reserve_lock' => true,
                'source' => 'storage_claim_wems',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ]);

        $pdo->commit();

        return [
            'request_uid' => $requestUid,
            'amount_wems' => $amount,
            'claim_status' => 'pending',
            'duplicate' => false,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
