<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'].'/var/www/html/public/rwa/inc/core/bootstrap.php';

$pdo = (($GLOBALS['pdo'] ?? null) instanceof PDO) ? $GLOBALS['pdo'] : (function_exists('db') ? db() : null);
if (!($pdo instanceof PDO)) { fwrite(STDERR, "DB_UNAVAILABLE\n"); exit(1); }

$rows = $pdo->query("
SELECT cert_uid, nft_item_address, meta_json
FROM poado_rwa_certs
WHERE status='minted'
AND listed_at IS NULL
LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $r) {

    $meta = json_decode($r['meta_json'], true);

    $listing = [
        "nft_address" => $r['nft_item_address'],
        "price_ton" => 1,
        "marketplace" => "getgems",
        "metadata" => $meta['vault']['metadata'] ?? ''
    ];

    // simulate listing (replace with API / webhook)
    file_put_contents(
        "/tmp/getgems_listing_{$r['cert_uid']}.json",
        json_encode($listing, JSON_UNESCAPED_SLASHES)
    );

    $pdo->prepare("
        UPDATE poado_rwa_certs
        SET listed_at = NOW()
        WHERE cert_uid=?
    ")->execute([$r['cert_uid']]);

    echo "LISTED {$r['cert_uid']}\n";
}
