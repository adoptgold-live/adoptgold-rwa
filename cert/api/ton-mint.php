<?php
declare(strict_types=1);

require_once __DIR__ . '/../../inc/core/bootstrap.php';

if (!function_exists('json_ok')) {
    function json_ok(array $data = [], int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('json_fail')) {
    function json_fail(string $message, int $status = 400, array $extra = []): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $message] + $extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function ton_mint_wrapper_input(): array
{
    $raw = file_get_contents('php://input');
    $json = [];
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $json = $decoded;
        }
    }
    return array_merge($_GET ?? [], $_POST ?? [], $json);
}

function ton_mint_wrapper_require_post(): void
{
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'POST') {
        json_fail('Method not allowed', 405);
    }
}

function ton_mint_wrapper_require_auth(): array
{
    if (function_exists('session_user')) {
        $u = session_user();
        if (is_array($u) && !empty($u)) {
            return $u;
        }
    }

    if (function_exists('get_wallet_session')) {
        $u = get_wallet_session();
        if (is_array($u) && !empty($u)) {
            return $u;
        }
    }

    json_fail('Authentication required', 401);
}

function ton_mint_wrapper_require_admin(array $sessionUser): void
{
    $role = strtolower((string)($sessionUser['role'] ?? ''));
    $isAdmin = (int)($sessionUser['is_admin'] ?? 0);
    $isSenior = (int)($sessionUser['is_senior'] ?? 0);

    if ($isAdmin === 1 || $isSenior === 1 || $role === 'admin') {
        return;
    }

    json_fail('Admin or senior permission required', 403);
}

function ton_mint_wrapper_require_csrf(array $input): void
{
    $token = (string)($input['csrf_token'] ?? '');
    if ($token === '') {
        json_fail('Missing CSRF token', 419);
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        json_fail('Session unavailable for CSRF validation', 500);
    }

    $sessionToken = (string)($_SESSION['csrf_token_rwa_ton_mint'] ?? '');
    if ($sessionToken === '') {
        json_fail('CSRF session token missing', 419);
    }

    if (!hash_equals($sessionToken, $token)) {
        json_fail('Invalid CSRF token', 419);
    }
}

function ton_mint_wrapper_require_fields(array $input): array
{
    $certUid = trim((string)($input['cert_uid'] ?? $input['uid'] ?? ''));
    $itemIndex = trim((string)($input['item_index'] ?? ''));
    $ownerAddress = trim((string)($input['owner_address'] ?? ''));

    if ($certUid === '') {
        json_fail('Missing cert_uid', 422);
    }

    if ($itemIndex === '') {
        json_fail('Missing item_index', 422);
    }

    if ($ownerAddress === '') {
        json_fail('Missing owner_address', 422);
    }

    return [
        'cert_uid' => $certUid,
        'item_index' => (int)$itemIndex,
        'owner_address' => $ownerAddress,
    ];
}

function ton_mint_wrapper_call_internal(array $payload): void
{
    $_POST = $payload;
    $_GET = [];
    $_REQUEST = $payload;

    require __DIR__ . '/_ton-mint.php';
    exit;
}

ton_mint_wrapper_require_post();

$input = ton_mint_wrapper_input();
$sessionUser = ton_mint_wrapper_require_auth();
ton_mint_wrapper_require_admin($sessionUser);
ton_mint_wrapper_require_csrf($input);

$fields = ton_mint_wrapper_require_fields($input);

ton_mint_wrapper_call_internal([
    'cert_uid' => $fields['cert_uid'],
    'item_index' => (string)$fields['item_index'],
    'owner_address' => $fields['owner_address'],
]);
