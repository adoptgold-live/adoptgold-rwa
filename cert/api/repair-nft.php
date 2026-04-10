<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/api/repair-nft.php
 * Version: v4.1.0-20260408-wrapper
 *
 * Thin web wrapper only.
 * Shared logic lives in /rwa/cert/lib/repair-nft-core.php
 */

require_once dirname(__DIR__) . "/lib/repair-nft-core.php";
rr_handle_request();
