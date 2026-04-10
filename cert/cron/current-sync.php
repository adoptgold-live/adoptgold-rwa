<?php
declare(strict_types=1);

require_once '/var/www/html/public/rwa/inc/core/bootstrap.php';
require_once '/var/www/html/public/rwa/cert/api/_cert-current-manager.php';

echo "[CURRENT-SYNC] START\n";

$pdo = db();

$rows = $pdo->query("
    SELECT cert_uid, rwa_code, family, owner_user_id, status, meta_json
    FROM poado_rwa_certs
    WHERE status IN ('issued','minted')
    ORDER BY id DESC
    LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $row) {

    $row['meta_json_decoded'] = json_decode($row['meta_json'] ?? '{}', true) ?: [];

    $r = cert_current_refresh($row);

    echo "[CURRENT] {$row['cert_uid']} => " . ($r['ok'] ? 'OK' : 'FAIL') . "\n";
}

echo "[CURRENT-SYNC] DONE\n";
