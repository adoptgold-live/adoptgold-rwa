<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'].'/var/www/html/public/rwa/inc/core/bootstrap.php';

$pdo = (($GLOBALS['pdo'] ?? null) instanceof PDO) ? $GLOBALS['pdo'] : (function_exists('db') ? db() : null);

function table_exists(PDO $pdo, string $table): bool {
    $st = $pdo->prepare("SHOW TABLES LIKE ?");
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
}
if (!($pdo instanceof PDO)) { fwrite(STDERR, "DB_UNAVAILABLE\n"); exit(1); }

echo "<pre style='color:#0f0;background:#000;padding:20px'>";

// =============================
// 1. NFT INTEGRITY
// =============================
echo "\n=== NFT CHECK ===\n";

$nfts = $pdo->query("
SELECT cert_uid, metadata_path
FROM poado_rwa_certs
WHERE status='minted'
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($nfts as $n) {

    $metaFile = $_SERVER['DOCUMENT_ROOT'] . $n['metadata_path'];

    if (!file_exists($metaFile)) {
        echo "MISSING META {$n['cert_uid']}\n";
        continue;
    }

    $meta = json_decode(file_get_contents($metaFile), true);

    if (!isset($meta['image']) || strpos($meta['image'], 'https://') !== 0) {
        echo "INVALID IMAGE {$n['cert_uid']}\n";
    }
}

// =============================
// 2. ROYALTY VS CLAIM
// =============================
echo "\n=== ROYALTY VS CLAIM ===\n";

$royalty = $pdo->query("
SELECT COALESCE(SUM(royalty_amount_ton),0) FROM poado_rwa_royalty_events_v2
")->fetchColumn();

$claims = $pdo->query("
SELECT SUM(amount_ton) FROM poado_rwa_claims
")->fetchColumn();

echo "Royalty Total: $royalty\n";
echo "Claims Total:  $claims\n";

if (abs($royalty - $claims) > 0.0001) {
    echo "⚠ MISMATCH DETECTED\n";
}

// =============================
// 3. DOUBLE CLAIM CHECK
// =============================
echo "\n=== DOUBLE CLAIM CHECK ===\n";

$dup = $pdo->query("
SELECT user_id, cert_uid, COUNT(*)
FROM poado_rwa_claims
GROUP BY user_id, cert_uid
HAVING COUNT(*) > 1
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($dup as $d) {
    echo "DUPLICATE CLAIM {$d['cert_uid']} USER {$d['user_id']}\n";
}

// =============================
// 4. UNCLAIMED BALANCE
// =============================
echo "\n=== UNCLAIMED BALANCE ===\n";

$unclaimed = $pdo->query("
SELECT SUM(amount_ton)
FROM poado_rwa_claims
WHERE claimed=0
")->fetchColumn();

echo "Unclaimed TON: $unclaimed\n";

echo "\n=== AUDIT COMPLETE ===\n";

echo "</pre>";
