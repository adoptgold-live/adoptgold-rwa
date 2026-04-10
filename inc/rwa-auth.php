<?php
// /rwa/inc/rwa-auth.php
declare(strict_types=1);

require_once __DIR__ . '/rwa-session.php';

function rwa_require_login(string $redirectTo = '/rwa/index.php'): void {
  if (!rwa_is_authed()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Location: ' . $redirectTo, true, 302);
    exit;
  }
}