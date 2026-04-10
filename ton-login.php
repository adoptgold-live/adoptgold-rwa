<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/ton-login.php
 * Version: v2.1.1-20260329
 * Changelog:
 * - keep previous premium design/layout unchanged
 * - fix sticky TonConnect wallet auto-restore with restoreConnection:false
 * - strengthen TON storage purge keys
 * - purge storage before TonConnect init and on reset/logout markers
 */

// /var/www/html/public/rwa/ton-login.php
// Final ultimate locked production version

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

if (session_user_id() > 0) {
    header('Location: /rwa/login-select.php');
    exit;
}

$lang = 'en';
if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'zh'], true)) {
    $lang = $_GET['lang'];
    setcookie('poado_lang', $lang, time() + 31536000, '/');
} elseif (!empty($_COOKIE['poado_lang']) && in_array($_COOKIE['poado_lang'], ['en', 'zh'], true)) {
    $lang = $_COOKIE['poado_lang'];
} else {
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
    $lang = str_contains($accept, 'zh') ? 'zh' : 'en';
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$T = [
    'en' => [
        'page_title'    => 'TON Login · AdoptGold RWA',
        'back_to_hub'   => 'Back to Hub',
        'title'         => 'TON Login',
        'subtitle'      => 'Primary ecosystem identity',
        'copy'          => 'Use your TON wallet as the authoritative ecosystem identity.',
        'connect'       => 'Connect TON Wallet',
        'reset'         => 'Reset TON Session',
        'ready'         => 'Ready',
        'prepare'       => 'Preparing TON login...',
        'open'          => 'Opening TON wallet...',
        'waiting'       => 'Waiting for wallet approval...',
        'verify'        => 'Verifying TON proof...',
        'success'       => 'TON login success. Redirecting...',
        'resetting'     => 'Resetting TON session...',
        'reset_ok'      => 'TON session reset completed.',
        'no_lib'        => 'TonConnect UI library is not loaded',
        'no_addr'       => 'TON wallet address not returned',
        'no_proof'      => 'Missing proof',
        'timeout'       => 'Wallet approval timeout',
        'nonce_failed'  => 'Unable to prepare TON login',
        'verify_failed' => 'Verify failed',
        'footer'        => '© 2025 Blockchain Group Ltd. (Hong Kong) · RWA Standard Organisation (RSO). All rights reserved.',
    ],
    'zh' => [
        'page_title'    => 'TON 登录 · AdoptGold RWA',
        'back_to_hub'   => '返回登录中心',
        'title'         => 'TON 登录',
        'subtitle'      => '生态主身份入口',
        'copy'          => '使用 TON 钱包作为生态系统主身份。',
        'connect'       => '连接 TON 钱包',
        'reset'         => '重置 TON 会话',
        'ready'         => '就绪',
        'prepare'       => '正在准备 TON 登录...',
        'open'          => '正在打开 TON 钱包...',
        'waiting'       => '等待钱包授权...',
        'verify'        => '正在验证 TON 证明...',
        'success'       => 'TON 登录成功，正在跳转...',
        'resetting'     => '正在重置 TON 会话...',
        'reset_ok'      => 'TON 会话重置完成。',
        'no_lib'        => 'TonConnect UI 库未加载',
        'no_addr'       => '未返回 TON 钱包地址',
        'no_proof'      => '缺少 proof',
        'timeout'       => '钱包授权超时',
        'nonce_failed'  => '无法准备 TON 登录',
        'verify_failed' => '验证失败',
        'footer'        => '© 2025 Blockchain Group Ltd. (Hong Kong) · RWA Standard Organisation (RSO). All rights reserved.',
    ],
];
$t = $T[$lang];
?>
<!doctype html>
<html lang="<?= h($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="color-scheme" content="dark">
<title><?= h($t['page_title']) ?></title>
<link rel="stylesheet" href="/rwa/assets/css/rwa-auth.css">
<style>
:root{
  --bg:#0b0714;
  --bg2:#1a1030;
  --line:rgba(193,154,255,.24);
  --text:#f1ecff;
  --muted:#c3b7e7;
  --ok:#78f0cb;
  --warn:#ffd86b;
  --err:#ff7c9a;
}
*{box-sizing:border-box}
html,body{
  margin:0;
  padding:0;
  min-height:100%;
  background:
    radial-gradient(circle at top left, rgba(166,123,255,.16), transparent 28%),
    linear-gradient(180deg,var(--bg2),var(--bg));
  color:var(--text);
  font-family:Inter,ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;
}
.wrap{
  min-height:100vh;
  display:flex;
  align-items:center;
  justify-content:center;
  padding:20px;
}
.panel{
  width:min(720px,100%);
  border:1px solid var(--line);
  border-radius:32px;
  background:rgba(58,46,92,.78);
  box-shadow:0 0 40px rgba(0,0,0,.25);
  padding:22px;
}
.toprow{
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:10px;
  margin-bottom:12px;
}
.back-btn,.lang-btn{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-height:48px;
  padding:0 18px;
  border-radius:20px;
  border:1px solid rgba(193,154,255,.28);
  background:transparent;
  color:var(--text);
  text-decoration:none;
  font:inherit;
}
.lang-group{display:flex;gap:8px}
.hero{
  text-align:center;
  padding:8px 8px 4px;
}
.hero h1{
  margin:8px 0 4px;
  font-size:34px;
  line-height:1.15;
  font-weight:900;
  letter-spacing:-.02em;
}
.hero p{
  margin:0;
  color:var(--muted);
  font-size:14px;
}
.card{
  margin-top:18px;
  border:1px solid rgba(193,154,255,.22);
  border-radius:24px;
  background:rgba(48,38,79,.72);
  padding:26px 22px;
}
.copy{
  text-align:center;
  color:var(--muted);
  font-size:16px;
  margin-bottom:22px;
}
.actions{
  display:flex;
  flex-direction:column;
  gap:14px;
}
.primary-btn,.secondary-btn{
  width:100%;
  min-height:60px;
  border-radius:18px;
  border:1px solid rgba(193,154,255,.32);
  cursor:pointer;
  font:inherit;
  font-size:18px;
  font-weight:900;
  letter-spacing:.01em;
}
.primary-btn{
  background:linear-gradient(90deg,#ad86ff 0%, #81d7d3 100%);
  color:#191329;
  border-color:rgba(255,255,255,.7);
  box-shadow:inset 0 0 0 2px rgba(255,255,255,.85);
}
.secondary-btn{
  background:rgba(255,255,255,.03);
  color:var(--text);
}
.primary-btn:disabled,.secondary-btn:disabled{
  opacity:.6;
  cursor:not-allowed;
}
.status{
  margin-top:16px;
  min-height:52px;
  border-radius:18px;
  border:1px solid rgba(193,154,255,.18);
  background:rgba(19,14,34,.6);
  padding:14px 16px;
  color:var(--muted);
  display:flex;
  align-items:center;
  justify-content:center;
  text-align:center;
  word-break:break-word;
}
.status.ok{
  border-color:rgba(120,240,203,.32);
  color:#d8fff3;
  background:rgba(120,240,203,.08);
}
.status.warn{
  border-color:rgba(255,216,107,.28);
  color:#fff1bf;
  background:rgba(255,216,107,.07);
}
.status.err{
  border-color:rgba(255,124,154,.28);
  color:#ffdbe4;
  background:rgba(255,124,154,.08);
}
.footer{
  text-align:center;
  color:#d7cfee;
  opacity:.9;
  font-size:13px;
  margin-top:18px;
  line-height:1.5;
}
#ton-connect-root{display:none}
@media (max-width:640px){
  .panel{padding:16px;border-radius:24px}
  .hero h1{font-size:28px}
  .card{padding:18px 16px}
  .primary-btn,.secondary-btn{min-height:56px;font-size:16px}
  .toprow{flex-direction:column;align-items:stretch}
  .lang-group{justify-content:flex-end}
}
</style>
</head>
<body>
<div class="wrap">
  <div class="panel">
    <div class="toprow">
      <a href="/rwa/index.php" class="back-btn"><?= h($t['back_to_hub']) ?></a>
      <div class="lang-group">
        <a href="/rwa/ton-login.php?lang=en" class="lang-btn">English</a>
        <a href="/rwa/ton-login.php?lang=zh" class="lang-btn">中文</a>
      </div>
    </div>

    <div class="hero">
      <h1><?= h($t['title']) ?></h1>
      <p><?= h($t['subtitle']) ?></p>
    </div>

    <div class="card">
      <div class="copy"><?= h($t['copy']) ?></div>

      <div class="actions">
        <button type="button" class="primary-btn" id="btnTonConnect"><?= h($t['connect']) ?></button>
        <button type="button" class="secondary-btn" id="btnTonReset"><?= h($t['reset']) ?></button>
      </div>

      <div class="status" id="tonStatusBox"><?= h($t['ready']) ?></div>
      <div id="ton-connect-root"></div>
    </div>

    <div class="footer"><?= h($t['footer']) ?></div>
  </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/gt-inline.php'; ?>
<script src="/rwa/inc/core/poado-i18n.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@tonconnect/ui@latest/dist/tonconnect-ui.min.js"></script>
<script>
(function () {
  'use strict';

  const I18N = <?= json_encode([
    'prepare'      => $t['prepare'],
    'open'         => $t['open'],
    'waiting'      => $t['waiting'],
    'verify'       => $t['verify'],
    'success'      => $t['success'],
    'resetting'    => $t['resetting'],
    'reset_ok'     => $t['reset_ok'],
    'no_lib'       => $t['no_lib'],
    'no_addr'      => $t['no_addr'],
    'no_proof'     => $t['no_proof'],
    'timeout'      => $t['timeout'],
    'nonce_failed' => $t['nonce_failed'],
    'verify_ng'    => $t['verify_failed'],
    'ready'        => $t['ready']
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

  const btnConnect = document.getElementById('btnTonConnect');
  const btnReset = document.getElementById('btnTonReset');
  const statusBox = document.getElementById('tonStatusBox');
  const buttonRootId = 'ton-connect-root';

  let tonConnectUI = null;

  function setStatus(text, type) {
    statusBox.textContent = text || '';
    statusBox.className = 'status' + (type ? (' ' + type) : '');
  }

  function purgeTonConnectStorage() {
    try {
      const keys = [];
      for (let i = 0; i < localStorage.length; i++) keys.push(localStorage.key(i));
      keys.forEach((k) => {
        if (!k) return;
        const x = String(k).toLowerCase();
        if (
          x.includes('tonconnect') ||
          x.includes('ton-connect') ||
          x.includes('ton_connect') ||
          x.includes('wallet')
        ) {
          localStorage.removeItem(k);
        }
      });
    } catch (_) {}

    try {
      const keys = [];
      for (let i = 0; i < sessionStorage.length; i++) keys.push(sessionStorage.key(i));
      keys.forEach((k) => {
        if (!k) return;
        const x = String(k).toLowerCase();
        if (
          x.includes('tonconnect') ||
          x.includes('ton-connect') ||
          x.includes('ton_connect') ||
          x.includes('wallet')
        ) {
          sessionStorage.removeItem(k);
        }
      });
    } catch (_) {}

    try {
      if (window.indexedDB && typeof indexedDB.databases === 'function') {
        indexedDB.databases().then((dbs) => {
          (dbs || []).forEach((db) => {
            const name = String(db && db.name ? db.name : '').toLowerCase();
            if (name && (name.includes('ton') || name.includes('connect') || name.includes('wallet'))) {
              try { indexedDB.deleteDatabase(db.name); } catch (_) {}
            }
          });
        }).catch(() => {});
      }
    } catch (_) {}
  }

  function ensureTonConnect() {
    if (tonConnectUI) return tonConnectUI;

    let Ctor = null;
    if (window.TON_CONNECT_UI && typeof window.TON_CONNECT_UI.TonConnectUI === 'function') {
      Ctor = window.TON_CONNECT_UI.TonConnectUI;
    } else if (typeof window.TonConnectUI === 'function') {
      Ctor = window.TonConnectUI;
    } else if (window.TonConnectUI && typeof window.TonConnectUI.TonConnectUI === 'function') {
      Ctor = window.TonConnectUI.TonConnectUI;
    }

    if (!Ctor) throw new Error(I18N.no_lib);

    tonConnectUI = new Ctor({
      manifestUrl: 'https://adoptgold.app/tonconnect-manifest.json',
      buttonRootId: buttonRootId,
      restoreConnection: false
    });

    return tonConnectUI;
  }

  async function getNonce() {
    const res = await fetch('/rwa/auth/ton/nonce.php', {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });

    const text = await res.text();
    let js;
    try {
      js = JSON.parse(text);
    } catch (e) {
      throw new Error(text || I18N.nonce_failed);
    }

    if (!js || !js.ok || !js.payload) {
      throw new Error((js && (js.error || js.message)) ? (js.error || js.message) : I18N.nonce_failed);
    }

    return js;
  }

  async function postJson(url, payload) {
    const res = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify(payload || {})
    });

    const text = await res.text();
    let js;
    try {
      js = JSON.parse(text);
    } catch (e) {
      throw new Error(text || I18N.verify_ng);
    }

    if (!js || !js.ok) {
      throw new Error((js && (js.error || js.message || js.detail)) ? (js.error || js.message || js.detail) : I18N.verify_ng);
    }

    return js;
  }

  function normalizeAddress(v) {
    return String(v || '').trim();
  }

  function extractTonAddress(walletObj) {
    const candidates = [
      walletObj?.account?.address,
      walletObj?.address,
      walletObj?.wallet?.account?.address,
      walletObj?.wallet?.address
    ];
    for (const c of candidates) {
      const addr = normalizeAddress(c);
      if (addr) return addr;
    }
    return '';
  }

  function extractTonProof(walletObj) {
    const candidates = [
      walletObj?.connectItems?.tonProof?.proof,
      walletObj?.connectItems?.tonProof,
      walletObj?.tonProof?.proof,
      walletObj?.tonProof,
      walletObj?.wallet?.connectItems?.tonProof?.proof,
      walletObj?.wallet?.connectItems?.tonProof,
      walletObj?.wallet?.tonProof?.proof,
      walletObj?.wallet?.tonProof
    ];
    for (const item of candidates) {
      if (item && typeof item === 'object') return item;
    }
    return null;
  }

  async function resetServer() {
    try {
      await fetch('/rwa/auth/ton/reset.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
    } catch (_) {}
  }

  async function freshDisconnect(ui) {
    try {
      if (ui && typeof ui.disconnect === 'function') {
        await ui.disconnect();
      }
    } catch (_) {}
    purgeTonConnectStorage();
    await resetServer();
  }

  async function waitForWalletWithProof(ui, timeoutMs = 30000) {
    const started = Date.now();

    while (Date.now() - started < timeoutMs) {
      const wallet = ui.wallet || null;
      const tonAddress = extractTonAddress(wallet);
      const proof = extractTonProof(wallet);

      if (tonAddress && proof) {
        return { tonAddress, proof, wallet };
      }

      await new Promise(resolve => setTimeout(resolve, 500));
    }

    throw new Error(I18N.timeout);
  }

  async function runTonLogin() {
    btnConnect.disabled = true;
    setStatus(I18N.prepare, 'warn');

    try {
      purgeTonConnectStorage();
      const ui = ensureTonConnect();

      await freshDisconnect(ui);

      const nonce = await getNonce();

      if (typeof ui.setConnectRequestParameters === 'function') {
        ui.setConnectRequestParameters({
          state: 'ready',
          value: { tonProof: nonce.payload }
        });
      }

      setStatus(I18N.open, 'warn');

      if (typeof ui.openModal === 'function') {
        await ui.openModal();
      } else if (typeof ui.connectWallet === 'function') {
        await ui.connectWallet();
      } else {
        throw new Error(I18N.no_lib);
      }

      setStatus(I18N.waiting, 'warn');

      const approved = await waitForWalletWithProof(ui);
      const tonAddress = approved.tonAddress;
      const proof = approved.proof;

      if (!tonAddress) {
        throw new Error(I18N.no_addr);
      }
      if (!proof) {
        await freshDisconnect(ui);
        throw new Error(I18N.no_proof);
      }

      setStatus(I18N.verify, 'warn');

      const verify = await postJson('/rwa/auth/ton/verify.php', {
        ton_address: tonAddress,
        proof: proof
      });

      setStatus(I18N.success, 'ok');
      window.location.href = verify.next || '/rwa/login-select.php';
    } catch (err) {
      console.error('TON LOGIN ERROR', err);
      setStatus((err && err.message) ? err.message : I18N.verify_ng, 'err');
    } finally {
      btnConnect.disabled = false;
    }
  }

  async function runTonReset() {
    btnReset.disabled = true;
    setStatus(I18N.resetting, 'warn');

    try {
      purgeTonConnectStorage();
      const ui = ensureTonConnect();
      await freshDisconnect(ui);
      setStatus(I18N.reset_ok, 'ok');
    } catch (err) {
      setStatus((err && err.message) ? err.message : I18N.verify_ng, 'err');
    } finally {
      btnReset.disabled = false;
    }
  }

  const qs = new URLSearchParams(window.location.search);
  if (qs.get('m') === 'logged_out' || qs.get('m') === 'reset') {
    purgeTonConnectStorage();
    setStatus(I18N.ready, '');
  }

  btnConnect.addEventListener('click', runTonLogin);
  btnReset.addEventListener('click', runTonReset);
})();
</script>
</body>
</html>
