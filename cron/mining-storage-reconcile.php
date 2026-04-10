<?php
declare(strict_types=1);

/**
 * /rwa/cron/mining-storage-reconcile.php
 *
 * Sync Mining → Storage (rwa_storage_balances.unclaim_wems)
 */

require_once __DIR__ . '/../inc/core/bootstrap.php';

date_default_timezone_set('UTC');

db_connect();
$pdo = $GLOBALS['pdo'] ?? null;

if (!$pdo instanceof PDO) {
    echo "[ERR] DB fail\n";
    exit(1);
}

$stmt = $pdo->query("
    SELECT user_id
    FROM poado_miner_profiles
");

$count = 0;

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {

    $uid = (int)$row['user_id'];

    try {

        $st = $pdo->prepare("
            SELECT
                total_mined_wems,
                total_binding_wems,
                total_node_bonus_wems,
                total_claimed_wems
            FROM poado_miner_profiles
            WHERE user_id = ?
            LIMIT 1
        ");
        $st->execute([$uid]);
        $mp = $st->fetch(PDO::FETCH_ASSOC);

        if (!$mp) continue;

        $total =
            (float)$mp['total_mined_wems']
          + (float)$mp['total_binding_wems']
          + (float)$mp['total_node_bonus_wems'];

        $claimed = (float)$mp['total_claimed_wems'];

        $unclaimed = max(0, $total - $claimed);

        // 🔥 WRITE INTO CORRECT COLUMN
        $st2 = $pdo->prepare("
            INSERT INTO rwa_storage_balances
            (user_id, unclaim_wems, updated_at)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                unclaim_wems = VALUES(unclaim_wems),
                updated_at = NOW()
        ");

        $st2->execute([$uid, $unclaimed]);

        $count++;

    } catch (Throwable $e) {
        echo "[ERR] user {$uid} : " . $e->getMessage() . "\n";
    }
}

echo "[OK] reconciled users: {$count}\n";
