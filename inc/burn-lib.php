<?php
declare(strict_types=1);

/**
 * Burn / Sink Engine
 */

if (defined('POADO_BURN_LIB')) return;
define('POADO_BURN_LIB', true);

function poado_get_burn_rate(): float
{
    return 0.05; // 5% burn
}

function poado_apply_burn(float $amount): array
{
    $rate = poado_get_burn_rate();

    $burn = round($amount * $rate, 9);
    $net  = round($amount - $burn, 9);

    return [
        'gross' => $amount,
        'burn'  => $burn,
        'net'   => $net
    ];
}
