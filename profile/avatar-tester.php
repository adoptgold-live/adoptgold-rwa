<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/avatar.php';

if (!function_exists('session_user_id') || (int) session_user_id() <= 0) {
    header('Location: /rwa/?m=login_required');
    exit;
}

$sessionUser = function_exists('session_user') && is_array(session_user()) ? session_user() : [];
$sessionWallet = (string)($sessionUser['wallet_address'] ?? $sessionUser['wallet'] ?? '');
$sessionNickname = (string)($sessionUser['nickname'] ?? 'User');

$walletInput = trim((string)($_GET['wallet'] ?? ''));
$nickInput   = trim((string)($_GET['nick'] ?? ''));

$demoWalletA = $walletInput !== '' ? $walletInput : $sessionWallet;
$demoNickA   = $nickInput !== '' ? $nickInput : $sessionNickname;

$demoWalletB = 'UQBdYfGArtoCUmBs5TjYQtfPFfQuGC2Ydbj2pQr3zIlNrDta';
$demoNickB   = 'Hari';

$serverAvatarSession = rwa_avatar_src($sessionWallet, $sessionNickname);
$serverAvatarManual  = rwa_avatar_src($demoWalletA, $demoNickA);
$serverAvatarBlank   = rwa_avatar_src('', $demoNickA ?: 'User');
$serverAvatarDemoB   = rwa_avatar_src($demoWalletB, $demoNickB);

function e(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>RWA Avatar Tester</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#0b0613">
  <style>
    :root{
      --line:rgba(173,112,255,.26);
      --text:#f5ecff;
      --muted:#bca8df;
      --soft:#8c77af;
      --ok:#22c55e;
      --warn:#f59e0b;
      --bad:#ef4444;
      --shadow:0 0 0 1px rgba(176,108,255,.10), 0 10px 32px rgba(0,0,0,.34), 0 0 32px rgba(124,77,255,.10);
      --radius:18px;
    }
    *{box-sizing:border-box}
    html,body{
      margin:0;padding:0;min-height:100%;
      background:
        radial-gradient(circle at top, rgba(124,77,255,.10), transparent 28%),
        linear-gradient(180deg, #09050f 0%, #0b0613 45%, #0a0613 100%);
      color:var(--text);
      font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
    }
    body{padding:18px}
    .page{width:min(1120px,100%);margin:0 auto}
    .hero,.card{
      border:1px solid var(--line);
      border-radius:var(--radius);
      background:linear-gradient(180deg, rgba(18,13,31,.96), rgba(12,8,20,.97));
      box-shadow:var(--shadow);
    }
    .hero{padding:16px 18px;margin-bottom:16px}
    .hero h1{margin:0 0 8px;font-size:18px}
    .hero p{margin:0;color:var(--muted);font-size:12px;line-height:1.5}
    .grid{
      display:grid;
      grid-template-columns:repeat(2,minmax(0,1fr));
      gap:16px;
    }
    .card{padding:16px}
    .title{font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#fff;margin-bottom:10px}
    .meta{font-size:12px;color:var(--muted);line-height:1.6;word-break:break-word}
    .avatar-wrap{
      width:112px;height:112px;border-radius:26px;overflow:hidden;
      border:1px solid rgba(173,112,255,.22);
      background:#f7f3ff;
      display:flex;align-items:center;justify-content:center;
      box-shadow:0 0 0 1px rgba(176,108,255,.08), 0 8px 24px rgba(0,0,0,.28);
      margin-bottom:14px;
    }
    .avatar-wrap img{
      width:100%;height:100%;object-fit:cover;display:block;background:#f7f3ff;
    }
    .code{
      margin-top:10px;
      padding:10px 12px;
      border-radius:12px;
      background:#0d0915;
      border:1px solid rgba(173,112,255,.18);
      font-size:11px;
      color:#d8cbf3;
      overflow:auto;
      word-break:break-all;
    }
    .form{
      display:grid;gap:12px;margin-bottom:16px;
      border:1px solid rgba(173,112,255,.18);
      border-radius:16px;padding:14px;background:#100b18;
    }
    .row{display:grid;grid-template-columns:1fr 1fr auto;gap:10px}
    input,button{
      min-height:48px;border-radius:12px;font:inherit;
    }
    input{
      width:100%;padding:0 14px;border:1px solid rgba(173,112,255,.20);
      background:#0d0915;color:#fff;outline:none;
    }
    button{
      padding:0 16px;border:none;cursor:pointer;color:#fff;font-weight:700;
      background:linear-gradient(180deg, #b06cff, #7d4dff);
    }
    .badge{
      display:inline-flex;align-items:center;gap:8px;min-height:28px;padding:0 10px;border-radius:999px;font-size:11px;
      border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.03);color:#e9defd;
      margin-top:8px;
    }
    .dot{width:8px;height:8px;border-radius:50%;background:var(--ok);box-shadow:0 0 10px currentColor}
    .span-2{grid-column:1/-1}
    @media (max-width:860px){
      .grid{grid-template-columns:1fr}
      .row{grid-template-columns:1fr}
    }
  </style>
</head>
<body>
<div class="page">
  <div class="hero">
    <h1>RWA Avatar Helper Tester</h1>
    <p>
      This page validates <code>/rwa/inc/core/avatar.php</code> first-paint rendering and helper output.
      Session wallet should show identicon immediately. Empty wallet should fall back to nickname placeholder.
    </p>
    <div class="badge"><span class="dot"></span><span>Helper loaded</span></div>
  </div>

  <form class="form" method="get" action="">
    <div class="row">
      <input type="text" name="wallet" value="<?php echo e($walletInput); ?>" placeholder="Test TON wallet address">
      <input type="text" name="nick" value="<?php echo e($nickInput); ?>" placeholder="Test nickname fallback">
      <button type="submit">Test</button>
    </div>
  </form>

  <div class="grid">
    <div class="card">
      <div class="title">1. Session wallet first paint</div>
      <div class="avatar-wrap">
        <img src="<?php echo e($serverAvatarSession); ?>" alt="Session avatar">
      </div>
      <div class="meta">
        Session nickname: <?php echo e($sessionNickname !== '' ? $sessionNickname : '—'); ?><br>
        Session wallet: <?php echo e($sessionWallet !== '' ? $sessionWallet : '—'); ?>
      </div>
      <div class="code"><?php echo e($serverAvatarSession); ?></div>
    </div>

    <div class="card">
      <div class="title">2. Manual wallet / nickname test</div>
      <div class="avatar-wrap">
        <img src="<?php echo e($serverAvatarManual); ?>" alt="Manual avatar">
      </div>
      <div class="meta">
        Input nickname: <?php echo e($demoNickA !== '' ? $demoNickA : '—'); ?><br>
        Input wallet: <?php echo e($demoWalletA !== '' ? $demoWalletA : '—'); ?>
      </div>
      <div class="code"><?php echo e($serverAvatarManual); ?></div>
    </div>

    <div class="card">
      <div class="title">3. Placeholder fallback only</div>
      <div class="avatar-wrap">
        <img src="<?php echo e($serverAvatarBlank); ?>" alt="Placeholder avatar">
      </div>
      <div class="meta">
        Wallet forced blank.<br>
        Placeholder nickname source: <?php echo e($demoNickA !== '' ? $demoNickA : 'User'); ?>
      </div>
      <div class="code"><?php echo e($serverAvatarBlank); ?></div>
    </div>

    <div class="card">
      <div class="title">4. Fixed demo wallet identicon</div>
      <div class="avatar-wrap">
        <img src="<?php echo e($serverAvatarDemoB); ?>" alt="Demo wallet avatar">
      </div>
      <div class="meta">
        Demo nickname: <?php echo e($demoNickB); ?><br>
        Demo wallet: <?php echo e($demoWalletB); ?>
      </div>
      <div class="code"><?php echo e($serverAvatarDemoB); ?></div>
    </div>

    <div class="card span-2">
      <div class="title">5. Helper direct render test</div>
      <div style="display:flex;gap:18px;align-items:center;flex-wrap:wrap">
        <div class="avatar-wrap" style="width:88px;height:88px;border-radius:22px;margin:0">
          <?php echo rwa_avatar_img($sessionWallet, $sessionNickname, 'Direct helper avatar', '', ['loading' => 'eager', 'decoding' => 'sync']); ?>
        </div>
        <div class="meta">
          This block uses <code>rwa_avatar_img()</code> directly, not a manual <code>&lt;img src&gt;</code> string.
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>