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

function envv(string $k, string $d = ""): string {
    if (function_exists("poado_env")) {
        $v = poado_env($k, $d);
        return (is_string($v) && $v !== "") ? $v : $d;
    }
    $v = getenv($k);
    return ($v === false || $v === "") ? $d : (string)$v;
}

function must(bool $ok, string $msg): void {
    if (!$ok) {
        fwrite(STDERR, "ERROR: {$msg}\n");
        exit(2);
    }
}

function read_file_bytes(string $path): string {
    if (!is_file($path)) throw new RuntimeException("File not found: {$path}");
    $b = @file_get_contents($path);
    if ($b === false) throw new RuntimeException("Failed to read file: {$path}");
    if (strlen($b) < 1000) throw new RuntimeException("File too small: {$path}");
    return $b;
}

function upload_bytes(string $folderId, string $name, string $mime, string $bytes, array $meta): array {
    if (function_exists("poado_vault_upload_bytes")) {
        $r = poado_vault_upload_bytes($folderId, $name, $mime, $bytes, $meta);
        return is_array($r) ? $r : ["raw" => $r];
    }

    if (function_exists("poado_drive_upload_bytes")) {
        $r = poado_drive_upload_bytes($folderId, $name, $mime, $bytes);
        return is_array($r) ? $r : ["raw" => $r];
    }

    throw new RuntimeException("Missing poado_vault_upload_bytes() and poado_drive_upload_bytes()");
}


function do_upload(string $kind, string $path, string $folderId, string $dbName): void {
    $bytes = read_file_bytes($path);
    $ts = gmdate("Ymd_His");

    if ($kind === "full") {
        $fname = "db-full-{$dbName}-{$ts}.sql.gz";
        $mime = "application/gzip";
        $purpose = "db_backup_full";
    } elseif ($kind === "binlog") {
        $fname = "db-binlog-{$dbName}-{$ts}.tar.gz";
        $mime = "application/gzip";
        $purpose = "db_backup_binlog";
    } elseif ($kind === "system") {
        $fname = "system-{$ts}.tar.gz";
        $mime = "application/gzip";
        $purpose = "system_snapshot";
    } else {
        throw new RuntimeException("Unknown upload kind: {$kind}");
    }

    $sha256 = hash("sha256", $bytes);
    $shaName = $fname . ".sha256";
    $shaBody = $sha256 . "  " . $fname . "\n";

    $meta = [
        "purpose" => $purpose,
        "db"      => $dbName,
        "ts_utc"  => $ts,
        "source"  => $path,
        "bytes"   => strlen($bytes),
    ];

    $r1 = upload_bytes($folderId, $fname, $mime, $bytes, $meta);
    upload_bytes($folderId, $shaName, "text/plain", $shaBody, [
        "purpose" => $purpose . "_sha256",
        "db"      => $dbName,
        "ts_utc"  => $ts,
    ]);

    $fileId = (string)($r1["file_id"] ?? $r1["id"] ?? $r1["fileId"] ?? "");
    $link   = (string)($r1["link"] ?? $r1["webViewLink"] ?? $r1["web_view_link"] ?? "");
    if ($fileId !== "" && $link === "") {
        $link = "https://drive.google.com/file/d/{$fileId}/view?usp=drivesdk";
    }

    echo strtoupper($kind) . " upload OK\n";
    echo "file_id={$fileId}\n";
    echo "link={$link}\n";
    echo "sha256={$sha256}\n";
}

echo "=== RWA BACKUP RUNNER ===\n";
echo "TIME(UTC): " . gmdate("c") . "\n";

$mode = strtolower(trim((string)($argv[1] ?? "full")));
echo "MODE: {$mode}\n";

must(in_array($mode, ["full", "binlog", "system", "all"], true), "Unknown mode {}");
must(strtolower(envv("GOOGLE_DRIVE_MODE", "")) === "shared_drive", "GOOGLE_DRIVE_MODE must be shared_drive");

$folderId = envv("GOOGLE_DRIVE_BACKUP_FOLDER_ID", "");
must($folderId !== "", "Missing GOOGLE_DRIVE_BACKUP_FOLDER_ID");

$dbName = envv("DB_NAME", "wems_db");

$fullPath   = "/var/tmp/wems_db_latest.sql.gz";
$binlogPath = "/var/tmp/wems_db_binlog_daily.tar.gz";
$systemPath = "/var/tmp/poado_system_snapshot.tar.gz";

$exit = 0;

try {
    if ($mode === "full") {
        do_upload("full", $fullPath, $folderId, $dbName);
    } elseif ($mode === "binlog") {
        if (is_file($binlogPath)) {
            do_upload("binlog", $binlogPath, $folderId, $dbName);
        } else {
            echo "BINLOG missing, skip: {$binlogPath}\n";
        }
    } elseif ($mode === "system") {
        do_upload("system", $systemPath, $folderId, $dbName);
    } elseif ($mode === "all") {
        do_upload("full", $fullPath, $folderId, $dbName);
        if (is_file($binlogPath)) do_upload("binlog", $binlogPath, $folderId, $dbName);
        if (is_file($systemPath)) do_upload("system", $systemPath, $folderId, $dbName);
    }
} catch (Throwable $e) {
    $exit = 2;
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
}

echo "=== RUNNER COMPLETE rc={$exit} ===\n";
exit($exit);
