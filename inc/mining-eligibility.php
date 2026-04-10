<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/inc/mining-eligibility.php
 *
 * Canonical shared helper for:
 * - profile completeness
 * - TON binding status
 * - mining gate
 *
 * Rules:
 * - use db_connect() + $GLOBALS['pdo']
 * - no new PDO
 * - poado_identity_links is canonical identity map
 * - poado_ton_wallets may be synced as compatibility layer if table exists
 */

if (!function_exists('poado_h')) {
    function poado_h(?string $v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('poado_short_wallet')) {
    function poado_short_wallet(string $wallet): string {
        $wallet = trim($wallet);
        if ($wallet === '') return 'SESSION: NONE';
        if (strlen($wallet) <= 14) return $wallet;
        return substr($wallet, 0, 6) . '...' . substr($wallet, -4);
    }
}

if (!function_exists('poado_has_table')) {
    function poado_has_table(PDO $pdo, string $table): bool {
        try {
            $st = $pdo->prepare("SHOW TABLES LIKE ?");
            $st->execute([$table]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('poado_get_user_id_from_session')) {
    function poado_get_user_id_from_session(): int {
        return (int)($_SESSION['user_id'] ?? $_SESSION['uid'] ?? 0);
    }
}

if (!function_exists('poado_get_wallet_from_session')) {
    function poado_get_wallet_from_session(): string {
        $wallet = trim((string)($_SESSION['wallet'] ?? $_SESSION['user_wallet'] ?? $_SESSION['poado_wallet'] ?? ''));
        if ($wallet !== '') return $wallet;

        if (function_exists('get_wallet_session')) {
            try {
                $s = get_wallet_session();
                if (is_array($s)) {
                    $wallet = trim((string)($s['wallet'] ?? $s['wallet_address'] ?? ''));
                }
            } catch (Throwable $e) {}
        }
        return $wallet;
    }
}

if (!function_exists('poado_fetch_user_profile_state')) {
    function poado_fetch_user_profile_state(PDO $pdo, int $userId, string $sessionWallet = ''): array {
        $out = [
            'user_id' => $userId,
            'wallet' => trim($sessionWallet),
            'wallet_short' => poado_short_wallet(trim($sessionWallet)),
            'nickname' => '',
            'email' => '',
            'country_code' => '',
            'country_name' => '',
            'state' => '',
            'region' => '',
            'mobile_e164' => '',
            'wallet_address' => '',
            'ton_wallet' => '',
            'is_profile_complete' => false,
            'is_ton_bound' => false,
            'is_mining_eligible' => false,
        ];

        if ($userId <= 0) return $out;

        try {
            $st = $pdo->prepare("
                SELECT id, wallet, wallet_address, nickname, email, country_code, country_name, state, region, mobile_e164
                FROM users
                WHERE id = ?
                LIMIT 1
            ");
            $st->execute([$userId]);
            $u = $st->fetch(PDO::FETCH_ASSOC) ?: [];

            $out['wallet'] = trim((string)($u['wallet'] ?? $out['wallet']));
            $out['wallet_short'] = poado_short_wallet($out['wallet']);
            $out['wallet_address'] = trim((string)($u['wallet_address'] ?? ''));
            $out['nickname'] = trim((string)($u['nickname'] ?? ''));
            $out['email'] = trim((string)($u['email'] ?? ''));
            $out['country_code'] = trim((string)($u['country_code'] ?? ''));
            $out['country_name'] = trim((string)($u['country_name'] ?? ''));
            $out['state'] = trim((string)($u['state'] ?? ''));
            $out['region'] = trim((string)($u['region'] ?? ''));
            $out['mobile_e164'] = trim((string)($u['mobile_e164'] ?? ''));
        } catch (Throwable $e) {}

        try {
            // Canonical TON identity from poado_identity_links
            $st = $pdo->prepare("
                SELECT identity_key
                FROM poado_identity_links
                WHERE user_id = ?
                  AND identity_type = 'ton'
                  AND is_active = 1
                ORDER BY id DESC
                LIMIT 1
            ");
            $st->execute([$userId]);
            $ton = trim((string)($st->fetchColumn() ?: ''));
            if ($ton !== '') {
                $out['ton_wallet'] = $ton;
            }
        } catch (Throwable $e) {}

        if ($out['ton_wallet'] === '' && poado_has_table($pdo, 'poado_ton_wallets')) {
            try {
                $st = $pdo->prepare("
                    SELECT ton_wallet
                    FROM poado_ton_wallets
                    WHERE user_id = ?
                    LIMIT 1
                ");
                $st->execute([$userId]);
                $ton = trim((string)($st->fetchColumn() ?: ''));
                if ($ton !== '') {
                    $out['ton_wallet'] = $ton;
                }
            } catch (Throwable $e) {}
        }

        $out['is_profile_complete'] =
            $out['nickname'] !== '' &&
            strtolower($out['nickname']) !== 'guest' &&
            $out['email'] !== '' &&
            $out['mobile_e164'] !== '' &&
            $out['country_code'] !== '';

        $out['is_ton_bound'] = ($out['ton_wallet'] !== '');
        $out['is_mining_eligible'] = $out['is_profile_complete'] && $out['is_ton_bound'];

        return $out;
    }
}

if (!function_exists('poado_sync_ton_identity')) {
    function poado_sync_ton_identity(PDO $pdo, int $userId, string $tonWallet): array {
        $tonWallet = trim($tonWallet);
        if ($userId <= 0) {
            return ['ok' => false, 'error' => 'NO_USER'];
        }
        if ($tonWallet === '') {
            return ['ok' => false, 'error' => 'EMPTY_TON_WALLET'];
        }

        try {
            $pdo->beginTransaction();

            // deactivate prior ton links for this user
            $st = $pdo->prepare("
                UPDATE poado_identity_links
                SET is_active = 0, last_login_at = CURRENT_TIMESTAMP()
                WHERE user_id = ?
                  AND identity_type = 'ton'
            ");
            $st->execute([$userId]);

            // insert fresh active ton identity link
            $st = $pdo->prepare("
                INSERT INTO poado_identity_links
                    (user_id, identity_type, identity_key, created_at, last_login_at, is_active)
                VALUES
                    (?, 'ton', ?, CURRENT_TIMESTAMP(), CURRENT_TIMESTAMP(), 1)
            ");
            $st->execute([$userId, $tonWallet]);

            // optional compatibility sync
            if (poado_has_table($pdo, 'poado_ton_wallets')) {
                try {
                    $st = $pdo->prepare("SHOW COLUMNS FROM poado_ton_wallets LIKE 'ton_wallet'");
                    $st->execute();
                    $hasTonWallet = (bool)$st->fetch(PDO::FETCH_ASSOC);

                    $st = $pdo->prepare("SHOW COLUMNS FROM poado_ton_wallets LIKE 'updated_at'");
                    $st->execute();
                    $hasUpdatedAt = (bool)$st->fetch(PDO::FETCH_ASSOC);

                    $exists = false;
                    $st = $pdo->prepare("SELECT id FROM poado_ton_wallets WHERE user_id = ? LIMIT 1");
                    $st->execute([$userId]);
                    $rowId = (int)($st->fetchColumn() ?: 0);
                    $exists = ($rowId > 0);

                    if ($hasTonWallet) {
                        if ($exists) {
                            $sql = "UPDATE poado_ton_wallets SET ton_wallet = ?";
                            $params = [$tonWallet];
                            if ($hasUpdatedAt) {
                                $sql .= ", updated_at = CURRENT_TIMESTAMP()";
                            }
                            $sql .= " WHERE user_id = ?";
                            $params[] = $userId;
                            $st = $pdo->prepare($sql);
                            $st->execute($params);
                        } else {
                            $cols = ['user_id', 'ton_wallet'];
                            $vals = ['?', '?'];
                            $params = [$userId, $tonWallet];
                            if ($hasUpdatedAt) {
                                $cols[] = 'updated_at';
                                $vals[] = 'CURRENT_TIMESTAMP()';
                            }
                            $sql = "INSERT INTO poado_ton_wallets (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
                            $st = $pdo->prepare($sql);
                            $st->execute($params);
                        }
                    }
                } catch (Throwable $e) {
                    // ignore compatibility sync failure; canonical write is identity_links
                }
            }

            $pdo->commit();
            return ['ok' => true, 'ton_wallet' => $tonWallet];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'error' => 'TON_BIND_SAVE_FAILED'];
        }
    }
}

if (!function_exists('poado_require_mining_eligible_json')) {
    function poado_require_mining_eligible_json(PDO $pdo, int $userId, string $wallet = ''): array {
        $state = poado_fetch_user_profile_state($pdo, $userId, $wallet);
        if (!$state['is_mining_eligible']) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'error' => 'MINING_PROFILE_GATE',
                'message' => 'Complete profile and bind TON wallet first',
                'profile_complete' => $state['is_profile_complete'],
                'ton_bound' => $state['is_ton_bound'],
                'mining_eligible' => $state['is_mining_eligible'],
            ]);
            exit;
        }
        return $state;
    }
}