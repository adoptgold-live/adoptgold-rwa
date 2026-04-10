<?php
require_once $_SERVER['DOCUMENT_ROOT'].'/var/www/html/public/rwa/inc/core/bootstrap.php';

$pdo = (($GLOBALS['pdo'] ?? null) instanceof PDO) ? $GLOBALS['pdo'] : (function_exists('db') ? db() : null);

function table_exists(PDO $pdo, string $table): bool {
    $st = $pdo->prepare("SHOW TABLES LIKE ?");
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
}
if (!($pdo instanceof PDO)) { fwrite(STDERR, "DB_UNAVAILABLE\n"); exit(1); }

function val($q) {
    global $pdo;
    return $pdo->query($q)->fetchColumn() ?: 0;
}

$royalty = val("SELECT SUM(royalty_amount_ton) FROM poado_rwa_royalty_events_v2");
$claims  = val("SELECT SUM(amount_ton) FROM poado_rwa_claims");

$diff = abs($royalty - $claims);

// ===== ALERT CONDITIONS =====
if ($diff > 0.01) {

    $msg = "CRITICAL: RWA mismatch detected\n";
    $msg .= "Royalty: $royalty\nClaims: $claims\nDiff: $diff\n";

    file_put_contents("/var/log/rwa-alert.log", date('c')." ".$msg, FILE_APPEND);

    echo $msg;
}
