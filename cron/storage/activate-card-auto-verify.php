<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cron/storage/activate-card-auto-verify.php
 * Storage Master v7.7 — Activate Card Auto Verify Cron
 * FINAL-LOCK-1
 *
 * Locked rule:
 * token + amount + ref match => ACCEPT
 * destination match is not required
 *
 * Uses shared verifier:
 * /rwa/inc/core/onchain-verify.php
 */

require_once __DIR__ . '/../../inc/core/bootstrap.php';
require_once __DIR__ . '/../../inc/core/onchain-verify.php';

if (!defined('ACTIVATE_CARD_AUTO_VERIFY_VERSION')) {
    define('ACTIVATE_CARD_AUTO_VERIFY_VERSION', 'FINAL-LOCK-1');
}

if (!defined('ACTIVATE_CARD_AUTO_VERIFY_FILE')) {
    define('ACTIVATE_CARD_AUTO_VERIFY_FILE', __FILE__);
}

if (!defined('ACTIVATE_CARD_TOKEN_KEY')) {
    define('ACTIVATE_CARD_TOKEN_KEY', 'EMX');
}

if (!defined('ACTIVATE_CARD_REQUIRED_AMOUNT_DISPLAY')) {
    define('ACTIVATE_CARD_REQUIRED_AMOUNT_DISPLAY', '100.000000');
}

if (!defined('ACTIVATE_CARD_REQUIRED_AMOUNT_UNITS')) {
    define('ACTIVATE_CARD_REQUIRED_AMOUNT_UNITS', '100000000000');
}

if (!function_exists('acav_log')) {
    function acav_log(string $msg): void
    {
        echo '[' . gmdate('c') . '] ' . $msg . PHP_EOL;
    }
}

if (!function_exists('acav_pdo')) {
    function acav_pdo(): PDO
    {
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            return $GLOBALS['pdo'];
        }

        if (function_exists('db_connect')) {
            db_connect();
        }

        if (!isset($GLOBALS['pdo']) || !($GLOBALS['pdo'] instanceof PDO)) {
            throw new RuntimeException('PDO_NOT_AVAILABLE');
        }

        return $GLOBALS['pdo'];
    }
}

if (!function_exists('acav_emx_master')) {
    function acav_emx_master(): string
    {
        foreach ([
            'EMX_MASTER_ADDRESS',
            'RWA_EMX_MASTER_ADDRESS',
            'POADO_EMX_MASTER_ADDRESS',
            'EMX_JETTON_MASTER_RAW',
        ] as $k) {
            $v = getenv($k);
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
            if (defined($k)) {
                $c = constant($k);
                if (is_string($c) && trim($c) !== '') {
                    return trim($c);
                }
            }
        }

        $resolved = rwa_onchain_resolve_token(['token_key' => 'EMX']);
        $master = trim((string)($resolved['jetton_master'] ?? ''));
        if ($master === '') {
            throw new RuntimeException('EMX_MASTER_NOT_CONFIGURED');
        }

        return $master;
    }
}

if (!function_exists('acav_ema_price_snapshot')) {
    function acav_ema_price_snapshot(): string
    {
        if (function_exists('storage_activation_ema_price_snapshot')) {
            try {
                $v = (string)storage_activation_ema_price_snapshot();
                if ($v !== '') {
                    return $v;
                }
            } catch (Throwable $e) {
            }
        }

        $url = 'http://127.0.0.1/rwa/api/global/ema-price.php';
        $ctx = stream_context_create([
            'http' => ['timeout' => 6, 'ignore_errors' => true],
        ]);

        $raw = @file_get_contents($url, false, $ctx);
        if (!is_string($raw) || trim($raw) === '') {
            return '';
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return '';
        }

        foreach ([
            $json['price'] ?? null,
            $json['ema_price'] ?? null,
            $json['data']['price'] ?? null,
            $json['data']['ema_price'] ?? null,
        ] as $candidate) {
            if (is_numeric($candidate) && (float)$candidate > 0) {
                return number_format((float)$candidate, 9, '.', '');
            }
        }

        return '';
    }
}

if (!function_exists('acav_ema_reward_amount')) {
    function acav_ema_reward_amount(string $emaPrice): string
    {
        if ($emaPrice === '' || !is_numeric($emaPrice) || (float)$emaPrice <= 0) {
            return '';
        }

        if (function_exists('storage_activation_reward_amount')) {
            try {
                $v = (string)storage_activation_reward_amount($emaPrice);
                if ($v !== '') {
                    return $v;
                }
            } catch (Throwable $e) {
            }
        }

        if (function_exists('bcdiv')) {
            return bcdiv('100', $emaPrice, 9);
        }

        return number_format(100 / (float)$emaPrice, 9, '.', '');
    }
}

if (!function_exists('acav_card_is_active')) {
    function acav_card_is_active(array $row): bool
    {
        foreach (['is_active', 'card_active', 'is_card_active'] as $k) {
            if (!array_key_exists($k, $row)) {
                continue;
            }
            $v = $row[$k];
            if (is_bool($v)) {
                return $v;
            }
            if (is_numeric($v)) {
                return ((int)$v) === 1;
            }
            if (in_array(strtolower(trim((string)$v)), ['1', 'true', 'yes', 'active'], true)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('acav_find_candidates')) {
    function acav_find_candidates(PDO $pdo, int $limit = 50): array
    {
        $out = [];

        // 1) Preferred helper if project already has pending activation storage
        if (function_exists('storage_activation_pending_list')) {
            try {
                $rows = storage_activation_pending_list($limit);
                if (is_array($rows)) {
                    return $rows;
                }
            } catch (Throwable $e) {
            }
        }

        // 2) Fallback: use card row + users table if activation_ref is stored there
        $sql = "
            SELECT
                u.id AS user_id,
                u.wallet_address,
                c.card_number,
                c.activation_ref,
                c.activation_tx_hash,
                c.is_active
            FROM users u
            INNER JOIN rwa_storage_cards c ON c.user_id = u.id
            WHERE COALESCE(u.wallet_address, '') <> ''
              AND COALESCE(c.activation_ref, '') <> ''
              AND COALESCE(c.is_active, 0) = 0
            ORDER BY u.id ASC
            LIMIT " . (int)$limit;

        try {
            $st = $pdo->query($sql);
            if ($st instanceof PDOStatement) {
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);
                if (is_array($rows)) {
                    return $rows;
                }
            }
        } catch (Throwable $e) {
            acav_log('candidate query fallback failed: ' . $e->getMessage());
        }

        return $out;
    }
}

if (!function_exists('acav_mark_active')) {
    function acav_mark_active(int $userId, string $activationRef, string $txHash): void
    {
        if (function_exists('storage_mark_card_active_for_user')) {
            storage_mark_card_active_for_user($userId, $activationRef, $txHash);
            return;
        }

        $pdo = acav_pdo();

        // Fallback exact-schema-light update
        $sql = "
            UPDATE rwa_storage_cards
               SET is_active = 1,
                   activation_ref = :activation_ref,
                   activation_tx_hash = :tx_hash
             WHERE user_id = :user_id
             LIMIT 1
        ";
        $st = $pdo->prepare($sql);
        $st->execute([
            ':activation_ref' => $activationRef,
            ':tx_hash' => $txHash,
            ':user_id' => $userId,
        ]);
    }
}

if (!function_exists('acav_credit_reward')) {
    function acav_credit_reward(int $userId, string $activationRef, string $txHash, string $emaPrice, string $emaReward): array
    {
        if (function_exists('storage_activation_credit_ema_reward')) {
            try {
                $reward = storage_activation_credit_ema_reward($userId, $activationRef, $txHash);
                if (is_array($reward)) {
                    return [
                        'ema_price_snapshot' => (string)($reward['ema_price_snapshot'] ?? $emaPrice),
                        'ema_reward' => (string)($reward['ema_reward'] ?? $emaReward),
                        'reward_token' => (string)($reward['reward_token'] ?? 'EMA'),
                        'reward_status' => (string)($reward['reward_status'] ?? 'credited_to_unclaim_ema'),
                        'already_rewarded' => !empty($reward['already_rewarded']),
                    ];
                }
            } catch (Throwable $e) {
                acav_log('reward helper failed for user ' . $userId . ': ' . $e->getMessage());
            }
        }

        return [
            'ema_price_snapshot' => $emaPrice,
            'ema_reward' => $emaReward,
            'reward_token' => 'EMA',
            'reward_status' => 'credited_to_unclaim_ema',
            'already_rewarded' => false,
        ];
    }
}

if (!function_exists('acav_history')) {
    function acav_history(int $userId, string $activationRef, string $txHash, array $verify, array $reward): void
    {
        if (function_exists('storage_history_record')) {
            try {
                storage_history_record($userId, 'activate_card', 'EMX', '100.000000', [
                    'activation_ref' => $activationRef,
                    'tx_hash' => $txHash,
                    'status' => 'confirmed',
                    'source' => 'activate_card_auto_verify_cron',
                    'verify_code' => (string)($verify['code'] ?? ''),
                    'verify_mode' => (string)($verify['verify_mode'] ?? ''),
                    'verify_source' => (string)($verify['verify_source'] ?? ''),
                    'ema_price_snapshot' => (string)($reward['ema_price_snapshot'] ?? ''),
                    'ema_reward' => (string)($reward['ema_reward'] ?? ''),
                    'reward_token' => (string)($reward['reward_token'] ?? 'EMA'),
                    'reward_status' => (string)($reward['reward_status'] ?? ''),
                ]);
            } catch (Throwable $e) {
                acav_log('history write failed for user ' . $userId . ': ' . $e->getMessage());
            }
        }
    }
}

if (!function_exists('acav_run')) {
    function acav_run(): int
    {
        $pdo = acav_pdo();
        $emxMaster = acav_emx_master();
        $emaPrice = acav_ema_price_snapshot();
        $emaReward = acav_ema_reward_amount($emaPrice);

        $limit = (int)(getenv('ACTIVATE_CARD_AUTO_VERIFY_LIMIT') ?: 50);
        if ($limit <= 0) {
            $limit = 50;
        }

        $candidates = acav_find_candidates($pdo, $limit);
        acav_log('version=' . ACTIVATE_CARD_AUTO_VERIFY_VERSION . ' candidates=' . count($candidates));

        $done = 0;

        foreach ($candidates as $row) {
            $userId = (int)($row['user_id'] ?? 0);
            $walletAddress = trim((string)($row['wallet_address'] ?? ''));
            $activationRef = trim((string)($row['activation_ref'] ?? ''));
            $alreadyTxHash = trim((string)($row['activation_tx_hash'] ?? ''));

            if ($userId <= 0 || $walletAddress === '' || $activationRef === '') {
                continue;
            }

            if (acav_card_is_active($row)) {
                acav_log("skip user={$userId} already active");
                continue;
            }

            try {
                $verify = rwa_onchain_verify_jetton_transfer([
                    'token_key' => ACTIVATE_CARD_TOKEN_KEY,
                    'jetton_master' => $emxMaster,
                    'owner_address' => $walletAddress,
                    'amount_units' => ACTIVATE_CARD_REQUIRED_AMOUNT_UNITS,
                    'ref' => $activationRef,
                    'tx_hash' => $alreadyTxHash,
                    'lookback_seconds' => 86400 * 14,
                    'min_confirmations' => 0,
                    'limit' => 120,
                ]);

                if (empty($verify['ok']) || empty($verify['verified'])) {
                    acav_log("pending user={$userId} ref={$activationRef} code=" . (string)($verify['code'] ?? 'NO_MATCH'));
                    continue;
                }

                $txHash = trim((string)($verify['tx_hash'] ?? ''));
                if ($txHash === '') {
                    acav_log("skip user={$userId} ref={$activationRef} no tx_hash");
                    continue;
                }

                $startedTxn = false;
                if (!$pdo->inTransaction()) {
                    $pdo->beginTransaction();
                    $startedTxn = true;
                }

                try {
                    acav_mark_active($userId, $activationRef, $txHash);
                    $reward = acav_credit_reward($userId, $activationRef, $txHash, $emaPrice, $emaReward);
                    acav_history($userId, $activationRef, $txHash, $verify, $reward);

                    if ($startedTxn && $pdo->inTransaction()) {
                        $pdo->commit();
                    }

                    $done++;
                    acav_log("confirmed user={$userId} ref={$activationRef} tx={$txHash}");
                } catch (Throwable $e) {
                    if ($startedTxn && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    acav_log("rollback user={$userId} ref={$activationRef} err=" . $e->getMessage());
                }
            } catch (Throwable $e) {
                acav_log("verify failed user={$userId} ref={$activationRef} err=" . $e->getMessage());
            }
        }

        acav_log("done={$done}");
        return 0;
    }
}

try {
    exit(acav_run());
} catch (Throwable $e) {
    acav_log('fatal=' . $e->getMessage());
    exit(1);
}