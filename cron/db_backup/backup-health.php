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

function ok(string $m): void { echo "OK: {$m}\n"; }
function failm(string $m): void { echo "FAIL: {$m}\n"; }

function must_file(string $path, int $minBytes): bool {
    if (!is_file($path)) { failm("missing file: {$path}"); return false; }
    $sz = @filesize($path);
    if (!is_int($sz) || $sz <= 0) { failm("cannot stat: {$path}"); return false; }
    if ($sz < $minBytes) { failm("too small ({$sz}B < {$minBytes}B): {$path}"); return false; }
    ok("file OK ({$sz}B): {$path}");
    return true;
}

function list_folder_files(Google\Service\Drive $service, string $sharedId, string $folderId): array {
    $out = [];
    $pageToken = null;

    do {
        $res = $service->files->listFiles([
            "q" => sprintf("'%s' in parents and trashed=false", $folderId),
            "fields" => "nextPageToken, files(id,name,createdTime,modifiedTime,size)",
            "pageSize" => 200,
            "supportsAllDrives" => true,
            "includeItemsFromAllDrives" => true,
            "corpora" => "drive",
            "driveId" => $sharedId,
            "pageToken" => $pageToken
        ]);

        foreach ($res->getFiles() as $f) {
            $out[] = [
                "id" => (string)$f->getId(),
                "name" => (string)$f->getName(),
                "modified" => (string)($f->getModifiedTime() ?: $f->getCreatedTime()),
                "size" => (string)($f->getSize() ?? ""),
            ];
        }

        $pageToken = $res->getNextPageToken();
    } while ($pageToken);

    return $out;
}

function recent_by_prefix(array $files, string $prefix, int $hours): array {
    $cut = time() - ($hours * 3600);
    $out = [];

    foreach ($files as $f) {
        $name = (string)($f["name"] ?? "");
        $mod  = (string)($f["modified"] ?? "");
        if ($name === "" || $mod === "") continue;
        if (!str_starts_with($name, $prefix)) continue;

        $ts = strtotime($mod);
        if ($ts === false) continue;
        if ($ts >= $cut) $out[] = $f;
    }

    usort($out, function($a, $b) {
        return strcmp((string)$b["modified"], (string)$a["modified"]);
    });

    return $out;
}

echo "=== RWA BACKUP HEALTH ===\n";
echo "TIME(UTC): " . gmdate("c") . "\n";

$mode = strtolower(envv("GOOGLE_DRIVE_MODE", ""));
if ($mode !== "shared_drive") {
    failm("GOOGLE_DRIVE_MODE must be shared_drive (got {})");
    exit(2);
}

$folderId = envv("GOOGLE_DRIVE_BACKUP_FOLDER_ID", "");
$sharedId = envv("GOOGLE_DRIVE_SHARED_ID", "");
$svcJson  = envv("GOOGLE_DRIVE_SERVICE_JSON", "");
echo "DEBUG folderId={$folderId}
"; echo "DEBUG sharedId={$sharedId}
"; echo "DEBUG svcJson={$svcJson}
";

if ($folderId === "" || $sharedId === "" || $svcJson === "" || !is_file($svcJson)) {
    failm("Drive env missing/invalid (folder/shared/service_json)");
    exit(2);
}

$rc = 0;
$rc |= must_file("/var/tmp/wems_db_latest.sql.gz", 10000) ? 0 : 1;
$rc |= must_file("/var/tmp/poado_system_snapshot.tar.gz", 1000000) ? 0 : 1;

$client = new Google\Client();
$client->setAuthConfig($svcJson);
$client->setScopes([Google\Service\Drive::DRIVE]);
$service = new Google\Service\Drive($client);

try {
    $files = list_folder_files($service, $sharedId, $folderId);

    $db26  = recent_by_prefix($files, "db-full-", 26);
    $sys26 = recent_by_prefix($files, "system-", 26);
    $db48  = recent_by_prefix($files, "db-full-", 48);
    $sys48 = recent_by_prefix($files, "system-", 48);

    if (count($db26) >= 1) {
        ok("Drive recent db-full OK: " . $db26[0]["name"] . " @ " . $db26[0]["modified"]);
    } else {
        failm("Drive missing recent db-full in last 26h");
        $rc |= 1;
    }

    if (count($sys26) >= 1) {
        ok("Drive recent system OK: " . $sys26[0]["name"] . " @ " . $sys26[0]["modified"]);
    } else {
        failm("Drive missing recent system in last 26h");
        $rc |= 1;
    }

    ok("Drive 48h counts: db-full=" . count($db48) . " system=" . count($sys48));
} catch (Throwable $e) {
    failm("Drive query failed: " . $e->getMessage());
    $rc |= 1;
}

echo "=== HEALTH COMPLETE rc={$rc} ===\n";
exit($rc ? 2 : 0);
