<?php
declare(strict_types=1);

/**
 * Mining → Storage Bridge
 * Sync unclaimed wEMS bucket
 *
 * Call this after ANY mining credit/debit event
 */

function mining_storage_sync(PDO $pdo, int $userId): void
{
    // get mining totals
    $stmt = $pdo->prepare("
        SELECT
            wallet,
            total_mined_wems,
            total_binding_wems,
            total_node_bonus_wems,
            total_claimed_wems
        FROM poado_miner_profiles
        WHERE user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) return;

    $wallet = (string)$row['wallet'];

    $total =
        (float)$row['total_mined_wems']
      + (float)$row['total_binding_wems']
      + (float)$row['total_node_bonus_wems'];

    $claimed = (float)$row['total_claimed_wems'];

    $unclaimed = max(0, $total - $claimed);

    // store in bucket table (canonical storage layer)
    $stmt = $pdo->prepare("
        INSERT INTO poado_offchain_balances
        (user_id, wallet, bucket_key, amount, updated_at)
        VALUES (?, ?, 'unclaimed_wems', ?, NOW())
        ON DUPLICATE KEY UPDATE
            amount = VALUES(amount),
            updated_at = NOW()
    ");

    $stmt->execute([
        $userId,
        $wallet,
        $unclaimed
    ]);
}
