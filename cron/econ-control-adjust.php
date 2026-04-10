<?php
declare(strict_types=1);

require_once __DIR__.'/../inc/core/bootstrap.php';
require_once __DIR__.'/../inc/econ-control-lib.php';

db_connect();
$pdo = $GLOBALS['pdo'];

$econ = poado_get_econ_state($pdo);
$factor = poado_get_emission_factor($econ);

/**
 * Apply to system config
 * (simple global multiplier)
 */
file_put_contents(
    __DIR__.'/../runtime/emission_factor.txt',
    (string)$factor
);

echo "[ECON] factor={$factor} issued={$econ['issued']} unclaimed={$econ['unclaimed']}\n";
