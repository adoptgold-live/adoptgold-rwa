<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/core/bootstrap.php';

db_connect();
$pdo = $GLOBALS['pdo'];

$st = $pdo->query("
SELECT user_id, COUNT(*) as cnt
FROM poado_mining_anomalies
WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
GROUP BY user_id
HAVING cnt > 20
");

while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $uid = $row['user_id'];

    echo "[ALERT] user {$uid} anomaly count={$row['cnt']}\n";

    // future:
    // auto flag / reduce multiplier / freeze mining
}
