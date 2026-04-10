<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/core/bootstrap.php';

$pdo = $GLOBALS['pdo'];

$rows = $pdo->query("
SELECT cert_uid, holder_pool_ton, ace_pool_ton
FROM poado_rwa_royalty_events_v2
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $r) {

    $cert = $pdo->prepare("SELECT owner_user_id FROM poado_rwa_certs WHERE cert_uid=?");
    $cert->execute([$r['cert_uid']]);
    $owner = $cert->fetchColumn();

    if (!$owner) continue;

    $pdo->prepare("
    INSERT INTO poado_rwa_claims (user_id, cert_uid, pool_type, amount_ton)
    VALUES (?, ?, 'holder', ?)
    ")->execute([$owner, $r['cert_uid'], $r['holder_pool_ton']]);

    echo "CLAIM BUILT {$r['cert_uid']}\n";
}
