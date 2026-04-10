<?php
declare(strict_types=1);

/**
 * /rwa/inc/core/env.php
 * Standalone RWA Env Loader
 * Version: v1.0.20260314.1
 *
 * Purpose
 * - make getenv() reliable
 * - load locked secure env first
 * - export values to putenv(), $_ENV, $_SERVER
 * - safe reusable helpers for standalone RWA modules
 */

if (!function_exists('poado_env')) {
    function poado_env(string $key, $default = null)
    {
        $key = trim($key);
        if ($key === '') {
            return $default;
        }

        $v = getenv($key);
        if ($v !== false && $v !== null && $v !== '') {
            return $v;
        }

        if (array_key_exists($key, $_ENV) && $_ENV[$key] !== '' && $_ENV[$key] !== null) {
            return $_ENV[$key];
        }

        if (array_key_exists($key, $_SERVER) && $_SERVER[$key] !== '' && $_SERVER[$key] !== null) {
            return $_SERVER[$key];
        }

        return $default;
    }
}

if (!function_exists('poado_env_has')) {
    function poado_env_has(string $key): bool
    {
        $key = trim($key);
        if ($key === '') {
            return false;
        }

        $v = getenv($key);
        if ($v !== false && $v !== null && $v !== '') {
            return true;
        }

        if (array_key_exists($key, $_ENV) && $_ENV[$key] !== '' && $_ENV[$key] !== null) {
            return true;
        }

        if (array_key_exists($key, $_SERVER) && $_SERVER[$key] !== '' && $_SERVER[$key] !== null) {
            return true;
        }

        return false;
    }
}

if (!function_exists('poado_env_set')) {
    function poado_env_set(string $key, string $value, bool $overwrite = true): void
    {
        $key = trim($key);
        if ($key === '' || !preg_match('/^[A-Z0-9_]+$/', $key)) {
            return;
        }

        if (!$overwrite && poado_env_has($key)) {
            return;
        }

        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

if (!function_exists('poado_env_unquote')) {
    function poado_env_unquote(string $value): string
    {
        $value = trim($value);
        $len = strlen($value);

        if ($len >= 2) {
            $first = $value[0];
            $last  = $value[$len - 1];

            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        return str_replace(["\r", "\n"], '', $value);
    }
}

if (!function_exists('poado_env_strip_inline_comment')) {
    function poado_env_strip_inline_comment(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $first = $value[0];
        if ($first === '"' || $first === "'") {
            return $value;
        }

        $hashPos = strpos($value, ' #');
        if ($hashPos !== false) {
            $value = substr($value, 0, $hashPos);
        }

        return trim($value);
    }
}

if (!function_exists('poado_env_load_file')) {
    function poado_env_load_file(string $file, bool $overwrite = false): void
    {
        if (!is_file($file) || !is_readable($file)) {
            return;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }

            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1));

            if ($key === '' || !preg_match('/^[A-Z0-9_]+$/', $key)) {
                continue;
            }

            $val = poado_env_strip_inline_comment($val);
            $val = poado_env_unquote($val);

            poado_env_set($key, $val, $overwrite);
        }
    }
}

if (!function_exists('poado_env_require')) {
    function poado_env_require(string $key): string
    {
        $v = poado_env($key, null);
        if ($v === null || $v === '') {
            error_log('[RWA ENV] Missing required env key: ' . $key);
            http_response_code(500);
            exit('ENV ERROR');
        }
        return (string)$v;
    }
}

if (!defined('POADO_ENV_LOADED')) {
    define('POADO_ENV_LOADED', 1);

    /**
     * Locked priority order
     * 1) global secure env
     * 2) optional public root env
     * 3) optional standalone RWA local env
     *
     * Default behavior:
     * - first loaded value wins
     * - later files do not overwrite earlier secure values
     */
    $poadoEnvFiles = [
        '/var/www/secure/.env',
        '/var/www/html/public/.env',
        '/var/www/html/public/rwa/.env',
    ];

    foreach ($poadoEnvFiles as $poadoEnvFile) {
        poado_env_load_file($poadoEnvFile, false);
    }

    /**
     * Optional canonical defaults
     * Set only if missing
     */
    poado_env_set('APP_ENV', (string)poado_env('APP_ENV', 'production'), false);
    poado_env_set('DB_NAME', (string)poado_env('DB_NAME', 'wems_db'), false);
    poado_env_set('DB_CHARSET', (string)poado_env('DB_CHARSET', 'utf8mb4'), false);
}