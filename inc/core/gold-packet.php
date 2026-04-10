<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/inc/core/gold-packet.php
 *
 * Canonical Gold Packet USDT-TON credit core.
 *
 * MASTER LOCK:
 * - Any USDT-TON inflow that should be credited for later claim
 *   MUST go to "My Unclaimed Gold Packet USDT-TON"
 * - No direct wallet payout here
 * - No parallel bucket
 * - Canonical reserve ledger:
 *   wems_db.poado_token_manual_reserves
 *
 * Supported sources:
 * - royalty
 * - claim
 * - manual
 * - system
 */

if (!function_exists('gp_rwa_db')) {
    function gp_rwa_db(): PDO
    {
        if (function_exists('rwa_db')) {
            $pdo = rwa_db();
            if ($pdo instanceof PDO) {
                return $pdo;
            }
        }

        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            return $GLOBALS['pdo'];
        }

        throw new RuntimeException('Database connection unavailable for gold-packet core');
    }
}

if (!function_exists('gp_now_utc')) {
    function gp_now_utc(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}

if (!function_exists('gp_uuidish')) {
    function gp_uuidish(string $prefix = 'GPU'): string
    {
        return $prefix . '-' . gmdate('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(4)));
    }
}

if (!function_exists('gp_normalize_decimal')) {
    function gp_normalize_decimal($value, int $scale = 6): string
    {
        $raw = trim((string)$value);
        if ($raw === '') {
            throw new InvalidArgumentException('Amount is required');
        }

        if (!preg_match('/^-?\d+(?:\.\d+)?$/', $raw)) {
            throw new InvalidArgumentException('Invalid decimal amount');
        }

        if (bccomp($raw, '0', $scale) <= 0) {
            throw new InvalidArgumentException('Amount must be greater than zero');
        }

        return bcadd($raw, '0', $scale);
    }
}

if (!function_exists('gp_validate_source_bucket')) {
    function gp_validate_source_bucket(string $sourceBucket): string
    {
        $sourceBucket = strtolower(trim($sourceBucket));
        $allowed = ['royalty', 'claim', 'manual', 'system'];

        if (!in_array($sourceBucket, $allowed, true)) {
            throw new InvalidArgumentException('Invalid source_bucket');
        }

        return $sourceBucket;
    }
}

if (!function_exists('gp_json')) {
    function gp_json(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('Failed to encode metadata JSON');
        }
        return $json;
    }
}

if (!function_exists('gp_log')) {
    /**
     * Safe logger wrapper.
     * Tries project logger first, then falls back to error_log.
     */
    function gp_log(string $code, array $ctx = []): void
    {
        $parts = [];
        foreach ($ctx as $k => $v) {
            if (is_array($v) || is_object($v)) {
                $v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $parts[] = $k . '=' . (string)$v;
        }
        $msg = $code . (empty($parts) ? '' : ' | ' . implode(' | ', $parts));

        try {
            if (function_exists('poado_error')) {
                poado_error($msg, $code);
                return;
            }
        } catch (Throwable $ignore) {
        }

        error_log($msg);
    }
}

if (!function_exists('gp_ensure_table_shape')) {
    /**
     * Uses the locked canonical ledger shape.
     * We do not CREATE TABLE here because the schema is already locked live.
     * This only validates that the minimum required columns exist.
     */
    function gp_ensure_table_shape(PDO $pdo): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }

        $required = [
            'request_uid',
            'user_id',
            'flow_type',
            'source_bucket',
            'amount_units',
            'status',
        ];

        $cols = [];
        $st = $pdo->query("SHOW COLUMNS FROM poado_token_manual_reserves");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cols[] = strtolower((string)$row['Field']);
        }

        foreach ($required as $col) {
            if (!in_array(strtolower($col), $cols, true)) {
                throw new RuntimeException('Missing required column in poado_token_manual_reserves: ' . $col);
            }
        }

        $checked = true;
    }
}

if (!function_exists('gp_build_metadata')) {
    function gp_build_metadata(array $meta = []): array
    {
        return [
            'bucket_name' => 'My Unclaimed Gold Packet USDT-TON',
            'token' => 'USDT-TON',
            'credited_at_utc' => gp_now_utc(),
            'meta' => $meta,
        ];
    }
}

if (!function_exists('gp_credit_unclaimed_gold_packet_usdt_ton')) {
    /**
     * Canonical credit function.
     *
     * Returns:
     * [
     *   'ok' => true,
     *   'request_uid' => '...',
     *   'user_id' => 123,
     *   'amount_units' => '12.345000',
     *   'flow_type' => 'gold_packet_usdt',
     *   'source_bucket' => 'royalty',
     *   'status' => 'ACTIVE',
     * ]
     */
    function gp_credit_unclaimed_gold_packet_usdt_ton(
        int $userId,
        $amountUnits,
        string $sourceBucket,
        array $meta = [],
        ?string $requestUid = null,
        ?PDO $pdo = null
    ): array {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Invalid user_id');
        }

        $pdo = $pdo instanceof PDO ? $pdo : gp_rwa_db();
        gp_ensure_table_shape($pdo);

        $amount = gp_normalize_decimal($amountUnits, 6);
        $sourceBucket = gp_validate_source_bucket($sourceBucket);
        $requestUid = trim((string)$requestUid);
        if ($requestUid === '') {
            $requestUid = gp_uuidish('GPU');
        }

        $flowType = 'gold_packet_usdt';
        $status = 'ACTIVE';
        $metaPayload = gp_build_metadata($meta);

        $pdo->beginTransaction();
        try {
            $dup = $pdo->prepare("
                SELECT id, request_uid, user_id, flow_type, source_bucket, amount_units, status
                FROM poado_token_manual_reserves
                WHERE request_uid = :request_uid
                LIMIT 1
            ");
            $dup->execute([':request_uid' => $requestUid]);
            $existing = $dup->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $pdo->commit();

                gp_log('gold_packet_usdt_duplicate_request_uid', [
                    'request_uid' => $requestUid,
                    'user_id' => $userId,
                    'amount_units' => $amount,
                    'source_bucket' => $sourceBucket,
                ]);

                return [
                    'ok' => true,
                    'request_uid' => (string)$existing['request_uid'],
                    'user_id' => (int)$existing['user_id'],
                    'amount_units' => (string)$existing['amount_units'],
                    'flow_type' => (string)$existing['flow_type'],
                    'source_bucket' => (string)$existing['source_bucket'],
                    'status' => (string)$existing['status'],
                    'duplicate' => true,
                ];
            }

            $sql = "
                INSERT INTO poado_token_manual_reserves
                (request_uid, user_id, flow_type, source_bucket, amount_units, status)
                VALUES
                (:request_uid, :user_id, :flow_type, :source_bucket, :amount_units, :status)
            ";
            $ins = $pdo->prepare($sql);
            $ins->execute([
                ':request_uid'   => $requestUid,
                ':user_id'       => $userId,
                ':flow_type'     => $flowType,
                ':source_bucket' => $sourceBucket,
                ':amount_units'  => $amount,
                ':status'        => $status,
            ]);

            /**
             * Optional side-log into project error/audit logger.
             * We intentionally do not require extra DB columns here.
             */
            gp_log('gold_packet_usdt_credited', [
                'request_uid' => $requestUid,
                'user_id' => $userId,
                'amount_units' => $amount,
                'source_bucket' => $sourceBucket,
                'meta' => $metaPayload,
            ]);

            $pdo->commit();

            return [
                'ok' => true,
                'request_uid' => $requestUid,
                'user_id' => $userId,
                'amount_units' => $amount,
                'flow_type' => $flowType,
                'source_bucket' => $sourceBucket,
                'status' => $status,
                'duplicate' => false,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            gp_log('gold_packet_usdt_credit_failed', [
                'request_uid' => $requestUid,
                'user_id' => $userId,
                'amount_units' => (string)$amountUnits,
                'source_bucket' => $sourceBucket,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}

if (!function_exists('gp_get_active_unclaimed_gold_packet_usdt_ton_total')) {
    function gp_get_active_unclaimed_gold_packet_usdt_ton_total(int $userId, ?PDO $pdo = null): string
    {
        if ($userId <= 0) {
            return '0.000000';
        }

        $pdo = $pdo instanceof PDO ? $pdo : gp_rwa_db();
        gp_ensure_table_shape($pdo);

        $st = $pdo->prepare("
            SELECT COALESCE(SUM(CAST(amount_units AS DECIMAL(30,6))), 0) AS total_amount
            FROM poado_token_manual_reserves
            WHERE user_id = :user_id
              AND flow_type = 'gold_packet_usdt'
              AND status = 'ACTIVE'
        ");
        $st->execute([':user_id' => $userId]);
        $val = $st->fetchColumn();

        return bcadd((string)$val, '0', 6);
    }
}

if (!function_exists('gp_list_active_unclaimed_gold_packet_usdt_ton')) {
    function gp_list_active_unclaimed_gold_packet_usdt_ton(int $userId, int $limit = 100, ?PDO $pdo = null): array
    {
        if ($userId <= 0) {
            return [];
        }

        $pdo = $pdo instanceof PDO ? $pdo : gp_rwa_db();
        gp_ensure_table_shape($pdo);

        $limit = max(1, min(500, $limit));

        $st = $pdo->prepare("
            SELECT
                id,
                request_uid,
                user_id,
                flow_type,
                source_bucket,
                amount_units,
                status,
                released_at,
                consumed_at,
                created_at,
                updated_at
            FROM poado_token_manual_reserves
            WHERE user_id = :user_id
              AND flow_type = 'gold_packet_usdt'
              AND status = 'ACTIVE'
            ORDER BY id DESC
            LIMIT {$limit}
        ");
        $st->execute([':user_id' => $userId]);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('gp_credit_gold_packet_from_royalty')) {
    function gp_credit_gold_packet_from_royalty(
        int $userId,
        $amountUnits,
        array $meta = [],
        ?string $requestUid = null,
        ?PDO $pdo = null
    ): array {
        return gp_credit_unclaimed_gold_packet_usdt_ton(
            $userId,
            $amountUnits,
            'royalty',
            $meta,
            $requestUid,
            $pdo
        );
    }
}

if (!function_exists('gp_credit_gold_packet_from_claim')) {
    function gp_credit_gold_packet_from_claim(
        int $userId,
        $amountUnits,
        array $meta = [],
        ?string $requestUid = null,
        ?PDO $pdo = null
    ): array {
        return gp_credit_unclaimed_gold_packet_usdt_ton(
            $userId,
            $amountUnits,
            'claim',
            $meta,
            $requestUid,
            $pdo
        );
    }
}

if (!function_exists('gp_credit_gold_packet_manual')) {
    function gp_credit_gold_packet_manual(
        int $userId,
        $amountUnits,
        array $meta = [],
        ?string $requestUid = null,
        ?PDO $pdo = null
    ): array {
        return gp_credit_unclaimed_gold_packet_usdt_ton(
            $userId,
            $amountUnits,
            'manual',
            $meta,
            $requestUid,
            $pdo
        );
    }
}
