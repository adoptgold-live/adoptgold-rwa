<?php
declare(strict_types=1);

/**
 * /rwa/inc/core/json.php
 * Standalone RWA JSON Helpers
 * Version: v1.0.20260314.1
 */

if (!function_exists('json_headers')) {
    function json_headers(int $code = 200, int $cacheSeconds = 0): void
    {
        if (headers_sent()) {
            return;
        }

        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');

        if ($cacheSeconds > 0) {
            header('Cache-Control: public, max-age=' . $cacheSeconds);
        } else {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
        }
    }
}

if (!function_exists('json_encode_safe')) {
    function json_encode_safe(array $payload): string
    {
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            return '{"ok":false,"error":"json_encode_failed"}';
        }

        return $json;
    }
}

if (!function_exists('json_out')) {
    function json_out(array $payload, int $code = 200, int $cacheSeconds = 0): void
    {
        json_headers($code, $cacheSeconds);
        echo json_encode_safe($payload);
        exit;
    }
}

if (!function_exists('json_ok')) {
    function json_ok(array $data = [], int $code = 200): void
    {
        json_out(['ok' => true] + $data, $code, 0);
    }
}

if (!function_exists('json_fail')) {
    function json_fail(string $error, int $code = 400, array $extra = []): void
    {
        json_out(['ok' => false, 'error' => $error] + $extra, $code, 0);
    }
}

if (!function_exists('json_message')) {
    function json_message(string $message, int $code = 200, array $extra = []): void
    {
        json_out(['ok' => true, 'message' => $message] + $extra, $code, 0);
    }
}

if (!function_exists('json_input_raw')) {
    function json_input_raw(): string
    {
        $raw = file_get_contents('php://input');
        return is_string($raw) ? $raw : '';
    }
}

if (!function_exists('json_input_body')) {
    function json_input_body(): array
    {
        $ct = (string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
        $raw = json_input_raw();

        if (stripos($ct, 'application/json') !== false) {
            $arr = json_decode($raw !== '' ? $raw : '[]', true);
            return is_array($arr) ? $arr : [];
        }

        return [];
    }
}

if (!function_exists('json_input')) {
    function json_input(): array
    {
        /**
         * Backward-compatible behavior:
         * - JSON body if content-type is application/json
         * - otherwise POST
         */
        $json = json_input_body();
        if (!empty($json)) {
            return $json;
        }

        return is_array($_POST ?? null) ? $_POST : [];
    }
}

if (!function_exists('json_request')) {
    function json_request(): array
    {
        /**
         * Unified request helper:
         * query < form < json
         */
        $get  = is_array($_GET ?? null) ? $_GET : [];
        $post = is_array($_POST ?? null) ? $_POST : [];
        $json = json_input_body();

        return array_replace($get, $post, $json);
    }
}

if (!function_exists('json_str')) {
    function json_str(array $src, string $key, string $default = ''): string
    {
        $v = $src[$key] ?? $default;
        if (is_array($v) || is_object($v)) {
            return $default;
        }
        return trim((string)$v);
    }
}

if (!function_exists('json_int')) {
    function json_int(array $src, string $key, int $default = 0): int
    {
        $v = $src[$key] ?? $default;
        return is_numeric($v) ? (int)$v : $default;
    }
}

if (!function_exists('json_bool')) {
    function json_bool(array $src, string $key, bool $default = false): bool
    {
        $v = $src[$key] ?? $default;

        if (is_bool($v)) {
            return $v;
        }

        if (is_int($v)) {
            return $v === 1;
        }

        if (is_string($v)) {
            $v = strtolower(trim($v));
            if (in_array($v, ['1', 'true', 'yes', 'on'], true)) return true;
            if (in_array($v, ['0', 'false', 'no', 'off'], true)) return false;
        }

        return $default;
    }
}

if (!function_exists('json_require')) {
    function json_require(array $src, array $keys, string $error = 'missing_required_fields'): void
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $src)) {
                json_fail($error, 422, ['missing' => $key]);
            }

            $v = $src[$key];
            if (is_string($v) && trim($v) === '') {
                json_fail($error, 422, ['missing' => $key]);
            }
        }
    }
}