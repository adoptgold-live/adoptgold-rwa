<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function bal_out(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
    exit;
}

$wallet = trim((string)($_GET['wallet'] ?? ''));
$ownerUserId = trim((string)($_GET['owner_user_id'] ?? ''));

if ($wallet === '' && $ownerUserId === '') {
    bal_out([
        'ok' => false,
        'error' => 'BALANCE_CONTEXT_REQUIRED',
        'version' => 'v1.1.0-20260405-forward-session-cookie',
        'ts' => time(),
    ], 422);
}

$base = 'https://adoptgold.app';
$url = $base . '/rwa/api/storage/overview.php?' . http_build_query([
    'wallet' => $wallet,
    'owner_user_id' => $ownerUserId,
]);

$headers = ['Accept: application/json'];

if (!empty($_COOKIE)) {
    $cookiePairs = [];
    foreach ($_COOKIE as $k => $v) {
        $cookiePairs[] = rawurlencode((string)$k) . '=' . rawurlencode((string)$v);
    }
    if ($cookiePairs) {
        $headers[] = 'Cookie: ' . implode('; ', $cookiePairs);
    }
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);

$raw = curl_exec($ch);
$err = curl_error($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!is_string($raw) || $raw === '') {
    bal_out([
        'ok' => false,
        'error' => 'OVERVIEW_EMPTY_RESPONSE',
        'detail' => $err ?: 'empty body',
        'version' => 'v1.1.0-20260405-forward-session-cookie',
        'ts' => time(),
        'source_http_code' => $code,
    ], 502);
}

$json = json_decode($raw, true);
if (!is_array($json)) {
    bal_out([
        'ok' => false,
        'error' => 'OVERVIEW_INVALID_JSON',
        'detail' => substr($raw, 0, 300),
        'version' => 'v1.1.0-20260405-forward-session-cookie',
        'ts' => time(),
        'source_http_code' => $code,
    ], 502);
}

if (($json['ok'] ?? null) === false) {
    bal_out([
        'ok' => false,
        'error' => (string)($json['error'] ?? 'OVERVIEW_FAILED'),
        'detail' => (string)($json['detail'] ?? ''),
        'version' => 'v1.1.0-20260405-forward-session-cookie',
        'ts' => time(),
        'source_http_code' => $code,
    ], $code >= 400 ? $code : 502);
}

$balances = $json['balances'] ?? $json['data']['balances'] ?? null;
if (!is_array($balances)) {
    bal_out([
        'ok' => false,
        'error' => 'OVERVIEW_INVALID_SCHEMA',
        'version' => 'v1.1.0-20260405-forward-session-cookie',
        'ts' => time(),
        'source_http_code' => $code,
        'raw_keys' => array_keys($json),
    ], 502);
}

$wems = (string)($balances['onchain_wems'] ?? $balances['wems'] ?? '0');
$ema  = (string)($balances['onchain_ema'] ?? $balances['ema'] ?? '0');
$ton  = (string)($balances['fuel_ton_gas'] ?? $balances['ton'] ?? '0');

bal_out([
    'ok' => true,
    'version' => 'v1.1.0-20260405-forward-session-cookie',
    'ts' => time(),
    'balances' => [
        'wems' => $wems,
        'ema' => $ema,
        'ton' => $ton,
    ],
    'ton_ready' => ((float)$ton >= 0.5),
    'source_http_code' => $code,
]);
