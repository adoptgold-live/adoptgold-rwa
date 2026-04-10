<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

function verify_api_env(string $key, string $default = ''): string
{
    $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($v === false || $v === null) {
        return $default;
    }
    return trim((string)$v);
}

function verify_api_now(): string
{
    return date('c');
}

function verify_api_request_method(): string
{
    return strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
}

function verify_api_clean_string(mixed $value): string
{
    $value = trim((string)$value);
    $value = preg_replace('/\s+/', '', $value) ?? '';
    return $value;
}

function verify_api_payload_input(): array
{
    $method = verify_api_request_method();
    $raw = [];

    if ($method === 'POST') {
        $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
        if (str_contains($contentType, 'application/json')) {
            $body = file_get_contents('php://input');
            if (is_string($body) && $body !== '') {
                try {
                    $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($decoded)) {
                        $raw = $decoded;
                    }
                } catch (Throwable $e) {
                    verify_api_json([
                        'ok' => false,
                        'verified' => false,
                        'status' => 'invalid',
                        'message' => 'Invalid JSON body.',
                        'error' => $e->getMessage(),
                        'ts' => time(),
                        'at' => verify_api_now(),
                    ], 400);
                }
            }
        } else {
            $raw = $_POST;
        }
    } else {
        $raw = $_GET;
    }

        $raw = [];
    }

    $query = verify_api_clean_string($raw['q'] ?? $raw['query'] ?? $raw['token'] ?? $raw['address'] ?? $raw['cert_uid'] ?? '');
    $mode  = strtolower(trim((string)($raw['mode'] ?? 'auto')));

    return [
        'method' => $method,
        'mode' => $mode === '' ? 'auto' : $mode,
        'query' => $query,
        'raw' => $raw,
    ];
}

function verify_api_token_registry(): array
{
    return [
        'EMA' => [
            'symbol' => 'EMA',
            'display_symbol' => 'EMA$',
            'name' => 'eMoney RWA Adoption Token',
            'master' => 'EQDK-bRI706S1cIIoLhTrTf-e8pL2TpOD5rcP3OaxYyzs74b',
            'master_raw' => '0:caf9b448ef4e92d5c208a0b853ad37fe7bca4bd93a4e0f9adc3f739ac58cb3b3',
            'decimals' => 9,
            'fixed_supply' => '2100000000',
            'role' => 'RWA Adoption Token',
            'metadata_json' => '/var/www/html/public/rwa/metadata/ema.json',
            'metadata_image' => '/var/www/html/public/rwa/metadata/ema.png',
            'metadata_url' => 'https://adoptgold.app/rwa/metadata/ema.json',
            'image_url' => 'https://adoptgold.app/rwa/metadata/ema.png',
            'supply_units_expected' => '2100000000000000000',
        ],
        'EMX' => [
            'symbol' => 'EMX',
            'display_symbol' => 'EMX',
            'name' => 'eMoney XAU Gold RWA Stable Token',
            'master' => 'EQBj0zGcHOvN5IsBP_BAAG5NRiuAa_SLBu-xjsJn7AeM4nQy',
            'master_raw' => '0:63d3319c1cebcde48b013ff040006e4d462b806bf48b06efb18ec267ec078ce2',
            'decimals' => 9,
            'fixed_supply' => '100000000',
            'role' => 'XAU Gold RWA Stable Token',
            'metadata_json' => '/var/www/html/public/rwa/metadata/emx.json',
            'metadata_image' => '/var/www/html/public/rwa/metadata/emx.png',
            'metadata_url' => 'https://adoptgold.app/rwa/metadata/emx.json',
            'image_url' => 'https://adoptgold.app/rwa/metadata/emx.png',
            'supply_units_expected' => '100000000000000000',
        ],
        'EMS' => [
            'symbol' => 'EMS',
            'display_symbol' => 'EMS',
            'name' => 'eMoney Solvency RWA Fuel Token',
            'master' => 'EQCpJURzB4DJcL1keSRF8u5J5SmakM-_FaftTAyXRrVnnNmD',
            'master_raw' => '0:a92544730780c970bd64792445f2ee49e5299a90cfbf15a7ed4c0c9746b5679c',
            'decimals' => 9,
            'fixed_supply' => '50000000',
            'role' => 'Solvency RWA Fuel Token',
            'metadata_json' => '/var/www/html/public/rwa/metadata/ems.json',
            'metadata_image' => '/var/www/html/public/rwa/metadata/ems.png',
            'metadata_url' => 'https://adoptgold.app/rwa/metadata/ems.json',
            'image_url' => 'https://adoptgold.app/rwa/metadata/ems.png',
            'supply_units_expected' => '50000000000000000',
        ],
        'WEMS' => [
            'symbol' => 'wEMS',
            'display_symbol' => 'wEMS',
            'name' => 'WEB3 Gold Mining Reward Token',
            'master' => 'EQA8dAgNtnsfGF0M-MJfnqii5AhxcRe73M8nCkkxuq85Tr-Q',
            'master_raw' => '0:3c74080db67b1f185d0cf8c25f9ea8a2e408717117bbdccf270a4931baaf394e',
            'decimals' => 9,
            'fixed_supply' => '10000000000',
            'role' => 'Web Gold',
            'metadata_json' => '/var/www/html/public/rwa/metadata/wems.json',
            'metadata_image' => '/var/www/html/public/rwa/metadata/wems.png',
            'metadata_url' => 'https://adoptgold.app/rwa/metadata/wems.json',
            'image_url' => 'https://adoptgold.app/rwa/metadata/wems.png',
            'supply_units_expected' => '10000000000000000000',
        ],
        'USDT' => [
            'symbol' => 'USDT',
            'display_symbol' => 'USDT-TON',
            'name' => 'Tether USD (TON)',
            'master' => 'EQCxE6mUtQJKFnGfaROTKOt1lZbDiiX1kCixRv7Nw2Id_sDs',
            'master_raw' => '0:b113a994b5024a16719f69139328eb759596c38a25f59028b146fecdc3621dfe',
            'decimals' => 6,
            'fixed_supply' => '',
            'role' => 'Gold Packet Vault payment rail',
            'metadata_json' => '/var/www/html/public/rwa/metadata/usdt_ton.json',
            'metadata_image' => '/var/www/html/public/rwa/metadata/usdt_ton.png',
            'metadata_url' => 'https://adoptgold.app/rwa/metadata/usdt_ton.json',
            'image_url' => 'https://adoptgold.app/rwa/metadata/usdt_ton.png',
            'supply_units_expected' => '',
        ],
    ];
}

function verify_api_cert_registry(): array
{
    return [
        'RCO2C-EMA' => ['class' => 'Genesis',   'label' => 'Green Cert',    'mint_token' => 'wEMS', 'mint_price' => '1000',  'weight' => 1],
        'RH2O-EMA'  => ['class' => 'Genesis',   'label' => 'Blue Cert',     'mint_token' => 'wEMS', 'mint_price' => '5000',  'weight' => 2],
        'RBLACK-EMA'=> ['class' => 'Genesis',   'label' => 'Black Cert',    'mint_token' => 'wEMS', 'mint_price' => '10000', 'weight' => 3],
        'RK92-EMA'  => ['class' => 'Genesis',   'label' => 'Gold Cert',     'mint_token' => 'wEMS', 'mint_price' => '50000', 'weight' => 5],
        'RLIFE-EMA' => ['class' => 'Secondary', 'label' => 'Health Cert',   'mint_token' => 'EMA$', 'mint_price' => '100',   'weight' => 10],
        'RPROP-EMA' => ['class' => 'Secondary', 'label' => 'Property Cert', 'mint_token' => 'EMA$', 'mint_price' => '100',   'weight' => 10],
        'RTRIP-EMA' => ['class' => 'Secondary', 'label' => 'Travel Cert',   'mint_token' => 'EMA$', 'mint_price' => '100',   'weight' => 10],
    ];
}

function verify_api_guess_type(string $query, string $mode = 'auto'): string
{
    if ($mode === 'token') {
        return 'token';
    }
    if ($mode === 'cert') {
        return 'cert';
    }

    $tokens = verify_api_token_registry();
    $upper = strtoupper($query);

    if (isset($tokens[$upper])) {
        return 'token';
    }

    foreach ($tokens as $token) {
        if ($query === $token['master'] || strtolower($query) === strtolower($token['master_raw'])) {
            return 'token';
        }
    }

    if (preg_match('/^[A-Z0-9]+(?:-[A-Z0-9]+)+-\d{8}-[A-F0-9]{8}$/', strtoupper($query)) === 1) {
        return 'cert';
    }

    if (preg_match('/^(EQ|UQ|0:)[A-Za-z0-9\-_:\.]{20,}$/', $query) === 1) {
        return 'token';
    }

    return 'unknown';
}

function verify_api_find_token(string $query): ?array
{
    $registry = verify_api_token_registry();
    $upper = strtoupper($query);

    if (isset($registry[$upper])) {
        return $registry[$upper];
    }

    foreach ($registry as $token) {
        if ($query === $token['master'] || strtolower($query) === strtolower($token['master_raw'])) {
            return $token;
        }
    }

    return null;
}

function verify_api_toncenter_base(): string
{
    $base = verify_api_env('TONCENTER_BASE', 'https://toncenter.com/api/v2/jsonRPC');
    return rtrim($base, '/');
}

function verify_api_http_json_rpc(string $method, array $params): array
{
    $url = verify_api_toncenter_base();
    $headers = ['Content-Type: application/json'];

    $apiKey = verify_api_env('TONCENTER_API_KEY', '');
    if ($apiKey !== '') {
        $headers[] = 'X-API-Key: ' . $apiKey;
    }

    $payload = [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => $method,
        'params' => $params,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $http  = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($errno !== 0 || $body === false) {
        return [
            'ok' => false,
            'transport_ok' => false,
            'http' => $http,
            'error' => $error !== '' ? $error : 'CURL_ERROR',
            'raw' => null,
            'json' => null,
        ];
    }

    $decoded = null;
    $jsonError = null;

    try {
        $decoded = json_decode((string)$body, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        $jsonError = $e->getMessage();
    }

    return [
        'ok' => is_array($decoded),
        'transport_ok' => true,
        'http' => $http,
        'error' => $jsonError,
        'raw' => (string)$body,
        'json' => is_array($decoded) ? $decoded : null,
    ];
}

function verify_api_fetch_url_meta(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $http  = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($errno !== 0 || $body === false) {
        return ['ok' => false, 'http' => $http, 'body' => null, 'error' => $error !== '' ? $error : 'CURL_ERROR'];
    }

    return ['ok' => true, 'http' => $http, 'body' => (string)$body, 'error' => null];
}

function verify_api_load_metadata_file(string $path): array
{
        return ['exists' => false, 'valid' => false, 'data' => null, 'error' => 'FILE_NOT_FOUND'];
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        return ['exists' => true, 'valid' => false, 'data' => null, 'error' => 'READ_FAILED'];
    }

    try {
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        return ['exists' => true, 'valid' => is_array($data), 'data' => $data, 'error' => null];
    } catch (Throwable $e) {
        return ['exists' => true, 'valid' => false, 'data' => null, 'error' => $e->getMessage()];
    }
}

function verify_api_file_exists(string $path): bool
{
    return is_file($path);
}

function verify_api_stack_num_to_dec(?string $hexOrNum): ?string
{
    if ($hexOrNum === null || $hexOrNum === '') {
        return null;
    }

    $value = trim($hexOrNum);
    $negative = false;

    if (str_starts_with($value, '-')) {
        $negative = true;
        $value = substr($value, 1);
    }

    if (str_starts_with(strtolower($value), '0x')) {
        $hex = substr($value, 2);
        if ($hex === '') {
            return '0';
        }

        $dec = '0';
        $hexLen = strlen($hex);
        for ($i = 0; $i < $hexLen; $i++) {
            $digit = hexdec($hex[$i]);
            $dec = verify_api_dec_mul($dec, 16);
            $dec = verify_api_dec_add($dec, (string)$digit);
        }
        return $negative ? '-' . $dec : $dec;
    }

    return $negative ? '-' . $value : $value;
}

function verify_api_dec_add(string $a, string $b): string
{
    $a = ltrim($a, '0');
    $b = ltrim($b, '0');
    if ($a === '') $a = '0';
    if ($b === '') $b = '0';

    $carry = 0;
    $out = '';
    $i = strlen($a) - 1;
    $j = strlen($b) - 1;

    while ($i >= 0 || $j >= 0 || $carry > 0) {
        $da = $i >= 0 ? (int)$a[$i] : 0;
        $db = $j >= 0 ? (int)$b[$j] : 0;
        $sum = $da + $db + $carry;
        $out = (string)($sum % 10) . $out;
        $carry = intdiv($sum, 10);
        $i--;
        $j--;
    }

    return ltrim($out, '0') === '' ? '0' : ltrim($out, '0');
}

function verify_api_dec_mul(string $a, int $multiplier): string
{
    if ($multiplier === 0 || $a === '0') {
        return '0';
    }

    $carry = 0;
    $out = '';
    for ($i = strlen($a) - 1; $i >= 0; $i--) {
        $prod = ((int)$a[$i] * $multiplier) + $carry;
        $out = (string)($prod % 10) . $out;
        $carry = intdiv($prod, 10);
    }

    while ($carry > 0) {
        $out = (string)($carry % 10) . $out;
        $carry = intdiv($carry, 10);
    }

    return ltrim($out, '0') === '' ? '0' : ltrim($out, '0');
}

function verify_api_format_units(?string $units, int $decimals): ?string
{
    if ($units === null || $units === '') {
        return null;
    }

    $negative = str_starts_with($units, '-');
    $digits = $negative ? substr($units, 1) : $units;
    $digits = ltrim($digits, '0');
    if ($digits === '') {
        return '0';
    }

    if ($decimals <= 0) {
        return ($negative ? '-' : '') . $digits;
    }

    if (strlen($digits) <= $decimals) {
        $digits = str_pad($digits, $decimals + 1, '0', STR_PAD_LEFT);
    }

    $whole = substr($digits, 0, -$decimals);
    $frac  = substr($digits, -$decimals);
    $frac  = rtrim($frac, '0');

    $out = $whole === '' ? '0' : $whole;
    if ($frac !== '') {
        $out .= '.' . $frac;
    }

    return ($negative ? '-' : '') . $out;
}

function verify_api_extract_supply_units(array $json): ?string
{
    $stack = $json['result']['stack'] ?? null;
        return null;
    }
    return verify_api_stack_num_to_dec((string)$stack[0][1]);
}

function verify_api_extract_mintable(array $json): ?bool
{
    $stack = $json['result']['stack'] ?? null;
        return null;
    }
    $v = verify_api_stack_num_to_dec((string)$stack[1][1]);
    if ($v === null) {
        return null;
    }
    return $v !== '0';
}

function verify_api_build_token_result(array $token, string $query, string $queryType): array
{
    $addrInfo = verify_api_http_json_rpc('getAddressInformation', [
        'address' => $token['master'],
    ]);

    $getter = verify_api_http_json_rpc('runGetMethod', [
        'address' => $token['master'],
        'method' => 'get_jetton_data',
        'stack' => [],
    ]);

    $metaFile = verify_api_load_metadata_file($token['metadata_json']);
    $metaHttp = verify_api_fetch_url_meta($token['metadata_url']);
    $imgHttp  = verify_api_fetch_url_meta($token['image_url']);

    $state = $addrInfo['json']['result']['state'] ?? null;
    $balance = $addrInfo['json']['result']['balance'] ?? null;
    $getterExit = $getter['json']['result']['exit_code'] ?? null;
    $gasUsed = $getter['json']['result']['gas_used'] ?? null;

    $supplyUnits = is_array($getter['json']) ? verify_api_extract_supply_units($getter['json']) : null;
    $mintable = is_array($getter['json']) ? verify_api_extract_mintable($getter['json']) : null;
    $supplyDisplay = verify_api_format_units($supplyUnits, (int)$token['decimals']);

    $metaJson = ($metaHttp['ok'] && $metaHttp['http'] >= 200 && $metaHttp['http'] < 300)
        ? json_decode((string)$metaHttp['body'], true)
        : null;

    $metaChecks = [
        'metadata_file_exists' => $metaFile['exists'],
        'metadata_file_valid'  => $metaFile['valid'],
        'metadata_http_ok'     => $metaHttp['ok'] && $metaHttp['http'] >= 200 && $metaHttp['http'] < 300 && is_array($metaJson),
        'image_http_ok'        => $imgHttp['ok'] && $imgHttp['http'] >= 200 && $imgHttp['http'] < 300,
        'name_match'           => is_array($metaJson) && ((string)($metaJson['name'] ?? '') === $token['name']),
        'symbol_match'         => is_array($metaJson) && ((string)($metaJson['symbol'] ?? '') === $token['symbol']),
        'decimals_match'       => is_array($metaJson) && ((string)($metaJson['decimals'] ?? '') === (string)$token['decimals']),
    ];

    $checks = [
        'master_match'         => ($query === $token['master'] || strtolower($query) === strtolower($token['master_raw']) || strtoupper($query) === strtoupper($token['symbol'])),
        'state_active'         => $state === 'active',
        'getter_ok'            => $getter['ok'] && is_array($getter['json']) && (($getter['json']['ok'] ?? false) === true),
        'exit_code_zero'       => $getterExit === 0,
        'supply_match'         => ($token['supply_units_expected'] === '' || $supplyUnits === $token['supply_units_expected']),
    ];

    $status = $verified ? 'verified' : (($checks['state_active'] || $checks['getter_ok']) ? 'partial' : 'invalid');

    return [
        'ok' => true,
        'verified' => $verified,
        'status' => $status,
        'type' => 'token',
        'query' => $query,
        'query_type' => $queryType,
        'token' => [
            'symbol' => $token['symbol'],
            'display_symbol' => $token['display_symbol'],
            'name' => $token['name'],
            'role' => $token['role'],
            'master' => $token['master'],
            'master_raw' => $token['master_raw'],
            'decimals' => $token['decimals'],
            'fixed_supply' => $token['fixed_supply'],
            'supply_units_expected' => $token['supply_units_expected'],
        ],
        'onchain' => [
            'state' => $state,
            'balance' => $balance,
            'mintable' => $mintable,
            'exit_code' => $getterExit,
            'gas_used' => $gasUsed,
            'supply_units' => $supplyUnits,
            'supply_display' => $supplyDisplay,
        ],
        'metadata' => [
            'json_path' => $token['metadata_json'],
            'image_path' => $token['metadata_image'],
            'json_url' => $token['metadata_url'],
            'image_url' => $token['image_url'],
            'http_json' => $metaHttp['http'],
            'http_image' => $imgHttp['http'],
            'json' => is_array($metaJson) ? $metaJson : null,
            'checks' => $metaChecks,
        ],
        'checks' => $checks,
        'transport' => [
            'address_info_http' => $addrInfo['http'],
            'getter_http' => $getter['http'],
            'address_info_error' => $addrInfo['error'],
            'getter_error' => $getter['error'],
            'toncenter_base' => verify_api_toncenter_base(),
        ],
        'links' => [
            'tonviewer' => 'https://tonviewer.com/' . rawurlencode($token['master']),
            'tonsacan_note' => 'Use tonviewer for authoritative quick inspection.',
            'internal_address_page' => '/address.html?address=' . rawurlencode($token['master']),
        ],
        'ts' => time(),
        'at' => verify_api_now(),
    ];
}

function verify_api_build_cert_result(string $query): array
{
    $uid = strtoupper($query);
    $registry = verify_api_cert_registry();
    $matched = null;

    foreach ($registry as $prefix => $row) {
        if (str_starts_with($uid, $prefix . '-')) {
            $matched = ['prefix' => $prefix] + $row;
            break;
        }
    }

    $patternOk = preg_match('/^[A-Z0-9]+(?:-[A-Z0-9]+)+-\d{8}-[A-F0-9]{8}$/', $uid) === 1;
    $prefixOk = is_array($matched);

    $verified = $patternOk && $prefixOk;
    $status = $verified ? 'verified' : 'invalid';

    return [
        'ok' => true,
        'verified' => $verified,
        'status' => $status,
        'type' => 'cert',
        'query' => $uid,
        'query_type' => 'cert_uid',
        'checks' => [
            'uid_pattern_ok' => $patternOk,
            'prefix_ok' => $prefixOk,
            'db_lookup_wired' => false,
            'nft_lookup_wired' => false,
        ],
        'cert' => [
            'prefix' => $matched['prefix'] ?? null,
            'class' => $matched['class'] ?? null,
            'label' => $matched['label'] ?? null,
            'mint_token' => $matched['mint_token'] ?? null,
            'mint_price' => $matched['mint_price'] ?? null,
            'weight' => $matched['weight'] ?? null,
        ],
        'royalty_rules' => [
            'treasury_percent' => 5,
            'rewards_pool_percent' => 15,
            'gold_packet_percent' => 5,
            'seller_or_minter_percent' => 75,
            'claims_require_full_kyc' => true,
        ],
        'notes' => [
            'This endpoint validates canonical UID structure and locked mint rules only.',
            'No DB or NFT item lookup is guessed here.',
        ],
        'ts' => time(),
        'at' => verify_api_now(),
    ];
}

function verify_api_not_found(string $query, string $queryType): array
{
    return [
        'ok' => false,
        'verified' => false,
        'status' => 'not_found',
        'type' => $queryType,
        'query' => $query,
        'message' => 'Unsupported query. Use locked token symbol/master or canonical cert UID.',
        'examples' => [
            'EMA',
            'EMX',
            'EMS',
            'WEMS',
            'USDT',
            'EQDK-bRI706S1cIIoLhTrTf-e8pL2TpOD5rcP3OaxYyzs74b',
            'RK92-EMA-20260310-1A2B3C4D',
        ],
        'ts' => time(),
        'at' => verify_api_now(),
    ];
}
