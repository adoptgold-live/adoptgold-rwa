<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

if (function_exists('session_user_id') && (int) session_user_id() > 0) {
    header('Location: /rwa/login-select.php');
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Telegram PIN Login</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root{
      --bg:#080315;
      --panel:#1a1233;
      --panel-2:#241b42;
      --panel-3:#0b0620;
      --line:rgba(170,134,255,.28);
      --line-soft:rgba(170,134,255,.18);
      --text:#f4efff;
      --muted:#c8bde8;
      --muted-2:#9a91b7;
      --btn:#3a3156;
      --grad-1:#b08cff;
      --grad-2:#8cb7ff;
      --grad-3:#74e3bf;
      --danger:#ff9fa3;
      --ok:#8af0be;
      --shadow:0 0 0 1px rgba(170,134,255,.08), 0 24px 80px rgba(0,0,0,.42);
    }

    *{box-sizing:border-box}

    html,body{
      margin:0;
      padding:0;
      min-height:100%;
      background:
        radial-gradient(circle at top center, rgba(96,61,170,.18), transparent 38%),
        linear-gradient(180deg, #110622 0%, #080315 100%);
      color:var(--text);
      font-family: Inter, ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;
    }

    body{
      min-height:100vh;
      display:flex;
      align-items:center;
      justify-content:center;
      padding:24px;
    }

    .shell{
      width:100%;
      max-width:680px;
      background:linear-gradient(180deg, rgba(33,22,65,.98), rgba(23,16,48,.98));
      border:1px solid var(--line);
      border-radius:32px;
      box-shadow:var(--shadow);
      padding:20px 20px 22px;
    }

    .back-row{
      margin-bottom:6px;
    }

    .back-link{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-width:112px;
      height:52px;
      padding:0 18px;
      border-radius:999px;
      border:1px solid var(--line);
      background:rgba(255,255,255,.03);
      color:var(--text);
      text-decoration:none;
      font-weight:600;
      font-size:16px;
      transition:.18s ease;
    }

    .back-link:hover{
      background:rgba(255,255,255,.06);
      border-color:rgba(170,134,255,.45);
    }

    .hero{
      text-align:center;
      padding:8px 8px 2px;
    }

    .hero h1{
      margin:0;
      font-size:56px;
      line-height:1.04;
      font-weight:900;
      letter-spacing:-0.03em;
      color:#f6f0ff;
    }

    .hero p{
      margin:10px 0 0;
      font-size:19px;
      line-height:1.4;
      color:var(--muted);
      font-weight:500;
    }

    .card{
      margin:18px auto 16px;
      background:linear-gradient(180deg, rgba(41,31,76,.96), rgba(34,26,65,.96));
      border:1px solid var(--line);
      border-radius:24px;
      padding:20px;
    }

    .pin-display{
      width:100%;
      height:68px;
      border-radius:20px;
      border:1px solid rgba(157,122,255,.28);
      background:linear-gradient(180deg, rgba(5,2,18,.96), rgba(10,6,30,.98));
      display:flex;
      align-items:center;
      justify-content:center;
      gap:14px;
      padding:0 14px;
      margin-bottom:18px;
      box-shadow:inset 0 0 0 1px rgba(255,255,255,.01);
    }

    .digit{
      width:34px;
      border:0;
      outline:none;
      background:transparent;
      color:rgba(255,255,255,.48);
      text-align:center;
      font-size:24px;
      font-weight:500;
      line-height:1;
      padding:0;
      caret-color:#e8dcff;
    }

    .digit.filled,
    .digit:focus{
      color:#f5f0ff;
    }

    .btn{
      width:100%;
      border:0;
      outline:none;
      cursor:pointer;
      border-radius:18px;
      min-height:58px;
      font-size:18px;
      font-weight:800;
      transition:transform .14s ease, opacity .14s ease, box-shadow .14s ease;
      text-decoration:none;
      display:flex;
      align-items:center;
      justify-content:center;
      text-align:center;
    }

    .btn:hover{ transform:translateY(-1px); }
    .btn:active{ transform:translateY(0); }
    .btn[disabled]{ opacity:.7; cursor:not-allowed; transform:none; }

    .btn-bot{
      margin-bottom:14px;
      background:var(--btn);
      color:#fff8ff;
      border:1px solid rgba(185,165,255,.16);
      box-shadow:inset 0 1px 0 rgba(255,255,255,.05);
    }

    .btn-login{
      background:linear-gradient(90deg, var(--grad-1) 0%, var(--grad-2) 48%, var(--grad-3) 100%);
      color:#130b25;
      box-shadow:0 10px 28px rgba(113,227,191,.14);
    }

    .hint{
      margin:18px 6px 0;
      text-align:center;
      color:var(--muted);
      font-size:15px;
      line-height:1.55;
      font-weight:500;
    }

    .footer{
      text-align:center;
      color:#d6cdee;
      font-size:14px;
      line-height:1.55;
      padding:4px 10px 0;
    }

    .status{
      display:none;
      margin:14px 2px 0;
      border-radius:16px;
      padding:12px 14px;
      font-size:14px;
      line-height:1.5;
      text-align:center;
      white-space:pre-wrap;
    }

    .status.show{ display:block; }
    .status.err{
      display:block;
      color:var(--danger);
      background:rgba(255,159,163,.08);
      border:1px solid rgba(255,159,163,.22);
    }
    .status.ok{
      display:block;
      color:var(--ok);
      background:rgba(138,240,190,.08);
      border:1px solid rgba(138,240,190,.22);
    }

    @media (max-width: 760px){
      .shell{
        border-radius:28px;
        padding:18px 16px 20px;
      }

      .hero h1{
        font-size:40px;
      }

      .hero p{
        font-size:17px;
      }

      .card{
        padding:18px;
        border-radius:22px;
      }

      .pin-display{
        height:64px;
        gap:10px;
      }

      .digit{
        width:28px;
        font-size:22px;
      }

      .btn{
        min-height:56px;
        font-size:17px;
      }
    }

    @media (max-width: 480px){
      body{
        padding:14px;
      }

      .hero h1{
        font-size:32px;
      }

      .hero p{
        font-size:16px;
      }

      .back-link{
        min-width:106px;
        height:48px;
        font-size:15px;
      }

      .pin-display{
        gap:6px;
        padding:0 10px;
      }

      .digit{
        width:24px;
        font-size:20px;
      }

      .footer{
        font-size:13px;
      }
    }
  </style>
</head>
<body>
  <div class="shell">
    <div class="back-row">
      <a class="back-link" href="/rwa/index.php">Back to Hub</a>
    </div>

    <div class="hero">
      <h1>Telegram PIN Login</h1>
      <p>Use PIN from @adoptgold_bot</p>
    </div>

    <div class="card">
      <form id="pinForm" autocomplete="one-time-code" novalidate>
        <div class="pin-display" id="pinDisplay" aria-label="6 digit pin">
          <input class="digit" type="text" inputmode="numeric" maxlength="1" autocomplete="one-time-code" aria-label="Digit 1">
          <input class="digit" type="text" inputmode="numeric" maxlength="1" aria-label="Digit 2">
          <input class="digit" type="text" inputmode="numeric" maxlength="1" aria-label="Digit 3">
          <input class="digit" type="text" inputmode="numeric" maxlength="1" aria-label="Digit 4">
          <input class="digit" type="text" inputmode="numeric" maxlength="1" aria-label="Digit 5">
          <input class="digit" type="text" inputmode="numeric" maxlength="1" aria-label="Digit 6">
        </div>

        <a
          class="btn btn-bot"
          href="https://t.me/adoptgold_bot"
          target="_blank"
          rel="noopener noreferrer"
        >Open Bot</a>

        <button type="submit" class="btn btn-login" id="loginBtn">Login</button>

        <div id="statusBox" class="status"></div>

        <div class="hint">
          Send /login in the Telegram bot to get your 6-digit PIN.
        </div>
      </form>
    </div>

    <div class="footer">
      © 2025 Blockchain Group Ltd. (Hong Kong) · RWA Standard Organisation (RSO). All rights reserved.
    </div>
  </div>

  <script>
    (function () {
      const form = document.getElementById('pinForm');
      const loginBtn = document.getElementById('loginBtn');
      const statusBox = document.getElementById('statusBox');
      const digits = Array.from(document.querySelectorAll('.digit'));

      function setFilledState(input) {
        if ((input.value || '').trim() !== '') {
          input.classList.add('filled');
        } else {
          input.classList.remove('filled');
        }
      }

      function clearStatus() {
        statusBox.className = 'status';
        statusBox.textContent = '';
      }

      function showStatus(type, text) {
        statusBox.className = 'status show ' + type;
        statusBox.textContent = text;
      }

      function getPin() {
        return digits.map(el => el.value.trim()).join('');
      }

      function focusDigit(index) {
        if (digits[index]) {
          digits[index].focus();
          digits[index].select?.();
        }
      }

      function sanitizeOne(val) {
        return String(val || '').replace(/\D+/g, '').slice(0, 1);
      }

      digits.forEach((input, idx) => {
        input.addEventListener('input', () => {
          input.value = sanitizeOne(input.value);
          setFilledState(input);

          if (input.value && idx < digits.length - 1) {
            focusDigit(idx + 1);
          }
        });

        input.addEventListener('keydown', (e) => {
          if (e.key === 'Backspace') {
            if (input.value) {
              input.value = '';
              setFilledState(input);
              e.preventDefault();
              return;
            }
            if (idx > 0) {
              focusDigit(idx - 1);
              digits[idx - 1].value = '';
              setFilledState(digits[idx - 1]);
              e.preventDefault();
            }
          }

          if (e.key === 'ArrowLeft' && idx > 0) {
            focusDigit(idx - 1);
            e.preventDefault();
          }

          if (e.key === 'ArrowRight' && idx < digits.length - 1) {
            focusDigit(idx + 1);
            e.preventDefault();
          }
        });

        input.addEventListener('focus', () => {
          input.select?.();
          setFilledState(input);
        });

        input.addEventListener('paste', (e) => {
          const pasted = (e.clipboardData || window.clipboardData).getData('text') || '';
          const nums = pasted.replace(/\D+/g, '').slice(0, 6).split('');
          if (!nums.length) return;

          e.preventDefault();
          digits.forEach((d, i) => {
            d.value = nums[i] || '';
            setFilledState(d);
          });

          const nextIndex = Math.min(nums.length, 5);
          focusDigit(nextIndex);
        });
      });

      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        clearStatus();

        const pin = getPin();
        if (!/^\d{6}$/.test(pin)) {
          showStatus('err', 'Please enter a valid 6-digit PIN.');
          focusDigit(0);
          return;
        }

        loginBtn.disabled = true;
        loginBtn.textContent = 'Checking...';

        try {
          const res = await fetch('/rwa/auth/tg/login.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ pin })
          });

          let data = {};
          try {
            data = await res.json();
          } catch (err) {
            throw new Error('Invalid JSON response');
          }

          if (!res.ok || !data.ok) {
            const msg = String(data.error || data.message || 'Login failed');
            showStatus('err', msg);
            loginBtn.disabled = false;
            loginBtn.textContent = 'Login';
            return;
          }

          showStatus('ok', 'Login success. Redirecting...');
          const nextUrl = String(data.next_url || '/rwa/login-select.php');
          const safeUrl = nextUrl.startsWith('/') ? nextUrl : '/rwa/login-select.php';

          setTimeout(() => {
            window.location.href = safeUrl;
          }, 450);
        } catch (err) {
          showStatus('err', String(err.message || 'Login failed'));
          loginBtn.disabled = false;
          loginBtn.textContent = 'Login';
        }
      });

      digits.forEach(setFilledState);
      focusDigit(0);
    })();
  </script>
</body>
</html>