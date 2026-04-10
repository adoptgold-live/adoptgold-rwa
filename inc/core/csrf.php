<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/inc/core/csrf.php
 * AdoptGold / POAdo — Canonical Scope-Persistent CSRF Helper
 * Version: v1.0.0-locked-20260318
 *
 * Master lock:
 * - scope-persistent tokens
 * - POST field name = csrf_token
 * - no overwrite across scopes
 */

if (!function_exists('poado_csrf_boot')) {
    function poado_csrf_boot(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        if (!isset($_SESSION['poado_csrf']) || !is_array($_SESSION['poado_csrf'])) {
            $_SESSION['poado_csrf'] = [];
        }
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(string $scope): string
    {
        $scope = trim($scope);
        if ($scope === '') {
            throw new InvalidArgumentException('CSRF_SCOPE_REQUIRED');
        }

        poado_csrf_boot();

        if (
            !isset($_SESSION['poado_csrf'][$scope]) ||
            !is_string($_SESSION['poado_csrf'][$scope]) ||
            $_SESSION['poado_csrf'][$scope] === ''
        ) {
            $_SESSION['poado_csrf'][$scope] = bin2hex(random_bytes(16));
        }

        return $_SESSION['poado_csrf'][$scope];
    }
}

if (!function_exists('csrf_value')) {
    function csrf_value(): string
    {
        poado_csrf_boot();

        if (isset($_POST['csrf_token']) && is_scalar($_POST['csrf_token'])) {
            return trim((string) $_POST['csrf_token']);
        }

        if (isset($_SERVER['HTTP_X_CSRF_TOKEN']) && is_scalar($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            return trim((string) $_SERVER['HTTP_X_CSRF_TOKEN']);
        }

        return '';
    }
}

if (!function_exists('csrf_check')) {
    function csrf_check(string $scope, ?string $token = null): bool
    {
        $scope = trim($scope);
        if ($scope === '') {
            return false;
        }

        poado_csrf_boot();

        $token = $token ?? csrf_value();
        if (!is_string($token) || $token === '') {
            return false;
        }

        $expected = $_SESSION['poado_csrf'][$scope] ?? '';
        if (!is_string($expected) || $expected === '') {
            return false;
        }

        return hash_equals($expected, $token);
    }
}