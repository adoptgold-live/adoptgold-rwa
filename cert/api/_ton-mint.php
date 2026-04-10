<?php
declare(strict_types=1);

require_once __DIR__ . '/../../inc/core/bootstrap.php';
require_once __DIR__ . '/_vault-path.php';
require_once __DIR__ . '/_vault-uploader.php';

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

function ton_mint_env(string $key, ?string $default = null): string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        if ($default !== null) {
            return $default;
        }
        json_fail("Missing env: {$key}", 500);
    }
    return (string) $value;
}

function ton_mint_input(): array
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

function ton_mint_pdo(): PDO
{
    if (function_exists('rwa_db')) {
        return rwa_db();
    }
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        return $GLOBALS['pdo'];
    }
    json_fail('Database handle unavailable', 500);
}

function ton_mint_iso_now(): string
{
    return gmdate('c');
}

function ton_mint_pick_first(array $row, array $keys, $default = null)
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
            return $row[$key];
        }
    }
    return $default;
}

function ton_mint_resolve_cert_uid(array $input): string
{
    $uid = trim((string)($input['cert_uid'] ?? $input['uid'] ?? ''));
    if ($uid === '') {
        json_fail('Missing cert_uid', 422);
    }
    return $uid;
}

function ton_mint_load_cert(PDO $pdo, string $certUid): array
{
    $stmt = $pdo->prepare("SELECT * FROM poado_rwa_certs WHERE cert_uid = ? LIMIT 1");
    $stmt->execute([$certUid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        json_fail('Certificate not found', 404, ['cert_uid' => $certUid]);
    }
    return $row;
}

function ton_mint_resolve_user_id(array $cert): int
{
    $userId = ton_mint_pick_first($cert, ['owner_user_id', 'user_id'], null);
    if ($userId === null || $userId === '') {
        json_fail('Missing owner user id on cert record', 422);
    }
    return (int)$userId;
}

function ton_mint_resolve_owner_address(array $input, array $cert): string
{
    $owner = trim((string)($input['owner_address'] ?? ''));
    if ($owner !== '') {
        return $owner;
    }

    $owner = trim((string)ton_mint_pick_first($cert, [
        'ton_wallet',
        'owner_wallet',
        'owner_address',
        'wallet_address',
        'wallet',
    ], ''));

    if ($owner === '') {
        json_fail('Missing owner TON address', 422);
    }

    return $owner;
}

function ton_mint_resolve_item_index(array $input): int
{
    if (!isset($input['item_index']) || $input['item_index'] === '') {
        json_fail('Missing item_index', 422);
    }
    return (int)$input['item_index'];
}

function ton_mint_guess_family_code(array $cert): array
{
    $family = strtoupper((string)ton_mint_pick_first($cert, ['family'], ''));
    $rwaCode = (string)ton_mint_pick_first($cert, ['rwa_code'], '');
    $rwaType = strtolower((string)ton_mint_pick_first($cert, ['rwa_type'], ''));
    $uid = (string)ton_mint_pick_first($cert, ['cert_uid'], '');

    if ($family !== '' && $rwaCode !== '') {
        return [$family, $rwaCode];
    }

    $map = [
        'green' => ['GENESIS', 'RCO2C-EMA'],
        'blue' => ['GENESIS', 'RH2O-EMA'],
        'black' => ['GENESIS', 'RBLACK-EMA'],
        'gold' => ['GENESIS', 'RK92-EMA'],
        'yellow' => ['TERTIARY', 'RHRD-EMA'],
        'pink' => ['SECONDARY', 'RLIFE-EMA'],
        'royal_blue' => ['SECONDARY', 'RPROP-EMA'],
        'red' => ['SECONDARY', 'RTRIP-EMA'],
    ];

    if (isset($map[$rwaType])) {
        return $map[$rwaType];
    }

    $prefixMap = [
        'RCO2C-EMA'  => ['GENESIS', 'RCO2C-EMA'],
        'RH2O-EMA'   => ['GENESIS', 'RH2O-EMA'],
        'RBLACK-EMA' => ['GENESIS', 'RBLACK-EMA'],
        'RK92-EMA'   => ['GENESIS', 'RK92-EMA'],
        'RHRD-EMA'   => ['TERTIARY', 'RHRD-EMA'],
        'RLIFE-EMA'  => ['SECONDARY', 'RLIFE-EMA'],
        'RPROP-EMA'  => ['SECONDARY', 'RPROP-EMA'],
        'RTRIP-EMA'  => ['SECONDARY', 'RTRIP-EMA'],
    ];

    foreach ($prefixMap as $prefix => $pair) {
        if (strpos($uid, $prefix . '-') === 0) {
            return $pair;
        }
    }

    json_fail('Unable to resolve cert family / RWA code', 422, ['cert_uid' => $uid, 'rwa_type' => $rwaType]);
}

function ton_mint_build_item_suffix(int $userId, string $certUid): string
{
    return 'U' . $userId . '/' . $certUid . '/metadata.json';
}

function ton_mint_public_metadata_url_from_cert(array $cert, int $userId, string $certUid, string $commonPrefix): string
{
    $explicit = trim((string)ton_mint_pick_first($cert, [
        'metadata_url',
        'nft_metadata_url',
        'token_uri',
        'uri',
    ], ''));

    if ($explicit !== '') {
        return $explicit;
    }

    return rtrim($commonPrefix, '/') . '/U' . $userId . '/' . rawurlencode($certUid) . '/metadata.json';
}

function ton_mint_http_get_json(string $url): array
{
    $context = stream_context_create([
        'http' => [
            'timeout' => 20,
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\nUser-Agent: AdoptGold-RWA-Mint/1.0\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        json_fail('Unable to fetch metadata_url', 422, ['metadata_url' => $url]);
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        json_fail('metadata_url did not return valid JSON', 422, ['metadata_url' => $url]);
    }

    return $decoded;
}

function ton_mint_expand_command(string $template, array $vars): string
{
    $replace = [];
    foreach ($vars as $key => $value) {
        $replace['{' . $key . '}'] = str_replace('"', '\"', (string)$value);
    }
    return strtr($template, $replace);
}

function ton_mint_run_optional_command(?string $commandTemplate, array $vars): array
{
    if ($commandTemplate === null || trim($commandTemplate) === '') {
        return [
            'ok' => false,
            'mode' => 'prepared_only',
            'note' => 'RWA_CERT_MINT_CMD not configured; mint payload prepared only.',
        ];
    }

    $command = ton_mint_expand_command($commandTemplate, $vars);
    $output = [];
    $code = 0;
    exec($command . ' 2>&1', $output, $code);
    $stdout = trim(implode("\n", $output));

    if ($code !== 0) {
        return [
            'ok' => false,
            'mode' => 'command_failed',
            'command' => $command,
            'exit_code' => $code,
            'stdout' => $stdout,
        ];
    }

    $decoded = json_decode($stdout, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    return [
        'ok' => false,
        'mode' => 'command_non_json',
        'command' => $command,
        'exit_code' => 0,
        'stdout' => $stdout,
    ];
}

function ton_mint_update_cert(PDO $pdo, array $cert, array $result): void
{
    $stmt = $pdo->prepare("
        UPDATE poado_rwa_certs
        SET
            nft_item_address = ?,
            nft_minted = ?,
            status = ?,
            minted_at = ?,
            updated_at = UTC_TIMESTAMP()
        WHERE id = ?
        LIMIT 1
    ");

    $nftItemAddress = !empty($result['nft_item_address']) ? (string)$result['nft_item_address'] : null;
    $nftMinted = (!empty($result['ok']) && !empty($result['nft_item_address'])) ? 1 : 0;
    $status = $nftMinted ? 'minted' : 'issued';
    $mintedAt = $nftMinted ? gmdate('Y-m-d H:i:s') : null;

    $stmt->execute([
        $nftItemAddress,
        $nftMinted,
        $status,
        $mintedAt,
        (int)$cert['id'],
    ]);
}

function ton_mint_upload_vault_json(string $vaultPath, array $payload): array
{
    if (function_exists('poado_rwa_vault_upload_json')) {
        return (array)poado_rwa_vault_upload_json($vaultPath, $payload);
    }
    if (function_exists('poado_vault_upload_json')) {
        return (array)poado_vault_upload_json($vaultPath, $payload);
    }
    return [
        'ok' => false,
        'mode' => 'helper_missing',
        'path' => $vaultPath,
        'payload' => $payload,
    ];
}

$input = ton_mint_input();
$pdo = ton_mint_pdo();

$certUid = ton_mint_resolve_cert_uid($input);
$cert = ton_mint_load_cert($pdo, $certUid);

$userId = ton_mint_resolve_user_id($cert);
[$family, $rwaCode] = ton_mint_guess_family_code($cert);

$collectionAddress = ton_mint_env('RWA_CERT_COLLECTION_ADDRESS');
$treasuryAddress = ton_mint_env('RWA_CERT_COLLECTION_TREASURY');
$commonPrefix = ton_mint_env('RWA_CERT_COMMON_CONTENT_PREFIX');

$ownerAddress = ton_mint_resolve_owner_address($input, $cert);
$itemIndex = ton_mint_resolve_item_index($input);
$itemSuffix = ton_mint_build_item_suffix($userId, $certUid);
$metadataUrl = ton_mint_public_metadata_url_from_cert($cert, $userId, $certUid, $commonPrefix);

$metadata = ton_mint_http_get_json($metadataUrl);

$missing = [];
foreach (['name', 'description', 'image'] as $requiredKey) {
    if (!isset($metadata[$requiredKey]) || trim((string)$metadata[$requiredKey]) === '') {
        $missing[] = $requiredKey;
    }
}
if ($missing) {
    json_fail('NFT metadata missing required keys', 422, [
        'metadata_url' => $metadataUrl,
        'missing' => $missing,
    ]);
}

$year = gmdate('Y');
$month = gmdate('m');

$vaultBase = "RWA_CERT/{$family}/{$rwaCode}/TON/{$year}/{$month}/U{$userId}/{$certUid}";
$mintResultPath = $vaultBase . "/mint/mint-result.json";
$auditLifecyclePath = $vaultBase . "/audit/lifecycle.json";

$prepared = [
    'ok' => true,
    'mode' => 'prepared',
    'prepared_at' => ton_mint_iso_now(),
    'cert_uid' => $certUid,
    'collection_address' => $collectionAddress,
    'treasury_address' => $treasuryAddress,
    'item_index' => $itemIndex,
    'owner_address' => $ownerAddress,
    'metadata_url' => $metadataUrl,
    'item_content_suffix' => $itemSuffix,
    'family' => $family,
    'rwa_code' => $rwaCode,
    'vault_base' => $vaultBase,
];

$cmdTemplate = getenv('RWA_CERT_MINT_CMD');
$cmdResult = ton_mint_run_optional_command($cmdTemplate !== false ? (string)$cmdTemplate : null, [
    'CERT_UID' => $certUid,
    'ITEM_INDEX' => (string)$itemIndex,
    'OWNER_ADDRESS' => $ownerAddress,
    'ITEM_SUFFIX' => $itemSuffix,
    'COLLECTION_ADDRESS' => $collectionAddress,
    'METADATA_URL' => $metadataUrl,
]);

if (!empty($cmdResult['ok']) && !empty($cmdResult['nft_item_address'])) {
    $result = array_merge($prepared, [
        'mode' => 'minted',
        'minted_at' => ton_mint_iso_now(),
    ], $cmdResult);
} else {
    $result = array_merge($prepared, [
        'mode' => 'mint_pending',
        'note' => $cmdResult['note'] ?? 'Mint prepared but not auto-sent.',
        'command_result' => $cmdResult,
    ]);
}

ton_mint_update_cert($pdo, $cert, $result);

$mintUpload = ton_mint_upload_vault_json($mintResultPath, $result);
$auditUpload = ton_mint_upload_vault_json($auditLifecyclePath, [
    'events' => [[
        'ts' => ton_mint_iso_now(),
        'event' => !empty($result['ok']) && !empty($result['nft_item_address']) ? 'minted' : 'mint_prepared',
        'cert_uid' => $certUid,
        'collection_address' => $collectionAddress,
        'item_index' => $itemIndex,
        'owner_address' => $ownerAddress,
        'metadata_url' => $metadataUrl,
    ]]
]);

json_ok([
    'cert_uid' => $certUid,
    'collection_address' => $collectionAddress,
    'item_index' => $itemIndex,
    'owner_address' => $ownerAddress,
    'metadata_url' => $metadataUrl,
    'item_content_suffix' => $itemSuffix,
    'result' => $result,
    'vault' => [
        'mint_result' => $mintUpload,
        'audit_lifecycle' => $auditUpload,
    ],
]);
