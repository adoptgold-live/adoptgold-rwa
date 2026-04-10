<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/storage/commit/tester.php
 * Storage Master v7.8
 * Commit Tester with full debug mode
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$user = null;
if (function_exists('rwa_session_user')) {
    try {
        $tmp = rwa_session_user();
        if (is_array($tmp) && !empty($tmp)) {
            $user = $tmp;
        }
    } catch (Throwable $e) {
    }
}

$csrfCommit = function_exists('csrf_token') ? csrf_token('storage_commit_emx') : '';
$walletAddress = (string)($user['wallet_address'] ?? '');
$userId = (string)($user['id'] ?? '0');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <title>Commit Tester</title>
  <style>
    :root{
      --bg:#06080a;
      --panel:#0b0f0d;
      --panel2:#101612;
      --line:rgba(125,255,159,.14);
      --text:#eff8f1;
      --muted:rgba(239,248,241,.68);
      --green:#7dff9f;
      --gold:#f6d768;
      --red:#ff8e7f;
    }
    *{box-sizing:border-box}
    body{
      margin:0;
      background:var(--bg);
      color:var(--text);
      font:16px/1.45 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
      padding:18px;
    }
    .wrap{
      max-width:1100px;
      margin:0 auto;
      display:grid;
      gap:16px;
    }
    .card{
      background:linear-gradient(180deg,var(--panel),var(--panel2));
      border:1px solid var(--line);
      border-radius:18px;
      padding:16px;
      box-shadow:0 0 0 1px rgba(125,255,159,.04) inset;
    }
    h1,h2{margin:0 0 10px}
    h1{font-size:26px;color:var(--gold)}
    h2{font-size:18px;color:var(--green)}
    .muted{color:var(--muted)}
    .grid{
      display:grid;
      grid-template-columns:1fr 1fr;
      gap:16px;
    }
    .field{display:grid;gap:6px;margin-bottom:12px}
    label{font-size:13px;color:var(--muted)}
    input,textarea{
      width:100%;
      background:#050706;
      color:var(--text);
      border:1px solid var(--line);
      border-radius:12px;
      padding:12px;
      font:14px/1.4 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
      outline:none;
    }
    textarea{min-height:140px;resize:vertical}
    .row{display:flex;flex-wrap:wrap;gap:10px}
    button{
      min-height:42px;
      padding:0 14px;
      border-radius:12px;
      border:1px solid var(--line);
      background:#121814;
      color:var(--text);
      cursor:pointer;
      font:600 14px ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
    }
    button.primary{border-color:rgba(246,215,104,.35);color:var(--gold)}
    button.good{border-color:rgba(125,255,159,.28);color:var(--green)}
    button.danger{border-color:rgba(255,142,127,.35);color:var(--red)}
    pre{
      margin:0;
      white-space:pre-wrap;
      word-break:break-word;
      background:#050706;
      border:1px solid var(--line);
      border-radius:12px;
      padding:12px;
      min-height:160px;
      overflow:auto;
    }
    .ok{color:var(--green)}
    .bad{color:var(--red)}
    .kv{display:grid;grid-template-columns:180px 1fr;gap:8px}
    .small{font-size:12px}
    @media (max-width: 900px){
      .grid{grid-template-columns:1fr}
      .kv{grid-template-columns:1fr}
    }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <h1>Commit Tester</h1>
      <div class="muted">Direct debug page for <code>/rwa/api/storage/commit.php</code></div>
    </div>

    <div class="card">
      <h2>Session Snapshot</h2>
      <div class="kv small">
        <div>User ID</div><div id="dbgUserId"><?= h($userId) ?></div>
        <div>Wallet Address</div><div id="dbgWallet"><?= h($walletAddress ?: '-') ?></div>
        <div>CSRF</div><div id="dbgCsrf"><?= h($csrfCommit ?: '-') ?></div>
      </div>
    </div>

    <div class="grid">
      <div class="card">
        <h2>Prepare</h2>

        <div class="field">
          <label for="amount">Amount EMX</label>
          <input id="amount" type="text" value="1.000000" autocomplete="off">
        </div>

        <div class="field">
          <label for="csrf">CSRF</label>
          <input id="csrf" type="text" value="<?= h($csrfCommit) ?>" autocomplete="off">
        </div>

        <div class="row">
          <button id="btnPrepare" class="primary">PREPARE</button>
          <button id="btnCopyPrepareCurl">COPY PREPARE CURL</button>
        </div>
      </div>

      <div class="card">
        <h2>Verify</h2>

        <div class="field">
          <label for="commitRef">Commit Ref</label>
          <input id="commitRef" type="text" value="" autocomplete="off">
        </div>

        <div class="field">
          <label for="txHash">Optional TX Hash</label>
          <input id="txHash" type="text" value="" autocomplete="off">
        </div>

        <div class="row">
          <button id="btnVerify" class="good">VERIFY</button>
          <button id="btnAutoVerify">AUTO VERIFY 5s</button>
          <button id="btnStopAutoVerify" class="danger">STOP</button>
          <button id="btnCopyVerifyCurl">COPY VERIFY CURL</button>
        </div>
      </div>
    </div>

    <div class="grid">
      <div class="card">
        <h2>Request Payload</h2>
        <pre id="reqView"></pre>
      </div>
      <div class="card">
        <h2>Response</h2>
        <pre id="resView"></pre>
        <div class="row" style="margin-top:12px">
          <button id="btnCopyJson">COPY JSON</button>
          <button id="btnCopyRaw">COPY RAW</button>
        </div>
      </div>
    </div>

    <div class="card">
      <h2>Log</h2>
      <pre id="logView"></pre>
    </div>
  </div>

  <script src="/rwa/storage/commit/tester.js?v=1"></script>
</body>
</html>