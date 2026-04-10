<?php
// /var/www/html/public/dashboard/inc/session-test.php
// v1.0.20260301
// Standalone tester for session-user.php (NO bootstrap, NO DB, NO redirects)

declare(strict_types=1);

require_once __DIR__ . '/session-user.php';
poado_session_start();

header('Content-Type: text/plain; charset=utf-8');

$wallet = poado_wallet_address();
$sid = session_id();

echo "POAdo Session Test (session-user.php)\n";
echo "-----------------------------------\n";
echo "HTTPS: " . (poado_is_https() ? "YES" : "NO") . "\n";
echo "Session ID: " . ($sid ?: "NONE") . "\n";
echo "Cookie Name: " . session_name() . "\n";

$params = session_get_cookie_params();
echo "Cookie Params:\n";
echo "  path     = " . ($params['path'] ?? '') . "\n";
echo "  domain   = " . ($params['domain'] ?? '') . "\n";
echo "  secure   = " . (!empty($params['secure']) ? "1" : "0") . "\n";
echo "  httponly = " . (!empty($params['httponly']) ? "1" : "0") . "\n";
echo "  samesite = " . ($params['samesite'] ?? '(n/a)') . "\n";
echo "\n";

echo "Wallet Detected: " . ($wallet ? "YES" : "NO") . "\n";
echo "Wallet: " . ($wallet ?: "N/A") . "\n\n";

echo "Session Keys Snapshot:\n";
$keys = array_keys($_SESSION ?? []);
sort($keys);
foreach ($keys as $k) {
  $v = $_SESSION[$k];
  $type = gettype($v);
  $preview = '';
  if (is_scalar($v)) {
    $preview = (string)$v;
    if (strlen($preview) > 120) $preview = substr($preview, 0, 120) . '...';
  } elseif (is_array($v)) {
    $preview = 'array(' . count($v) . ') keys=' . implode(',', array_slice(array_keys($v), 0, 12));
    if (count($v) > 12) $preview .= ',...';
  } else {
    $preview = $type;
  }
  echo "- {$k} ({$type}) {$preview}\n";
}

echo "\nActions:\n";
echo "- Append ?set_demo=1 to set a demo wallet_session\n";
echo "- Append ?clear=1 to clear session\n\n";

// Demo actions (optional)
if (isset($_GET['clear'])) {
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'] ?? '/dashboard');
  }
  session_destroy();
  echo ">> CLEARED session.\n";
  exit;
}

if (isset($_GET['set_demo'])) {
  $_SESSION['wallet_session'] = [
    'wallet_address' => '0xDEMO_WALLET_SESSION_TEST',
    'wallet' => '0xDEMO_WALLET_SESSION_TEST',
    'ts' => time(),
  ];
  echo ">> SET demo wallet_session.\n";
  echo "Reload page without params to verify detection.\n";
  exit;
}