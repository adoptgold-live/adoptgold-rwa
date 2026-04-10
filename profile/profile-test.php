<?php
// /var/www/html/public/rwa/profile/profile-test.php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';

$user = session_user();
if (empty($user['id'])) {
    header('Location: /rwa/index.php');
    exit;
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function csrf_profile_token(): string {
    if (function_exists('csrf_token')) {
        return (string) csrf_token('rwa_profile_save');
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    if (empty($_SESSION['csrf_token_rwa_profile']) || !is_string($_SESSION['csrf_token_rwa_profile'])) {
        $_SESSION['csrf_token_rwa_profile'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token_rwa_profile'];
}

$pdo = function_exists('rwa_db') ? rwa_db() : ($GLOBALS['pdo'] ?? null);
$countries = [];
if ($pdo instanceof PDO) {
    try {
        $countries = $pdo->query("
            SELECT iso2, name_en, calling_code, flag_png
            FROM countries
            WHERE is_enabled = 1 AND iso2 <> 'IL'
            ORDER BY
              CASE
                WHEN iso2='MY' THEN 0
                WHEN iso2='CN' THEN 1
                WHEN iso2 IN ('SG','TH','ID','VN','PH','BN','KH','LA','MM','TL') THEN 2
                WHEN iso2 IN ('HK','TW','JP','KR','IN') THEN 3
                ELSE 4
              END,
              COALESCE(sort_order,999),
              name_en ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $countries = [];
    }
}

$defaults = [
    'nickname' => (string)($user['nickname'] ?? ''),
    'email' => (string)($user['email'] ?? ''),
    'prefix_iso2' => 'MY',
    'mobile' => preg_replace('/\D+/', '', (string)($user['mobile'] ?? '')),
    'country_iso2' => strtoupper((string)($user['country_code'] ?? 'MY')),
    'state_id' => '',
    'area_id' => '',
];

$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted = [
        'csrf_token'   => (string)($_POST['csrf_token'] ?? ''),
        'nickname'     => trim((string)($_POST['nickname'] ?? '')),
        'email'        => trim((string)($_POST['email'] ?? '')),
        'prefix_iso2'  => strtoupper(trim((string)($_POST['prefix_iso2'] ?? ''))),
        'mobile'       => preg_replace('/\D+/', '', (string)($_POST['mobile'] ?? '')),
        'country_iso2' => strtoupper(trim((string)($_POST['country_iso2'] ?? ''))),
        'state_id'     => trim((string)($_POST['state_id'] ?? '')),
        'area_id'      => trim((string)($_POST['area_id'] ?? '')),
    ];

    $callingCode = '';
    foreach ($countries as $c) {
        if (strtoupper((string)$c['iso2']) === $posted['prefix_iso2']) {
            $callingCode = preg_replace('/\D+/', '', (string)$c['calling_code']);
            break;
        }
    }
    $posted['mobile_e164'] = $callingCode . $posted['mobile'];

    $endpoint = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'adoptgold.app') . '/rwa/api/profile/save.php';

    $cookie = '';
    if (!empty($_SERVER['HTTP_COOKIE'])) {
        $cookie = (string)$_SERVER['HTTP_COOKIE'];
    }

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_POSTFIELDS => http_build_query($posted),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'X-Requested-With: XMLHttpRequest',
        ],
        CURLOPT_COOKIE => $cookie,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    $raw = curl_exec($ch);
    $curlErr = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $respHeaders = '';
    $respBody = '';
    if (is_string($raw)) {
        $respHeaders = substr($raw, 0, $headerSize);
        $respBody = substr($raw, $headerSize);
    }

    $json = null;
    $jsonErr = null;
    if ($respBody !== '') {
        $json = json_decode($respBody, true);
        if (!is_array($json)) {
            $jsonErr = json_last_error_msg();
        }
    }

    $result = [
        'endpoint' => $endpoint,
        'posted' => $posted,
        'http_status' => $status,
        'curl_error' => $curlErr,
        'response_headers' => $respHeaders,
        'response_body' => $respBody,
        'json' => $json,
        'json_error' => $jsonErr,
    ];

    $defaults = array_merge($defaults, $posted);
}

$csrf = csrf_profile_token();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Profile Save Test</title>
<style>
body{background:#050308;color:#f3ecff;font-family:ui-monospace,Menlo,Consolas,monospace;margin:0;padding:20px}
.wrap{max-width:1100px;margin:0 auto}
.card{border:1px solid rgba(182,108,255,.28);border-radius:18px;padding:16px;margin-bottom:16px;background:#11071d}
h1{font-size:20px;margin:0 0 12px}
h2{font-size:14px;margin:0 0 10px;color:#ffd86b}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.row3{display:grid;grid-template-columns:120px 220px 1fr;gap:12px}
input,select,button,textarea{width:100%;min-height:42px;border-radius:12px;border:1px solid rgba(255,216,107,.22);background:#050308;color:#fff;padding:10px 12px;font:inherit}
button{cursor:pointer;background:rgba(255,216,107,.10);color:#ffd86b;font-weight:700}
pre{white-space:pre-wrap;word-break:break-word;background:#050308;border:1px solid rgba(255,255,255,.08);padding:12px;border-radius:12px}
.small{font-size:12px;color:#bdb2d7}
.ok{color:#4cffb2}
.bad{color:#ff7f9a}
@media (max-width:800px){
  .grid,.row3{grid-template-columns:1fr}
}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>Profile Save Test</h1>
    <div class="small">This page posts directly to <code>/rwa/api/profile/save.php</code> using your current session cookie and shows the raw response for debugging.</div>
  </div>

  <div class="card">
    <h2>Session Snapshot</h2>
    <pre><?php
echo h(json_encode([
    'user_id' => $user['id'] ?? null,
    'nickname' => $user['nickname'] ?? null,
    'email' => $user['email'] ?? null,
    'wallet_address' => $user['wallet_address'] ?? null,
    'country_code' => $user['country_code'] ?? null,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
?></pre>
  </div>

  <div class="card">
    <h2>Send Test Save</h2>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

      <div class="grid">
        <div>
          <div class="small">Nickname</div>
          <input name="nickname" value="<?= h((string)$defaults['nickname']) ?>">
        </div>
        <div>
          <div class="small">Email</div>
          <input name="email" value="<?= h((string)$defaults['email']) ?>">
        </div>
      </div>

      <div style="height:12px"></div>

      <div class="row3">
        <div>
          <div class="small">Flag</div>
          <div style="height:42px;display:flex;align-items:center;justify-content:center;border:1px solid rgba(255,216,107,.22);border-radius:12px;background:#050308">
            <?php
            $flag = '/dashboard/assets/flags/' . strtolower((string)$defaults['prefix_iso2']) . '.png';
            ?>
            <img src="<?= h($flag) ?>" alt="" style="width:28px;height:20px;object-fit:cover;border-radius:4px">
          </div>
        </div>
        <div>
          <div class="small">Prefix</div>
          <select name="prefix_iso2">
            <?php foreach ($countries as $c): ?>
              <?php $iso = strtoupper((string)$c['iso2']); ?>
              <option value="<?= h($iso) ?>" <?= $iso === (string)$defaults['prefix_iso2'] ? 'selected' : '' ?>>
                +<?= h((string)$c['calling_code']) ?> · <?= h((string)$c['name_en']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <div class="small">Mobile</div>
          <input name="mobile" value="<?= h((string)$defaults['mobile']) ?>">
        </div>
      </div>

      <div style="height:12px"></div>

      <div class="grid">
        <div>
          <div class="small">Country</div>
          <select name="country_iso2">
            <?php foreach ($countries as $c): ?>
              <?php $iso = strtoupper((string)$c['iso2']); ?>
              <option value="<?= h($iso) ?>" <?= $iso === (string)$defaults['country_iso2'] ? 'selected' : '' ?>>
                <?= h((string)$c['name_en']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <div class="small">State ID</div>
          <input name="state_id" value="<?= h((string)$defaults['state_id']) ?>" placeholder="optional numeric id">
        </div>
      </div>

      <div style="height:12px"></div>

      <div class="grid">
        <div>
          <div class="small">Area ID</div>
          <input name="area_id" value="<?= h((string)$defaults['area_id']) ?>" placeholder="optional numeric id">
        </div>
        <div style="display:flex;align-items:end">
          <button type="submit">POST TO SAVE API</button>
        </div>
      </div>
    </form>
  </div>

  <?php if ($result): ?>
    <div class="card">
      <h2>Result Summary</h2>
      <pre><?php
echo h(json_encode([
    'endpoint' => $result['endpoint'],
    'http_status' => $result['http_status'],
    'curl_error' => $result['curl_error'],
    'json_ok' => is_array($result['json']) ? ($result['json']['ok'] ?? null) : null,
    'json_error' => $result['json_error'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
?></pre>
    </div>

    <div class="card">
      <h2>Posted Payload</h2>
      <pre><?= h(json_encode($result['posted'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
    </div>

    <div class="card">
      <h2>Response Headers</h2>
      <pre><?= h($result['response_headers']) ?></pre>
    </div>

    <div class="card">
      <h2>Response Body</h2>
      <pre><?= h($result['response_body']) ?></pre>
    </div>

    <div class="card">
      <h2>Parsed JSON</h2>
      <pre><?= h(json_encode($result['json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
    </div>
  <?php endif; ?>
</div>
</body>
</html>