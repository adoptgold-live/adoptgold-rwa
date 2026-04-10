<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/api/profile/load.php
 *
 * Final locked version
 * - standalone RWA only
 * - canonical path /rwa/api/profile/*
 * - bootstrap-compatible PDO resolution
 * - schema-safe users SELECT
 * - correct MY / CN country -> state -> area resolution
 * - returns frontend-compatible fields for profile/index.php
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

header('Content-Type: application/json; charset=utf-8');

function out_ok(array $data = [], int $status = 200): never
{
    http_response_code($status);
    echo json_encode(
        array_merge(['ok' => true, 'ts' => time()], $data),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function out_err(string $error, int $status = 400, array $extra = []): never
{
    http_response_code($status);
    echo json_encode(
        array_merge(['ok' => false, 'error' => $error, 'ts' => time()], $extra),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function rwa_pdo(): PDO
{
    foreach (['pdo', 'db', 'conn'] as $k) {
        if (isset($GLOBALS[$k]) && $GLOBALS[$k] instanceof PDO) {
            return $GLOBALS[$k];
        }
    }

    foreach (['rwa_db', 'db_connect'] as $fn) {
        if (function_exists($fn)) {
            $pdo = $fn();
            if ($pdo instanceof PDO) {
                return $pdo;
            }
        }
    }

    throw new RuntimeException('Standalone RWA DB handle unavailable.');
}

function fetch_one(PDO $pdo, string $sql, array $params = []): ?array
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function fetch_all(PDO $pdo, string $sql, array $params = []): array
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function normalize_str($v): string
{
    $v = trim((string)$v);
    if ($v === '') {
        return '';
    }
    return preg_replace('/\s+/u', ' ', $v) ?? $v;
}

function normalize_key($v): string
{
    $v = mb_strtolower(normalize_str($v), 'UTF-8');
    $v = str_replace(['-', '_'], ' ', $v);
    return preg_replace('/\s+/u', ' ', $v) ?? $v;
}

function is_iso2($v): bool
{
    return (bool)preg_match('/^[A-Z]{2}$/', strtoupper(trim((string)$v)));
}

function get_current_user_id(): int
{
    if (function_exists('session_user_id')) {
        $id = (int)session_user_id();
        if ($id > 0) {
            return $id;
        }
    }

    if (function_exists('session_user')) {
        $u = session_user();
        if (is_array($u) && !empty($u['id'])) {
            return (int)$u['id'];
        }
    }

    $paths = [
        ['rwa_user', 'id'],
        ['user', 'id'],
        ['auth_user', 'id'],
        ['poado_user', 'id'],
    ];

    foreach ($paths as $p) {
        if (!empty($_SESSION[$p[0]][$p[1]])) {
            return (int)$_SESSION[$p[0]][$p[1]];
        }
    }

    if (!empty($_SESSION['user_id'])) {
        return (int)$_SESSION['user_id'];
    }
    if (!empty($_SESSION['uid'])) {
        return (int)$_SESSION['uid'];
    }

    return 0;
}

function table_columns(PDO $pdo, string $table): array
{
    $rows = fetch_all(
        $pdo,
        "SELECT COLUMN_NAME
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = :t",
        [':t' => $table]
    );

    $out = [];
    foreach ($rows as $r) {
        $out[] = (string)$r['COLUMN_NAME'];
    }
    return $out;
}

function has_col(array $cols, string $name): bool
{
    return in_array($name, $cols, true);
}

function user_select_sql(PDO $pdo): string
{
    $cols = table_columns($pdo, 'users');

    $wanted = [
        'id',
        'nickname',
        'email',
        'email_verified_at',
        'mobile',
        'mobile_e164',
        'country_code',
        'country',
        'country_name',
        'state',
        'region',
        'wallet_address',
    ];

    $optional = [
        'mobile_country_code',
    ];

    $select = [];

    foreach ($wanted as $col) {
        $select[] = has_col($cols, $col) ? $col : "NULL AS {$col}";
    }

    foreach ($optional as $col) {
        $select[] = has_col($cols, $col) ? $col : "NULL AS {$col}";
    }

    return implode(",\n            ", $select);
}

function countries_rows(PDO $pdo): array
{
    $cols = table_columns($pdo, 'countries');
    if (!$cols) {
        return [];
    }

    $select = [];
    $select[] = has_col($cols, 'iso2') ? 'iso2' : "'' AS iso2";
    $select[] = has_col($cols, 'name_en') ? 'name_en' : "'' AS name_en";

    if (has_col($cols, 'name_local')) {
        $select[] = 'name_local';
    } elseif (has_col($cols, 'name_zh')) {
        $select[] = 'name_zh AS name_local';
    } else {
        $select[] = "'' AS name_local";
    }

    $select[] = has_col($cols, 'calling_code') ? 'calling_code' : "'' AS calling_code";

    $where = [];
    if (has_col($cols, 'is_enabled')) {
        $where[] = 'COALESCE(is_enabled,1)=1';
    }

    $sql = 'SELECT ' . implode(', ', $select) . ' FROM countries';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY iso2 ASC';

    return fetch_all($pdo, $sql);
}

function resolve_country_iso2(PDO $pdo, array $user): string
{
    $rows = countries_rows($pdo);
    $byIso = [];
    $byName = [];
    $byCall = [];

    foreach ($rows as $r) {
        $iso2 = strtoupper(trim((string)($r['iso2'] ?? '')));
        if ($iso2 === '') {
            continue;
        }

        $byIso[$iso2] = true;

        $en = normalize_key($r['name_en'] ?? '');
        $local = normalize_key($r['name_local'] ?? '');

        if ($en !== '') {
            $byName[$en] = $iso2;
        }
        if ($local !== '') {
            $byName[$local] = $iso2;
        }

        $cc = preg_replace('/\D+/', '', (string)($r['calling_code'] ?? ''));
        if ($cc !== '') {
            $byCall[$cc] = $iso2;
        }
    }

    $countryCode = strtoupper(trim((string)($user['country_code'] ?? '')));
    if (is_iso2($countryCode) && isset($byIso[$countryCode])) {
        return $countryCode;
    }

    foreach ([
        $user['country'] ?? '',
        $user['country_name'] ?? '',
    ] as $cand) {
        $k = normalize_key($cand);
        if ($k !== '' && isset($byName[$k])) {
            return $byName[$k];
        }
    }

    $mcc = preg_replace('/\D+/', '', (string)($user['mobile_country_code'] ?? ''));
    if ($mcc !== '' && isset($byCall[$mcc])) {
        return $byCall[$mcc];
    }

    $e164 = preg_replace('/\D+/', '', (string)($user['mobile_e164'] ?? ''));
    if ($e164 !== '') {
        $tmp = $byCall;
        uksort($tmp, static fn($a, $b) => strlen($b) <=> strlen($a));
        foreach ($tmp as $cc => $iso2) {
            if ($cc !== '' && str_starts_with($e164, $cc)) {
                return $iso2;
            }
        }
    }

    return '';
}

function resolve_prefix_iso2(PDO $pdo, array $user, string $countryIso2): string
{
    $rows = countries_rows($pdo);
    $byIso = [];
    $codes = [];

    foreach ($rows as $r) {
        $iso2 = strtoupper(trim((string)($r['iso2'] ?? '')));
        if ($iso2 === '') {
            continue;
        }
        $byIso[$iso2] = true;
        $codes[$iso2] = preg_replace('/\D+/', '', (string)($r['calling_code'] ?? ''));
    }

    $countryCode = strtoupper(trim((string)($user['country_code'] ?? '')));
    if (is_iso2($countryCode) && isset($byIso[$countryCode])) {
        return $countryCode;
    }

    $mcc = preg_replace('/\D+/', '', (string)($user['mobile_country_code'] ?? ''));
    if ($mcc !== '') {
        foreach ($codes as $iso2 => $cc) {
            if ($cc !== '' && $cc === $mcc) {
                return $iso2;
            }
        }
    }

    $e164 = preg_replace('/\D+/', '', (string)($user['mobile_e164'] ?? ''));
    if ($e164 !== '') {
        $tmp = $codes;
        uasort($tmp, static fn($a, $b) => strlen($b) <=> strlen($a));
        foreach ($tmp as $iso2 => $cc) {
            if ($cc !== '' && str_starts_with($e164, $cc)) {
                return $iso2;
            }
        }
    }

    return $countryIso2;
}

function state_match_keys(array $row): array
{
    $out = [];
    foreach (['name_en', 'name_local'] as $k) {
        $v = normalize_key($row[$k] ?? '');
        if ($v !== '') {
            $out[] = $v;
        }
    }
    return array_values(array_unique($out));
}

function resolve_state_id(PDO $pdo, string $countryIso2, array $user): string
{
    if ($countryIso2 === '') {
        return '';
    }

    $saved = normalize_key($user['state'] ?? '');
    if ($saved === '') {
        return '';
    }

    $rows = fetch_all(
        $pdo,
        "SELECT id, name_en, COALESCE(name_local,'') AS name_local
           FROM poado_states
          WHERE country_iso2 = :country
            AND COALESCE(is_active,1)=1
          ORDER BY sort_order ASC, id ASC",
        [':country' => strtoupper($countryIso2)]
    );

    foreach ($rows as $r) {
        if (in_array($saved, state_match_keys($r), true)) {
            return (string)$r['id'];
        }
    }

    return '';
}

function resolve_area_id(PDO $pdo, string $stateId, array $user): string
{
    if ($stateId === '') {
        return '';
    }

    $saved = normalize_key($user['region'] ?? '');
    if ($saved === '') {
        return '';
    }

    $rows = fetch_all(
        $pdo,
        "SELECT id, name_en, COALESCE(name_local,'') AS name_local
           FROM poado_areas
          WHERE state_id = :sid
            AND COALESCE(is_active,1)=1
          ORDER BY sort_order ASC, id ASC",
        [':sid' => $stateId]
    );

    foreach ($rows as $r) {
        if (in_array($saved, state_match_keys($r), true)) {
            return (string)$r['id'];
        }
    }

    return '';
}

function resolve_mobile_country_code(PDO $pdo, string $prefixIso2, array $user): string
{
    $fromUser = preg_replace('/\D+/', '', (string)($user['mobile_country_code'] ?? ''));
    if ($fromUser !== '') {
        return $fromUser;
    }

    $rows = countries_rows($pdo);
    foreach ($rows as $r) {
        if (strtoupper((string)($r['iso2'] ?? '')) === strtoupper($prefixIso2)) {
            return preg_replace('/\D+/', '', (string)($r['calling_code'] ?? ''));
        }
    }

    $e164 = preg_replace('/\D+/', '', (string)($user['mobile_e164'] ?? ''));
    $mobile = preg_replace('/\D+/', '', (string)($user['mobile'] ?? ''));
    if ($e164 !== '' && $mobile !== '' && str_ends_with($e164, $mobile) && strlen($e164) > strlen($mobile)) {
        return substr($e164, 0, strlen($e164) - strlen($mobile));
    }

    return '';
}

try {
    $userId = get_current_user_id();
    if ($userId <= 0) {
        out_err('Unauthenticated.', 401);
    }

    $pdo = rwa_pdo();
    $userSelect = user_select_sql($pdo);

    $user = fetch_one(
        $pdo,
        "SELECT
            {$userSelect}
         FROM users
         WHERE id = :id
         LIMIT 1",
        [':id' => $userId]
    );

    if (!$user) {
        out_err('User not found.', 404);
    }

    $countryIso2 = resolve_country_iso2($pdo, $user);
    $prefixIso2  = resolve_prefix_iso2($pdo, $user, $countryIso2);
    $stateId     = resolve_state_id($pdo, $countryIso2, $user);
    $areaId      = resolve_area_id($pdo, $stateId, $user);
    $mcc         = resolve_mobile_country_code($pdo, $prefixIso2, $user);

    out_ok([
        'user' => [
            'id' => (int)$user['id'],
            'nickname' => (string)($user['nickname'] ?? ''),
            'email' => (string)($user['email'] ?? ''),
            'email_verified_at' => $user['email_verified_at'] ?? null,
            'mobile' => preg_replace('/\D+/', '', (string)($user['mobile'] ?? '')),
            'mobile_country_code' => $mcc,
            'prefix_iso2' => $prefixIso2,
            'country_iso2' => $countryIso2,
            'state_id' => $stateId,
            'area_id' => $areaId,
            'wallet_address' => (string)($user['wallet_address'] ?? ''),
        ],
    ]);
} catch (Throwable $e) {
    out_err('Failed to load profile.', 500, [
        'debug' => [
            'message' => $e->getMessage()
        ]
    ]);
}