<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set("display_errors", "1");

if (PHP_SAPI !== "cli") { http_response_code(403); exit("CLI only\n"); }

if (!defined("POADO_ENV_PATH")) {
    define("POADO_ENV_PATH", "/var/www/secure/.env");
}

require_once dirname(__DIR__, 2) . "/inc/core/env.php";
require_once dirname(__DIR__, 2) . "/inc/core/drive.php";
require_once dirname(__DIR__, 2) . "/inc/core/vault.php";

if (function_exists("poado_env_bootstrap")) {
    try { poado_env_bootstrap(); } catch (Throwable $e) {}
}

function envv(string $k, string $default = ""): string {
    if (function_exists("poado_env")) {
        $v = poado_env($k, $default);
        return is_string($v) ? $v : $default;
    }
    $v = getenv($k);
    return ($v === false) ? $default : (string)$v;
}

echo "START BINLOG backup\n";
echo "TIME(UTC): " . gmdate("c") . "\n";

if (strtolower(trim(envv("GOOGLE_DRIVE_MODE", ""))) !== "shared_drive") {
    fwrite(STDERR, "ERROR: GOOGLE_DRIVE_MODE must be shared_drive\n");
    exit(2);
}

$dbHost = envv("DB_HOST", "127.0.0.1");
$dbName = envv("DB_NAME", "wems_db");
$dbUser = envv("DB_USER", "wems_user");
$dbPass = envv("DB_PASS", "");

$mysql = "/usr/bin/mysql";
if (!is_file($mysql)) $mysql = "/bin/mysql";

$cmd = [
    $mysql,
    "--host=" . $dbHost,
    "--user=" . $dbUser,
    "--password=" . $dbPass,
    "--database=" . $dbName,
    "--batch",
    "--skip-column-names",
    "-e", "SHOW VARIABLES LIKE 'log_bin';"
];

$desc = [1 => ["pipe", "w"], 2 => ["pipe", "w"]];
$proc = proc_open($cmd, $desc, $pipes);
if (!is_resource($proc)) {
    fwrite(STDERR, "ERROR: Failed to start mysql\n");
    exit(2);
}

$out = trim((string)stream_get_contents($pipes[1])); fclose($pipes[1]);
$err = trim((string)stream_get_contents($pipes[2])); fclose($pipes[2]);
$rc  = (int)proc_close($proc);

if ($rc !== 0) {
    fwrite(STDERR, "ERROR: mysql rc={$rc}\n{$err}\n");
    exit(2);
}

$parts = preg_split("/\s+/", $out);
$val = strtoupper((string)($parts[1] ?? ""));

if ($val !== "ON") {
    echo "BINLOG is not enabled (log_bin={$val}). Skipping.\n";
    echo "END rc=0\n";
    exit(0);
}

echo "BINLOG is ON. Export is intentionally disabled in this production-safe build.\n";
echo "END rc=0\n";
exit(0);
