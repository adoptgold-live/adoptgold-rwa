<?php
declare(strict_types=1);

/**
 * /rwa/inc/core/error-assert.php
 * Standalone RWA Assert Helper
 * Version: v1.0.20260314.1
 */

require_once __DIR__ . '/error.php';

if (!function_exists('poado_assert')) {
    function poado_assert(
        bool $cond,
        string $module,
        string $code,
        array $ctx = [],
        string $public_hint = '',
        string $severity = 'error'
    ): void {
        if ($cond) {
            return;
        }

        poado_error($module, $code, $ctx, $public_hint, $severity);
    }
}

/**
 * Boolean-return helper for places where code wants:
 * if (!poado_assert_ok(...)) { return; }
 */
if (!function_exists('poado_assert_ok')) {
    function poado_assert_ok(
        bool $cond,
        string $module,
        string $code,
        array $ctx = [],
        string $public_hint = '',
        string $severity = 'error'
    ): bool {
        if ($cond) {
            return true;
        }

        poado_error($module, $code, $ctx, $public_hint, $severity);
        return false;
    }
}