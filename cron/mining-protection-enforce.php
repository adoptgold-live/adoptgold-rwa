<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/core/bootstrap.php';
require_once __DIR__ . '/../inc/mining-protection-lib.php';

db_connect();
$pdo = $GLOBALS['pdo'];

$users = $pdo->query("
SELECT user_id, wallet
FROM poado_miner_profiles
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($users as $u) {

    $action = poado_apply_protection(
        $pdo,
        (int)$u['user_id'],
        $u['wallet']
    );

    if ($action !== 'OK') {
        echo "[PROTECT] user {$u['user_id']} => {$action}\n";
    }
}
