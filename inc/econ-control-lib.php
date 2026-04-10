<?php
declare(strict_types=1);

/**
 * Economic Control Engine
 */

if (defined('POADO_ECON_LIB')) return;
define('POADO_ECON_LIB', true);

function poado_get_econ_state(PDO $pdo): array
{
    $t = $pdo->query("
    SELECT
        SUM(total_mined_wems) mined,
        SUM(total_binding_wems) binding,
        SUM(total_node_bonus_wems) node,
        SUM(total_claimed_wems) claimed
    FROM poado_miner_profiles
    ")->fetch(PDO::FETCH_ASSOC);

    $unclaimed = $pdo->query("
    SELECT SUM(unclaim_wems) FROM rwa_storage_balances
    ")->fetchColumn();

    $issued =
        (float)$t['mined']
      + (float)$t['binding']
      + (float)$t['node'];

    $claimed = (float)$t['claimed'];
    $unclaimed = (float)$unclaimed;

    return [
        'issued'=>$issued,
        'claimed'=>$claimed,
        'unclaimed'=>$unclaimed,
        'net'=>$issued - $claimed
    ];
}

/**
 * Determine emission multiplier
 */
function poado_get_emission_factor(array $econ): float
{
    $ratio = $econ['unclaimed'] / max(1, $econ['issued']);

    // 🔒 control logic
    if ($ratio < 0.2) return 1.2;   // growth mode
    if ($ratio < 0.5) return 1.0;   // normal
    if ($ratio < 0.8) return 0.7;   // tighten
    return 0.4;                     // defensive mode
}
