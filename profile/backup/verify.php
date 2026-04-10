<?php
// /var/www/html/public/rwa/profile/verify.php
declare(strict_types=1);

require_once __DIR__ . '/../inc/core/bootstrap.php';
require_once __DIR__ . '/../inc/core/session-user.php';
require_once __DIR__ . '/../inc/rwa-session.php';

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$pdo = function_exists('rwa_db') ? rwa_db() : ($GLOBALS['pdo'] ?? null);
if (!$pdo instanceof PDO) {
    http_response_code(500);
    echo 'DB not ready';
    exit;
}

$token = trim((string)($_GET['token'] ?? ''));
$msg = '';
$msgType = 'warn';

if ($token === '') {
    $msg = 'Verification token missing.';
    $msgType = 'err';
} else {
    try {
        $st = $pdo->prepare("
            SELECT id, email, email_verified_at, verify_token
            FROM users
            WHERE verify_token = ?
            LIMIT 1
        ");
        $st->execute([$token]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $msg = 'Invalid or expired verification token.';
            $msgType = 'err';
        } elseif (!empty($row['email_verified_at'])) {
            $msg = 'Email already verified.';
            $msgType = 'ok';
        } else {
            $up = $pdo->prepare("
                UPDATE users
                SET email_verified_at = NOW(),
                    updated_at = NOW()
                WHERE id = ?
                LIMIT 1
            ");
            $up->execute([(int)$row['id']]);

            if (function_exists('session_user')) {
                $user = session_user();
                if (!empty($user['id']) && (int)$user['id'] === (int)$row['id']) {
                    $fresh = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
                    $fresh->execute([(int)$row['id']]);
                    $freshRow = $fresh->fetch(PDO::FETCH_ASSOC);
                    if ($freshRow && function_exists('session_user_set')) {
                        @session_user_set($freshRow);
                    } elseif ($freshRow) {
                        if (session_status() !== PHP_SESSION_ACTIVE) {
                            @session_start();
                        }
                        $_SESSION['session_user'] = $freshRow;
                        $_SESSION['user'] = $freshRow;
                    }
                }
            }

            $msg = 'Email verified successfully.';
            $msgType = 'ok';
        }
    } catch (Throwable $e) {
        $msg = 'Unable to verify email.';
        $msgType = 'err';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Email Verification Result · RWA</title>
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
.msg{margin-top:18px;padding:14px 14px;border-radius:14px;font-size:13px;line-height:1.45}
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
    <div class="h1">EMAIL VERIFICATION</div>
    <div class="sub" style="margin-top:8px;">This page confirms your standalone RWA profile email.</div>

    <div class="msg <?= h($msgType) ?>"><?= h($msg) ?></div>

    <div class="actions">
      <a href="/rwa/profile/index.php" class="btn gold">BACK TO PROFILE</a>
      <a href="/rwa/profile/verify-email.php" class="btn">VERIFY EMAIL PAGE</a>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../inc/rwa-bottom-nav.php'; ?>
</body>
</html>