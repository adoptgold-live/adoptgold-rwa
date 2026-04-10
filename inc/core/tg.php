<?php
declare(strict_types=1);
/**
 * /dashboard/inc/tg.php (LOCKED)
 * Shared Telegram helpers for ALL modules/endpoints.
 *
 * Canonical rules:
 * - Secret header name: X-TG-BOT-SECRET
 * - Env key: TG_BOT_SECRET
 * - Canonical TG id key: tg_id
 */

if (!defined('POADO_TG_HELPER_LOADED')) {
  define('POADO_TG_HELPER_LOADED', 1);
}

/**
 * Ensure TG_BOT_SECRET constant exists (loaded from env via bootstrap loader).
 * Do NOT hardcode secret here.
 */
if (!defined('TG_BOT_SECRET')) {
  $v = function_exists('poado_env') ? (string)poado_env('TG_BOT_SECRET', '') : (string)(getenv('TG_BOT_SECRET') ?: '');
  define('TG_BOT_SECRET', $v);
}

/** Optional (not required by issue-login-token.php but useful elsewhere) */
if (!defined('TG_BOT_USERNAME')) {
  $v = function_exists('poado_env') ? (string)poado_env('TG_BOT_USERNAME', '') : (string)(getenv('TG_BOT_USERNAME') ?: '');
  define('TG_BOT_USERNAME', $v);
}
if (!defined('TG_BOT_TOKEN')) {
  $v = function_exists('poado_env') ? (string)poado_env('TG_BOT_TOKEN', '') : (string)(getenv('TG_BOT_TOKEN') ?: '');
  define('TG_BOT_TOKEN', $v);
}
if (!defined('ISSUE_PIN_URL')) {
  $v = function_exists('poado_env') ? (string)poado_env('ISSUE_PIN_URL', '') : (string)(getenv('ISSUE_PIN_URL') ?: '');
  define('ISSUE_PIN_URL', $v);
}

/**
 * Read request header value (case-insensitive).
 */
if (!function_exists('poado_header')) {
  function poado_header(string $name): string {
    $name_l = strtolower($name);

    if (function_exists('getallheaders')) {
      $h = getallheaders();
      if (is_array($h)) {
        foreach ($h as $k => $v) {
          if (strtolower((string)$k) === $name_l) return (string)$v;
        }
      }
    }

    // Fallback: PHP maps headers to HTTP_* keys
    $httpKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return (string)($_SERVER[$httpKey] ?? '');
  }
}

/**
 * Verify BOT secret header for Telegram bot→server calls.
 * Returns [ok(bool), err(string)] without echo/exit so API can decide response style.
 */
if (!function_exists('poado_tg_verify_bot_secret')) {
  function poado_tg_verify_bot_secret(): array {
    if (!defined('TG_BOT_SECRET') || !is_string(TG_BOT_SECRET) || TG_BOT_SECRET === '') {
      return [false, 'Server not configured'];
    }
    $hdr = poado_header('X-TG-BOT-SECRET');
    if ($hdr === '') return [false, 'Forbidden'];
    if (!hash_equals(TG_BOT_SECRET, $hdr)) return [false, 'Forbidden'];
    return [true, ''];
  }
}

/**
 * Convenience: hard-fail JSON (for APIs) using the same message style.
 */
if (!function_exists('poado_tg_json_fail')) {
  function poado_tg_json_fail(int $code, string $msg, ?string $error_id = null): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    $out = ['ok' => false, 'error' => $msg];
    if ($error_id) $out['error_id'] = $error_id;
    echo json_encode($out, JSON_UNESCAPED_SLASHES);
    exit;
  }
}