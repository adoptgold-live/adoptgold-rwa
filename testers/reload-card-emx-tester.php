<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

if (is_file($_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/csrf.php')) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/csrf.php';
}

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function tester_file_health(string $absPath): array
{
    $exists = is_file($absPath);
    return [
        'path'        => $absPath,
        'exists'      => $exists,
        'readable'    => $exists ? is_readable($absPath) : false,
        'size'        => $exists ? (int)@filesize($absPath) : 0,
        'modified_at' => $exists ? @date('Y-m-d H:i:s', (int)@filemtime($absPath)) : '',
        'sha1'        => $exists ? (string)@sha1_file($absPath) : '',
    ];
}

function tester_pdo(): ?PDO
{
    try {
        if (($GLOBALS['pdo'] ?? null) instanceof PDO) {
            return $GLOBALS['pdo'];
        }
        if (function_exists('poado_pdo')) {
            $pdo = poado_pdo();
            if ($pdo instanceof PDO) {
                $GLOBALS['pdo'] = $pdo;
                return $pdo;
            }
        }
        if (function_exists('db_connect')) {
            db_connect();
            if (($GLOBALS['pdo'] ?? null) instanceof PDO) {
                return $GLOBALS['pdo'];
            }
        }
        if (function_exists('db')) {
            $pdo = db();
            if ($pdo instanceof PDO) {
                $GLOBALS['pdo'] = $pdo;
                return $pdo;
            }
        }
    } catch (Throwable $e) {
        return null;
    }
    return null;
}

function tester_bound_storage_card(int $userId): array
{
    $out = [
        'found'             => false,
        'card_number'       => '',
        'bound_ton_address' => '',
        'is_active'         => 0,
        'updated_at'        => '',
    ];

    if ($userId <= 0) {
        return $out;
    }

    $pdo = tester_pdo();
    if (!$pdo instanceof PDO) {
        return $out;
    }

    try {
        $st = $pdo->prepare("
            SELECT user_id, card_number, bound_ton_address, is_active, updated_at
            FROM rwa_storage_cards
            WHERE user_id = :uid
            LIMIT 1
        ");
        $st->execute([':uid' => $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (is_array($row) && $row) {
            $out['found'] = true;
            $out['card_number'] = (string)($row['card_number'] ?? '');
            $out['bound_ton_address'] = (string)($row['bound_ton_address'] ?? '');
            $out['is_active'] = (int)($row['is_active'] ?? 0);
            $out['updated_at'] = (string)($row['updated_at'] ?? '');
        }
    } catch (Throwable $e) {
        return $out;
    }

    return $out;
}

function tester_reload_csrf(): string
{
    if (!function_exists('csrf_token')) {
        return '';
    }

    $candidates = [
        'storage_reload_card_emx',
        'reload_card_emx',
        'storage_reload',
    ];

    foreach ($candidates as $name) {
        try {
            $t = (string)csrf_token($name);
            if ($t !== '') {
                return $t;
            }
        } catch (Throwable $e) {
        }
    }

    return '';
}

$user = function_exists('session_user') ? session_user() : null;
if (!is_array($user)) {
    $user = [];
}

$userId        = (int)($user['id'] ?? 0);
$wallet        = (string)($user['wallet'] ?? '');
$walletAddress = (string)($user['wallet_address'] ?? '');
$nickname      = (string)($user['nickname'] ?? '');
$email         = (string)($user['email'] ?? '');
$loggedIn      = ($userId > 0);
$csrfReload    = tester_reload_csrf();
$boundCard     = tester_bound_storage_card($userId);

$root = $_SERVER['DOCUMENT_ROOT'] ?: '/var/www/html/public';

$coreFiles = [
    '/rwa/inc/core/onchain-verify.php',
    '/rwa/inc/core/bootstrap.php',
    '/rwa/inc/core/session-user.php',
    '/rwa/inc/core/csrf.php',
    '/rwa/inc/core/qr.php',
    '/rwa/api/storage/_bootstrap.php',
    '/rwa/api/storage/reload-card-emx.php',
    '/rwa/api/storage/reload-card-emx-verify.php',
    '/rwa/api/storage/reload-card-emx/_bootstrap.php',
    '/rwa/storage/index.php',
    '/rwa/storage/reload-card-emx/helper.js',
    '/rwa/storage/reload-card-emx/style.css',
];

$fileHealth = [];
foreach ($coreFiles as $rel) {
    $fileHealth[$rel] = tester_file_health($root . $rel);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <title>Reload Card EMX Tester — Onchain Verify</title>
  <style>
    :root{
      --bg:#05070a;
      --panel:#0c1117;
      --line:rgba(255,255,255,.08);
      --text:#f8fafc;
      --muted:rgba(248,250,252,.68);
      --gold:#f6d768;
      --green:#5dffb3;
      --red:#ff8e8e;
      --blue:#7bc7ff;
      --warn:#ffd27a;
    }
    *{box-sizing:border-box}
    body{
      margin:0;
      background:var(--bg);
      color:var(--text);
      font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
      padding:16px;
    }
    .wrap{max-width:1480px;margin:0 auto;display:grid;gap:16px}
    .card{
      background:linear-gradient(180deg,rgba(255,255,255,.03),rgba(255,255,255,.02));
      border:1px solid var(--line);
      border-radius:20px;
      padding:16px;
    }
    .title{
      margin:0 0 6px;
      color:var(--gold);
      font-size:28px;
      line-height:1.1;
      font-weight:800;
    }
    .sub{margin:0;color:var(--muted)}
    .grid{
      display:grid;
      grid-template-columns:repeat(2,minmax(0,1fr));
      gap:16px;
    }
    .shell{
      display:grid;
      grid-template-columns:minmax(360px,1fr) minmax(420px,560px);
      gap:16px;
      min-height:760px;
    }
    .left,.right{
      display:grid;
      gap:14px;
      min-height:0;
    }
    .row{
      display:grid;
      grid-template-columns:240px 1fr;
      gap:10px;
      padding:8px 0;
      border-bottom:1px solid rgba(255,255,255,.05);
    }
    .k{color:var(--muted)}
    .v{word-break:break-word}
    .ok{color:var(--green);font-weight:800}
    .bad{color:var(--red);font-weight:800}
    .warn{color:var(--warn);font-weight:800}
    .status{
      display:inline-flex;
      align-items:center;
      min-height:40px;
      padding:8px 14px;
      border-radius:999px;
      border:1px solid var(--line);
      background:rgba(255,255,255,.03);
      font-weight:800;
    }
    .status.idle{color:var(--muted)}
    .status.pending{color:var(--blue)}
    .status.ok{color:var(--green)}
    .status.bad{color:var(--red)}
    .label{font-size:13px;color:var(--muted);margin-bottom:6px}
    .input{
      width:100%;
      min-height:52px;
      border-radius:14px;
      border:1px solid var(--line);
      background:#08111d;
      color:var(--text);
      padding:12px 14px;
      font:inherit;
      font-size:18px;
    }
    .input[readonly]{
      opacity:.92;
      background:#0a0f16;
    }
    .controls{display:flex;flex-wrap:wrap;gap:10px}
    .btn{
      appearance:none;
      border:1px solid var(--line);
      background:#131924;
      color:var(--text);
      min-height:46px;
      padding:10px 16px;
      border-radius:14px;
      cursor:pointer;
      font:inherit;
      font-weight:800;
    }
    .btn.primary{border-color:rgba(246,215,104,.28);background:rgba(246,215,104,.08)}
    .btn.success{border-color:rgba(93,255,179,.24);background:rgba(93,255,179,.08)}
    .btn.blue{border-color:rgba(123,199,255,.22);background:rgba(123,199,255,.08)}
    .btn:disabled{opacity:.45;cursor:not-allowed}
    .detail{
      display:grid;
      gap:5px;
      padding:12px 14px;
      border-radius:16px;
      background:rgba(255,255,255,.025);
      border:1px solid rgba(255,255,255,.05);
    }
    .detail-k{
      font-size:.78rem;
      text-transform:uppercase;
      letter-spacing:.08em;
      color:var(--muted);
      font-weight:800;
    }
    .detail-v{
      font-size:1rem;
      line-height:1.5;
      word-break:break-word;
    }
    .qr{
      min-height:340px;
      display:flex;
      align-items:center;
      justify-content:center;
      border-radius:18px;
      border:1px dashed rgba(246,215,104,.22);
      background:rgba(255,255,255,.02);
      overflow:hidden;
      position:relative;
    }
    .qr.ready{border-style:solid}
    .qr img{
      width:min(100%,320px);
      height:auto;
      display:none;
      border-radius:14px;
    }
    .qr-placeholder{
      min-height:160px;
      width:100%;
      display:flex;
      align-items:center;
      justify-content:center;
      color:rgba(255,255,255,.38);
      font-weight:700;
      letter-spacing:.03em;
    }
    .log,.json{
      min-height:200px;
      overflow:auto;
      white-space:pre-wrap;
      word-break:break-word;
      background:#040608;
      border:1px solid var(--line);
      border-radius:14px;
      padding:12px;
      line-height:1.5;
    }
    table{
      width:100%;
      border-collapse:collapse;
      font-size:13px;
    }
    th,td{
      text-align:left;
      vertical-align:top;
      padding:8px 10px;
      border-bottom:1px solid rgba(255,255,255,.06);
      word-break:break-word;
    }
    th{color:var(--muted);font-weight:800}
    .mini{font-size:12px;color:var(--muted)}
    @media (max-width:980px){
      .grid,.shell{grid-template-columns:1fr}
      .row{grid-template-columns:1fr}
    }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <h1 class="title">Reload Card EMX Tester — Onchain Verify</h1>
      <p class="sub">Prepare and verify aligned to shared onchain-verify.php. Includes related file health and expanded debug output.</p>
    </div>

    <div class="grid">
      <div class="card">
        <div class="row"><div class="k">Logged In</div><div class="v <?= $loggedIn ? 'ok' : 'bad' ?>"><?= $loggedIn ? 'YES' : 'NO' ?></div></div>
        <div class="row"><div class="k">User ID</div><div class="v"><?= h((string)$userId) ?></div></div>
        <div class="row"><div class="k">Nickname</div><div class="v"><?= h($nickname) ?></div></div>
        <div class="row"><div class="k">Email</div><div class="v"><?= h($email) ?></div></div>
        <div class="row"><div class="k">Wallet</div><div class="v"><?= h($wallet) ?></div></div>
        <div class="row"><div class="k">TON Address</div><div class="v"><?= h($walletAddress) ?></div></div>
        <div class="row"><div class="k">CSRF Reload</div><div class="v <?= $csrfReload !== '' ? 'ok' : 'bad' ?>"><?= $csrfReload !== '' ? 'PRESENT' : 'MISSING' ?></div></div>
        <div class="row"><div class="k">Bound Card Found</div><div class="v <?= $boundCard['found'] ? 'ok' : 'bad' ?>"><?= $boundCard['found'] ? 'YES' : 'NO' ?></div></div>
        <div class="row"><div class="k">Bound Card Number</div><div class="v"><?= h($boundCard['card_number'] ?: '-') ?></div></div>
        <div class="row"><div class="k">Bound TON Address</div><div class="v"><?= h($boundCard['bound_ton_address'] ?: '-') ?></div></div>
        <div class="row"><div class="k">Card Active</div><div class="v <?= ((int)$boundCard['is_active'] === 1) ? 'ok' : 'warn' ?>"><?= ((int)$boundCard['is_active'] === 1) ? 'YES' : 'NO' ?></div></div>
        <div class="row"><div class="k">Prepare Endpoint</div><div class="v">/rwa/api/storage/reload-card-emx.php</div></div>
        <div class="row"><div class="k">Verify Endpoint</div><div class="v">/rwa/api/storage/reload-card-emx-verify.php</div></div>
        <div class="row"><div class="k">Shared Verify Core</div><div class="v">/rwa/inc/core/onchain-verify.php</div></div>
      </div>

      <div class="card">
        <h2 style="margin:0 0 10px;color:var(--gold);font-size:20px;">Related File Health</h2>
        <table>
          <thead>
            <tr>
              <th>File</th>
              <th>Exists</th>
              <th>Readable</th>
              <th>Size</th>
              <th>Modified</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($fileHealth as $rel => $meta): ?>
            <tr>
              <td><code><?= h($rel) ?></code><div class="mini"><?= h($meta['sha1']) ?></div></td>
              <td class="<?= $meta['exists'] ? 'ok' : 'bad' ?>"><?= $meta['exists'] ? 'YES' : 'NO' ?></td>
              <td class="<?= $meta['readable'] ? 'ok' : 'bad' ?>"><?= $meta['readable'] ? 'YES' : 'NO' ?></td>
              <td><?= h((string)$meta['size']) ?></td>
              <td><?= h($meta['modified_at']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <div class="shell">
        <div class="left">
          <div class="status idle" id="statusBox">Idle</div>

          <div>
            <div class="label">EMX Amount</div>
            <input id="amountInput" class="input" type="text" value="1.3333" inputmode="decimal" autocomplete="off">
          </div>

          <div>
            <div class="label">Bound Card Number (display only)</div>
            <input id="cardNumberInput" class="input" type="text" value="<?= h($boundCard['card_number'] ?: '') ?>" readonly>
          </div>

          <div>
            <div class="label">Optional TX Hash Hint</div>
            <input id="txHashInput" class="input" type="text" value="" autocomplete="off" placeholder="0x... optional">
          </div>

          <div class="controls">
            <button type="button" class="btn primary" id="btnPrepare">PREPARE RELOAD</button>
            <button type="button" class="btn success" id="btnVerify" disabled>VERIFY RELOAD</button>
            <button type="button" class="btn" id="btnReadiness">READINESS</button>
            <button type="button" class="btn" id="btnGetPrepare">GET PREPARE</button>
            <button type="button" class="btn" id="btnGetVerify">GET VERIFY</button>
            <button type="button" class="btn" id="btnClear">CLEAR</button>
          </div>

          <pre id="logBox" class="log"></pre>
          <pre id="jsonBox" class="json"></pre>
        </div>

        <div class="right">
          <div class="detail">
            <div class="detail-k">Reload Ref</div>
            <div class="detail-v" id="reloadRefView">-</div>
          </div>

          <div class="detail">
            <div class="detail-k">Wallet Deeplink</div>
            <div class="detail-v" id="deeplinkView">-</div>
          </div>

          <div class="detail">
            <div class="detail-k">Debug Findings</div>
            <div class="detail-v" id="debugFindings">-</div>
          </div>

          <div class="qr" id="qrWrap">
            <img id="qrImg" alt="QR code">
            <div id="qrPlaceholder" class="qr-placeholder">QR not ready</div>
          </div>

          <div class="controls">
            <button type="button" class="btn blue" id="btnOpenWallet" disabled>OPEN WALLET</button>
            <button type="button" class="btn" id="btnCopyRef" disabled>COPY REF</button>
            <button type="button" class="btn" id="btnCopyLink" disabled>COPY LINK</button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script>
    window.RELOAD_TEST_BOOT = {
      csrfReload: <?= json_encode($csrfReload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
      loggedIn: <?= $loggedIn ? 'true' : 'false' ?>,
      userId: <?= json_encode((string)$userId, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
      boundCardNumber: <?= json_encode((string)$boundCard['card_number'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
      boundTonAddress: <?= json_encode((string)$boundCard['bound_ton_address'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
      prepareEndpoint: '/rwa/api/storage/reload-card-emx.php',
      verifyEndpoint: '/rwa/api/storage/reload-card-emx-verify.php'
    };
  </script>

  <script>
    (function () {
      'use strict';

      const E = {
        amount: document.getElementById('amountInput'),
        card: document.getElementById('cardNumberInput'),
        txHash: document.getElementById('txHashInput'),
        status: document.getElementById('statusBox'),
        log: document.getElementById('logBox'),
        json: document.getElementById('jsonBox'),
        ref: document.getElementById('reloadRefView'),
        link: document.getElementById('deeplinkView'),
        findings: document.getElementById('debugFindings'),
        qrWrap: document.getElementById('qrWrap'),
        qrImg: document.getElementById('qrImg'),
        qrPlaceholder: document.getElementById('qrPlaceholder'),
        btnPrepare: document.getElementById('btnPrepare'),
        btnVerify: document.getElementById('btnVerify'),
        btnReadiness: document.getElementById('btnReadiness'),
        btnGetPrepare: document.getElementById('btnGetPrepare'),
        btnGetVerify: document.getElementById('btnGetVerify'),
        btnOpenWallet: document.getElementById('btnOpenWallet'),
        btnCopyRef: document.getElementById('btnCopyRef'),
        btnCopyLink: document.getElementById('btnCopyLink'),
        btnClear: document.getElementById('btnClear')
      };

      const S = {
        reloadRef: '',
        deeplink: '',
        qrText: '',
        busy: false,
        lastJson: null
      };

      function now() {
        const d = new Date();
        const pad = n => String(n).padStart(2, '0');
        return pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
      }

      function log(msg) {
        E.log.textContent += '[' + now() + '] ' + msg + '\n';
        E.log.scrollTop = E.log.scrollHeight;
      }

      function showJson(obj) {
        E.json.textContent = typeof obj === 'string' ? obj : JSON.stringify(obj, null, 2);
      }

      function setStatus(text, cls) {
        E.status.className = 'status ' + cls;
        E.status.textContent = text;
      }

      function syncButtons() {
        const hasRef = !!S.reloadRef;
        const hasLink = !!S.deeplink;
        E.btnVerify.disabled = S.busy || !hasRef;
        E.btnOpenWallet.disabled = S.busy || !hasLink;
        E.btnCopyRef.disabled = S.busy || !hasRef;
        E.btnCopyLink.disabled = S.busy || !hasLink;
        E.btnPrepare.disabled = S.busy;
        E.btnGetPrepare.disabled = S.busy;
        E.btnGetVerify.disabled = S.busy;
      }

      function renderQr(text) {
        const t = String(text || '').trim();

        if (!t) {
          E.qrImg.onload = null;
          E.qrImg.onerror = null;
          E.qrImg.removeAttribute('src');
          E.qrImg.style.display = 'none';
          E.qrWrap.classList.remove('ready');
          E.qrPlaceholder.style.display = 'flex';
          return;
        }

        E.qrImg.onload = function () {
          E.qrImg.style.display = 'block';
          E.qrPlaceholder.style.display = 'none';
          E.qrWrap.classList.add('ready');
        };

        E.qrImg.onerror = function () {
          E.qrImg.removeAttribute('src');
          E.qrImg.style.display = 'none';
          E.qrWrap.classList.remove('ready');
          E.qrPlaceholder.style.display = 'flex';
        };

        E.qrImg.style.display = 'none';
        E.qrPlaceholder.style.display = 'flex';
        E.qrWrap.classList.remove('ready');
        E.qrImg.src = '/rwa/inc/core/qr.php?text=' + encodeURIComponent(t);
      }

      function setFindings(lines) {
        E.findings.innerHTML = lines.join('<br>');
      }

      async function copyText(v) {
        if (!v) return false;
        try {
          if (navigator.clipboard && navigator.clipboard.writeText) {
            await navigator.clipboard.writeText(v);
            return true;
          }
        } catch (e) {}
        return false;
      }

      async function postForm(url, bodyObj) {
        const body = new URLSearchParams();
        Object.keys(bodyObj).forEach((k) => body.set(k, bodyObj[k]));

        const start = performance.now();
        const res = await fetch(url, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'Accept': 'application/json'
          },
          body: body.toString()
        });
        const ms = Math.round(performance.now() - start);

        const raw = await res.text();
        let json;
        try {
          json = JSON.parse(raw);
        } catch (e) {
          json = { ok: false, error: 'NON_JSON_RESPONSE', raw: raw };
        }

        return { res, json, raw, ms };
      }

      async function postPrepare(bodyObj) {
        return postForm(window.RELOAD_TEST_BOOT.prepareEndpoint, bodyObj);
      }

      async function postVerify(bodyObj) {
        return postForm(window.RELOAD_TEST_BOOT.verifyEndpoint, bodyObj);
      }

      async function getEndpoint(url) {
        const start = performance.now();
        const res = await fetch(url, {
          method: 'GET',
          credentials: 'same-origin',
          headers: { 'Accept': 'application/json' }
        });
        const ms = Math.round(performance.now() - start);
        const raw = await res.text();

        let json;
        try {
          json = JSON.parse(raw);
        } catch (e) {
          json = raw;
        }

        showJson({ endpoint: url, http_status: res.status, latency_ms: ms, response: json });
        log('GET ' + url + ' => HTTP ' + res.status + ' · ' + ms + 'ms');
      }

      function analyzePrepareJson(json) {
        const out = [];
        const hasReloadRef = !!(json && typeof json.reload_ref === 'string' && json.reload_ref.trim() !== '');
        const hasDeeplink = !!(json && typeof json.deeplink === 'string' && json.deeplink.trim() !== '');
        const hasQrText = !!(json && typeof json.qr_text === 'string' && json.qr_text.trim() !== '');
        const hasUnits = !!(json && typeof json.amount_units !== 'undefined' && String(json.amount_units).trim() !== '');
        const hasVersion = !!(json && typeof json._version === 'string' && json._version.trim() !== '');
        const hasFile = !!(json && typeof json._file === 'string' && json._file.trim() !== '');
        const hasJetton = !!(json && typeof json.jetton_master === 'string' && json.jetton_master.trim() !== '');

        out.push('reload_ref present: ' + (hasReloadRef ? '<span class="ok">YES</span>' : '<span class="bad">NO</span>'));
        out.push('deeplink present: ' + (hasDeeplink ? '<span class="ok">YES</span>' : '<span class="bad">NO</span>'));
        out.push('qr_text present: ' + (hasQrText ? '<span class="ok">YES</span>' : '<span class="bad">NO</span>'));
        out.push('amount_units present: ' + (hasUnits ? '<span class="ok">YES</span>' : '<span class="bad">NO</span>'));
        out.push('jetton_master present: ' + (hasJetton ? '<span class="ok">YES</span>' : '<span class="warn">NO</span>'));
        out.push('_version present: ' + (hasVersion ? '<span class="ok">YES</span>' : '<span class="bad">NO</span>'));
        out.push('_file present: ' + (hasFile ? '<span class="ok">YES</span>' : '<span class="bad">NO</span>'));

        return out;
      }

      function analyzeVerifyJson(json) {
        const out = [];
        const debug = json && typeof json.debug === 'object' ? json.debug : null;

        out.push('status: ' + (json && json.status ? '<span class="ok">' + String(json.status) + '</span>' : '<span class="warn">MISSING</span>'));
        out.push('verify_source: ' + (json && json.verify_source ? '<span class="ok">' + String(json.verify_source) + '</span>' : '<span class="warn">MISSING</span>'));
        out.push('tx_hash present: ' + (json && json.tx_hash ? '<span class="ok">YES</span>' : '<span class="warn">NO</span>'));
        out.push('confirmations present: ' + (json && typeof json.confirmations !== 'undefined' ? '<span class="ok">YES</span>' : '<span class="warn">NO</span>'));
        out.push('_version present: ' + (json && json._version ? '<span class="ok">YES</span>' : '<span class="bad">NO</span>'));
        out.push('_file present: ' + (json && json._file ? '<span class="ok">YES</span>' : '<span class="bad">NO</span>'));

        if (json && json.verify_code) {
          out.push('verify_code: <span class="warn">' + String(json.verify_code) + '</span>');
        }
        if (json && json.error) {
          out.push('error: <span class="bad">' + String(json.error) + '</span>');
        }

        if (debug) {
          if (debug.owner_address) out.push('debug.owner_address: ' + String(debug.owner_address));
          if (debug.token_key) out.push('debug.token_key: ' + String(debug.token_key));
          if (debug.jetton_master) out.push('debug.jetton_master: ' + String(debug.jetton_master));
          if (debug.amount_units) out.push('debug.amount_units: ' + String(debug.amount_units));
          if (debug.ref) out.push('debug.ref: ' + String(debug.ref));
          if (debug.tx_hint) out.push('debug.tx_hint: ' + String(debug.tx_hint));
        }

        return out;
      }

      function runReadiness() {
        const lines = [
          'logged_in: ' + window.RELOAD_TEST_BOOT.loggedIn,
          'user_id: ' + window.RELOAD_TEST_BOOT.userId,
          'csrf_present: ' + (!!window.RELOAD_TEST_BOOT.csrfReload),
          'bound_card_number: ' + (window.RELOAD_TEST_BOOT.boundCardNumber || '-'),
          'bound_ton_address: ' + (window.RELOAD_TEST_BOOT.boundTonAddress || '-'),
          'prepare_endpoint: ' + window.RELOAD_TEST_BOOT.prepareEndpoint,
          'verify_endpoint: ' + window.RELOAD_TEST_BOOT.verifyEndpoint,
          'shared_core: /rwa/inc/core/onchain-verify.php',
          'verify_rule: jetton + amount + ref = ACCEPT (destination not required)'
        ];
        log('Readiness check run.');
        showJson(lines);
      }

      async function prepareReload() {
        const amount = String(E.amount.value || '').trim();

        if (!window.RELOAD_TEST_BOOT.csrfReload) {
          setStatus('CSRF missing.', 'bad');
          log('Reload CSRF missing.');
          return;
        }

        if (!amount || Number(amount) <= 0) {
          setStatus('Invalid amount.', 'bad');
          log('Invalid amount.');
          return;
        }

        S.busy = true;
        S.reloadRef = '';
        S.deeplink = '';
        S.qrText = '';
        E.ref.textContent = '-';
        E.link.textContent = '-';
        setFindings(['-']);
        renderQr('');
        syncButtons();
        setStatus('Preparing reload...', 'pending');
        log('Prepare reload started. amount=' + amount);

        try {
          const out = await postPrepare({
            csrf_token: window.RELOAD_TEST_BOOT.csrfReload,
            action: 'prepare',
            amount: amount
          });

          S.lastJson = out.json;
          showJson({
            endpoint: window.RELOAD_TEST_BOOT.prepareEndpoint,
            http_status: out.res.status,
            latency_ms: out.ms,
            response: out.json
          });

          log('Prepare => HTTP ' + out.res.status + ' · ' + out.ms + 'ms');

          if (!out.res.ok || !out.json || !out.json.ok) {
            const err = out.json && out.json.error ? out.json.error : ('HTTP_' + out.res.status);
            setStatus('Prepare failed.', 'bad');
            log('Prepare failed: ' + err);
            setFindings(analyzePrepareJson(out.json || {}));
            return;
          }

          S.reloadRef = String(out.json.reload_ref || '').trim();
          S.deeplink = String(out.json.deeplink || '').trim();
          S.qrText = String(out.json.qr_text || out.json.deeplink || '').trim();

          E.ref.textContent = S.reloadRef || '-';
          E.link.textContent = S.deeplink || '-';
          renderQr(S.qrText);
          setFindings(analyzePrepareJson(out.json));

          setStatus('Reload prepared.', 'ok');
          log('Prepared successfully. Reload Ref: ' + S.reloadRef);
          if (out.json.amount_units) log('Prepared amount_units: ' + String(out.json.amount_units));
          if (out.json.jetton_master) log('Prepared jetton_master: ' + String(out.json.jetton_master));
        } catch (e) {
          setStatus('Prepare failed.', 'bad');
          log('Prepare exception: ' + (e && e.message ? e.message : String(e)));
        } finally {
          S.busy = false;
          syncButtons();
        }
      }

      async function verifyReload() {
        if (!S.reloadRef) {
          setStatus('Reload ref missing.', 'bad');
          log('Prepare reload first.');
          syncButtons();
          return;
        }

        const txHint = String(E.txHash.value || '').trim();

        S.busy = true;
        syncButtons();
        setStatus('Verifying transaction...', 'pending');
        log('Verify reload started. reload_ref=' + S.reloadRef + (txHint ? ' tx_hint=' + txHint : ''));

        try {
          const payload = {
            csrf_token: window.RELOAD_TEST_BOOT.csrfReload,
            action: 'verify',
            reload_ref: S.reloadRef
          };
          if (txHint) {
            payload.tx_hash = txHint;
          }

          const out = await postVerify(payload);

          showJson({
            endpoint: window.RELOAD_TEST_BOOT.verifyEndpoint,
            http_status: out.res.status,
            latency_ms: out.ms,
            response: out.json
          });

          log('Verify => HTTP ' + out.res.status + ' · ' + out.ms + 'ms');

          if (!out.res.ok || !out.json || !out.json.ok) {
            const err = out.json && out.json.error ? out.json.error : ('HTTP_' + out.res.status);
            setStatus('Verify failed.', 'bad');
            log('Verify failed: ' + err);
            if (out.json && out.json.verify_code) {
              log('Verify code: ' + String(out.json.verify_code));
            }
            if (out.json && out.json.debug) {
              log('Verify debug present.');
            }
            setFindings(analyzeVerifyJson(out.json || {}));
            return;
          }

          setFindings(analyzeVerifyJson(out.json));

          const status = String(out.json.status || '');
          const txHash = String(out.json.tx_hash || '');
          const conf = String(out.json.confirmations ?? '');
          const source = String(out.json.verify_source || '');

          if (status === 'CONFIRMED' || status === 'ALREADY_CONFIRMED') {
            setStatus(status === 'CONFIRMED' ? 'Reload confirmed.' : 'Already confirmed.', 'ok');
            log(
              (status === 'CONFIRMED' ? 'Confirmed' : 'Already confirmed') +
              (txHash ? ' · TX: ' + txHash : '') +
              (conf !== '' ? ' · Conf: ' + conf : '') +
              (source ? ' · Source: ' + source : '')
            );
          } else {
            setStatus('Verify completed.', 'ok');
            log('Verify result: ' + status);
          }
        } catch (e) {
          setStatus('Verify failed.', 'bad');
          log('Verify exception: ' + (e && e.message ? e.message : String(e)));
        } finally {
          S.busy = false;
          syncButtons();
        }
      }

      E.btnPrepare.addEventListener('click', prepareReload);
      E.btnVerify.addEventListener('click', verifyReload);
      E.btnReadiness.addEventListener('click', runReadiness);
      E.btnGetPrepare.addEventListener('click', function () {
        getEndpoint(window.RELOAD_TEST_BOOT.prepareEndpoint);
      });
      E.btnGetVerify.addEventListener('click', function () {
        getEndpoint(window.RELOAD_TEST_BOOT.verifyEndpoint);
      });

      E.btnOpenWallet.addEventListener('click', function () {
        if (S.deeplink) window.location.href = S.deeplink;
      });

      E.btnCopyRef.addEventListener('click', async function () {
        if (!S.reloadRef) return;
        const ok = await copyText(S.reloadRef);
        log(ok ? 'Reload reference copied.' : 'Copy failed.');
      });

      E.btnCopyLink.addEventListener('click', async function () {
        if (!S.deeplink) return;
        const ok = await copyText(S.deeplink);
        log(ok ? 'Wallet deeplink copied.' : 'Copy failed.');
      });

      E.btnClear.addEventListener('click', function () {
        E.log.textContent = '';
        E.json.textContent = '';
        E.findings.textContent = '-';
      });

      syncButtons();
      renderQr('');
      log('Reload Card Tester ready.');
      runReadiness();
    })();
  </script>
</body>
</html>