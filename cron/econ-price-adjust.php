<?php
declare(strict_types=1);

require_once __DIR__.'/../inc/core/bootstrap.php';
require_once __DIR__.'/../inc/econ-control-lib.php';
require_once __DIR__.'/../inc/econ-price-lib.php';

db_connect();
$pdo = $GLOBALS['pdo'];

/**
 * Get economy state
 */
$econ = poado_get_econ_state($pdo);
$econFactor = poado_get_emission_factor($econ);

/**
 * Get price factor
 */
$price = poado_get_ema_price();
$priceFactor = poado_price_factor($price);

/**
 * Combine both
 */
$final = $econFactor * $priceFactor;

/**
 * Clamp safety
 */
if ($final > 1.5) $final = 1.5;
if ($final < 0.2) $final = 0.2;

/**
 * Save
 */
file_put_contents(
    __DIR__.'/../runtime/emission_factor.txt',
    (string)$final
);

echo "[ECON-PRICE] factor={$final} price={$price}\n";
