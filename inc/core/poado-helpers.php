<?php
declare(strict_types=1);

/**
 * /dashboard/inc/poado-helpers.php
 *
 * LOCKED helper layer:
 * - No session_start
 * - No redirects
 * - No DB calls
 * - No custom CSRF system
 * - Only constants + pure helper functions
 */

if (!defined('POADO_HELPERS_LOADED')) define('POADO_HELPERS_LOADED', 1);

/* =========================================================
 * BOOKING STATUS (LOCKED)
 * ========================================================= */
if (!defined('BOOKING_STATUS_PENDING'))    define('BOOKING_STATUS_PENDING',    'pending');
if (!defined('BOOKING_STATUS_ACCEPTED'))   define('BOOKING_STATUS_ACCEPTED',   'accepted');
if (!defined('BOOKING_STATUS_ASSIGNED'))   define('BOOKING_STATUS_ASSIGNED',   'assigned');
if (!defined('BOOKING_STATUS_CONFIRMED'))  define('BOOKING_STATUS_CONFIRMED',  'confirmed');
if (!defined('BOOKING_STATUS_CANCELLED'))  define('BOOKING_STATUS_CANCELLED',  'cancelled');

/* =========================================================
 * DEAL STATUS (LOCKED)
 * ========================================================= */
if (!defined('DEAL_STATUS_CREATED'))          define('DEAL_STATUS_CREATED',          'created');
if (!defined('DEAL_STATUS_PENDING_PAYMENT'))  define('DEAL_STATUS_PENDING_PAYMENT',  'pending_payment');
if (!defined('DEAL_STATUS_PAID'))             define('DEAL_STATUS_PAID',             'paid');
if (!defined('DEAL_STATUS_COMPLETED'))        define('DEAL_STATUS_COMPLETED',        'completed');
if (!defined('DEAL_STATUS_EXPIRED'))          define('DEAL_STATUS_EXPIRED',          'expired');
if (!defined('DEAL_STATUS_CANCELLED'))        define('DEAL_STATUS_CANCELLED',        'cancelled');

/* =========================================================
 * CSRF ACTION NAMES (LOCKED)
 * ========================================================= */
if (!defined('CSRF_BOOK_CREATE'))      define('CSRF_BOOK_CREATE',      'book_create');
if (!defined('CSRF_BOOK_ACCEPT'))      define('CSRF_BOOK_ACCEPT',      'book_accept');
if (!defined('CSRF_BOOK_UPDATE'))      define('CSRF_BOOK_UPDATE',      'book_update');
if (!defined('CSRF_BOOK_CANCEL'))      define('CSRF_BOOK_CANCEL',      'book_cancel');
if (!defined('CSRF_BOOK_ASSIGN_ACE'))  define('CSRF_BOOK_ASSIGN_ACE',  'book_assign_ace');

if (!defined('CSRF_DEAL_CREATE'))      define('CSRF_DEAL_CREATE',      'deal_create');
if (!defined('CSRF_DEAL_PAYMENT_INIT'))define('CSRF_DEAL_PAYMENT_INIT','deal_payment_init');
if (!defined('CSRF_DEAL_PAYMENT_CONFIRM')) define('CSRF_DEAL_PAYMENT_CONFIRM','deal_payment_confirm');
if (!defined('CSRF_DEAL_UPDATE'))      define('CSRF_DEAL_UPDATE',      'deal_update');
if (!defined('CSRF_DEAL_CANCEL'))      define('CSRF_DEAL_CANCEL',      'deal_cancel');

/* =========================================================
 * PURE HELPERS (NO DB / NO SESSION / NO REDIRECT)
 * ========================================================= */

/**
 * Booking datetime string (DB fields only).
 * Never use scheduled_at in this project.
 */
if (!function_exists('poado_booking_datetime')) {
  function poado_booking_datetime(array $b): string {
    $d = trim((string)($b['meeting_date'] ?? ''));
    $t = trim((string)($b['meeting_time'] ?? ''));
    return trim($d . ' ' . $t);
  }
}

/**
 * Booking geo display (country/state/area text fields).
 * state/area are optional globally.
 */
if (!function_exists('poado_booking_geo')) {
  function poado_booking_geo(array $b): string {
    $parts = array_filter([
      trim((string)($b['customer_country_name'] ?? '')),
      trim((string)($b['customer_state'] ?? '')),
      trim((string)($b['customer_area'] ?? '')),
    ], fn($x) => $x !== '');
    return implode(' / ', $parts);
  }
}

/**
 * Safe status list for "needs ACE action" views.
 * (helps avoid mismatch like booked vs pending vs accepted)
 */
if (!function_exists('poado_booking_actionable_statuses')) {
  function poado_booking_actionable_statuses(): array {
    return [BOOKING_STATUS_PENDING, BOOKING_STATUS_ACCEPTED, BOOKING_STATUS_ASSIGNED];
  }
}
