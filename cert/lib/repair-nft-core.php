<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/lib/repair-nft-core.php
 * Version: v4.1.0-20260408-shared-core
 *
 * FINAL LOCK
 * - shared single source of truth for repair nft
 * - cron + api wrappers must require this file only
 * - executor only
 * - it must NEVER write nft/image.png directly
 * - it must NEVER write metadata.json directly
 * - it must NEVER write verify.json directly
 * - all artifact generation must come only from _image-bundle.php
 */

require_once dirname(__DIR__) . "/api/_image-bundle.php";

if (!defined("RWA_CORE_BOOTSTRAPPED")) {
    $bootstrapCandidates = [
        dirname(__DIR__, 2) . "/inc/core/bootstrap.php",
        dirname(__DIR__, 3) . "/rwa/inc/core/bootstrap.php",
        dirname(__DIR__, 3) . "/dashboard/inc/bootstrap.php",
    ];
    $loaded = false;
    foreach ($bootstrapCandidates as $file) {
        if (is_file($file)) {
            require_once $file;
            $loaded = true;
            break;
        }
    }
    if (!$loaded) {
        throw new RuntimeException("BOOTSTRAP_NOT_FOUND");
    }
}

const RR_VERSION = "v4.1.0-20260408-shared-core";
const RR_PUBLIC_ROOT = "/var/www/html/public";
const RR_SITE_URL = "https://adoptgold.app";

function rr_is_cli(): bool
{
    return PHP_SAPI === "cli";
}

function rr_site_url(): string
{
    return rtrim((string)($_ENV["APP_BASE_URL"] ?? RR_SITE_URL), "/");
}

function rr_slash(string $v): string
{
    return str_replace("\\\\", "/", $v);
}

function rr_json(array $payload, int $status = 200): never
{
    if (!rr_is_cli()) {
        http_response_code($status);
        header("Content-Type: application/json; charset=utf-8");
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
    exit;
}

function rr_fail(string $error, array $extra = [], int $status = 400): never
{
    rr_json(array_merge([
        "ok" => false,
        "error" => $error,
        "version" => RR_VERSION,
        "ts" => time(),
    ], $extra), $status);
}

function rr_db(): PDO
{
    if (function_exists("db")) {
        $pdo = db();
        if ($pdo instanceof PDO) {
            return $pdo;
        }
    }

    $host = $_ENV["DB_HOST"] ?? "127.0.0.1";
    $name = $_ENV["DB_NAME"] ?? "wems_db";
    $user = $_ENV["DB_USER"] ?? "";
    $pass = $_ENV["DB_PASS"] ?? "";
    $charset = $_ENV["DB_CHARSET"] ?? "utf8mb4";

    $dsn = "mysql:host={$host};dbname={$name};charset={$charset}";
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function rr_request_json(): array
{
    static $json = null;
    if (is_array($json)) {
        return $json;
    }

    $raw = file_get_contents("php://input");
    $raw = is_string($raw) ? trim($raw) : "";
    if ($raw === "") {
        $json = [];
        return $json;
    }

    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $json = is_array($decoded) ? $decoded : [];
    } catch (Throwable) {
        $json = [];
    }

    return $json;
}

function rr_get_input_uid(): string
{
    if (rr_is_cli()) {
        global $argv;
        $args = is_array($argv) ? $argv : [];
        foreach ($args as $arg) {
            if (preg_match("/^--(?:uid|cert|cert_uid)=(.+)$/", (string)$arg, $m)) {
                return trim((string)$m[1]);
            }
        }
        foreach ($args as $i => $arg) {
            if (in_array($arg, ["--uid", "--cert", "--cert_uid"], true) && isset($args[$i + 1])) {
                return trim((string)$args[$i + 1]);
            }
        }
        return "";
    }

    $json = rr_request_json();

    return trim((string)(
        $_GET["cert_uid"]
        ?? $_GET["uid"]
        ?? $_GET["cert"]
        ?? $_POST["cert_uid"]
        ?? $_POST["uid"]
        ?? $_POST["cert"]
        ?? $json["cert_uid"]
        ?? $json["uid"]
        ?? $json["cert"]
        ?? ""
    ));
}

function rr_get_force(): bool
{
    if (rr_is_cli()) {
        global $argv;
        $args = is_array($argv) ? $argv : [];
        foreach ($args as $arg) {
            if ($arg === "--force" || $arg === "--force=1") {
                return true;
            }
        }
        return false;
    }

    $json = rr_request_json();
    $raw = $_GET["force"] ?? $_POST["force"] ?? $json["force"] ?? "";
    return in_array((string)$raw, ["1", "true", "yes", "on"], true);
}

function rr_load_cert(PDO $pdo, string $uid): array
{
    $sql = "
        SELECT
            id,
            cert_uid,
            rwa_type,
            family,
            rwa_code,
            owner_user_id,
            ton_wallet,
            pdf_path,
            nft_image_path,
            metadata_path,
            status,
            meta_json,
            updated_at
        FROM poado_rwa_certs
        WHERE cert_uid = :uid
        LIMIT 1
    ";
    $st = $pdo->prepare($sql);
    $st->execute([":uid" => $uid]);
    $row = $st->fetch();

    if (!is_array($row) || !$row) {
        throw new RuntimeException("CERT_NOT_FOUND");
    }

    $row["meta_json_decoded"] = rr_meta_decode((string)($row["meta_json"] ?? ""));
    return $row;
}

function rr_meta_decode(string $json): array
{
    $json = trim($json);
    if ($json === "") {
        return [];
    }
    try {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    } catch (Throwable) {
        return [];
    }
}

function rr_public_rel_to_abs(string $rel): string
{
    $rel = rr_slash(trim($rel));
    if ($rel === "") {
        return "";
    }
    if (str_starts_with($rel, RR_PUBLIC_ROOT . "/")) {
        return $rel;
    }
    if ($rel[0] === "/") {
        return RR_PUBLIC_ROOT . $rel;
    }
    return RR_PUBLIC_ROOT . "/" . ltrim($rel, "/");
}

function rr_abs_to_rel(string $abs): string
{
    $abs = rr_slash($abs);
    $root = rr_slash(RR_PUBLIC_ROOT);
    if (str_starts_with($abs, $root . "/")) {
        return substr($abs, strlen($root));
    }
    return $abs;
}

function rr_abs_to_url(string $abs): string
{
    return rr_site_url() . rr_abs_to_rel($abs);
}

function rr_fix_dir_ownership(string $dir): void
{
    $dir = trim($dir);
    if ($dir === '' || !is_dir($dir)) {
        return;
    }

    $owner = function_exists('posix_getpwuid') ? @posix_getpwuid(@fileowner($dir)) : false;
    $group = function_exists('posix_getgrgid') ? @posix_getgrgid(@filegroup($dir)) : false;

    $ownerName = is_array($owner) ? (string)($owner['name'] ?? '') : '';
    $groupName = is_array($group) ? (string)($group['name'] ?? '') : '';

    if ($ownerName === 'www-data' && $groupName === 'www-data') {
        return;
    }

    @chown($dir, 'www-data');
    @chgrp($dir, 'www-data');
}

function rr_prepare_workspace(array $paths): void
{
    $dirs = [
        dirname((string)($paths["image_path"] ?? "")),
        dirname((string)($paths["meta_path"] ?? "")),
        dirname((string)($paths["verify_path"] ?? "")),
    ];

    foreach ($dirs as $dir) {
        $dir = trim($dir);
        if ($dir === '') {
            continue;
        }
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("WORKSPACE_MKDIR_FAILED: " . $dir);
        }

        rr_fix_dir_ownership($dir);
        @chmod($dir, 0775);

        $parent = dirname($dir);
        if ($parent && $parent !== $dir && is_dir($parent)) {
            rr_fix_dir_ownership($parent);
            @chmod($parent, 0775);
        }
    }
}

function rr_detect_rwa_code(array $row): string
{
    $raw = strtoupper(trim((string)($row["rwa_code"] ?? $row["rwa_type"] ?? "")));
    if ($raw !== "" && str_contains($raw, "-EMA")) {
        return $raw;
    }

    $uid = strtoupper(trim((string)($row["cert_uid"] ?? "")));
    $prefix = explode("-", $uid)[0] ?? "";

    return match ($prefix) {
        "RCO2C" => "RCO2C-EMA",
        "RH2O" => "RH2O-EMA",
        "RBLACK" => "RBLACK-EMA",
        "RK92" => "RK92-EMA",
        "RHRD" => "RHRD-EMA",
        "RLIFE" => "RLIFE-EMA",
        "RTRIP" => "RTRIP-EMA",
        "RPROP" => "RPROP-EMA",
        default => "",
    };
}

function rr_detect_family(array $row): string
{
    $family = strtoupper(trim((string)($row["family"] ?? "")));
    if ($family !== "") {
        return $family;
    }

    $rwaCode = rr_detect_rwa_code($row);
    if (in_array($rwaCode, ["RLIFE-EMA", "RTRIP-EMA", "RPROP-EMA", "RHRD-EMA"], true)) {
        return "SECONDARY";
    }

    return "GENESIS";
}

function rr_user_bucket(array $row): string
{
    $meta = $row["meta_json_decoded"] ?? [];
    $userId = trim((string)($row["owner_user_id"] ?? ""));

    $candidates = [
        trim((string)($meta["user_bucket"] ?? "")),
        trim((string)($meta["vault"]["user_bucket"] ?? "")),
        trim((string)($meta["user"]["bucket"] ?? "")),
    ];

    foreach ($candidates as $v) {
        if ($v !== "") {
            return preg_replace("/[^A-Za-z0-9_-]/", "", $v) ?: "U13";
        }
    }

    if ($userId !== "" && ctype_digit($userId)) {
        return "U" . $userId;
    }

    return "U13";
}

function rr_cert_paths(array $row): array
{
    $uid = trim((string)$row["cert_uid"]);
    $rwaCode = rr_detect_rwa_code($row);
    $family = rr_detect_family($row);
    $bucket = rr_user_bucket($row);

    if ($uid === "" || $rwaCode === "" || $family === "") {
        throw new RuntimeException("CERT_PATH_CONTEXT_INVALID");
    }

    $uid = strtoupper(trim((string)$uid));

    if (!preg_match("/^([A-Z0-9]+-[A-Z0-9]+)-(\\d{8})-([A-Z0-9]{8})$/", $uid, $m)) {
        throw new RuntimeException("CERT_UID_FORMAT_INVALID");
    }

    $rwaCodeFromUid = $m[1];
    $yyyymmdd = $m[2];
    $year = substr($yyyymmdd, 0, 4);
    $month = substr($yyyymmdd, 4, 2);

    if ($rwaCode === "") {
        $rwaCode = $rwaCodeFromUid;
    }

    $baseRel = "/rwa/metadata/cert/RWA_CERT/"
        . $family . "/"
        . $rwaCode . "/TON/"
        . $year . "/"
        . $month . "/"
        . $bucket . "/"
        . $uid;

    $baseAbs = rr_public_rel_to_abs($baseRel);

    return [
        "base_dir" => $baseAbs,
        "image_path" => $baseAbs . "/nft/image.png",
        "meta_path" => $baseAbs . "/meta/metadata.json",
        "verify_path" => $baseAbs . "/verify/verify.json",
        "verify_page_url" => rr_site_url() . "/rwa/cert/verify.php?uid=" . rawurlencode($uid),
    ];
}

function rr_meta_merge(array $existing, array $bundle): array
{
    $paths = $bundle["paths"] ?? [];
    $verify = $bundle["verify"] ?? [];

    if (!is_array($existing)) {
        $existing = [];
    }

    $existing["artifacts"] = [
        "image_path" => rr_abs_to_rel((string)($paths["image_path"] ?? "")),
        "metadata_path" => rr_abs_to_rel((string)($paths["meta_path"] ?? "")),
        "verify_json_path" => rr_abs_to_rel((string)($paths["verify_path"] ?? "")),
        "image_url" => (string)($paths["image_url"] ?? ""),
        "metadata_url" => (string)($paths["metadata_url"] ?? ""),
        "verify_json_url" => (string)($paths["verify_json_url"] ?? ""),
        "verify_page_url" => (string)($paths["verify_page_url"] ?? ""),
        "qr_png_url" => (string)($paths["qr_png_url"] ?? ""),
        "debug_url" => (string)($paths["debug_url"] ?? ""),
    ];

    $existing["nft_health"] = [
        "ok" => (bool)($verify["ok"] ?? false),
        "healthy" => (bool)($verify["healthy"] ?? false),
        "artifact_ready" => (bool)($verify["artifact_ready"] ?? false),
        "nft_healthy" => (bool)($verify["nft_healthy"] ?? false),
        "image_authority" => (string)($verify["image_authority"] ?? ""),
        "compose_engine" => (string)($verify["compose_engine"] ?? ""),
        "used_fallback_placeholder" => (bool)($verify["used_fallback_placeholder"] ?? true),
        "template_sha1" => (string)($verify["template_sha1"] ?? ""),
        "qr_sha1" => (string)($verify["qr_sha1"] ?? ""),
        "final_sha1" => (string)($verify["final_sha1"] ?? ""),
        "verified_at" => (string)($verify["verified_at"] ?? gmdate("c")),
    ];

    return $existing;
}

function rr_update_cert(PDO $pdo, array $row, array $bundle): void
{
    $paths = $bundle["paths"] ?? [];
    $verify = $bundle["verify"] ?? [];

    if (!is_array($paths) || !is_array($verify)) {
        throw new RuntimeException("BUNDLE_RESULT_INVALID");
    }

    $meta = $row["meta_json_decoded"] ?? [];
    $meta = rr_meta_merge(is_array($meta) ? $meta : [], $bundle);

    $sql = "
        UPDATE poado_rwa_certs
        SET
            nft_image_path = :nft_image_path,
            metadata_path = :metadata_path,
            meta_json = :meta_json,
            updated_at = NOW()
        WHERE id = :id
        LIMIT 1
    ";

    $st = $pdo->prepare($sql);
    $st->execute([
        ":nft_image_path" => rr_abs_to_rel((string)($paths["image_path"] ?? "")),
        ":metadata_path" => rr_abs_to_rel((string)($paths["meta_path"] ?? "")),
        ":meta_json" => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ":id" => (int)$row["id"],
    ]);
}

function rr_run(PDO $pdo, string $uid, bool $force): array
{
    $row = rr_load_cert($pdo, $uid);
    $paths = rr_cert_paths($row);
    rr_prepare_workspace($paths);

    $bundle = cert_v3_generate_image_bundle($row, [
        "cert_uid" => $uid,
        "image_path" => $paths["image_path"],
        "meta_path" => $paths["meta_path"],
        "verify_path" => $paths["verify_path"],
        "verify_page_url" => $paths["verify_page_url"],
        "force_rebuild" => $force,
    ]);

    if (!is_array($bundle) || !($bundle["ok"] ?? false)) {
        throw new RuntimeException("IMAGE_BUNDLE_FAILED");
    }

    rr_update_cert($pdo, $row, $bundle);

    return [
        "ok" => true,
        "version" => RR_VERSION,
        "cert_uid" => $uid,
        "force_rebuild" => $force,
        "preserved" => (bool)($bundle["preserved"] ?? false),
        "paths" => $bundle["paths"] ?? [],
        "verify" => $bundle["verify"] ?? [],
        "metadata" => $bundle["metadata"] ?? [],
        "db_synced" => true,
        "executor_only" => true,
    ];
}

function rr_handle_request(): never
{
    try {
        $uid = rr_get_input_uid();
        if ($uid === "") {
            rr_fail("CERT_UID_REQUIRED", [], 422);
        }

        $force = rr_get_force();
        $pdo = rr_db();
        $result = rr_run($pdo, $uid, $force);

        rr_json(array_merge($result, [
            "ts" => time(),
        ]));
    } catch (Throwable $e) {
        rr_fail($e->getMessage(), [
            "exception" => get_class($e),
            "trace_hint" => rr_is_cli() ? $e->getFile() . ":" . $e->getLine() : null,
        ], 500);
    }
}
