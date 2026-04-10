<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cron/mining-reset.php
 * Reset daily mining counters at UTC 00:00
 */

require_once __DIR__ . '/../inc/core/bootstrap.php';

date_default_timezone_set('UTC');

function out(string $msg): void
{
    echo '[' . gmdate('c') . '] ' . $msg . PHP_EOL;
}

db_connect();
$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) {
    throw new RuntimeException('DB connection unavailable');
}

$affected = $pdo->exec("
    UPDATE poado_miner_profiles
    SET
      today_mined_wems = 0,
      today_binding_wems = 0,
      today_node_bonus_wems = 0
");

out('mining-reset done rows=' . (int)$affected);
