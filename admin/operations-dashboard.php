<?php
declare(strict_types=1);

/**
 * POAdo RWA Cert Engine
 * Canonical DB Bootstrap
 *
 * File:
 * /var/www/html/public/rwa/cert/inc/db-bootstrap.php
 *
 * Purpose:
 * - Single source of truth for cert/admin DB object creation
 * - Create missing tables/indexes only
 * - Seed required default rows only when missing
 *
 * Locked rules:
 * - DB = wems_db
 * - charset/collation = utf8mb4 / utf8mb4_unicode_ci
 * - use db_connect() only
 * - use $GLOBALS['pdo']
 */

require_once $_SERVER['DOCUMENT_ROOT'].'/dashboard/inc/bootstrap.php';

if (!function_exists('poado_rwa_cert_db_bootstrap')) {
    function poado_rwa_cert_db_bootstrap(): array
    {
        db_connect();
        /** @var PDO $pdo */
        $pdo = $GLOBALS['pdo'];

        $out = [
            'ok' => true,
            'tables_checked' => [],
            'tables_created' => [],
            'indexes_checked' => [],
            'rows_seeded' => [],
            'errors' => [],
        ];

        $exec = static function (PDO $pdo, string $sql) use (&$out): bool {
            try {
                $pdo->exec($sql);
                return true;
            } catch (Throwable $e) {
                $out['errors'][] = $e->getMessage();
                return false;
            }
        };

        $tableExists = static function (PDO $pdo, string $table): bool {
            $stmt = $pdo->prepare("SHOW TABLES LIKE :t");
            $stmt->execute([':t' => $table]);
            return (bool)$stmt->fetchColumn();
        };

        $indexExists = static function (PDO $pdo, string $table, string $index): bool {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM information_schema.statistics
                WHERE table_schema = DATABASE()
                  AND table_name = :table
                  AND index_name = :idx
            ");
            $stmt->execute([
                ':table' => $table,
                ':idx'   => $index,
            ]);
            return (int)$stmt->fetchColumn() > 0;
        };

        $seedFlag = static function (PDO $pdo, string $flagKey, int $flagValue) use (&$out): void {
            $stmt = $pdo->prepare("
                INSERT INTO poado_system_flags (flag_key, flag_value)
                SELECT :flag_key, :flag_value
                FROM DUAL
                WHERE NOT EXISTS (
                    SELECT 1 FROM poado_system_flags WHERE flag_key = :flag_key2
                )
            ");
            $stmt->execute([
                ':flag_key'  => $flagKey,
                ':flag_value'=> $flagValue,
                ':flag_key2' => $flagKey,
            ]);
            if ($stmt->rowCount() > 0) {
                $out['rows_seeded'][] = 'poado_system_flags:' . $flagKey;
            }
        };

        /*
        |--------------------------------------------------------------------------
        | 1. Canonical cert issuance table
        |--------------------------------------------------------------------------
        */
        $table = 'poado_rwa_certs';
        $out['tables_checked'][] = $table;
        if (!$tableExists($pdo, $table)) {
            $sql = "
            CREATE TABLE poado_rwa_certs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                cert_uid VARCHAR(64) NOT NULL,
                cert_type VARCHAR(32) NOT NULL,
                owner_user_id BIGINT UNSIGNED NOT NULL,
                ton_wallet VARCHAR(128) DEFAULT NULL,
                pdf_path VARCHAR(255) NOT NULL,
                nft_image_path VARCHAR(255) DEFAULT NULL,
                metadata_path VARCHAR(255) DEFAULT NULL,
                nft_item_address VARCHAR(128) DEFAULT NULL,
                nft_minted TINYINT(1) NOT NULL DEFAULT 0,
                status ENUM('initiated','payment_pending','paid','mint_pending','minted','listed','revoked') NOT NULL DEFAULT 'initiated',
                issued_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                minted_at TIMESTAMP NULL DEFAULT NULL,
                price_wems INT UNSIGNED DEFAULT NULL,
                price_units VARCHAR(64) DEFAULT NULL,
                fingerprint_hash VARCHAR(128) DEFAULT NULL,
                router_tx_hash VARCHAR(128) DEFAULT NULL,
                paid_at TIMESTAMP NULL DEFAULT NULL,
                meta LONGTEXT DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_cert_uid (cert_uid),
                KEY idx_rwa_type (cert_type),
                KEY idx_owner_user_id (owner_user_id),
                KEY idx_ton_wallet (ton_wallet),
                KEY idx_status (status),
                KEY idx_router_tx_hash (router_tx_hash),
                KEY idx_fingerprint_hash (fingerprint_hash)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ";
            if ($exec($pdo, $sql)) {
                $out['tables_created'][] = $table;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 2. Royalty event ledger
        |--------------------------------------------------------------------------
        */
        $table = 'poado_rwa_royalty_events';
        $out['tables_checked'][] = $table;
        if (!$tableExists($pdo, $table)) {
            $sql = "
            CREATE TABLE poado_rwa_royalty_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                event_uid VARCHAR(64) NOT NULL,
                cert_uid VARCHAR(64) DEFAULT NULL,
                nft_item_index INT DEFAULT NULL,
                marketplace VARCHAR(64) NOT NULL,
                sale_amount_ton DECIMAL(20,9) NOT NULL DEFAULT 0.000000000,
                royalty_amount_ton DECIMAL(20,9) NOT NULL DEFAULT 0.000000000,
                treasury_tx_hash VARCHAR(128) DEFAULT NULL,
                block_time DATETIME DEFAULT NULL,
                holder_pool_ton DECIMAL(20,9) NOT NULL DEFAULT 0.000000000,
                ace_pool_ton DECIMAL(20,9) NOT NULL DEFAULT 0.000000000,
                gold_packet_pool_ton DECIMAL(20,9) NOT NULL DEFAULT 0.000000000,
                treasury_retained_ton DECIMAL(20,9) NOT NULL DEFAULT 0.000000000,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_event_uid (event_uid),
                KEY idx_cert_uid (cert_uid),
                KEY idx_treasury_tx_hash (treasury_tx_hash),
                KEY idx_block_time (block_time),
                KEY idx_marketplace (marketplace)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ";
            if ($exec($pdo, $sql)) {
                $out['tables_created'][] = $table;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 3. Holder claim ledger
        |--------------------------------------------------------------------------
        */
        $table = 'poado_rwa_holder_claims';
        $out['tables_checked'][] = $table;
        if (!$tableExists($pdo, $table)) {
            $sql = "
            CREATE TABLE poado_rwa_holder_claims (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                claim_uid VARCHAR(64) NOT NULL,
                event_uid VARCHAR(64) NOT NULL,
                cert_uid VARCHAR(64) DEFAULT NULL,
                owner_user_id BIGINT UNSIGNED NOT NULL,
                owner_wallet VARCHAR(128) NOT NULL,
                allocated_ton DECIMAL(20,9) NOT NULL DEFAULT 0.000000000,
                snapshot_time DATETIME DEFAULT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'claimable',
                claimed_ton DECIMAL(20,9) NOT NULL DEFAULT 0.000000000,
                claimed_tx_hash VARCHAR(128) DEFAULT NULL,
                claimed_at DATETIME DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_claim_uid (claim_uid),
                KEY idx_event_uid (event_uid),
                KEY idx_cert_uid (cert_uid),
                KEY idx_owner_user_id (owner_user_id),
                KEY idx_owner_wallet (owner_wallet),
                KEY idx_status (status),
                KEY idx_snapshot_time (snapshot_time)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ";
            if ($exec($pdo, $sql)) {
                $out['tables_created'][] = $table;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 4. ACE claim ledger
        |--------------------------------------------------------------------------
        */
        $table = 'poado_rwa_ace_claims';
        $out['tables_checked'][] = $table;
        if (!$tableExists($pdo, $table)) {
            $sql = "
            CREATE TABLE poado_rwa_ace_claims (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                claim_uid VARCHAR(64) NOT NULL,
                event_uid VARCHAR(64) NOT NULL,
                ace_user_id BIGINT UNSIGNED NOT NULL,
                ace_wallet VARCHAR(128) NOT NULL,
                weight_value DECIMAL(20,9) NOT NULL DEFAULT 0.000000000,
                allocated_ton DECIMAL(20,9) NOT NULL DEFAULT 0.000000000,
                snapshot_time DATETIME DEFAULT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'claimable',
                claimed_ton DECIMAL(20,9) NOT NULL DEFAULT 0.000000000,
                claimed_tx_hash VARCHAR(128) DEFAULT NULL,
                claimed_at DATETIME DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_claim_uid (claim_uid),
                KEY idx_event_uid (event_uid),
                KEY idx_ace_user_id (ace_user_id),
                KEY idx_ace_wallet (ace_wallet),
                KEY idx_status (status),
                KEY idx_snapshot_time (snapshot_time)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ";
            if ($exec($pdo, $sql)) {
                $out['tables_created'][] = $table;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 5. Gold Packet vault ledger
        |--------------------------------------------------------------------------
        */
        $table = 'poado_rwa_gold_packet_claims';
        $out['tables_checked'][] = $table;
        if (!$tableExists($pdo, $table)) {
            $sql = "
            CREATE TABLE poado_rwa_gold_packet_claims (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                claim_uid VARCHAR(64) NOT NULL,
                event_uid VARCHAR(64) NOT NULL,
                cert_uid VARCHAR(64) DEFAULT NULL,
                allocated_ton DECIMAL(20,9) NOT NULL DEFAULT 0.000000000,
                snapshot_time DATETIME DEFAULT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'vaulted',
                distributed_ton DECIMAL(20,9) NOT NULL DEFAULT 0.000000000,
                distributed_tx_hash VARCHAR(128) DEFAULT NULL,
                distributed_at DATETIME DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_claim_uid (claim_uid),
                KEY idx_event_uid (event_uid),
                KEY idx_cert_uid (cert_uid),
                KEY idx_status (status),
                KEY idx_snapshot_time (snapshot_time)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ";
            if ($exec($pdo, $sql)) {
                $out['tables_created'][] = $table;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 6. Treasury retained ledger
        |--------------------------------------------------------------------------
        */
        $table = 'poado_rwa_treasury_retained';
        $out['tables_checked'][] = $table;
        if (!$tableExists($pdo, $table)) {
            $sql = "
            CREATE TABLE poado_rwa_treasury_retained (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                retain_uid VARCHAR(64) NOT NULL,
                event_uid VARCHAR(64) NOT NULL,
                cert_uid VARCHAR(64) DEFAULT NULL,
                marketplace VARCHAR(64) DEFAULT NULL,
                treasury_wallet VARCHAR(128) NOT NULL,
                retained_ton DECIMAL(20,9) NOT NULL DEFAULT 0.000000000,
                snapshot_time DATETIME DEFAULT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'retained',
                note TEXT DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_retain_uid (retain_uid),
                KEY idx_event_uid (event_uid),
                KEY idx_cert_uid (cert_uid),
                KEY idx_status (status),
                KEY idx_snapshot_time (snapshot_time)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ";
            if ($exec($pdo, $sql)) {
                $out['tables_created'][] = $table;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 7. Daily Gold Packet distributions
        |--------------------------------------------------------------------------
        */
        $table = 'poado_rwa_gold_packet_distributions';
        $out['tables_checked'][] = $table;
        if (!$tableExists($pdo, $table)) {
            $sql = "
            CREATE TABLE poado_rwa_gold_packet_distributions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                distribution_uid VARCHAR(64) NOT NULL,
                distribution_date DATE NOT NULL,
                owner_user_id BIGINT UNSIGNED NOT NULL,
                owner_wallet VARCHAR(128) NOT NULL,
                cert_count INT UNSIGNED NOT NULL DEFAULT 0,
                allocated_ton DECIMAL(20,9) NOT NULL DEFAULT 0.000000000,
                status VARCHAR(32) NOT NULL DEFAULT 'pending',
                payout_tx_hash VARCHAR(128) DEFAULT NULL,
                paid_at DATETIME DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_distribution_uid (distribution_uid),
                KEY idx_distribution_date (distribution_date),
                KEY idx_owner_user_id (owner_user_id),
                KEY idx_owner_wallet (owner_wallet),
                KEY idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ";
            if ($exec($pdo, $sql)) {
                $out['tables_created'][] = $table;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 8. Emergency/system flags
        |--------------------------------------------------------------------------
        */
        $table = 'poado_system_flags';
        $out['tables_checked'][] = $table;
        if (!$tableExists($pdo, $table)) {
            $sql = "
            CREATE TABLE poado_system_flags (
                id INT NOT NULL AUTO_INCREMENT,
                flag_key VARCHAR(64) NOT NULL,
                flag_value TINYINT(1) NOT NULL DEFAULT 0,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_flag_key (flag_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ";
            if ($exec($pdo, $sql)) {
                $out['tables_created'][] = $table;
            }
        }

        if ($tableExists($pdo, 'poado_system_flags')) {
            $seedFlag($pdo, 'mint_engine_pause', 0);
            $seedFlag($pdo, 'market_listing_pause', 0);
            $seedFlag($pdo, 'mining_pause', 0);
            $seedFlag($pdo, 'login_pause', 0);
            $seedFlag($pdo, 'maintenance_mode', 0);
        }

        /*
        |--------------------------------------------------------------------------
        | 9. Useful safety indexes on poado_rwa_certs if table already existed
        |--------------------------------------------------------------------------
        */
        $certTable = 'poado_rwa_certs';
        if ($tableExists($pdo, $certTable)) {
            $neededIndexes = [
                'idx_status'            => "ALTER TABLE poado_rwa_certs ADD INDEX idx_status (status)",
                'idx_router_tx_hash'    => "ALTER TABLE poado_rwa_certs ADD INDEX idx_router_tx_hash (router_tx_hash)",
                'idx_fingerprint_hash'  => "ALTER TABLE poado_rwa_certs ADD INDEX idx_fingerprint_hash (fingerprint_hash)",
                'idx_owner_user_id'     => "ALTER TABLE poado_rwa_certs ADD INDEX idx_owner_user_id (owner_user_id)",
            ];

            foreach ($neededIndexes as $idx => $sql) {
                $out['indexes_checked'][] = $certTable . ':' . $idx;
                if (!$indexExists($pdo, $certTable, $idx)) {
                    $exec($pdo, $sql);
                }
            }
        }

        $out['ok'] = count($out['errors']) === 0;
        return $out;
    }
}