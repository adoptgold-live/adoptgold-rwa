<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/api/storage/_balance-resolver.php
 *
 * Storage Balance Resolver
 *
 * Canonical shared resolver for Storage manual-claims-related balances:
 *   - claim_ema
 *   - claim_wems
 *   - claim_usdt_ton
 *   - claim_emx_tips
 *   - fuel_ems
 *
 * Locked formula:
 *   available_units = raw_units - active_reserved_units
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

if (!function_exists('storage_balance_pdo')) {
    function storage_balance_pdo(): PDO
    {
        if (!isset($GLOBALS['pdo']) || !($GLOBALS['pdo'] instanceof PDO)) {
            if (function_exists('db_connect')) {
                db_connect();
            }
        }

        if (!isset($GLOBALS['pdo']) || !($GLOBALS['pdo'] instanceof PDO)) {
            throw new RuntimeException('Database connection unavailable.');
        }

        /** @var PDO $pdo */
        $pdo = $GLOBALS['pdo'];
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }
}

if (!function_exists('storage_balance_session_user')) {
    function storage_balance_session_user(): array
    {
        $candidates = [];

        if (function_exists('session_user')) {
            try {
                $u = session_user();
                if (is_array($u)) {
                    $candidates[] = $u;
                }
            } catch (Throwable $e) {
            }
        }

        if (isset($GLOBALS['session_user']) && is_array($GLOBALS['session_user'])) {
            $candidates[] = $GLOBALS['session_user'];
        }

        if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
            $candidates[] = $_SESSION['user'];
        }

        foreach ($candidates as $u) {
            $id = (int)($u['id'] ?? $u['user_id'] ?? 0);
            if ($id > 0) {
                return [
                    'id' => $id,
                    'wallet_address' => (string)($u['wallet_address'] ?? $u['wallet'] ?? ''),
                    'ton_address' => (string)($u['ton_address'] ?? $u['wallet_address'] ?? $u['wallet'] ?? ''),
                    'nickname' => (string)($u['nickname'] ?? ''),
                    'email' => (string)($u['email'] ?? ''),
                ];
            }
        }

        throw new RuntimeException('AUTH_REQUIRED');
    }
}

if (!function_exists('storage_balance_flow_map')) {
    function storage_balance_flow_map(): array
    {
        return [
            'claim_ema' => [
                'source_bucket' => 'unclaimed_ema',
                'request_token' => 'OFFCHAIN_EMA',
                'settle_token' => 'EMA',
                'decimals' => 9,
                'display_decimals' => 9,
            ],
            'claim_wems' => [
                'source_bucket' => 'unclaimed_wems',
                'request_token' => 'OFFCHAIN_WEMS',
                'settle_token' => 'WEMS',
                'decimals' => 9,
                'display_decimals' => 9,
            ],
            'claim_usdt_ton' => [
                'source_bucket' => 'unclaimed_gold_packet_usdt_ton',
                'request_token' => 'OFFCHAIN_USDT_TON',
                'settle_token' => 'USDT_TON',
                'decimals' => 6,
                'display_decimals' => 6,
            ],
            'claim_emx_tips' => [
                'source_bucket' => 'unclaimed_emx_tips',
                'request_token' => 'OFFCHAIN_EMX_TIPS',
                'settle_token' => 'EMX',
                'decimals' => 9,
                'display_decimals' => 9,
            ],
            'fuel_ems' => [
                'source_bucket' => 'fuel_ems',
                'request_token' => 'OFFCHAIN_EMS',
                'settle_token' => 'EMS',
                'decimals' => 9,
                'display_decimals' => 9,
            ],
        ];
    }
}

if (!function_exists('storage_balance_require_flow')) {
    function storage_balance_require_flow(string $flowType): array
    {
        $map = storage_balance_flow_map();
        if (!isset($map[$flowType])) {
            throw new InvalidArgumentException('Unsupported flow type: ' . $flowType);
        }
        return $map[$flowType];
    }
}

if (!function_exists('storage_balance_normalize_units')) {
    function storage_balance_normalize_units($value): string
    {
        $s = trim((string)$value);
        if ($s === '' || !preg_match('/^-?\d+$/', $s)) {
            return '0';
        }
        $neg = false;
        if ($s[0] === '-') {
            $neg = true;
            $s = substr($s, 1);
        }
        $s = ltrim($s, '0');
        $s = $s === '' ? '0' : $s;
        return $neg && $s !== '0' ? ('-' . $s) : $s;
    }
}

if (!function_exists('storage_balance_bcadd')) {
    function storage_balance_bcadd(string $a, string $b): string
    {
        if (function_exists('bcadd')) {
            return bcadd($a, $b, 0);
        }
        return (string)((int)$a + (int)$b);
    }
}

if (!function_exists('storage_balance_bcsub')) {
    function storage_balance_bcsub(string $a, string $b): string
    {
        if (function_exists('bcsub')) {
            return bcsub($a, $b, 0);
        }
        return (string)((int)$a - (int)$b);
    }
}

if (!function_exists('storage_balance_bccomp')) {
    function storage_balance_bccomp(string $a, string $b): int
    {
        if (function_exists('bccomp')) {
            return bccomp($a, $b, 0);
        }
        return ((int)$a <=> (int)$b);
    }
}

if (!function_exists('storage_balance_units_to_display')) {
    function storage_balance_units_to_display(string $units, int $decimals, ?int $displayDecimals = null): string
    {
        $units = storage_balance_normalize_units($units);
        $displayDecimals = $displayDecimals ?? $decimals;

        $negative = false;
        if ($units !== '' && $units[0] === '-') {
            $negative = true;
            $units = substr($units, 1);
        }

        $units = ltrim($units, '0');
        $units = $units === '' ? '0' : $units;

        if ($decimals <= 0) {
            return ($negative ? '-' : '') . $units;
        }

        if (strlen($units) <= $decimals) {
            $units = str_pad($units, $decimals + 1, '0', STR_PAD_LEFT);
        }

        $whole = substr($units, 0, -$decimals);
        $frac = substr($units, -$decimals);

        if ($displayDecimals < $decimals) {
            $frac = substr($frac, 0, $displayDecimals);
        } elseif ($displayDecimals > $decimals) {
            $frac = str_pad($frac, $displayDecimals, '0');
        }

        return ($negative ? '-' : '') . $whole . '.' . $frac;
    }
}

if (!function_exists('storage_balance_reserved_units')) {
    function storage_balance_reserved_units(PDO $pdo, int $userId, string $flowType): string
    {
        $sql = "SELECT COALESCE(SUM(CAST(amount_units AS DECIMAL(65,0))), 0)
                FROM wems_db.poado_token_manual_reserves
                WHERE user_id = :uid
                  AND flow_type = :flow
                  AND status = 'ACTIVE'";
        $st = $pdo->prepare($sql);
        $st->execute([
            ':uid' => $userId,
            ':flow' => $flowType,
        ]);
        return storage_balance_normalize_units((string)$st->fetchColumn());
    }
}

if (!function_exists('storage_balance_raw_units_from_known_tables')) {
    function storage_balance_raw_units_from_known_tables(PDO $pdo, int $userId, string $sourceBucket): ?string
    {
        $candidates = [
            ['table' => 'poado_offchain_balances', 'user_col' => 'user_id', 'bucket_col' => 'bucket_key', 'amount_col' => 'amount_units'],
            ['table' => 'poado_token_buckets', 'user_col' => 'user_id', 'bucket_col' => 'bucket_key', 'amount_col' => 'amount_units'],
            ['table' => 'poado_user_buckets', 'user_col' => 'user_id', 'bucket_col' => 'bucket_key', 'amount_col' => 'amount_units'],
            ['table' => 'poado_manual_claim_balances', 'user_col' => 'user_id', 'bucket_col' => 'source_bucket', 'amount_col' => 'amount_units'],
        ];

        foreach ($candidates as $cfg) {
            try {
                $sql = "SELECT {$cfg['amount_col']}
                        FROM wems_db.{$cfg['table']}
                        WHERE {$cfg['user_col']} = :uid
                          AND {$cfg['bucket_col']} = :bucket
                        LIMIT 1";
                $st = $pdo->prepare($sql);
                $st->execute([
                    ':uid' => $userId,
                    ':bucket' => $sourceBucket,
                ]);
                $value = $st->fetchColumn();
                if ($value !== false && $value !== null && $value !== '') {
                    return storage_balance_normalize_units((string)$value);
                }
            } catch (Throwable $e) {
            }
        }

        return null;
    }
}

if (!function_exists('storage_balance_raw_units')) {
    function storage_balance_raw_units(PDO $pdo, int $userId, string $flowType): string
    {
        $flow = storage_balance_require_flow($flowType);
        $bucket = (string)$flow['source_bucket'];

        $units = storage_balance_raw_units_from_known_tables($pdo, $userId, $bucket);
        if ($units !== null) {
            return $units;
        }

        return '0';
    }
}

if (!function_exists('storage_balance_available_units')) {
    function storage_balance_available_units(string $rawUnits, string $reservedUnits): string
    {
        $available = storage_balance_bcsub($rawUnits, $reservedUnits);
        if (storage_balance_bccomp($available, '0') < 0) {
            return '0';
        }
        return storage_balance_normalize_units($available);
    }
}

if (!function_exists('storage_balance_snapshot_row')) {
    function storage_balance_snapshot_row(PDO $pdo, int $userId, string $flowType): array
    {
        $flow = storage_balance_require_flow($flowType);

        $rawUnits = storage_balance_raw_units($pdo, $userId, $flowType);
        $reservedUnits = storage_balance_reserved_units($pdo, $userId, $flowType);
        $availableUnits = storage_balance_available_units($rawUnits, $reservedUnits);

        $decimals = (int)$flow['decimals'];
        $displayDecimals = (int)($flow['display_decimals'] ?? $decimals);

        return [
            'flow_type' => $flowType,
            'source_bucket' => (string)$flow['source_bucket'],
            'request_token' => (string)$flow['request_token'],
            'settle_token' => (string)$flow['settle_token'],
            'decimals' => $decimals,
            'display_decimals' => $displayDecimals,
            'raw_units' => $rawUnits,
            'reserved_units' => $reservedUnits,
            'available_units' => $availableUnits,
            'display_amount' => storage_balance_units_to_display($availableUnits, $decimals, $displayDecimals),
            'raw_display_amount' => storage_balance_units_to_display($rawUnits, $decimals, $displayDecimals),
            'reserved_display_amount' => storage_balance_units_to_display($reservedUnits, $decimals, $displayDecimals),
        ];
    }
}

if (!function_exists('storage_balance_snapshot')) {
    function storage_balance_snapshot(PDO $pdo, int $userId): array
    {
        $rows = [];
        foreach (array_keys(storage_balance_flow_map()) as $flowType) {
            $rows[] = storage_balance_snapshot_row($pdo, $userId, $flowType);
        }
        return $rows;
    }
}
