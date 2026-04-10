<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/core/bootstrap.php';
if (function_exists('db_connect')) db_connect();
$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) exit(1);

$pdo->exec("DELETE FROM poado_tg_login_tokens WHERE used=1 OR revoked=1 OR expires_at < (NOW() - INTERVAL 1 DAY)");
$pdo->exec("DELETE FROM poado_tg_login_attempts WHERE created_at < (NOW() - INTERVAL 7 DAY)");
$pdo->exec("DELETE FROM poado_tg_login_rate_limits WHERE created_at < (NOW() - INTERVAL 7 DAY)");
echo "ok\n";
