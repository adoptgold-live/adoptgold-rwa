<?php
declare(strict_types=1);

/**
 * Dashboard config (locked)
 * Database: wems_db
 * User: wems_user
 * Pass: Tiger7709304653!
 */

if (!defined('DB_HOST'))    define('DB_HOST', '127.0.0.1');
if (!defined('DB_NAME'))    define('DB_NAME', 'wems_db');
if (!defined('DB_USER'))    define('DB_USER', 'wems_user');

/**
 * IMPORTANT: password MUST be quoted and includes "!"
 */
if (!defined('DB_PASS'))    define('DB_PASS', 'Tiger7709304653!');

if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

if (!defined('SESSION_NAME')) define('SESSION_NAME', 'poado_sess');

// change if needed
if (!defined('BASE_URL')) define('BASE_URL', '/dashboard');
