<?php
declare(strict_types=1);

/**
 * EMA Price Driven Emission Control
 */

if (defined('POADO_ECON_PRICE_LIB')) return;
define('POADO_ECON_PRICE_LIB', true);

/**
 * Read EMA price (canonical source)
 */
function poado_get_ema_price(): float
{
    $file = '/var/www/html/public/rwa/inc/ema-price.php';
    if (!file_exists($file)) return 0.1;

    $data = include $file;
    return (float)($data['price'] ?? 0.1);
}

/**
 * Determine price-based emission factor
 */
function poado_price_factor(float $price): float
{
    // baseline = 0.10
    $base = 0.10;

    $ratio = $price / $base;

    // clamp range
    if ($ratio >= 10) return 0.3;
    if ($ratio >= 5) return 0.5;
    if ($ratio >= 2) return 0.7;
    if ($ratio >= 1) return 1.0;
    if ($ratio >= 0.5) return 1.2;

    return 1.5; // stimulate if weak
}
