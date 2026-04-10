<?php
// /var/www/html/public/rwa/profile/verify-email.php
declare(strict_types=1);

require_once __DIR__ . '/../inc/core/bootstrap.php';
require_once __DIR__ . '/../inc/core/session-user.php';
require_once __DIR__ . '/../inc/rwa-session.php';

$user = session_user();
if (empty($user['id'])) {
    header('Location: /rwa/index.php');
    exit;
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function profile_csrf_token(): string {
    if (function_exists('csrf_token')) {
        return (string) csrf_token('rwa_profile_save');
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    if (empty($_SESSION['csrf_token_rwa_profile']) || !is_string($_SESSION['csrf_token_rwa_profile'])) {
        $_SESSION['csrf_token_rwa_profile'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf_token_rwa_profile'];
}

$email = trim((string)($user['email'] ?? ''));
$isVerified = !empty($user['email_verified_at']);
$csrf = profile_csrf_token();

$msg = '';
$msgType = 'warn';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedCsrf = trim((string)($_POST['csrf_token'] ?? ''));
    $validCsrf = false;

    if (function_exists('csrf_check')) {
        try {
            $validCsrf = (bool) csrf_check('rwa_profile_save', $postedCsrf);
        } catch (Throwable $e) {
            $validCsrf = false;
        }
    } else {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $sess = (string)($_SESSION['csrf_token_rwa_profile'] ?? '');
        $validCsrf = $sess !== '' && hash_equals($sess, $postedCsrf);
    }

    if (!$validCsrf) {
        $msg = 'Invalid CSRF token.';
        $msgType = 'err';
    } elseif ($isVerified) {
        $msg = 'Email already verified.';
        $msgType = 'ok';
    } else {
        $ch = curl_init('https://' . ($_SERVER['HTTP_HOST'] ?? 'adoptgold.app') . '/rwa/api/profile/save.php');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_POSTFIELDS => http_build_query([
                'csrf_token' => $csrf,
                'action' => 'resend_verification',
            ]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'X-Requested-With: XMLHttpRequest',
            ],
            CURLOPT_COOKIE => (string)($_SERVER['HTTP_COOKIE'] ?? ''),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $resp = curl_exec($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            $msg = 'Unable to resend verification email: ' . $curlErr;
            $msgType = 'err';
        } else {
            $json = json_decode((string)$resp, true);
            if (is_array($json) && !empty($json['ok'])) {
                $msg = (string)($json['msg'] ?? 'Verification email sent.');
                $msgType = 'ok';
            } else {
                $msg = is_array($json) ? (string)($json['error'] ?? 'Unable to resend verification email.') : 'Unexpected server response.';
                $msgType = 'err';
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Verify Email · RWA</title>
<link rel="stylesheet" href="/rwa/assets/css/rwa-design-system.css">
<style>
:root{
  --bg:#070211;--bg2:#120723;--line:rgba(187,117,255,.24);--txt:#efe7ff;--muted:rgba(239,231,255,.68);
  --purple:#b66cff;--gold:#ffd86b;--green:#4cffb2;--red:#ff637e;--shadow:0 0 28px rgba(182,108,255,.12);
}
*{box-sizing:border-box}
html,body{margin:0;padding:0;background:radial-gradient(circle at top left, rgba(182,108,255,.14), transparent 28%),linear-gradient(180deg,#120723,#070211);color:var(--txt);font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
.wrap{max-width:760px;margin:0 auto;padding:16px 12px 100px}
.card{background:linear-gradient(180deg,rgba(18,10,34,.96),rgba(10,6,17,.96));border:1px solid var(--line);border-radius:22px;box-shadow:var(--shadow);padding:18px}
.h1{font-size:20px;font-weight:900;letter-spacing:.03em}
.sub{font-size:12px;color:var(--muted);line-height:1.5}
.pill{display:inline-flex;align-items:center;min-height:30px;padding:5px 11px;border-radius:999px;border:1px solid var(--line);font-size:11px;font-weight:900}
.pill.ok{border-color:rgba(76,255,178,.35);background:rgba(76,255,178,.08);color:var(--green)}
.pill.warn{border-color:rgba(255,216,107,.35);background:rgba(255,216,107,.08);color:var(--gold)}
.msg{margin-top:14px;padding:11px 12px;border-radius:14px;font-size:12px;line-height:1.45}
.msg.ok{border:1px solid rgba(76,255,178,.28);background:rgba(76,255,178,.07);color:#d6ffef}
.msg.warn{border:1px solid rgba(255,216,107,.28);background:rgba(255,216,107,.07);color:#fff2c7}
.msg.err{border:1px solid rgba(255,99,126,.28);background:rgba(255,99,126,.07);color:#ffdbe2}
.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}
.btn{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:10px 14px;border-radius:14px;border:1px solid var(--line);background:rgba(182,108,255,.08);color:#fff;cursor:pointer;font:inherit;font-weight:900;text-decoration:none}
.btn.gold{border-color:rgba(255,216,107,.38);background:rgba(255,216,107,.10);color:var(--gold)}
</style>
</head>
<body>
<?php require __DIR__ . '/../inc/rwa-topbar-nav.php'; ?>

<div class="wrap">
  <div class="card">
    <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;">
      <div>
        <div class="h1">VERIFY EMAIL</div>
        <div class="sub" style="margin-top:8px;">
          Your profile email is used for notices and account recovery. Open the verification link from your inbox to complete verification.
        </div>
      </div>
      <span class="pill <?= $isVerified ? 'ok' : 'warn' ?>">
        <?= $isVerified ? 'VERIFIED' : 'PENDING' ?>
      </span>
    </div>

    <div class="sub" style="margin-top:18px;">
      Current email:
      <div style="margin-top:8px;color:#fff;font-size:16px;font-weight:800;word-break:break-all;">
        <?= h($email !== '' ? $email : 'No email saved') ?>
      </div>
    </div>

    <?php if ($msg !== ''): ?>
      <div class="msg <?= h($msgType) ?>"><?= h($msg) ?></div>
    <?php endif; ?>

    <div class="actions">
      <?php if (!$isVerified): ?>
        <form method="post" style="margin:0;">
          <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
          <button type="submit" class="btn gold">RESEND EMAIL VERIFY</button>
        </form>
      <?php endif; ?>

      <a href="/rwa/profile/index.php" class="btn">BACK TO PROFILE</a>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../inc/rwa-bottom-nav.php'; ?>
</body>
</html>