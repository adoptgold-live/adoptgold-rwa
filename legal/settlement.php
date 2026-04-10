<?php
// /var/www/html/public/rwa/legal/settlement.php
declare(strict_types=1);

require __DIR__ . '/../../dashboard/inc/bootstrap.php';
require __DIR__ . '/../../dashboard/inc/session-user.php';

header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"/>
  <meta name="robots" content="noindex,nofollow"/>
  <title>Settlement • RWA</title>

  <style>
    :root{
      --bg:#05060a; --bg2:#070916;
      --crt:#00ff86; --crtDim:rgba(0,255,134,.72);
      --purple:#9b5fff; --line2:rgba(155,95,255,.22);
      --radius:18px; --shadow:0 18px 60px rgba(0,0,0,.55);
    }
    *{box-sizing:border-box}
    html,body{height:100%}
    body{
      margin:0;
      background:
        radial-gradient(820px 520px at 22% 10%, rgba(155,95,255,.20), transparent 55%),
        radial-gradient(740px 520px at 85% 25%, rgba(111,60,255,.14), transparent 55%),
        linear-gradient(180deg,var(--bg2),var(--bg));
      color:var(--crt);
      font-family:ui-monospace,Menlo,Consolas,monospace;
      padding: env(safe-area-inset-top) env(safe-area-inset-right) env(safe-area-inset-bottom) env(safe-area-inset-left);
      -webkit-tap-highlight-color: transparent;
    }
    body:before{
      content:""; position:fixed; inset:0;
      background:repeating-linear-gradient(to bottom,rgba(255,255,255,.02),rgba(255,255,255,.02) 1px,rgba(0,0,0,0) 2px,rgba(0,0,0,0) 4px);
      pointer-events:none; mix-blend-mode:overlay; opacity:.35;
    }
    .wrap{min-height:100%;display:flex;justify-content:center;padding:16px}
    .screen{
      width:min(860px,100%);
      border:1px solid var(--line2);
      border-radius:calc(var(--radius) + 6px);
      background:linear-gradient(180deg,rgba(0,0,0,.55),rgba(0,0,0,.35));
      box-shadow:var(--shadow),0 0 0 1px rgba(0,255,134,.08) inset;
      overflow:hidden;
    }
    .hdr{
      padding:16px 16px 12px;
      border-bottom:1px solid rgba(155,95,255,.22);
      background:linear-gradient(180deg,rgba(155,95,255,.12),transparent);
    }
    .brand{font-weight:900;letter-spacing:.8px;text-transform:uppercase;display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap}
    .tag{
      display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;
      border:1px solid rgba(0,255,134,.22);background:rgba(0,255,134,.08);color:var(--crt);font-size:12px;white-space:nowrap
    }
    .tag.purple{border-color:rgba(155,95,255,.32);background:rgba(155,95,255,.10);color:rgba(239,233,255,.92)}
    .sub{margin-top:8px;font-size:12px;color:var(--crtDim);line-height:1.5}
    .content{padding:14px 16px 0}
    .card{
      border:1px solid rgba(0,255,134,.16);
      border-radius:var(--radius);
      background:linear-gradient(180deg,rgba(0,0,0,.42),rgba(8,10,20,.62));
      box-shadow:0 0 0 1px rgba(155,95,255,.08) inset;
      padding:14px;
    }
    h1{margin:0 0 10px 0;font-size:14px;letter-spacing:.6px;color:rgba(239,233,255,.92);text-transform:uppercase}
    h2{margin:14px 0 8px 0;font-size:13px;letter-spacing:.4px;color:rgba(239,233,255,.92);text-transform:uppercase}
    p,li{font-size:12px;color:var(--crtDim);line-height:1.6}
    ul{margin:8px 0 0 18px;padding:0}
    .back{
      display:inline-flex;align-items:center;gap:8px;
      margin-top:10px;
      padding:10px 14px;
      border-radius:999px;
      border:1px solid rgba(0,255,134,.22);
      background:rgba(0,0,0,.22);
      color:rgba(0,255,134,.95);
      text-decoration:none;
      font-size:12px;
    }
    .back:hover{border-color:rgba(155,95,255,.35);background:rgba(155,95,255,.12)}
    .rwa-legal-footer{
      margin-top:18px;
      padding:18px 16px 20px;
      border-top:1px solid rgba(0,255,134,.18);
      text-align:center;
      background: linear-gradient(180deg, rgba(0,0,0,.05), rgba(0,0,0,.25));
    }
    .rwa-legal-text{
      color: rgba(0,255,134,.85);
      font-size:12px;
      letter-spacing:.4px;
    }
    .rwa-legal-links{
      margin-top:12px;
      display:flex;
      justify-content:center;
      gap:12px;
      flex-wrap:wrap;
    }
    .rwa-legal-btn{
      padding:8px 16px;
      border-radius:999px;
      border:1px solid rgba(0,255,134,.22);
      background:rgba(0,0,0,.25);
      color:rgba(0,255,134,.95);
      text-decoration:none;
      font-size:12px;
    }
    .rwa-legal-btn:hover{
      border-color:rgba(155,95,255,.35);
      background:rgba(155,95,255,.12);
    }
  </style>
</head>

<body>
<div class="wrap">
  <div class="screen">

    <div class="hdr">
      <div class="brand">
        <span>RWA SETTLEMENT</span>
        <span style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;">
          <span class="tag">MOBILE CRT</span>
          <span class="tag purple">STANDALONE</span>
        </span>
      </div>
      <div class="sub">
        Settlement and EUR payout notice for RWA modules under Dubai/UAE legal framing.
      </div>
    </div>

    <div class="content">
      <div class="card">
        <h1>Settlement & EUR Payout Notice</h1>

        <p>
          All supported fiat settlement and payout processing, where available, is intended to be handled in EUR
          to eligible IBAN-enabled bank accounts, subject to platform controls, compliance review, banking acceptance,
          and operational availability.
        </p>

        <h2>1. Service scope</h2>
        <p>
          Blockchain Group RWA FZCO (DMCC, Dubai, UAE) does not describe itself on this page as a bank, remittance house,
          money services business, or regulated payment institution. Any payout execution may depend on independent
          settlement partners, banking channels, or third-party service providers, where applicable.
        </p>

        <h2>2. Currency notice</h2>
        <ul>
          <li>No guarantee is made that USD, CNY, MYR, or other fiat rails will be available.</li>
          <li>RWA€ is described as an internal reference unit pegged to EUR on a 1:1 accounting basis within platform logic.</li>
          <li>Internal conversion, booking, or accounting displays do not by themselves create a bank deposit, stored-value claim, or regulated e-money promise.</li>
        </ul>

        <h2>3. Settlement conditions</h2>
        <ul>
          <li>Settlement may be subject to KYC, compliance review, sanctions screening, fraud controls, and transaction verification.</li>
          <li>Banking intermediaries or payout partners may reject, delay, request more information, or return a transfer.</li>
          <li>Processing times may vary based on banking cut-off windows, holidays, review requirements, and partner availability.</li>
        </ul>

        <h2>4. Regional legal position</h2>
        <p>
          This notice is intended to be read under the laws applicable in the Emirate of Dubai and the federal laws of the United Arab Emirates.
          Where banking, SEPA, or EU-region partner rails are involved, the relevant third-party provider may also apply its own legal and compliance requirements.
        </p>

        <h2>5. No representation beyond actual availability</h2>
        <p>
          Nothing on this page guarantees immediate availability of a payout route, bank onboarding, currency corridor,
          settlement speed, or acceptance by any third-party provider.
        </p>

        <a class="back" data-click-sfx href="/rwa/index.php">← Back to RWA</a>
      </div>

      <div class="rwa-legal-footer">
        <div class="rwa-legal-text">
          © 2026 Blockchain Group RWA FZCO (DMCC, Dubai, UAE) · RWA Standard Organisation (RSO). All rights reserved.
        </div>
        <div class="rwa-legal-links">
          <a class="rwa-legal-btn" data-click-sfx href="/rwa/legal/privacy.php">Privacy</a>
          <a class="rwa-legal-btn" data-click-sfx href="/rwa/legal/terms.php">Terms</a>
          <a class="rwa-legal-btn" data-click-sfx href="/rwa/legal/settlement.php">Settlement</a>
        </div>
      </div>

    </div>

  </div>
</div>

<script src="/dashboard/assets/js/sfx.js"></script>
<script src="/dashboard/inc/poado-i18n.js?v=1"></script>
<?php require __DIR__ . '/../../dashboard/inc/gt.php'; ?>
</body>
</html>
