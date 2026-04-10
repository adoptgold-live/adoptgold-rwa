<?php
declare(strict_types=1);

/**
 * Mining Auto Protection Engine
 */

if (defined('POADO_PROTECTION_LIB')) return;
define('POADO_PROTECTION_LIB', true);

function poado_get_anomaly_score(PDO $pdo, int $userId): int
{
    $st = $pdo->prepare("
        SELECT COUNT(*)
        FROM poado_mining_anomalies
        WHERE user_id = ?
        AND created_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)
    ");
    $st->execute([$userId]);
    return (int)$st->fetchColumn();
}

function poado_apply_protection(PDO $pdo, int $userId, string $wallet): string
{
    $score = poado_get_anomaly_score($pdo, $userId);

    // LEVEL 1 — mild (reduce multiplier)
    if ($score >= 10 && $score < 20) {
        $pdo->prepare("
            UPDATE poado_miner_profiles
            SET multiplier = multiplier * 0.5
            WHERE user_id = ?
        ")->execute([$userId]);

        return 'REDUCED_MULTIPLIER';
    }

    // LEVEL 2 — freeze mining
    if ($score >= 20 && $score < 40) {
        $pdo->prepare("
            UPDATE poado_miner_profiles
            SET multiplier = 0
            WHERE user_id = ?
        ")->execute([$userId]);

        return 'FROZEN';
    }

    // LEVEL 3 — hard lock
    if ($score >= 40) {
        $pdo->prepare("
            UPDATE poado_miner_profiles
            SET
                multiplier = 0,
                miner_tier = 'free',
                daily_cap_wems = 0
            WHERE user_id = ?
        ")->execute([$userId]);

        return 'HARD_LOCK';
    }

    return 'OK';
}
