<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/storage/tester-activate-card.php
 * Storage Master v7.7 — Activate Card Final Tester
 *
 * Locked activate endpoints:
 * - /rwa/api/storage/activate-card/activate-prepare.php
 * - /rwa/api/storage/activate-card/activate-verify.php
 * - /rwa/api/storage/activate-card/activate-confirm.php
 * - /rwa/api/storage/activate-card/activate.php
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function tester_activate_user_seed(): ?array
{
    if (function_exists('rwa_current_user')) {
        try {
            $tmp = rwa_current_user();
            if (is_array($tmp) && !empty($tmp)) {
                return $tmp;
            }
        } catch (Throwable $e) {}
    }

    if (function_exists('rwa_session_user')) {
        try {
            $tmp = rwa_session_user();
            if (is_array($tmp) && !empty($tmp)) {
                return $tmp;
            }
        } catch (Throwable $e) {}
    }

    if (function_exists('get_wallet_session')) {
        try {
            $tmp = get_wallet_session();
            if (is_array($tmp) && !empty($tmp)) {
                return $tmp;
            }
            if (is_string($tmp) && trim($tmp) !== '') {
                return ['wallet' => trim($tmp)];
            }
        } catch (Throwable $e) {}
    }

    return null;
}

function tester_activate_pdo(): ?PDO
{
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        return $GLOBALS['pdo'];
    }

    if (function_exists('db_connect')) {
        try {
            db_connect();
            if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
                return $GLOBALS['pdo'];
            }
        } catch (Throwable $e) {}
    }

    return null;
}

function tester_activate_user_hydrate(?array $seed): array
{
    $fallback = [
        'id' => 0,
        'wallet' => '',
        'wallet_address' => '',
        'nickname' => 'Storage Tester User',
        'email' => '',
        'email_verified_at' => '',
        'is_active' => 0,
    ];

    if (!$seed || !is_array($seed)) {
        return $fallback;
    }

    $pdo = tester_activate_pdo();
    if ($pdo instanceof PDO) {
        $userId = (int)($seed['id'] ?? 0);
        $wallet = trim((string)($seed['wallet'] ?? ''));
        $walletAddress = trim((string)($seed['wallet_address'] ?? ''));

        $sql = "SELECT id, wallet, wallet_address, nickname, email, email_verified_at, is_active
                FROM users";

        try {
            if ($userId > 0) {
                $st = $pdo->prepare($sql . " WHERE id = ? LIMIT 1");
                $st->execute([$userId]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if (is_array($row)) return $row;
            }

            if ($walletAddress !== '') {
                $st = $pdo->prepare($sql . " WHERE wallet_address = ? LIMIT 1");
                $st->execute([$walletAddress]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if (is_array($row)) return $row;
            }

            if ($wallet !== '') {
                $st = $pdo->prepare($sql . " WHERE wallet = ? LIMIT 1");
                $st->execute([$wallet]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if (is_array($row)) return $row;
            }
        } catch (Throwable $e) {}
    }

    return array_merge($fallback, [
        'id' => (int)($seed['id'] ?? 0),
        'wallet' => (string)($seed['wallet'] ?? ''),
        'wallet_address' => (string)($seed['wallet_address'] ?? ''),
        'nickname' => (string)($seed['nickname'] ?? 'Storage Tester User'),
        'email' => (string)($seed['email'] ?? ''),
        'email_verified_at' => (string)($seed['email_verified_at'] ?? ''),
        'is_active' => (int)($seed['is_active'] ?? 0),
    ]);
}

$seed = tester_activate_user_seed();
$user = tester_activate_user_hydrate($seed);

$csrfActivate = function_exists('csrf_token') ? csrf_token('storage_activate_card') : '';
$displayName = trim((string)($user['nickname'] ?? 'Storage Tester User'));
if ($displayName === '') $displayName = 'Storage Tester User';

$walletAddress = trim((string)($user['wallet_address'] ?? ''));
$email = trim((string)($user['email'] ?? ''));
$emailVerifiedAt = trim((string)($user['email_verified_at'] ?? ''));
$emailVerified = ($email !== '' && $emailVerifiedAt !== '');
$hasTonBind = ($walletAddress !== '');

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="theme-color" content="#050607">
  <title>Activate Card Tester</title>
  <style>
    :root{
      --bg:#050607;--panel:#0f1318;--line:#232a33;--text:#f5f7fa;--muted:#96a0ad;
      --gold:#f3c969;--green:#29d17d;--red:#ff6b6b;--blue:#72b7ff;
    }
    *{box-sizing:border-box}
    body{
      margin:0;background:var(--bg);color:var(--text);
      font:14px/1.45 ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;
    }
    .wrap{max-width:1180px;margin:0 auto;padding:20px}
    .head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap;margin-bottom:18px}
    .title{font-size:28px;font-weight:800;margin:0 0 6px}
    .sub{color:var(--muted);margin:0}
    .badge{display:inline-block;padding:6px 10px;border:1px solid var(--line);border-radius:999px;color:var(--gold)}
    .grid{display:grid;grid-template-columns:repeat(12,1fr);gap:16px}
    .card{
      grid-column:span 12;background:var(--panel);border:1px solid var(--line);
      border-radius:18px;padding:18px;box-shadow:0 10px 30px rgba(0,0,0,.22)
    }
    .half{grid-column:span 6}
    .third{grid-column:span 4}
    @media (max-width:960px){.half,.third{grid-column:span 12}}
    .k{color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.08em}
    .v{font-size:14px;word-break:break-word}
    .row{display:grid;grid-template-columns:180px 1fr;gap:12px;padding:8px 0;border-bottom:1px dashed rgba(255,255,255,.06)}
    .row:last-child{border-bottom:0}
    .actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
    button{
      appearance:none;border:1px solid var(--line);background:#141a21;color:var(--text);
      border-radius:12px;padding:11px 14px;cursor:pointer;font-weight:700
    }
    button.primary{background:#1a2230;border-color:#384760}
    button.gold{background:#221b0d;border-color:#6a5520;color:var(--gold)}
    button.green{background:#0f2217;border-color:#1f6b46;color:#9ae8bf}
    button.red{background:#2a1414;border-color:#7b2d2d;color:#ffc2c2}
    button:disabled{opacity:.45;cursor:not-allowed}
    input,textarea{
      width:100%;background:#0b0f14;color:var(--text);border:1px solid var(--line);
      border-radius:12px;padding:11px 12px;outline:none
    }
    .field{margin:12px 0}
    .field label{display:block;margin:0 0 6px;color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.08em}
    .status{
      min-height:44px;border:1px dashed var(--line);border-radius:12px;padding:12px;
      background:#0b0f14;color:var(--muted);white-space:pre-wrap
    }
    .ok{color:var(--green)}
    .fail{color:var(--red)}
    .warn{color:var(--gold)}
    .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace}
    .big{font-size:20px;font-weight:800;color:var(--gold)}
    .qrbox{
      min-height:280px;display:flex;align-items:center;justify-content:center;
      border:1px dashed var(--line);border-radius:14px;background:#0b0f14;padding:14px
    }
    .qrbox img{max-width:100%;height:auto;display:block}
    .log{
      min-height:260px;max-height:520px;overflow:auto;background:#0b0f14;
      border:1px solid var(--line);border-radius:14px;padding:14px;white-space:pre-wrap
    }
    .line{height:1px;background:var(--line);margin:14px 0}
    .health{display:flex;gap:10px;flex-wrap:wrap}
    .pill{
      display:inline-flex;align-items:center;gap:8px;padding:7px 10px;border:1px solid var(--line);
      border-radius:999px;background:#0b0f14
    }
    .dot{width:10px;height:10px;border-radius:50%}
    .dot.idle{background:#596270}.dot.ok{background:var(--green)}.dot.fail{background:var(--red)}.dot.warn{background:var(--gold)}
    .small{font-size:12px;color:var(--muted)}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="head">
      <div>
        <h1 class="title">Activate Card Final Tester</h1>
        <p class="sub">Storage Master v7.7 · multi-endpoint Activate Card · exact 100 EMX · token + amount + ref</p>
      </div>
      <div class="badge mono">Locked Paths Active</div>
    </div>

    <div class="grid">
      <section class="card half">
        <div class="k">Session Snapshot</div>
        <div class="line"></div>
        <div class="row"><div class="k">User ID</div><div class="v mono" id="userId"><?= h((string)($user['id'] ?? 0)) ?></div></div>
        <div class="row"><div class="k">Display Name</div><div class="v"><?= h($displayName) ?></div></div>
        <div class="row"><div class="k">Wallet Session</div><div class="v mono" id="walletSession"><?= h((string)($user['wallet'] ?? '')) ?></div></div>
        <div class="row"><div class="k">TON Address</div><div class="v mono" id="walletAddress"><?= h($walletAddress !== '' ? $walletAddress : '-') ?></div></div>
        <div class="row"><div class="k">Email</div><div class="v"><?= h($email !== '' ? $email : '-') ?></div></div>
        <div class="row"><div class="k">Email Verified</div><div class="v"><?= $emailVerified ? 'YES' : 'NO' ?></div></div>
        <div class="row"><div class="k">TON Bound</div><div class="v"><?= $hasTonBind ? 'YES' : 'NO' ?></div></div>
        <div class="row"><div class="k">CSRF Activate</div><div class="v mono" id="csrfActivate"><?= h($csrfActivate) ?></div></div>
      </section>

      <section class="card half">
        <div class="k">Locked Activate Endpoints</div>
        <div class="line"></div>
        <div class="row"><div class="k">Prepare</div><div class="v mono" id="epPrepare">/rwa/api/storage/activate-card/activate-prepare.php</div></div>
        <div class="row"><div class="k">Verify</div><div class="v mono" id="epVerify">/rwa/api/storage/activate-card/activate-verify.php</div></div>
        <div class="row"><div class="k">Confirm</div><div class="v mono" id="epConfirm">/rwa/api/storage/activate-card/activate-confirm.php</div></div>
        <div class="row"><div class="k">Router</div><div class="v mono" id="epRouter">/rwa/api/storage/activate-card/activate.php</div></div>
        <div class="actions">
          <button type="button" id="btnHealth" class="primary">Run Endpoint Health Test</button>
          <button type="button" id="btnClearLog">Clear Log</button>
        </div>
        <div class="line"></div>
        <div class="health" id="healthPills">
          <div class="pill"><span class="dot idle" id="dotPrepare"></span><span>Prepare</span></div>
          <div class="pill"><span class="dot idle" id="dotVerify"></span><span>Verify</span></div>
          <div class="pill"><span class="dot idle" id="dotConfirm"></span><span>Confirm</span></div>
          <div class="pill"><span class="dot idle" id="dotRouter"></span><span>Router</span></div>
        </div>
      </section>

      <section class="card third">
        <div class="k">Activation Input</div>
        <div class="line"></div>

        <div class="field">
          <label for="cardNumber">Card Number (16 digits)</label>
          <input id="cardNumber" class="mono" type="text" inputmode="numeric" maxlength="19" placeholder="0000 0000 0000 0000">
        </div>

        <div class="field">
          <label for="txHash">TX Hash (optional)</label>
          <input id="txHash" class="mono" type="text" autocomplete="off" spellcheck="false" placeholder="0x...">
        </div>

        <div class="field">
          <label for="lookbackSeconds">Lookback Seconds</label>
          <input id="lookbackSeconds" class="mono" type="text" value="604800">
        </div>

        <div class="field">
          <label for="minConfirmations">Min Confirmations</label>
          <input id="minConfirmations" class="mono" type="text" value="0">
        </div>

        <div class="actions">
          <button type="button" id="btnPrepare" class="gold">Prepare Activation</button>
          <button type="button" id="btnVerify" class="primary" disabled>Verify Activation</button>
          <button type="button" id="btnConfirm" class="green" disabled>Confirm Activation</button>
        </div>

        <div class="line"></div>
        <div class="status mono" id="actionStatus">Idle</div>
      </section>

      <section class="card third">
        <div class="k">Activation Result</div>
        <div class="line"></div>
        <div class="row"><div class="k">Activation Ref</div><div class="v mono" id="activationRef">-</div></div>
        <div class="row"><div class="k">Treasury</div><div class="v mono" id="treasuryAddress">-</div></div>
        <div class="row"><div class="k">Token</div><div class="v mono" id="tokenKey">-</div></div>
        <div class="row"><div class="k">Amount Display</div><div class="v mono" id="amountDisplay">-</div></div>
        <div class="row"><div class="k">Amount Units</div><div class="v mono" id="amountUnits">-</div></div>
        <div class="row"><div class="k">Memo</div><div class="v mono" id="memoText">-</div></div>
        <div class="row"><div class="k">TX Hash</div><div class="v mono" id="verifiedTxHash">-</div></div>
        <div class="row"><div class="k">Reward</div><div class="v big" id="rewardValue">-</div></div>
        <div class="actions">
          <button type="button" id="btnCopyRef" disabled>Copy Ref</button>
          <button type="button" id="btnCopyMemo" disabled>Copy Memo</button>
          <button type="button" id="btnOpenWallet" class="primary" disabled>Open Wallet</button>
        </div>
      </section>

      <section class="card third">
        <div class="k">QR / Deeplink</div>
        <div class="line"></div>
        <div class="qrbox" id="qrBox">
          <div class="small" id="qrEmpty">Prepare activation to render QR</div>
        </div>
        <div class="field">
          <label for="deeplink">Deeplink</label>
          <textarea id="deeplink" class="mono" rows="5" readonly></textarea>
        </div>
        <div class="actions">
          <button type="button" id="btnCopyLink" disabled>Copy Deeplink</button>
        </div>
      </section>

      <section class="card">
        <div class="k">Live Test Log</div>
        <div class="line"></div>
        <pre class="log mono" id="logBox">Tester ready.</pre>
      </section>
    </div>
  </div>

  <script>
    window.ACTIVATE_TESTER_BOOT = {
      csrf: <?= json_encode($csrfActivate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
      hasTonBind: <?= $hasTonBind ? 'true' : 'false' ?>,
      emailVerified: <?= $emailVerified ? 'true' : 'false' ?>,
      prepareUrl: "/rwa/api/storage/activate-card/activate-prepare.php",
      verifyUrl: "/rwa/api/storage/activate-card/activate-verify.php",
      confirmUrl: "/rwa/api/storage/activate-card/activate-confirm.php",
      routerUrl: "/rwa/api/storage/activate-card/activate.php"
    };
  </script>

  <script>
    (function () {
      "use strict";

      const BOOT = window.ACTIVATE_TESTER_BOOT || {};
      const els = {
        cardNumber: document.getElementById("cardNumber"),
        txHash: document.getElementById("txHash"),
        lookbackSeconds: document.getElementById("lookbackSeconds"),
        minConfirmations: document.getElementById("minConfirmations"),
        btnPrepare: document.getElementById("btnPrepare"),
        btnVerify: document.getElementById("btnVerify"),
        btnConfirm: document.getElementById("btnConfirm"),
        btnHealth: document.getElementById("btnHealth"),
        btnClearLog: document.getElementById("btnClearLog"),
        btnCopyRef: document.getElementById("btnCopyRef"),
        btnCopyMemo: document.getElementById("btnCopyMemo"),
        btnCopyLink: document.getElementById("btnCopyLink"),
        btnOpenWallet: document.getElementById("btnOpenWallet"),
        actionStatus: document.getElementById("actionStatus"),
        activationRef: document.getElementById("activationRef"),
        treasuryAddress: document.getElementById("treasuryAddress"),
        tokenKey: document.getElementById("tokenKey"),
        amountDisplay: document.getElementById("amountDisplay"),
        amountUnits: document.getElementById("amountUnits"),
        memoText: document.getElementById("memoText"),
        verifiedTxHash: document.getElementById("verifiedTxHash"),
        rewardValue: document.getElementById("rewardValue"),
        deeplink: document.getElementById("deeplink"),
        qrBox: document.getElementById("qrBox"),
        qrEmpty: document.getElementById("qrEmpty"),
        logBox: document.getElementById("logBox"),
        dotPrepare: document.getElementById("dotPrepare"),
        dotVerify: document.getElementById("dotVerify"),
        dotConfirm: document.getElementById("dotConfirm"),
        dotRouter: document.getElementById("dotRouter")
      };

      const state = {
        activationRef: "",
        memoText: "",
        deeplink: "",
        prepared: false,
        verified: false,
        confirmed: false
      };

      function now() {
        return new Date().toISOString();
      }

      function log(msg) {
        const line = `[${now()}] ${msg}`;
        els.logBox.textContent += (els.logBox.textContent ? "\n" : "") + line;
        els.logBox.scrollTop = els.logBox.scrollHeight;
      }

      function clearLog() {
        els.logBox.textContent = "Log cleared.";
      }

      function setStatus(text, type) {
        els.actionStatus.className = "status mono";
        if (type === "ok") els.actionStatus.classList.add("ok");
        if (type === "fail") els.actionStatus.classList.add("fail");
        if (type === "warn") els.actionStatus.classList.add("warn");
        els.actionStatus.textContent = text;
      }

      function setDot(el, type) {
        el.className = "dot " + (type || "idle");
      }

      function cleanCardNumber(v) {
        return String(v || "").replace(/\D+/g, "").slice(0, 16);
      }

      function formatCardNumber(v) {
        const n = cleanCardNumber(v);
        return n.replace(/(\d{4})(?=\d)/g, "$1 ").trim();
      }

      function copyText(v) {
        return navigator.clipboard.writeText(String(v || ""));
      }

      function setButtons() {
        els.btnVerify.disabled = !state.activationRef;
        els.btnConfirm.disabled = !state.activationRef;
        els.btnCopyRef.disabled = !state.activationRef;
        els.btnCopyMemo.disabled = !state.memoText;
        els.btnCopyLink.disabled = !state.deeplink;
        els.btnOpenWallet.disabled = !state.deeplink;
      }

      function fillPrepareResult(data) {
        state.activationRef = data.activation_ref || "";
        state.memoText = data.memo_text || data.activation_ref || "";
        state.deeplink = data.deeplink || data.ton_transfer_uri || data.qr_text || "";

        els.activationRef.textContent = state.activationRef || "-";
        els.treasuryAddress.textContent = data.treasury_address || "-";
        els.tokenKey.textContent = data.token_key || "-";
        els.amountDisplay.textContent = data.required_amount_display || "-";
        els.amountUnits.textContent = data.required_amount_units || "-";
        els.memoText.textContent = state.memoText || "-";
        els.deeplink.value = state.deeplink || "";
        renderQr(state.deeplink);
        setButtons();
      }

      function fillVerifyResult(data) {
        els.verifiedTxHash.textContent = data.tx_hash || "-";
        if (data.activation_ref) {
          state.activationRef = data.activation_ref;
          els.activationRef.textContent = data.activation_ref;
        }
        setButtons();
      }

      function fillConfirmResult(data) {
        els.verifiedTxHash.textContent = data.tx_hash || els.verifiedTxHash.textContent || "-";
        const reward = data.ema_reward ? `${data.ema_reward} ${data.reward_token || "EMA"}` : "-";
        els.rewardValue.textContent = reward;
      }

      function renderQr(text) {
        if (!text) {
          els.qrBox.innerHTML = '<div class="small" id="qrEmpty">Prepare activation to render QR</div>';
          return;
        }
        const src = "https://api.qrserver.com/v1/create-qr-code/?size=280x280&data=" + encodeURIComponent(text);
        els.qrBox.innerHTML = '<img alt="Activation QR" src="' + src + '">';
      }

      async function postJson(url, payload) {
        const t0 = performance.now();
        const res = await fetch(url, {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "Accept": "application/json"
          },
          credentials: "same-origin",
          body: JSON.stringify(payload)
        });
        const ms = Math.round(performance.now() - t0);

        let data = null;
        let raw = "";
        try {
          raw = await res.text();
          data = JSON.parse(raw);
        } catch (e) {
          data = { ok: false, code: "NON_JSON_RESPONSE", raw: raw };
        }

        return { res, data, ms };
      }

      async function healthTestOne(name, url, dotEl) {
        try {
          const t0 = performance.now();
          const res = await fetch(url, {
            method: "GET",
            headers: { "Accept": "application/json,text/html,*/*" },
            credentials: "same-origin"
          });
          const ms = Math.round(performance.now() - t0);
          const txt = await res.text();

          if (res.status === 405 || res.status === 400 || res.status === 401 || res.status === 403 || res.status === 419) {
            setDot(dotEl, "ok");
            log(`[OK] ${name} => reachable · HTTP ${res.status} · ${ms}ms`);
            return;
          }

          if (res.ok) {
            setDot(dotEl, "warn");
            log(`[WARN] ${name} => HTTP ${res.status} · ${ms}ms`);
            return;
          }

          setDot(dotEl, "fail");
          log(`[FAIL] ${name} => HTTP ${res.status} · ${ms}ms · ${txt.slice(0, 180)}`);
        } catch (e) {
          setDot(dotEl, "fail");
          log(`[FAIL] ${name} => ${e.message}`);
        }
      }

      async function runHealth() {
        setStatus("Running endpoint health test...", "warn");
        setDot(els.dotPrepare, "idle");
        setDot(els.dotVerify, "idle");
        setDot(els.dotConfirm, "idle");
        setDot(els.dotRouter, "idle");

        await healthTestOne("Prepare", BOOT.prepareUrl, els.dotPrepare);
        await healthTestOne("Verify", BOOT.verifyUrl, els.dotVerify);
        await healthTestOne("Confirm", BOOT.confirmUrl, els.dotConfirm);
        await healthTestOne("Router", BOOT.routerUrl, els.dotRouter);

        setStatus("Endpoint health test completed.", "ok");
      }

      async function doPrepare() {
        const cardNumber = cleanCardNumber(els.cardNumber.value);
        els.cardNumber.value = formatCardNumber(cardNumber);

        if (!BOOT.emailVerified) {
          setStatus("Email not verified. Activate flow may be blocked by business rules.", "warn");
        }

        if (!BOOT.hasTonBind) {
          setStatus("TON address is not bound.", "fail");
          return;
        }

        if (cardNumber.length !== 16) {
          setStatus("Card number must be exactly 16 digits.", "fail");
          return;
        }

        setStatus("Preparing activation...", "warn");
        log(`[INFO] Prepare start · card=${cardNumber}`);

        const payload = {
          csrf_token: BOOT.csrf,
          card_number: cardNumber
        };

        const { res, data, ms } = await postJson(BOOT.prepareUrl, payload);
        log(`[${data && data.ok ? "OK" : "FAIL"}] Prepare => ${data.code || "UNKNOWN"} · HTTP ${res.status} · ${ms}ms`);

        if (!data || !data.ok) {
          setStatus(JSON.stringify(data, null, 2), "fail");
          return;
        }

        fillPrepareResult(data);
        state.prepared = true;
        setStatus("Activation prepared successfully.", "ok");
      }

      async function doVerify() {
        if (!state.activationRef) {
          setStatus("Prepare activation first.", "fail");
          return;
        }

        setStatus("Verifying activation...", "warn");
        log(`[INFO] Verify start · ref=${state.activationRef}`);

        const payload = {
          csrf_token: BOOT.csrf,
          activation_ref: state.activationRef,
          tx_hash: String(els.txHash.value || "").trim(),
          lookback_seconds: parseInt(els.lookbackSeconds.value || "604800", 10) || 604800,
          min_confirmations: parseInt(els.minConfirmations.value || "0", 10) || 0
        };

        const { res, data, ms } = await postJson(BOOT.verifyUrl, payload);
        log(`[${data && data.ok ? "OK" : "FAIL"}] Verify => ${data.code || "UNKNOWN"} · HTTP ${res.status} · ${ms}ms`);

        if (!data || !data.ok) {
          setStatus(JSON.stringify(data, null, 2), "fail");
          return;
        }

        fillVerifyResult(data);
        state.verified = true;
        setStatus("Activation verify returned OK.", "ok");
      }

      async function doConfirm() {
        if (!state.activationRef) {
          setStatus("Prepare activation first.", "fail");
          return;
        }

        setStatus("Confirming activation...", "warn");
        log(`[INFO] Confirm start · ref=${state.activationRef}`);

        const payload = {
          csrf_token: BOOT.csrf,
          activation_ref: state.activationRef,
          tx_hash: String(els.txHash.value || "").trim(),
          lookback_seconds: parseInt(els.lookbackSeconds.value || "604800", 10) || 604800,
          min_confirmations: parseInt(els.minConfirmations.value || "0", 10) || 0
        };

        const { res, data, ms } = await postJson(BOOT.confirmUrl, payload);
        log(`[${data && data.ok ? "OK" : "FAIL"}] Confirm => ${data.code || "UNKNOWN"} · HTTP ${res.status} · ${ms}ms`);

        if (!data || !data.ok) {
          setStatus(JSON.stringify(data, null, 2), "fail");
          return;
        }

        fillConfirmResult(data);
        state.confirmed = true;
        setStatus("Activation confirmed successfully.", "ok");
      }

      els.cardNumber.addEventListener("input", function () {
        const pos = this.selectionStart || 0;
        const before = this.value;
        const cleaned = cleanCardNumber(before);
        this.value = formatCardNumber(cleaned);
        try { this.setSelectionRange(pos, pos); } catch (e) {}
      });

      els.btnHealth.addEventListener("click", runHealth);
      els.btnClearLog.addEventListener("click", clearLog);
      els.btnPrepare.addEventListener("click", doPrepare);
      els.btnVerify.addEventListener("click", doVerify);
      els.btnConfirm.addEventListener("click", doConfirm);

      els.btnCopyRef.addEventListener("click", async function () {
        if (!state.activationRef) return;
        await copyText(state.activationRef);
        setStatus("Activation ref copied.", "ok");
      });

      els.btnCopyMemo.addEventListener("click", async function () {
        if (!state.memoText) return;
        await copyText(state.memoText);
        setStatus("Activation memo copied.", "ok");
      });

      els.btnCopyLink.addEventListener("click", async function () {
        if (!state.deeplink) return;
        await copyText(state.deeplink);
        setStatus("Activation deeplink copied.", "ok");
      });

      els.btnOpenWallet.addEventListener("click", function () {
        if (!state.deeplink) return;
        window.open(state.deeplink, "_blank", "noopener");
      });

      setButtons();
      log("[INFO] Activate tester ready.");
      log("[INFO] Locked prepare path => " + BOOT.prepareUrl);
      log("[INFO] Locked verify path  => " + BOOT.verifyUrl);
      log("[INFO] Locked confirm path => " + BOOT.confirmUrl);
    })();
  </script>
</body>
</html>