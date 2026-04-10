<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

function verify_env(string $key, string $default = ''): string
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($value === false || $value === null) {
        return $default;
    }
    return trim((string)$value);
}

function verify_host_base(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? 'adoptgold.app'));
    return $scheme . '://' . $host;
}

function verify_metadata_mode(): string
{
    $mode = strtolower(verify_env('RWA_VERIFY_METADATA_MODE', 'soft'));
    return in_array($mode, ['soft', 'strict'], true) ? $mode : 'soft';
}

function verify_token_registry(): array
{
    $base = verify_host_base() . '/rwa/metadata/';
    return [
        'EMA' => [
            'symbol' => 'EMA',
            'display_symbol' => 'EMA$',
            'name' => 'eMoney RWA Adoption Token',
            'master' => 'EQDK-bRI706S1cIIoLhTrTf-e8pL2TpOD5rcP3OaxYyzs74b',
            'master_raw' => '0:caf9b448ef4e92d5c208a0b853ad37fe7bca4bd93a4e0f9adc3f739ac58cb3b3',
            'decimals' => 9,
            'image_path' => '/var/www/html/public/rwa/metadata/ema.png',
            'image_url' => $base . 'ema.png',
            'json_path' => '/var/www/html/public/rwa/metadata/ema.json',
            'json_url' => $base . 'ema.json',
            'supply' => '2100000000',
            'role' => 'RWA Adoption Token',
        ],
        'EMX' => [
            'symbol' => 'EMX',
            'display_symbol' => 'EMX',
            'name' => 'eMoney XAU Gold RWA Stable Token',
            'master' => 'EQBj0zGcHOvN5IsBP_BAAG5NRiuAa_SLBu-xjsJn7AeM4nQy',
            'master_raw' => '0:63d3319c1cebcde48b013ff040006e4d462b806bf48b06efb18ec267ec078ce2',
            'decimals' => 9,
            'image_path' => '/var/www/html/public/rwa/metadata/emx.png',
            'image_url' => $base . 'emx.png',
            'json_path' => '/var/www/html/public/rwa/metadata/emx.json',
            'json_url' => $base . 'emx.json',
            'supply' => '100000000',
            'role' => 'XAU Gold RWA Stable Token',
        ],
        'EMS' => [
            'symbol' => 'EMS',
            'display_symbol' => 'EMS',
            'name' => 'eMoney Solvency RWA Fuel Token',
            'master' => 'EQCpJURzB4DJcL1keSRF8u5J5SmakM-_FaftTAyXRrVnnNmD',
            'master_raw' => '0:a92544730780c970bd64792445f2ee49e5299a90cfbf15a7ed4c0c9746b5679c',
            'decimals' => 9,
            'image_path' => '/var/www/html/public/rwa/metadata/ems.png',
            'image_url' => $base . 'ems.png',
            'json_path' => '/var/www/html/public/rwa/metadata/ems.json',
            'json_url' => $base . 'ems.json',
            'supply' => '50000000',
            'role' => 'Solvency RWA Fuel Token',
        ],
        'WEMS' => [
            'symbol' => 'wEMS',
            'display_symbol' => 'wEMS',
            'name' => 'Web3 Gold Mining Reward Token',
            'master' => 'EQA8dAgNtnsfGF0M-MJfnqii5AhxcRe73M8nCkkxuq85Tr-Q',
            'master_raw' => '0:3c74080db67b1f185d0cf8c25f9ea8a2e408717117bbdccf270a4931baaf394e',
            'decimals' => 9,
            'image_path' => '/var/www/html/public/rwa/metadata/wems.png',
            'image_url' => $base . 'wems.png',
            'json_path' => '/var/www/html/public/rwa/metadata/wems.json',
            'json_url' => $base . 'wems.json',
            'supply' => '10000000000',
            'role' => 'Web Gold',
        ],
        'USDT' => [
            'symbol' => 'USDT',
            'display_symbol' => 'USDT-TON',
            'name' => 'Tether USD (TON)',
            'master' => 'EQCxE6mUtQJKFnGfaROTKOt1lZbDiiX1kCixRv7Nw2Id_sDs',
            'master_raw' => '0:b113a994b5024a16719f69139328eb759596c38a25f59028b146fecdc3621dfe',
            'decimals' => 6,
            'image_path' => '/var/www/html/public/rwa/metadata/usdt_ton.png',
            'image_url' => $base . 'usdt_ton.png',
            'json_path' => '/var/www/html/public/rwa/metadata/usdt_ton.json',
            'json_url' => $base . 'usdt_ton.json',
            'supply' => '',
            'role' => 'Gold Packet Vault payment rail',
        ],
    ];
}

function verify_cert_prefix_registry(): array
{
    return [
        'RCO2C-EMA' => ['class' => 'Genesis', 'label' => 'Green Cert', 'mint_token' => 'wEMS', 'mint_price' => '1000'],
        'RH2O-EMA' => ['class' => 'Genesis', 'label' => 'Blue Cert', 'mint_token' => 'wEMS', 'mint_price' => '5000'],
        'RBLACK-EMA' => ['class' => 'Genesis', 'label' => 'Black Cert', 'mint_token' => 'wEMS', 'mint_price' => '10000'],
        'RK92-EMA' => ['class' => 'Genesis', 'label' => 'Gold Cert', 'mint_token' => 'wEMS', 'mint_price' => '50000'],
        'RLIFE-EMA' => ['class' => 'Secondary', 'label' => 'Health Cert', 'mint_token' => 'EMA$', 'mint_price' => '100'],
        'RPROP-EMA' => ['class' => 'Secondary', 'label' => 'Property Cert', 'mint_token' => 'EMA$', 'mint_price' => '100'],
        'RTRIP-EMA' => ['class' => 'Secondary', 'label' => 'Travel Cert', 'mint_token' => 'EMA$', 'mint_price' => '100'],
    ];
}

function verify_clean_query(mixed $value): string
{
    $value = trim((string)$value);
    $value = preg_replace('/\s+/', '', $value) ?? '';
    return $value;
}

function verify_guess_query_type(string $q): string
{
    $upper = strtoupper($q);
    $tokens = verify_token_registry();

    if (isset($tokens[$upper])) {
        return 'token_symbol';
    }

    foreach ($tokens as $token) {
        if ($q === $token['master'] || strtolower($q) === strtolower($token['master_raw'])) {
            return 'master_address';
        }
    }

    if (preg_match('/^(EQ|UQ|kQ|0Q)[A-Za-z0-9\-_]{40,70}$/', $q) === 1 || str_starts_with($q, '0:')) {
        return 'ton_address';
    }

    if (preg_match('/^[A-Z0-9\-]{8,80}$/', strtoupper($q)) === 1 && str_contains($q, '-')) {
        return 'cert_uid';
    }

    return 'unknown';
}

function verify_tonviewer_link(string $addressOrHash): string
{
    return 'https://tonviewer.com/' . rawurlencode($addressOrHash);
}

function verify_tonscan_link(string $addressOrHash): string
{
    return 'https://tonscan.org/address/' . rawurlencode($addressOrHash);
}

function verify_file_head(string $path): array
{
    return [
        'exists' => is_file($path),
        'size' => is_file($path) ? filesize($path) : 0,
        'mtime' => is_file($path) ? date('c', (int)filemtime($path)) : null,
    ];
}

function verify_json_file(string $path): array
{
    if (!is_file($path)) {
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

function verify_http_get_json(string $url, int $timeout = 12): array
{
    $headers = ['Accept: application/json'];
    $apiKey = verify_env('TONCENTER_API_KEY', '');
    if ($apiKey !== '') {
        $headers[] = 'X-API-Key: ' . $apiKey;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($errno !== 0 || $body === false) {
        return ['ok' => false, 'http' => $http, 'data' => null, 'error' => $error !== '' ? $error : 'CURL_ERROR'];
    }

    try {
        $data = json_decode((string)$body, true, 512, JSON_THROW_ON_ERROR);
        return ['ok' => true, 'http' => $http, 'data' => $data, 'error' => null];
    } catch (Throwable $e) {
        return ['ok' => false, 'http' => $http, 'data' => null, 'error' => 'INVALID_JSON: ' . $e->getMessage()];
    }
}

function verify_toncenter_base(): string
{
    $base = verify_env('TONCENTER_BASE', 'https://toncenter.com/api/v2');
    return rtrim($base, '/');
}

function verify_run_get_method(string $address, string $method): array
{
    $url = verify_toncenter_base() . '/runGetMethod?address=' . rawurlencode($address) . '&method=' . rawurlencode($method);
    return verify_http_get_json($url);
}

function verify_get_address_info(string $address): array
{
    $url = verify_toncenter_base() . '/getAddressInformation?address=' . rawurlencode($address);
    return verify_http_get_json($url);
}

function verify_token_by_any(string $q): ?array
{
    $registry = verify_token_registry();
    $upper = strtoupper($q);

    if (isset($registry[$upper])) {
        return $registry[$upper];
    }

    foreach ($registry as $token) {
        if ($q === $token['master'] || strtolower($q) === strtolower($token['master_raw'])) {
            return $token;
        }
    }

    return null;
}

function verify_token_payload(array $token, string $input, string $inputType): array
{
    $metaMode = verify_metadata_mode();

    $image = verify_file_head($token['image_path']);
    $json = verify_json_file($token['json_path']);

    $metadataChecks = [
        'image_exists' => $image['exists'],
        'json_exists' => $json['exists'],
        'json_valid' => $json['valid'],
        'symbol_match' => false,
        'name_match' => false,
        'decimals_match' => false,
    ];

    if ($json['valid'] && is_array($json['data'])) {
        $meta = $json['data'];
        $metadataChecks['symbol_match'] = strtoupper((string)($meta['symbol'] ?? '')) === strtoupper($token['symbol']);
        $metadataChecks['name_match'] = trim((string)($meta['name'] ?? '')) === $token['name'];
        $metadataChecks['decimals_match'] = (string)($meta['decimals'] ?? '') === (string)$token['decimals'];
    }

    $metadataOk = $metadataChecks['image_exists']
        && $metadataChecks['json_exists']
        && $metadataChecks['json_valid']
        && $metadataChecks['symbol_match']
        && $metadataChecks['name_match']
        && $metadataChecks['decimals_match'];

    $getter = verify_run_get_method($token['master'], 'get_jetton_data');
    $getterOk = $getter['ok'] && $getter['http'] >= 200 && $getter['http'] < 300;

    $addrInfo = verify_get_address_info($token['master']);
    $addressOk = $addrInfo['ok'] && $addrInfo['http'] >= 200 && $addrInfo['http'] < 300;

    $warnings = [];
    if (!$getterOk) {
        $warnings[] = 'JETTON_GETTER_UNREACHABLE';
    }
    if (!$addressOk) {
        $warnings[] = 'ADDRESS_INFO_UNREACHABLE';
    }
    if (!$metadataOk) {
        $warnings[] = 'METADATA_INCOMPLETE_OR_MISMATCH';
    }

    $verified = $getterOk && $addressOk && ($metaMode === 'soft' ? true : $metadataOk);
    $status = $verified ? 'verified' : (($getterOk || $addressOk) ? 'partial' : 'invalid');

    return [
        'ok' => true,
        'verified' => $verified,
        'status' => $status,
        'query' => $input,
        'query_type' => $inputType,
        'token' => [
            'symbol' => $token['symbol'],
            'display_symbol' => $token['display_symbol'],
            'name' => $token['name'],
            'role' => $token['role'],
            'master' => $token['master'],
            'master_raw' => $token['master_raw'],
            'decimals' => $token['decimals'],
            'fixed_supply' => $token['supply'],
        ],
        'checks' => [
            'master_match' => ($input === $token['master'] || strtolower($input) === strtolower($token['master_raw']) || strtoupper($input) === strtoupper($token['symbol'])),
            'jetton_getter_ok' => $getterOk,
            'address_info_ok' => $addressOk,
            'metadata_mode' => $metaMode,
            'metadata_ok' => $metadataOk,
            'metadata' => $metadataChecks,
        ],
        'files' => [
            'image' => [
                'path' => $token['image_path'],
                'url' => $token['image_url'],
                'exists' => $image['exists'],
                'size' => $image['size'],
                'mtime' => $image['mtime'],
            ],
            'json' => [
                'path' => $token['json_path'],
                'url' => $token['json_url'],
                'exists' => $json['exists'],
                'valid' => $json['valid'],
                'error' => $json['error'],
                'data' => $json['valid'] ? $json['data'] : null,
            ],
        ],
        'explorer' => [
            'tonviewer' => verify_tonviewer_link($token['master']),
            'tonscan' => verify_tonscan_link($token['master']),
            'internal_address_page' => '/address.html?address=' . rawurlencode($token['master']),
        ],
        'network' => [
            'toncenter_base' => verify_toncenter_base(),
            'getter_http' => $getter['http'],
            'address_http' => $addrInfo['http'],
        ],
        'warnings' => $warnings,
        'ts' => time(),
    ];
}

function verify_cert_uid_payload(string $uid): array
{
    $registry = verify_cert_prefix_registry();
    $matchedPrefix = null;
    foreach ($registry as $prefix => $info) {
        if (str_starts_with($uid, $prefix . '-')) {
            $matchedPrefix = $prefix;
            break;
        }
    }

    $patternOk = preg_match('/^[A-Z0-9]+(?:-[A-Z0-9]+)+-\d{8}-[A-F0-9]{8}$/', strtoupper($uid)) === 1;
    $prefixOk = $matchedPrefix !== null;

    $info = $matchedPrefix !== null ? $registry[$matchedPrefix] : null;

    return [
        'ok' => true,
        'verified' => $patternOk && $prefixOk,
        'status' => ($patternOk && $prefixOk) ? 'verified' : 'invalid',
        'query' => $uid,
        'query_type' => 'cert_uid',
        'checks' => [
            'uid_pattern_ok' => $patternOk,
            'prefix_ok' => $prefixOk,
            'db_lookup_wired' => false,
            'nft_lookup_wired' => false,
        ],
        'cert' => [
            'prefix' => $matchedPrefix,
            'class' => $info['class'] ?? null,
            'label' => $info['label'] ?? null,
            'mint_token' => $info['mint_token'] ?? null,
            'mint_price' => $info['mint_price'] ?? null,
        ],
        'notes' => [
            'DB lookup intentionally not guessed.',
            'NFT item verification intentionally not guessed.',
            'This verifier currently validates canonical UID structure and locked mint rules only.',
        ],
        'ts' => time(),
    ];
}

function verify_unknown_payload(string $q): array
{
    return [
        'ok' => false,
        'verified' => false,
        'status' => 'not_found',
        'query' => $q,
        'query_type' => 'unknown',
        'message' => 'Unsupported input. Use token symbol, locked jetton master address, raw master address, or canonical cert UID.',
        'examples' => [
            'WEMS',
            'EMA',
            'EMX',
            'EMS',
            'USDT',
            'EQA8dAgNtnsfGF0M-MJfnqii5AhxcRe73M8nCkkxuq85Tr-Q',
            '0:3c74080db67b1f185d0cf8c25f9ea8a2e408717117bbdccf270a4931baaf394e',
            'RK92-EMA-20260310-1A2B3C4D',
        ],
        'ts' => time(),
    ];
}
