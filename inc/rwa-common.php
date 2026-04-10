<?php
// /var/www/html/public/rwa/inc/rwa-common.php
// Canonical include normalizer for ALL RWA standalone pages.
// Rules:
// - Never use fragile ../../../../ paths.
// - Compute absolute filesystem roots once.
// - Bootstrap + session-user OK here (no HTML output).
// - GT include must be called explicitly (because it outputs HTML).

declare(strict_types=1);

if (!defined('POADO_PUBLIC_ROOT')) {
    define('POADO_PUBLIC_ROOT', dirname(__DIR__, 2));                 // /var/www/html/public
    define('POADO_RWA_ROOT', POADO_PUBLIC_ROOT . '/rwa');            // /var/www/html/public/rwa
    define('POADO_RWA_INC', POADO_RWA_ROOT . '/inc');                // /var/www/html/public/rwa/inc
    define('POADO_DASH_ROOT', POADO_PUBLIC_ROOT . '/dashboard');     // /var/www/html/public/dashboard
    define('POADO_DASH_INC', POADO_DASH_ROOT . '/inc');              // /var/www/html/public/dashboard/inc
}

// ---- Hard requirements (filesystem absolute paths) ----
require_once POADO_DASH_INC . '/bootstrap.php';
require_once POADO_DASH_INC . '/session-user.php';

// ---- Project locks ----
const POADO_LOCKED_FOOTER =
    '© 2025 Blockchain Group Ltd. (Hong Kong) · RWA Standard Organisation (RSO). All rights reserved.';

// ---- Helpers ----
function rwa_render_gt(): void {
    // GT outputs UI (bottom-left). Include only after page HTML begins.
    require_once POADO_DASH_INC . '/gt.php';
}

function rwa_footer_html(): string {
    // Locked footer string required globally.
    return '<div class="poado-footer" style="opacity:.85;margin-top:18px;font-size:12px;">'
        . htmlspecialchars(POADO_LOCKED_FOOTER, ENT_QUOTES, 'UTF-8')
        . '</div>';
}

/**
 * Return wallet from session in a consistent way.
 * - TG session format: "tg:<tg_id>"
 * - TON/Web3 (later) can store as "ton:<addr>" or raw, but this function is the single read.
 */
function rwa_session_wallet(): string {
    // session-user.php in your stack typically exposes helper(s); but we stay defensive.
    if (!empty($_SESSION['wallet']) && is_string($_SESSION['wallet'])) return $_SESSION['wallet'];
    if (!empty($_SESSION['wallet_address']) && is_string($_SESSION['wallet_address'])) return $_SESSION['wallet_address'];
    return '';
}

/**
 * Standalone guard: NO redirects to /dashboard/* ever.
 * If not logged in, redirect only inside /rwa/.
 */
function rwa_require_login(string $redirect_to = '/rwa/login-select.php'): void {
    $w = rwa_session_wallet();
    if ($w === '') {
        header('Location: ' . $redirect_to);
        exit;
    }
}