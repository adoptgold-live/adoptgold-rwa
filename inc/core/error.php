<?php
declare(strict_types=1);

/**
 * /rwa/inc/core/error.php
 * Standalone RWA Global Error Logger
 * Version: v1.0.20260314.1
 *
 * Purpose
 * - production-safe error logging
 * - DB-backed API/module error logging
 * - silent failure on logger issues
 * - compatible with existing poado_error() calls
 */

if (!function_exists('poado_error_context_sanitize')) {
    function poado_error_context_sanitize(array $context, int $maxLen = 1000): array
    {
        $safe = [];

        foreach ($context as $k => $v) {
            $key = is_string($k) ? $k : (string)$k;

            if (is_scalar($v) || $v === null) {
                $value = $v;
            } else {
                $json = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $value = $json !== false ? $json : '[unserializable]';
            }

            if (is_string($value) && strlen($value) > $maxLen) {
                $value = substr($value, 0, $maxLen) . '...[trimmed]';
            }

            $safe[$key] = $value;
        }

        return $safe;
    }
}

if (!function_exists('poado_error_request_path')) {
    function poado_error_request_path(): string
    {
        $p = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        return is_string($p) && $p !== '' ? $p : '/';
    }
}

if (!function_exists('poado_error_client_ip')) {
    function poado_error_client_ip(): string
    {
        $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];

        foreach ($keys as $k) {
            $v = trim((string)($_SERVER[$k] ?? ''));
            if ($v === '') {
                continue;
            }

            if ($k === 'HTTP_X_FORWARDED_FOR' && strpos($v, ',') !== false) {
                $v = trim(explode(',', $v)[0]);
            }

            if ($v !== '') {
                return $v;
            }
        }

        return '';
    }
}

if (!function_exists('poado_error_file_fallback')) {
    function poado_error_file_fallback(array $payload): void
    {
        try {
            $dir = '/var/www/html/public/rwa/storage/logs';
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }

            $file = $dir . '/api-error.log';
            $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        } catch (Throwable $e) {
            // never break app
        }
    }
}

if (!function_exists('poado_error')) {
    function poado_error(
        string $module,
        string $error_code,
        array $context = [],
        string $public_hint = '',
        string $severity = 'error'
    ): void {
        try {
            $module = trim($module);
            $error_code = trim($error_code);
            $public_hint = trim($public_hint);
            $severity = strtolower(trim($severity));

            if ($module === '') {
                $module = 'rwa';
            }
            if ($error_code === '') {
                $error_code = 'unknown_error';
            }
            if ($severity === '') {
                $severity = 'error';
            }

            $safeContext = poado_error_context_sanitize($context);

            $meta = [
                'request_path' => poado_error_request_path(),
                'request_method' => strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')),
                'ip' => poado_error_client_ip(),
                'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
                'wallet' => $_SESSION['wallet'] ?? ($_SESSION['wallet_session']['wallet'] ?? ($_SESSION['wallet_session']['wallet_address'] ?? null)),
                'user_id' => $_SESSION['user_id'] ?? ($_SESSION['wallet_session']['user_id'] ?? null),
            ];

            $safeContext = array_merge($safeContext, $meta);
            $contextJson = json_encode($safeContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($contextJson === false) {
                $contextJson = '{}';
            }

            if (!isset($GLOBALS['pdo']) || !($GLOBALS['pdo'] instanceof PDO)) {
                if (function_exists('rwa_db')) {
                    $GLOBALS['pdo'] = rwa_db();
                } elseif (function_exists('db_connect')) {
                    $GLOBALS['pdo'] = db_connect();
                }
            }

            $pdo = $GLOBALS['pdo'] ?? null;
            if (!$pdo instanceof PDO) {
                poado_error_file_fallback([
                    'ts' => gmdate('c'),
                    'module' => $module,
                    'error_code' => $error_code,
                    'severity' => $severity,
                    'public_hint' => $public_hint,
                    'context' => $safeContext,
                    'fallback' => 'no_pdo',
                ]);
                return;
            }

            $stmt = $pdo->prepare("
                INSERT INTO poado_api_errors
                (module, error_code, severity, context_json, public_hint, created_at)
                VALUES
                (:module, :error_code, :severity, :context_json, :public_hint, UTC_TIMESTAMP())
            ");

            $stmt->execute([
                ':module' => substr($module, 0, 100),
                ':error_code' => substr($error_code, 0, 120),
                ':severity' => substr($severity, 0, 20),
                ':context_json' => $contextJson,
                ':public_hint' => substr($public_hint, 0, 255),
            ]);
        } catch (Throwable $e) {
            poado_error_file_fallback([
                'ts' => gmdate('c'),
                'module' => $module ?? 'rwa',
                'error_code' => $error_code ?? 'logger_failed',
                'severity' => $severity ?? 'error',
                'public_hint' => $public_hint ?? '',
                'context' => isset($safeContext) && is_array($safeContext) ? $safeContext : [],
                'fallback' => 'logger_exception',
                'logger_error' => $e->getMessage(),
            ]);
        }
    }
}