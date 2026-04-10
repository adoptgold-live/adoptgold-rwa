<?php
// /var/www/html/public/rwa/legal/privacy.php
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
  <title>Privacy • RWA</title>

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
        <span>RWA PRIVACY</span>
        <span style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;">
          <span class="tag">MOBILE CRT</span>
          <span class="tag purple">STANDALONE</span>
        </span>
      </div>
      <div class="sub">
        Privacy notice for RWA modules operated under the laws applicable in Dubai, United Arab Emirates.
      </div>
    </div>

    <div class="content">
      <div class="card">
        <h1>Privacy Notice</h1>
        <p>
          This Privacy Notice describes how RWA pages under <b>/rwa/</b> may collect, use, store, and protect
          information in connection with authentication, certificate workflows, verification, payment support,
          security monitoring, and platform operations.
        </p>

        <h2>1. Applicable legal framework</h2>
        <p>
          This Service is intended to operate in alignment with applicable laws of the Emirate of Dubai, UAE federal law,
          and relevant data protection and electronic transactions requirements in the United Arab Emirates.
        </p>

        <h2>2. Categories of data we may process</h2>
        <ul>
          <li><b>Identity and access data</b>: internal user ID, Telegram-related identifiers, wallet address data, session identifiers, login timestamps, and access-control records.</li>
          <li><b>Certificate and transaction data</b>: cert UID, verify route data, payment reference data, on-chain references, NFT item references, and related audit metadata.</li>
          <li><b>Technical and security data</b>: device/browser information, IP address, error traces, fraud-prevention logs, and operational monitoring events.</li>
          <li><b>Support and compliance data</b>: communications, dispute-handling records, compliance review notes, and legally required retention items.</li>
        </ul>

        <h2>3. Purposes of processing</h2>
        <ul>
          <li>To authenticate users and maintain secure account sessions.</li>
          <li>To issue, verify, render, and manage RWA certificate and related platform functions.</li>
          <li>To maintain auditability, fraud prevention, abuse detection, and platform security.</li>
          <li>To comply with applicable legal, regulatory, and internal governance obligations.</li>
        </ul>

        <h2>4. On-chain and public-record data</h2>
        <p>
          Some data used by the Service may reference public blockchain records. Public on-chain records are generally
          outside the exclusive control of the Service and may remain visible according to the rules of the relevant network.
        </p>

        <h2>5. Data sharing</h2>
        <p>
          We do not describe this Service as selling personal data. Limited disclosures may occur to infrastructure,
          security, messaging, storage, or compliance providers strictly where required to operate, secure, support,
          or legally comply with the Service.
        </p>

        <h2>6. Retention</h2>
        <p>
          Data may be retained for security operations, audit integrity, dispute handling, service continuity,
          regulatory compliance, and record-keeping purposes for periods considered necessary under applicable law
          and legitimate operational requirements.
        </p>

        <h2>7. Security</h2>
        <p>
          We use technical and organisational safeguards appropriate to the Service, including access control,
          logging, tokenisation or hashing where applicable, and operational monitoring. No system or transmission
          method can be guaranteed to be completely secure.
        </p>

        <h2>8. Cross-border or third-party systems</h2>
        <p>
          The Service may rely on third-party tools, infrastructure, or public blockchain systems that process or
          display data outside a single jurisdiction. Use of such systems may involve cross-border data flows
          consistent with the Service architecture and applicable legal requirements.
        </p>

        <h2>9. Contact and requests</h2>
        <p>
          For privacy, compliance, or data-handling enquiries, please use the official support channel available within the application.
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
