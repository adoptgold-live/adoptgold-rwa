<?php
declare(strict_types=1);

/**
 * AdoptGold / POAdo — Global Rates API (MASTER LOCK)
 *
 * LOCKED RULES:
 * - EMX = USD anchor (1.000000)
 * - RWA€ = EUR anchor (1.000000)
 * - PRIMARY conversion: EMX -> RWA€
 *
 * FORMULA:
 *   emx_to_rwae = 1 / eur_usd
 *   rwae_to_emx = eur_usd
 *
 * SOURCE PRIORITY:
 *   1. Frankfurter (primary)
 *   2. ECB Direct (secondary)
 *   3. Open ER API (fallback)
 *   4. Hard fallback = 1.080000
 *
 * CACHE:
 *   - file cache 300 seconds
 *
 * OUTPUT:
 *   - stable JSON schema (LOCKED)
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';

/* ===============================
   JSON OUTPUT
================================ */
function rates_ok(array $data = []): never {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok'=>true], $data), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

/* ===============================
   CACHE
================================ */
function rates_cache_file(): string {
    $dir = $_SERVER['DOCUMENT_ROOT'] . '/rwa/tmp';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir . '/rates-cache.json';
}

function rates_cache_read(int $ttl = 300): ?array {
    $f = rates_cache_file();
    if (!is_file($f)) return null;

    $raw = @file_get_contents($f);
    if (!$raw) return null;

    $j = json_decode($raw, true);
    if (!$j || !isset($j['ts'])) return null;

    if (time() - (int)$j['ts'] > $ttl) return null;

    return $j;
}

function rates_cache_write(array $d): void {
    $d['ts'] = time();
    @file_put_contents(rates_cache_file(), json_encode($d));
}

/* ===============================
   CURL FETCH
================================ */
function fx_fetch(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: AdoptGold-Rates'
        ]
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$raw || $code !== 200) return null;

    $j = json_decode($raw, true);
    return is_array($j) ? $j : null;
}

/* ===============================
   SOURCE 1: FRANKFURTER
================================ */
function fx_frankfurter(): ?float {
    $j = fx_fetch("https://api.frankfurter.app/latest?from=EUR&to=USD");
    if (!$j) return null;
    $v = $j['rates']['USD'] ?? null;
    return ($v && is_numeric($v)) ? (float)$v : null;
}

/* ===============================
   SOURCE 2: ECB
================================ */
function fx_ecb(): ?float {
    $j = fx_fetch("https://data-api.ecb.europa.eu/service/data/EXR/D.USD.EUR.SP00.A?lastNObservations=1&format=jsondata");
    if (!$j) return null;

    // robust extraction
    if (!isset($j['dataSets'][0]['series'])) return null;

    $series = $j['dataSets'][0]['series'];
    foreach ($series as $s) {
        if (!isset($s['observations'])) continue;
        foreach ($s['observations'] as $obs) {
            if (isset($obs[0]) && is_numeric($obs[0])) {
                return (float)$obs[0];
            }
        }
    }
    return null;
}

/* ===============================
   SOURCE 3: OPEN ER
================================ */
function fx_open_er(): ?float {
    $j = fx_fetch("https://open.er-api.com/v6/latest/EUR");
    if (!$j) return null;
    $v = $j['rates']['USD'] ?? null;
    return ($v && is_numeric($v)) ? (float)$v : null;
}

/* ===============================
   GET RATE
================================ */
function get_eur_usd(): array {

    // cache first
    $c = rates_cache_read();
    if ($c && isset($c['eur_usd'])) {
        return [$c['eur_usd'], $c['source'], 'cache'];
    }

    // 1. Frankfurter
    $v = fx_frankfurter();
    if ($v) {
        $r = number_format($v, 6, '.', '');
        rates_cache_write(['eur_usd'=>$r,'source'=>'frankfurter']);
        return [$r,'frankfurter','live'];
    }

    // 2. ECB
    $v = fx_ecb();
    if ($v) {
        $r = number_format($v, 6, '.', '');
        rates_cache_write(['eur_usd'=>$r,'source'=>'ecb']);
        return [$r,'ecb','live'];
    }

    // 3. Open ER
    $v = fx_open_er();
    if ($v) {
        $r = number_format($v, 6, '.', '');
        rates_cache_write(['eur_usd'=>$r,'source'=>'open_er']);
        return [$r,'open_er','fallback'];
    }

    // 4. hard fallback
    return ['1.080000','hard_fallback','fallback'];
}

/* ===============================
   MAIN
================================ */
[$eur_usd, $source, $mode] = get_eur_usd();

$emx_to_rwae = number_format(1 / (float)$eur_usd, 6, '.', '');
$rwae_to_emx = number_format((float)$eur_usd, 6, '.', '');

rates_ok([
    'emx_usd' => '1.000000',
    'rwae_eur'=> '1.000000',
    'eur_usd' => $eur_usd,
    'emx_to_rwae' => $emx_to_rwae,
    'rwae_to_emx' => $rwae_to_emx,
    'display' => [
        'primary_direction' => 'EMX_TO_RWAE',
        'rate_line' => "1 EMX = {$emx_to_rwae} RWA€",
        'reverse_line' => "1 RWA€ = {$rwae_to_emx} EMX"
    ],
    'anchors' => [
        'emx' => 'USD',
        'rwae'=> 'EUR'
    ],
    'source' => $source,
    'mode' => $mode,
    'ts' => gmdate('c')
]);
