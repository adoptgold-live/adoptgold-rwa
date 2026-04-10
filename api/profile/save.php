<?php
declare(strict_types=1);

/**
 * AdoptGold RWA
 * Profile API
 * File: /var/www/html/public/rwa/api/profile/save.php
 * Version: v1.0.20260315c
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

header('Content-Type: application/json; charset=utf-8');

function json_exit(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function req_data(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return $_POST ?: [];
}

function s(array $src, string $key, int $max = 255): string
{
    $v = isset($src[$key]) ? trim((string)$src[$key]) : '';
    if ($max > 0 && mb_strlen($v) > $max) {
        $v = mb_substr($v, 0, $max);
    }
    return $v;
}

function digits_only(string $v): string
{
    return preg_replace('/\D+/', '', $v) ?? '';
}

function normalize_country_iso(string $v): string
{
    $v = strtoupper(trim($v));
    return preg_match('/^[A-Z]{2}$/', $v) ? $v : 'MY';
}

function normalize_nullable_id(string $v): int
{
    $v = trim($v);
    return ctype_digit($v) ? (int)$v : 0;
}

$userId = (int) session_user_id();
if ($userId <= 0) {
    json_exit([
        'ok' => false,
        'error' => 'AUTH_REQUIRED',
        'message' => 'Please sign in first.'
    ], 401);
}

try {
    if (!function_exists('db')) {
        throw new RuntimeException('db() not available from bootstrap.');
    }

    $db = db();
    if (!$db instanceof PDO) {
        throw new RuntimeException('Database connection unavailable.');
    }

    $in = req_data();

    $nickname = s($in, 'nickname', 80);
    $email = strtolower(s($in, 'email', 190));
    $mobile = digits_only(s($in, 'mobile', 32));
    $mobile = substr($mobile, 0, 15);

    // profile page should now send ISO2 for prefix + country, same as register.php style
    $prefixIso2 = normalize_country_iso(s($in, 'prefix_iso2', 2) ?: s($in, 'prefix_country_iso2', 2));
    $countryIso2 = normalize_country_iso(s($in, 'country_iso2', 2) ?: s($in, 'country_code', 2));

    $stateId = normalize_nullable_id(s($in, 'state_id', 20));
    $areaId = normalize_nullable_id(s($in, 'area_id', 20));

    if ($nickname === '') {
        json_exit([
            'ok' => false,
            'error' => 'NICKNAME_REQUIRED',
            'message' => 'Nickname is required.'
        ], 422);
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_exit([
            'ok' => false,
            'error' => 'EMAIL_INVALID',
            'message' => 'Email format is invalid.'
        ], 422);
    }

    if ($mobile === '' || strlen($mobile) > 15) {
        json_exit([
            'ok' => false,
            'error' => 'MOBILE_INVALID',
            'message' => 'Mobile number must be numeric and max 15 digits.'
        ], 422);
    }

    $stmt = $db->prepare("
        SELECT id, nickname, email, email_verified_at
        FROM users
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$current) {
        json_exit([
            'ok' => false,
            'error' => 'USER_NOT_FOUND',
            'message' => 'User not found.'
        ], 404);
    }

    $stmt = $db->prepare("
        SELECT id
        FROM users
        WHERE nickname = ?
          AND id <> ?
        LIMIT 1
    ");
    $stmt->execute([$nickname, $userId]);
    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
        json_exit([
            'ok' => false,
            'error' => 'NICKNAME_TAKEN',
            'message' => 'Nickname is already used.'
        ], 409);
    }

    $stmt = $db->prepare("
        SELECT id
        FROM users
        WHERE email = ?
          AND id <> ?
        LIMIT 1
    ");
    $stmt->execute([$email, $userId]);
    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
        json_exit([
            'ok' => false,
            'error' => 'EMAIL_TAKEN',
            'message' => 'Email is already used.'
        ], 409);
    }

    // Load prefix country row
    $stmt = $db->prepare("
        SELECT iso2, name_en, name_zh, calling_code, flag_png
        FROM countries
        WHERE iso2 = ?
          AND is_enabled = 1
        LIMIT 1
    ");
    $stmt->execute([$prefixIso2]);
    $prefixCountry = $stmt->fetch(PDO::FETCH_ASSOC);

    // Load selected country row
    $stmt = $db->prepare("
        SELECT iso2, name_en, name_zh
        FROM countries
        WHERE iso2 = ?
          AND is_enabled = 1
        LIMIT 1
    ");
    $stmt->execute([$countryIso2]);
    $country = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$prefixCountry || !$country) {
        json_exit([
            'ok' => false,
            'error' => 'COUNTRY_INVALID',
            'message' => 'Invalid country or prefix selection.'
        ], 422);
    }

    $callingCode = digits_only((string)($prefixCountry['calling_code'] ?? ''));
    if ($callingCode === '') {
        json_exit([
            'ok' => false,
            'error' => 'CALLING_CODE_MISSING',
            'message' => 'Calling code missing.'
        ], 422);
    }

    $mobileE164 = substr($callingCode . $mobile, 0, 32);

    $countryDisplayName = ($countryIso2 === 'CN')
        ? trim((string)(($country['name_zh'] ?? '') ?: ($country['name_en'] ?? 'China')))
        : trim((string)(($country['name_en'] ?? '') ?: ($country['name_zh'] ?? $countryIso2)));

    // Optional state
    $stateName = '';
    if ($stateId > 0) {
        $stmt = $db->prepare("
            SELECT id, country_iso2, name_en, name_local
            FROM poado_states
            WHERE id = ?
              AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$stateId]);
        $stateRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($stateRow && strtoupper((string)$stateRow['country_iso2']) === $countryIso2) {
            $stateName = ($countryIso2 === 'CN')
                ? trim((string)(($stateRow['name_local'] ?? '') ?: ($stateRow['name_en'] ?? '')))
                : trim((string)(($stateRow['name_en'] ?? '') ?: ($stateRow['name_local'] ?? '')));
        } else {
            $stateId = 0;
            $areaId = 0;
        }
    }

    // Optional area
    $areaName = '';
    if ($areaId > 0 && $stateId > 0) {
        $stmt = $db->prepare("
            SELECT id, state_id, name_en, name_local
            FROM poado_areas
            WHERE id = ?
              AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$areaId]);
        $areaRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($areaRow && (int)$areaRow['state_id'] === $stateId) {
            $areaName = ($countryIso2 === 'CN')
                ? trim((string)(($areaRow['name_local'] ?? '') ?: ($areaRow['name_en'] ?? '')))
                : trim((string)(($areaRow['name_en'] ?? '') ?: ($areaRow['name_local'] ?? '')));
        } else {
            $areaId = 0;
        }
    }

    $emailChanged = ((string)($current['email'] ?? '') !== $email);

    $sql = "
        UPDATE users
        SET
            nickname = :nickname,
            email = :email,
            mobile = :mobile,
            mobile_e164 = :mobile_e164,
            country_code = :country_code,
            country_name = :country_name,
            state = :state_name,
            country = :country_text,
            region = :region_name
    ";

    if ($emailChanged) {
        $sql .= ",
            email_verified_at = NULL,
            verify_token = NULL,
            verify_sent_at = NULL
        ";
    }

    $sql .= ",
            updated_at = NOW()
        WHERE id = :id
        LIMIT 1
    ";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':nickname', $nickname, PDO::PARAM_STR);
    $stmt->bindValue(':email', $email, PDO::PARAM_STR);
    $stmt->bindValue(':mobile', $mobile, PDO::PARAM_STR);
    $stmt->bindValue(':mobile_e164', $mobileE164, PDO::PARAM_STR);
    $stmt->bindValue(':country_code', $callingCode, PDO::PARAM_STR); // live schema uses calling code here
    $stmt->bindValue(':country_name', $countryDisplayName, PDO::PARAM_STR);
    $stmt->bindValue(':state_name', $stateName, PDO::PARAM_STR);
    $stmt->bindValue(':country_text', $countryDisplayName, PDO::PARAM_STR);
    $stmt->bindValue(':region_name', $areaName, PDO::PARAM_STR);
    $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
    $stmt->execute();

    json_exit([
        'ok' => true,
        'message' => 'Profile saved.',
        'user' => [
            'nickname' => $nickname,
            'email' => $email,
            'mobile' => $mobile,
            'mobile_e164' => $mobileE164,
            'mobile_country_code' => '+' . $callingCode,
            'prefix_iso2' => $prefixIso2,
            'country_iso2' => $countryIso2,
            'country_code' => $callingCode,
            'country_name' => $countryDisplayName,
            'state_id' => $stateId > 0 ? (string)$stateId : '',
            'area_id' => $areaId > 0 ? (string)$areaId : '',
            'state' => $stateName,
            'region' => $areaName,
            'email_verified_at' => $emailChanged ? null : ($current['email_verified_at'] ?? null)
        ]
    ]);
} catch (Throwable $e) {
    json_exit([
        'ok' => false,
        'error' => 'PROFILE_SAVE_FAILED',
        'message' => $e->getMessage()
    ], 500);
}