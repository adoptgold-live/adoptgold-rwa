<?php
// /var/www/html/public/rwa/legal/terms.php
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
  <title>Terms • RWA</title>

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
        <span>RWA TERMS</span>
        <span style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;">
          <span class="tag">MOBILE CRT</span>
          <span class="tag purple">STANDALONE</span>
        </span>
      </div>
      <div class="sub">
        Terms of use for RWA modules governed by applicable Dubai and UAE laws.
      </div>
    </div>

    <div class="content">
      <div class="card">
        <h1>Terms of Use</h1>
        <p>
          These Terms govern access to and use of the RWA modules under <b>/rwa/</b>. By using the Service,
          you agree to these Terms to the extent permitted by applicable law.
        </p>

        <h2>1. Nature of service</h2>
        <p>
          The Service is intended for certificate, verification, audit, wallet-linked access, and related digital workflow functions.
          Nothing on the Service should be interpreted as legal, investment, banking, custody, securities, or regulated financial advice.
        </p>

        <h2>2. Eligibility and compliance</h2>
        <ul>
          <li>You must use the Service only in compliance with applicable law and lawful purpose.</li>
          <li>You are responsible for safeguarding your authentication tools, wallet credentials, and linked access methods.</li>
          <li>You must not attempt to manipulate records, bypass controls, interfere with infrastructure, or abuse platform functions.</li>
        </ul>

        <h2>3. Electronic records and network dependence</h2>
        <p>
          The Service may rely on electronic records, cryptographic proofs, wallet signatures, third-party infrastructure, and public blockchain systems.
          Transaction finality, fees, congestion, outages, and third-party wallet behaviour are not fully controlled by the Service.
        </p>

        <h2>4. Accuracy and availability</h2>
        <p>
          The Service may be updated, limited, suspended, or discontinued at any time for maintenance, security, legal, regulatory, or operational reasons.
          Features, outputs, and interfaces may change without prior notice where reasonably necessary.
        </p>

        <h2>5. User conduct</h2>
        <ul>
          <li>No unlawful use, fraud, impersonation, sanctions evasion, or prohibited transaction activity.</li>
          <li>No reverse engineering, unauthorised automation, scraping, probing, or attack activity against the platform.</li>
          <li>No misuse of certificates, verify routes, platform brands, compliance wording, or public-facing materials.</li>
        </ul>

        <h2>6. Intellectual property and platform materials</h2>
        <p>
          Platform code, layouts, branding, certificate formats, verification interfaces, and related materials remain protected
          to the extent permitted under applicable law unless expressly stated otherwise.
        </p>

        <h2>7. Limitation of liability</h2>
        <p>
          To the maximum extent permitted by applicable law, the Service is provided on an “as is” and “as available” basis.
          Indirect, incidental, consequential, exemplary, or special losses are excluded to the fullest extent legally permitted.
        </p>

        <h2>8. Governing law and jurisdiction</h2>
        <p>
          These Terms shall be governed by the laws applicable in the Emirate of Dubai and the federal laws of the United Arab Emirates.
          Subject to any mandatory law that requires otherwise, disputes arising from or related to the Service shall be submitted
          to the competent courts of Dubai.
        </p>

        <h2>9. Updates to these Terms</h2>
        <p>
          These Terms may be amended from time to time. Continued use of the Service after an update constitutes acceptance of the revised Terms.
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
