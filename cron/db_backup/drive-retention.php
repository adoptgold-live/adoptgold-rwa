<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set("display_errors", "1");

if (PHP_SAPI !== "cli") { http_response_code(403); exit("CLI only\n"); }
if (!defined("POADO_ENV_PATH")) define("POADO_ENV_PATH", "/var/www/secure/.env");

require_once dirname(__DIR__, 2) . "/inc/core/env.php";
require_once dirname(__DIR__, 2) . "/vendor/autoload.php";

if (function_exists("poado_env_bootstrap")) {
    try { poado_env_bootstrap(); } catch (Throwable $e) {}
}

function envv(string $k, string $d=""): string {
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

echo "=== RWA DRIVE RETENTION ===\n";
echo "TIME(UTC): " . gmdate("c") . "\n";

must(strtolower(envv("GOOGLE_DRIVE_MODE", "")) === "shared_drive", "GOOGLE_DRIVE_MODE must be shared_drive");

$svcJson = envv("GOOGLE_DRIVE_SERVICE_JSON", "");
$sharedId = envv("GOOGLE_DRIVE_SHARED_ID", "");
$folderId = envv("GOOGLE_DRIVE_BACKUP_FOLDER_ID", "");

must($svcJson !== "" && is_file($svcJson), "Missing/invalid GOOGLE_DRIVE_SERVICE_JSON");
must($sharedId !== "", "Missing GOOGLE_DRIVE_SHARED_ID");
must($folderId !== "", "Missing GOOGLE_DRIVE_BACKUP_FOLDER_ID");

$keepFull   = max(1, (int)envv("DB_BACKUP_FULL_KEEP_DAYS", "30"));
$keepBinlog = max(1, (int)envv("DB_BACKUP_BINLOG_KEEP_DAYS", "14"));

$now = new DateTimeImmutable("now", new DateTimeZone("UTC"));
$cutFull   = $now->modify("-{$keepFull} days");
$cutBinlog = $now->modify("-{$keepBinlog} days");

$client = new Google\Client();
$client->setAuthConfig($svcJson);
$client->setScopes([Google\Service\Drive::DRIVE]);
$service = new Google\Service\Drive($client);

function classify_cutoff(string $name, DateTimeImmutable $cutFull, DateTimeImmutable $cutBinlog): ?DateTimeImmutable {
    if (str_starts_with($name, "db-full-")) return $cutFull;
    if (str_starts_with($name, "system-")) return $cutFull;
    if (str_starts_with($name, "db-binlog-")) return $cutBinlog;

    if (str_ends_with($name, ".sha256")) {
        if (str_contains($name, "db-binlog-")) return $cutBinlog;
        if (str_contains($name, "db-full-") || str_contains($name, "system-")) return $cutFull;
    }

    return null;
}

$deleted = 0;
$pageToken = null;

do {
    $params = [
        "q" => sprintf("'%s' in parents and trashed=false", $folderId),
        "fields" => "nextPageToken, files(id,name,createdTime,modifiedTime)",
        "pageSize" => 200,
        "supportsAllDrives" => true,
        "includeItemsFromAllDrives" => true,
        "corpora" => "drive",
        "driveId" => $sharedId,
        "pageToken" => $pageToken,
    ];

    $res = $service->files->listFiles($params);
    foreach ($res->getFiles() as $f) {
        $name = (string)$f->getName();
        $id   = (string)$f->getId();
        $tStr = (string)($f->getModifiedTime() ?: $f->getCreatedTime());
        if ($tStr === "") continue;

        $cut = classify_cutoff($name, $cutFull, $cutBinlog);
        if ($cut === null) continue;

        $t = new DateTimeImmutable($tStr, new DateTimeZone("UTC"));
        if ($t < $cut) {
            $service->files->delete($id, ["supportsAllDrives" => true]);
            $deleted++;
            echo "DELETED: {$name} ({$tStr})\n";
        }
    }

    $pageToken = $res->getNextPageToken();
} while ($pageToken);

echo "=== RETENTION DONE deleted={$deleted} keepFullDays={$keepFull} keepBinlogDays={$keepBinlog} ===\n";
exit(0);
