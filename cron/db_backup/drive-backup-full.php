<?php
declare(strict_types=1);

if (PHP_SAPI !== "cli") { exit("CLI only\n"); }

if (!defined("POADO_ENV_PATH")) {
    define("POADO_ENV_PATH", "/var/www/secure/.env");
}

require_once dirname(__DIR__, 2) . "/inc/core/env.php";
require_once dirname(__DIR__, 2) . "/inc/core/drive.php";
require_once dirname(__DIR__, 2) . "/inc/core/vault.php";

if (function_exists("poado_env_bootstrap")) {
    try { poado_env_bootstrap(); } catch (Throwable $e) {}
}

function envv(string $k, string $d=""): string {
    if (function_exists("poado_env")) {
        $v = poado_env($k, $d);
        return is_string($v) ? $v : $d;
    }
    $v = getenv($k);
    return $v === false ? $d : (string)$v;
}

echo "START FULL backup upload\n";

$driveMode = envv("GOOGLE_DRIVE_MODE");
if ($driveMode !== "shared_drive") {
    fwrite(STDERR, "Drive mode must be shared_drive\n");
    exit(2);
}

$backupFolderId = envv("GOOGLE_DRIVE_BACKUP_FOLDER_ID");
if (!$backupFolderId) {
    fwrite(STDERR, "Missing GOOGLE_DRIVE_BACKUP_FOLDER_ID\n");
    exit(2);
}

$dumpFile = "/var/tmp/wems_db_latest.sql.gz";
if (!is_file($dumpFile)) {
    fwrite(STDERR, "Dump file not found: {$dumpFile}\n");
    exit(2);
}

$bytes = file_get_contents($dumpFile);
if ($bytes === false || strlen($bytes) < 1000) {
    fwrite(STDERR, "Invalid dump file\n");
    exit(2);
}

$filename = "db-full-wems_db-" . gmdate("Ymd_His") . ".sql.gz";

$result = poado_vault_upload_bytes(
    $backupFolderId,
    $filename,
    "application/gzip",
    $bytes,
    [
        "purpose" => "db_backup_full",
        "ts_utc"  => gmdate("c")
    ]
);

echo "Upload OK\n";
echo "END rc=0\n";
exit(0);
