<?php
declare(strict_types=1);

/**
 * /rwa/api/storage/_core.php
 * Storage API core bootstrap
 */

if (!function_exists('storage_assert_ready')) {
    function storage_assert_ready(): void
    {
        // ---- BASIC HARD GUARD ----
        if (!isset($_SERVER['REQUEST_METHOD'])) {
            http_response_code(400);
            exit('Invalid request context');
        }

        // ---- SESSION / AUTH ----
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        // Example: require login (adjust to your auth model)
        if (empty($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode([
                'ok' => false,
                'error' => 'AUTH_REQUIRED'
            ]);
            exit;
        }

        // ---- DB BOOTSTRAP ----
        if (!function_exists('rwa_db')) {
            require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/db.php';
        }

        // ---- OPTIONAL: ENV FLAGS ----
        // e.g. check maintenance mode, etc.

        // You can expand later safely
    }
}