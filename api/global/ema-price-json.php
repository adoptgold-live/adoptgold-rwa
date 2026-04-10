<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/api/global/ema-price-json.php
 * AdoptGold / POAdo — Global EMA Price JSON API
 * Version: v1.0.0-locked-20260318
 *
 * Global master lock:
 * - canonical EMA JSON path is /rwa/api/global/ema-price-json.php
 * - uses only /rwa/api/global/ema-price.php
 * - no DB override
 * - no fallback to any legacy path
 */

require_once __DIR__ . '/ema-price.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    $tsRaw = isset($_GET['ts']) ? trim((string) $_GET['ts']) : '';
    $queryTs = null;

    if ($tsRaw !== '') {
        if (!preg_match('/^\d{1,20}$/', $tsRaw)) {
            http_response_code(422);
            echo json_encode([
                'ok' => false,
                'error' => 'INVALID_TS',
                'ts' => time(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        $queryTs = (int) $tsRaw;
    }

    $meta = poado_ema_price_meta($queryTs);

    echo json_encode([
        'ok' => true,
        'ts' => time(),
        'source' => 'global_formula_locked',
        'path' => '/rwa/api/global/ema-price-json.php',
        'formula_path' => '/rwa/api/global/ema-price.php',
        'price' => $meta['price'],
        'currency' => 'EMX',
        'decimals' => 6,
        'meta' => $meta,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'EMA_PRICE_JSON_FAILED',
        'ts' => time(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}