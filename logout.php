<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/logout.php
 * AdoptGold / POAdo — Standalone RWA Logout
 * Version: v1.0.0-locked-20260318
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

if (function_exists('session_user_clear')) {
    session_user_clear();
}

if (session_status() === PHP_SESSION_ACTIVE) {
    @session_regenerate_id(true);
}

header('Location: /rwa/index.php', true, 302);
exit;