<?php
declare(strict_types=1);

/**
 * AdoptGold RWA
 * Verify Email Page
 * File: /var/www/html/public/rwa/verify-email.php
 * Version: v1.0.20260315d
 */

require_once __DIR__ . '/inc/rwa-session.php';
require_once __DIR__ . '/inc/core/bootstrap.php';
require_once __DIR__ . '/inc/core/session-user.php';

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

if (!function_exists('db')) {
    http_response_code(500);
    echo 'DB bootstrap not ready.';
    exit;
}

$pdo = db();
if (!$pdo instanceof PDO) {
    http_response_code(500);
    echo 'DB not ready.';
    exit;
}

$userId = (int)(function_exists('session_user_id') ? session_user_id() : 0);
$sessionUser = function_exists('session_user') ? (session_user() ?: []) : [];

$token = trim((string)($_GET['token'] ?? ''));
$msg = '';
$msgType = 'warn';
$verifiedNow = false;

/**
 * Mode A
 * Verification link from email:
 * /rwa/verify-email.php?token=...
 */
if ($token !== '') {
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
                SET
                    email_verified_at = NOW(),
                    verify_token = NULL,
                    updated_at = NOW()
                WHERE id = ?
                LIMIT 1
            ");
            $up->execute([(int)$row['id']]);

            // Refresh session user if current logged-in user matches verified row
            if ($userId > 0 && (int)$row['id'] === $userId) {
                $fresh = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
                $fresh->execute([(int)$row['id']]);
                $freshRow = $fresh->fetch(PDO::FETCH_ASSOC);
                if ($freshRow) {
                    if (function_exists('session_user_set')) {
                        @session_user_set($freshRow);
                    } else {
                        if (session_status() !== PHP_SESSION_ACTIVE) {
                            @session_start();
                        }
                        $_SESSION['session_user'] = $freshRow;
                        $_SESSION['user'] = $freshRow;
                        $_SESSION['rwa_user'] = $freshRow;
                    }
                }
            }

            $verifiedNow = true;
            $msg = 'Email verified successfully.';
            $msgType = 'ok';
        }
    } catch (Throwable $e) {
        $msg = 'Unable to verify email.';
        $msgType = 'err';
    }
} else {
    /**
     * Mode B
     * Open page directly without token
     */
    if ($userId <= 0) {
        $msg = 'Verification token missing.';
        $msgType = 'err';
    } else {
        $email = trim((string)($sessionUser['email'] ?? ''));
        $isVerified = !empty($sessionUser['email_verified_at']);

        if ($email === '') {
            $msg = 'No email saved on your profile.';
            $msgType = 'warn';
        } elseif ($isVerified) {
            $msg = 'Email already verified.';
            $msgType = 'ok';
        } else {
            $msg = 'Open the verification link sent to your inbox, or return to Profile and resend verification email.';
            $msgType = 'warn';
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
  --bg:#070211;
  --bg2:#120723;
  --line:rgba(187,117,255,.24);
  --txt:#efe7ff;
  --muted:rgba(239,231,255,.68);
  --gold:#ffd86b;
  --green:#4cffb2;
  --red:#ff637e;
  --shadow:0 0 28px rgba(182,108,255,.12);
}
*{box-sizing:border-box}
html,body{
  margin:0;
  padding:0;
  background:
    radial-gradient(circle at top left, rgba(182,108,255,.14), transparent 28%),
    linear-gradient(180deg,#120723,#070211);
  color:var(--txt);
  font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
}
.wrap{
  max-width:760px;
  margin:0 auto;
  padding:16px 12px 100px;
}
.card{
  background:linear-gradient(180deg,rgba(18,10,34,.96),rgba(10,6,17,.96));
  border:1px solid var(--line);
  border-radius:22px;
  box-shadow:var(--shadow);
  padding:18px;
}
.h1{
  font-size:20px;
  font-weight:900;
  letter-spacing:.03em;
}
.sub{
  font-size:12px;
  color:var(--muted);
  line-height:1.5;
}
.pill{
  display:inline-flex;
  align-items:center;
  min-height:30px;
  padding:5px 11px;
  border-radius:999px;
  border:1px solid var(--line);
  font-size:11px;
  font-weight:900;
}
.pill.ok{
  border-color:rgba(76,255,178,.35);
  background:rgba(76,255,178,.08);
  color:var(--green);
}
.pill.warn{
  border-color:rgba(255,216,107,.35);
  background:rgba(255,216,107,.08);
  color:var(--gold);
}
.pill.err{
  border-color:rgba(255,99,126,.35);
  background:rgba(255,99,126,.08);
  color:var(--red);
}
.msg{
  margin-top:14px;
  padding:12px 13px;
  border-radius:14px;
  font-size:13px;
  line-height:1.5;
}
.msg.ok{
  border:1px solid rgba(76,255,178,.28);
  background:rgba(76,255,178,.07);
  color:#d6ffef;
}
.msg.warn{
  border:1px solid rgba(255,216,107,.28);
  background:rgba(255,216,107,.07);
  color:#fff2c7;
}
.msg.err{
  border:1px solid rgba(255,99,126,.28);
  background:rgba(255,99,126,.07);
  color:#ffdbe2;
}
.actions{
  display:flex;
  gap:10px;
  flex-wrap:wrap;
  margin-top:18px;
}
.btn{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-height:42px;
  padding:10px 14px;
  border-radius:14px;
  border:1px solid var(--line);
  background:rgba(182,108,255,.08);
  color:#fff;
  cursor:pointer;
  font:inherit;
  font-weight:900;
  text-decoration:none;
}
.btn.gold{
  border-color:rgba(255,216,107,.38);
  background:rgba(255,216,107,.10);
  color:var(--gold);
}
</style>
</head>
<body>
<?php require __DIR__ . '/inc/rwa-topbar-nav.php'; ?>

<div class="wrap">
  <div class="card">
    <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;">
      <div>
        <div class="h1">VERIFY EMAIL</div>
        <div class="sub" style="margin-top:8px;">
          This page confirms your standalone RWA profile email verification.
        </div>
      </div>

      <span class="pill <?= h($msgType) ?>">
        <?= $msgType === 'ok' ? 'VERIFIED' : ($msgType === 'err' ? 'ERROR' : 'PENDING') ?>
      </span>
    </div>

    <div class="msg <?= h($msgType) ?>"><?= h($msg) ?></div>

    <div class="actions">
      <a href="/rwa/profile/" class="btn gold">BACK TO PROFILE</a>
      <?php if ($verifiedNow): ?>
        <a href="/rwa/login-select.php" class="btn">GO TO RWA</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/inc/rwa-bottom-nav.php'; ?>
</body>
</html>