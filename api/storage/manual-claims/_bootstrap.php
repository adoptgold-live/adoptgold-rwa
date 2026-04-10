<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/csrf.php';

if (!function_exists('manual_claims_json')) {
    function manual_claims_json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('manual_claims_ok')) {
    function manual_claims_ok(array $data = [], int $status = 200): never
    {
        manual_claims_json(['ok' => true, 'ts' => gmdate('c')] + $data, $status);
    }
}

if (!function_exists('manual_claims_fail')) {
    function manual_claims_fail(string $code, string $message, int $status = 400, array $extra = []): never
    {
        manual_claims_json(['ok' => false, 'ts' => gmdate('c'), 'code' => $code, 'message' => $message] + $extra, $status);
    }
}

if (!function_exists('manual_claims_pdo')) {
    function manual_claims_pdo(): PDO
    {
        if (!isset($GLOBALS['pdo']) || !($GLOBALS['pdo'] instanceof PDO)) {
            if (function_exists('db_connect')) {
                db_connect();
            }
        }
        if (!isset($GLOBALS['pdo']) || !($GLOBALS['pdo'] instanceof PDO)) {
            manual_claims_fail('DB_UNAVAILABLE', 'Database connection unavailable.', 500);
        }
        /** @var PDO $pdo */
        $pdo = $GLOBALS['pdo'];
        return $pdo;
    }
}

if (!function_exists('manual_claims_require_auth')) {
    function manual_claims_require_auth(): array
    {
        $candidates = [];

        if (function_exists('session_user')) {
            $u = session_user();
            if (is_array($u)) {
                $candidates[] = $u;
            }
        }

        if (isset($GLOBALS['session_user']) && is_array($GLOBALS['session_user'])) {
            $candidates[] = $GLOBALS['session_user'];
        }

        if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
            $candidates[] = $_SESSION['user'];
        }

        foreach ($candidates as $u) {
            $userId = (int)($u['id'] ?? $u['user_id'] ?? 0);
            if ($userId > 0) {
                return [
                    'id' => $userId,
                    'wallet_address' => (string)($u['wallet_address'] ?? $u['wallet'] ?? ''),
                    'ton_address' => (string)($u['ton_address'] ?? $u['wallet_address'] ?? $u['wallet'] ?? ''),
                    'nickname' => (string)($u['nickname'] ?? ''),
                    'email' => (string)($u['email'] ?? '')
                ];
            }
        }

        manual_claims_fail('AUTH_REQUIRED', 'Authentication required.', 401);
    }
}

if (!function_exists('manual_claims_is_admin')) {
    function manual_claims_is_admin(array $user): bool
    {
        $checks = [
            $_SESSION['is_admin'] ?? null,
            $_SESSION['is_super_admin'] ?? null,
            $_SESSION['admin'] ?? null,
            $_SESSION['role'] ?? null,
            $_SESSION['user_role'] ?? null,
            $_SESSION['session_user']['is_admin'] ?? null,
            $_SESSION['session_user']['role'] ?? null,
            $user['is_admin'] ?? null,
            $user['role'] ?? null,
        ];

        foreach ($checks as $v) {
            if ($v === true || $v === 1 || $v === '1' || $v === 'admin' || $v === 'super_admin') {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('manual_claims_require_admin')) {
    function manual_claims_require_admin(): array
    {
        $user = manual_claims_require_auth();
        if (!manual_claims_is_admin($user)) {
            manual_claims_fail('ADMIN_REQUIRED', 'Administrator access required.', 403);
        }
        return $user;
    }
}

if (!function_exists('manual_claims_read_json')) {
    function manual_claims_read_json(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return $_POST ?: [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return $_POST ?: [];
        }
        return $data;
    }
}

if (!function_exists('manual_claims_require_post')) {
    function manual_claims_require_post(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            manual_claims_fail('METHOD_NOT_ALLOWED', 'POST required.', 405);
        }
    }
}

if (!function_exists('manual_claims_validate_csrf')) {
    function manual_claims_validate_csrf(array $input): void
    {
        $token = (string)($input['csrf_token'] ?? $_POST['csrf_token'] ?? '');
        if ($token === '') {
            manual_claims_fail('CSRF_REQUIRED', 'Missing CSRF token.', 419);
        }

        $valid = false;

        if (function_exists('csrf_validate')) {
            try {
                $valid = (bool)csrf_validate($token);
            } catch (Throwable $e) {
                $valid = false;
            }
        } elseif (function_exists('csrf_check')) {
            try {
                $valid = (bool)csrf_check($token);
            } catch (Throwable $e) {
                $valid = false;
            }
        } else {
            $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
            $valid = ($sessionToken !== '' && hash_equals($sessionToken, $token));
        }

        if (!$valid) {
            manual_claims_fail('CSRF_INVALID', 'Invalid CSRF token.', 419);
        }
    }
}

if (!function_exists('manual_claims_flow_map')) {
    function manual_claims_flow_map(): array
    {
        return [
            'claim_ema' => [
                'source_bucket' => 'unclaimed_ema',
                'request_token' => 'OFFCHAIN_EMA',
                'settle_token' => 'EMA',
                'decimals' => 9,
                'proof_required' => 0,
            ],
            'claim_wems' => [
                'source_bucket' => 'unclaimed_wems',
                'request_token' => 'OFFCHAIN_WEMS',
                'settle_token' => 'WEMS',
                'decimals' => 9,
                'proof_required' => 0,
            ],
            'claim_usdt_ton' => [
                'source_bucket' => 'unclaimed_gold_packet_usdt_ton',
                'request_token' => 'OFFCHAIN_USDT_TON',
                'settle_token' => 'USDT_TON',
                'decimals' => 6,
                'proof_required' => 0,
            ],
            'claim_emx_tips' => [
                'source_bucket' => 'unclaimed_emx_tips',
                'request_token' => 'OFFCHAIN_EMX_TIPS',
                'settle_token' => 'EMX',
                'decimals' => 9,
                'proof_required' => 0,
            ],
            'fuel_ems' => [
                'source_bucket' => 'fuel_ems',
                'request_token' => 'OFFCHAIN_EMS',
                'settle_token' => 'EMS',
                'decimals' => 9,
                'proof_required' => 0,
            ],
        ];
    }
}

if (!function_exists('manual_claims_require_flow')) {
    function manual_claims_require_flow(string $flowType): array
    {
        $map = manual_claims_flow_map();
        if (!isset($map[$flowType])) {
            manual_claims_fail('BAD_FLOW_TYPE', 'Unsupported flow type.', 422);
        }
        return $map[$flowType];
    }
}

if (!function_exists('manual_claims_statuses')) {
    function manual_claims_statuses(): array
    {
        return ['requested', 'approved', 'proof_submitted', 'paid', 'rejected', 'failed', 'cancelled'];
    }
}

if (!function_exists('manual_claims_to_units')) {
    function manual_claims_to_units(string $amount, int $decimals): string
    {
        $amount = trim($amount);
        if ($amount === '' || !preg_match('/^\d+(\.\d+)?$/', $amount)) {
            manual_claims_fail('BAD_AMOUNT', 'Amount format invalid.', 422);
        }

        [$whole, $frac] = array_pad(explode('.', $amount, 2), 2, '');
        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;
        $frac = preg_replace('/\D+/', '', $frac);
        if (strlen($frac) > $decimals) {
            $frac = substr($frac, 0, $decimals);
        }
        $frac = str_pad($frac, $decimals, '0');

        $units = ltrim($whole . $frac, '0');
        return $units === '' ? '0' : $units;
    }
}

if (!function_exists('manual_claims_make_uid')) {
    function manual_claims_make_uid(string $prefix): string
    {
        return strtoupper($prefix) . '-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
    }
}

if (!function_exists('manual_claims_get_available_units')) {
    function manual_claims_get_available_units(PDO $pdo, int $userId, string $flowType): ?string
    {
        $source = manual_claims_require_flow($flowType)['source_bucket'];

        $candidates = [
            ['table' => 'poado_offchain_balances', 'key_col' => 'bucket_key', 'amount_col' => 'amount_units', 'user_col' => 'user_id'],
            ['table' => 'poado_token_buckets', 'key_col' => 'bucket_key', 'amount_col' => 'amount_units', 'user_col' => 'user_id'],
            ['table' => 'poado_user_buckets', 'key_col' => 'bucket_key', 'amount_col' => 'amount_units', 'user_col' => 'user_id'],
        ];

        foreach ($candidates as $cfg) {
            try {
                $sql = "SELECT {$cfg['amount_col']} FROM wems_db.{$cfg['table']} WHERE {$cfg['user_col']} = :uid AND {$cfg['key_col']} = :bucket LIMIT 1";
                $st = $pdo->prepare($sql);
                $st->execute([':uid' => $userId, ':bucket' => $source]);
                $v = $st->fetchColumn();
                if ($v !== false && $v !== null && $v !== '') {
                    return (string)$v;
                }
            } catch (Throwable $e) {
            }
        }

        return null;
    }
}

if (!function_exists('manual_claims_count_pending_same_flow')) {
    function manual_claims_count_pending_same_flow(PDO $pdo, int $userId, string $flowType): int
    {
        $sql = "SELECT COUNT(*) FROM wems_db.poado_token_manual_requests
                WHERE user_id = :uid
                  AND flow_type = :flow
                  AND status IN ('requested', 'approved', 'proof_submitted')";
        $st = $pdo->prepare($sql);
        $st->execute([':uid' => $userId, ':flow' => $flowType]);
        return (int)$st->fetchColumn();
    }
}

if (!function_exists('manual_claims_fetch_request')) {
    function manual_claims_fetch_request(PDO $pdo, string $requestUid): array
    {
        $st = $pdo->prepare("SELECT * FROM wems_db.poado_token_manual_requests WHERE request_uid = :uid LIMIT 1");
        $st->execute([':uid' => $requestUid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            manual_claims_fail('REQUEST_NOT_FOUND', 'Request not found.', 404);
        }
        return $row;
    }
}
