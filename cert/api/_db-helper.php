<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/api/_db-helper.php
 * Version: v1.1.0-20260403-cert-db-helper-stable
 *
 * MASTER LOCK
 * - Live DB schema is final authority
 * - Never guess cert-module DB columns
 * - All INSERT / UPDATE must be schema-aware
 */

if (!function_exists('cert_db_pdo')) {
    function cert_db_pdo(): PDO
    {
        if (function_exists('rwa_db')) {
            $pdo = rwa_db();
            if ($pdo instanceof PDO) return $pdo;
        }
        if (function_exists('db_connect')) {
            $pdo = db_connect();
            if ($pdo instanceof PDO) return $pdo;
        }
        if (($GLOBALS['pdo'] ?? null) instanceof PDO) {
            return $GLOBALS['pdo'];
        }
        throw new RuntimeException('CERT_DB_PDO_NOT_READY');
    }
}

if (!function_exists('cert_db_table_columns')) {
    function cert_db_table_columns(PDO $pdo, string $table): array
    {
        static $cache = [];
        $key = strtolower(trim($table));
        if (isset($cache[$key])) return $cache[$key];

        $st = $pdo->prepare("
            SELECT COLUMN_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table
            ORDER BY ORDINAL_POSITION
        ");
        $st->execute([':table' => $table]);

        $cols = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $col) {
            $cols[(string)$col] = true;
        }

        $cache[$key] = $cols;
        return $cols;
    }
}

if (!function_exists('cert_db_table_exists')) {
    function cert_db_table_exists(PDO $pdo, string $table): bool
    {
        return cert_db_table_columns($pdo, $table) !== [];
    }
}

if (!function_exists('cert_db_filter_to_columns')) {
    function cert_db_filter_to_columns(PDO $pdo, string $table, array $data): array
    {
        $cols = cert_db_table_columns($pdo, $table);
        $safe = [];
        foreach ($data as $k => $v) {
            if (isset($cols[$k])) {
                $safe[$k] = $v;
            }
        }
        return $safe;
    }
}

if (!function_exists('cert_db_insert')) {
    function cert_db_insert(PDO $pdo, string $table, array $data): bool
    {
        $safe = cert_db_filter_to_columns($pdo, $table, $data);
        if ($safe === []) return false;

        $fields = array_keys($safe);
        $placeholders = array_map(static fn(string $k): string => ':' . $k, $fields);

        $sql = "INSERT INTO {$table} (" . implode(', ', $fields) . ")
                VALUES (" . implode(', ', $placeholders) . ")";

        $st = $pdo->prepare($sql);
        $params = [];
        foreach ($safe as $k => $v) {
            $params[':' . $k] = $v;
        }
        return $st->execute($params);
    }
}

if (!function_exists('cert_db_update_by_id')) {
    function cert_db_update_by_id(PDO $pdo, string $table, int $id, array $data): bool
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('CERT_DB_INVALID_ID');
        }

        $safe = cert_db_filter_to_columns($pdo, $table, $data);
        unset($safe['id']);

        if ($safe === []) return false;

        $sets = [];
        $params = [':id' => $id];

        foreach ($safe as $k => $v) {
            $sets[] = "{$k} = :set_{$k}";
            $params[':set_' . $k] = $v;
        }

        $sql = "UPDATE {$table} SET " . implode(', ', $sets) . " WHERE id = :id LIMIT 1";
        $st = $pdo->prepare($sql);
        return $st->execute($params);
    }
}

if (!function_exists('cert_db_update_where')) {
    function cert_db_update_where(PDO $pdo, string $table, array $data, string $whereSql, array $whereParams = []): bool
    {
        $safe = cert_db_filter_to_columns($pdo, $table, $data);
        unset($safe['id']);

        if ($safe === []) return false;

        $sets = [];
        $params = [];

        foreach ($safe as $k => $v) {
            $sets[] = "{$k} = :set_{$k}";
            $params[':set_' . $k] = $v;
        }

        foreach ($whereParams as $k => $v) {
            $key = (strpos((string)$k, ':') === 0) ? (string)$k : ':' . $k;
            $params[$key] = $v;
        }

        $sql = "UPDATE {$table} SET " . implode(', ', $sets) . " WHERE {$whereSql}";
        $st = $pdo->prepare($sql);
        return $st->execute($params);
    }
}

if (!function_exists('cert_db_find_one')) {
    function cert_db_find_one(PDO $pdo, string $table, string $whereSql, array $params = [], string $orderBy = ''): ?array
    {
        $sql = "SELECT * FROM {$table} WHERE {$whereSql}";
        if ($orderBy !== '') {
            $sql .= " ORDER BY {$orderBy}";
        }
        $sql .= " LIMIT 1";

        $st = $pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}

if (!function_exists('cert_db_payment_meta_from_canonical')) {
    function cert_db_payment_meta_from_canonical(string $certUid, array $canon): array
    {
        return [
            'cert_uid'      => $certUid,
            'payment_ref'   => (string)($canon['payment_ref'] ?? ''),
            'status'        => (string)($canon['status'] ?? 'pending'),
            'verified'      => !empty($canon['verified']),
            'token'         => (string)($canon['token_symbol'] ?? ''),
            'token_symbol'  => (string)($canon['token_symbol'] ?? ''),
            'token_master'  => (string)($canon['token_master_b64'] ?? ($canon['token_master'] ?? '')),
            'destination'   => (string)($canon['destination'] ?? ''),
            'deeplink'      => (string)($canon['deeplink'] ?? ''),
            'wallet_link'   => (string)($canon['wallet_link'] ?? ''),
            'wallet_url'    => (string)($canon['wallet_link'] ?? ''),
            'qr_payload'    => (string)($canon['qr_payload'] ?? ''),
            'qr_text'       => (string)($canon['qr_text'] ?? ''),
            'qr_image'      => (string)($canon['qr_image'] ?? ''),
            'qr_url'        => (string)($canon['qr_image'] ?? ''),
            'amount'        => (string)($canon['amount'] ?? ''),
            'amount_units'  => (string)($canon['amount_units'] ?? ''),
            'decimals'      => (int)($canon['decimals'] ?? 9),
            'tx_hash'       => (string)($canon['tx_hash'] ?? ''),
            'paid_at'       => (string)($canon['paid_at'] ?? ''),
            'updated_at'    => date(DATE_ATOM),
        ];
    }
}

if (!function_exists('cert_db_upsert_payment_row')) {
    function cert_db_upsert_payment_row(PDO $pdo, ?array $existingRow, string $certUid, int $ownerUserId, string $ownerWallet, array $canon): ?array
    {
        if (!cert_db_table_exists($pdo, 'poado_rwa_cert_payments')) {
            return $existingRow ?: null;
        }

        $payload = [
            'cert_uid'      => $certUid,
            'payment_ref'   => (string)($canon['payment_ref'] ?? ''),
            'owner_user_id' => $ownerUserId > 0 ? $ownerUserId : null,
            'ton_wallet'    => $ownerWallet !== '' ? $ownerWallet : null,
            'token_symbol'  => (string)($canon['token_symbol'] ?? ''),
            'token_master'  => (string)($canon['token_master_b64'] ?? ($canon['token_master'] ?? '')),
            'decimals'      => (int)($canon['decimals'] ?? 9),
            'amount'        => (string)($canon['amount'] ?? ''),
            'amount_units'  => (string)($canon['amount_units'] ?? ''),
            'status'        => (string)($canon['status'] ?? 'pending'),
            'tx_hash'       => (string)($canon['tx_hash'] ?? ''),
            'verified'      => !empty($canon['verified']) ? 1 : 0,
            'paid_at'       => (string)($canon['paid_at'] ?? '') !== '' ? (string)$canon['paid_at'] : null,
            'meta_json'     => json_encode(
                cert_db_payment_meta_from_canonical($certUid, $canon),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
            ),
            'updated_at'    => date('Y-m-d H:i:s'),
        ];

        if ($existingRow && !empty($existingRow['id'])) {
            cert_db_update_by_id($pdo, 'poado_rwa_cert_payments', (int)$existingRow['id'], $payload);
        } else {
            cert_db_insert($pdo, 'poado_rwa_cert_payments', $payload);
        }

        return cert_db_find_one(
            $pdo,
            'poado_rwa_cert_payments',
            'cert_uid = :cert_uid',
            [':cert_uid' => $certUid],
            'id DESC'
        );
    }
}

if (!function_exists('cert_db_update_cert_row')) {
    function cert_db_update_cert_row(PDO $pdo, string $certUid, array $data): bool
    {
        return cert_db_update_where(
            $pdo,
            'poado_rwa_certs',
            $data,
            'cert_uid = :cert_uid',
            [':cert_uid' => $certUid]
        );
    }
}
