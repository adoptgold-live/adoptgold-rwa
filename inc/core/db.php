<?php
declare(strict_types=1);

/**
 * /rwa/inc/core/db.php
 * Standalone RWA Hardened DB Core
 * Version: v1.0.20260314.1
 *
 * Rules
 * - locked DB_NAME = wems_db
 * - single shared PDO via $GLOBALS['pdo']
 * - standalone accessor = rwa_db()
 * - compat accessors kept:
 *   db_connect()
 *   poado_pdo()
 *   db()
 * - hardened PDO defaults
 * - include-safe output guard
 */

# ============================================================
# 1) Include-time output guard
# ============================================================
if (!defined('POADO_DB_OB_GUARD')) {
    define('POADO_DB_OB_GUARD', 1);

    $__poado_db_ob_level = ob_get_level();
    ob_start();

    register_shutdown_function(static function () use ($__poado_db_ob_level): void {
        while (ob_get_level() > $__poado_db_ob_level) {
            @ob_end_clean();
        }
    });
}

# ============================================================
# 2) Small env reader helper
# ============================================================
if (!function_exists('poado_db_env')) {
    function poado_db_env(string $key, ?string $fallback = null): ?string
    {
        if (defined($key)) {
            $v = constant($key);
            if ($v !== null && $v !== '') {
                return (string)$v;
            }
        }

        if (function_exists('poado_env')) {
            $v = poado_env($key, null);
            if ($v !== null && $v !== '') {
                return (string)$v;
            }
        }

        $v = getenv($key);
        if ($v !== false && $v !== null && $v !== '') {
            return (string)$v;
        }

        return $fallback;
    }
}

# ============================================================
# 3) Main shared PDO connector
# ============================================================
if (!function_exists('db_connect')) {
    function db_connect(): PDO
    {
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            return $GLOBALS['pdo'];
        }

        /**
         * Env / constants priority
         * - DB_HOST / DB_NAME / DB_USER / DB_PASS / DB_CHARSET
         * - fallback locked values
         */
        $hostRaw = trim((string)(poado_db_env('DB_HOST', '127.0.0.1') ?? '127.0.0.1'));
        $name    = trim((string)(poado_db_env('DB_NAME', 'wems_db') ?? 'wems_db'));
        $user    = (string)(poado_db_env('DB_USER', 'wems_user') ?? 'wems_user');
        $pass    = (string)(poado_db_env('DB_PASS', 'Tiger7709304653!') ?? 'Tiger7709304653!');
        $charset = strtolower(trim((string)(poado_db_env('DB_CHARSET', 'utf8mb4') ?? 'utf8mb4')));

        /**
         * HARD LOCK: only wems_db
         */
        if ($name !== 'wems_db') {
            error_log('[RWA DB] BLOCKED: DB_NAME must be wems_db, got: ' . $name);
            http_response_code(500);
            exit('DB LOCK');
        }

        /**
         * Restrict charset to safe allowed values
         */
        if (!in_array($charset, ['utf8mb4', 'utf8'], true)) {
            $charset = 'utf8mb4';
        }

        /**
         * Parse host[:port]
         */
        $host = $hostRaw;
        $port = null;

        if (strpos($hostRaw, ':') !== false) {
            [$parsedHost, $parsedPort] = array_pad(explode(':', $hostRaw, 2), 2, '');
            $parsedHost = trim($parsedHost);
            $parsedPort = trim($parsedPort);

            if ($parsedHost !== '' && $parsedPort !== '' && ctype_digit($parsedPort)) {
                $host = $parsedHost;
                $port = (int)$parsedPort;
            }
        }

        /**
         * Basic host allowlist validation
         */
        if ($host === '' || !preg_match('/^[a-zA-Z0-9._-]+$/', $host)) {
            error_log('[RWA DB] BLOCKED: invalid DB_HOST');
            http_response_code(500);
            exit('DB LOCK');
        }

        /**
         * Build DSN
         */
        $dsn = $port
            ? "mysql:host={$host};port={$port};dbname=wems_db;charset={$charset}"
            : "mysql:host={$host};dbname=wems_db;charset={$charset}";

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ];

        if (defined('PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
            $options[PDO::MYSQL_ATTR_MULTI_STATEMENTS] = false;
        }

        try {
            $pdo = new PDO($dsn, $user, $pass, $options);

            /**
             * Secondary lock: verify actual active DB
             */
            $activeDb = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
            if ($activeDb !== 'wems_db') {
                error_log('[RWA DB] BLOCKED: connected DB mismatch. Active=' . $activeDb);
                http_response_code(500);
                exit('DB LOCK');
            }

            /**
             * Session defaults
             */
            $pdo->exec("SET NAMES {$charset}");
            $pdo->exec("SET time_zone = '+00:00'");

            $GLOBALS['pdo'] = $pdo;
            return $pdo;
        } catch (Throwable $e) {
            error_log('[RWA DB] Connect error: ' . $e->getMessage());
            http_response_code(500);
            exit('Database connection failed');
        }
    }
}

# ============================================================
# 4) Preferred standalone accessor
# ============================================================
if (!function_exists('rwa_db')) {
    function rwa_db(): PDO
    {
        return db_connect();
    }
}

# ============================================================
# 5) Project-standard accessor
# ============================================================
if (!function_exists('poado_pdo')) {
    function poado_pdo(): PDO
    {
        return db_connect();
    }
}

# ============================================================
# 6) Legacy compatibility alias
# ============================================================
if (!function_exists('db')) {
    function db(): PDO
    {
        error_log('[RWA DB] Deprecated call: db(); use rwa_db() or db_connect()');
        return db_connect();
    }
}

# ============================================================
# 7) Optional helper for simple health checks
# ============================================================
if (!function_exists('poado_db_ok')) {
    function poado_db_ok(): bool
    {
        try {
            $pdo = db_connect();
            return (bool)$pdo->query('SELECT 1')->fetchColumn();
        } catch (Throwable $e) {
            error_log('[RWA DB] Health check failed: ' . $e->getMessage());
            return false;
        }
    }
}

# ============================================================
# 8) Output guard cleanup
# ============================================================
if (defined('POADO_DB_OB_GUARD') && isset($__poado_db_ob_level)) {
    while (ob_get_level() > $__poado_db_ob_level) {
        @ob_end_clean();
    }
}