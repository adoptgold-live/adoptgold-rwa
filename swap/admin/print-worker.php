<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/qr.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/swap/inc/swap-helpers.php';

$user = swap_require_admin_or_agent();

$workerUid = trim((string)($_GET['uid'] ?? $_GET['worker_uid'] ?? ''));
if ($workerUid === '') {
    http_response_code(422);
    exit('WORKER UID REQUIRED');
}

$worker = swap_require_worker_print_access($workerUid, $user);

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function showv($v): string {
    $s = trim((string)$v);
    return $s !== '' ? $s : '-';
}

$wa = swap_wa_link((string)($worker['mobile_e164'] ?? ''));
$waQr = '';
if ($wa !== '' && function_exists('poado_qr_svg_data_uri')) {
    try {
        $waQr = (string)poado_qr_svg_data_uri($wa);
    } catch (Throwable $e) {
        $waQr = '';
    }
}

$projectShort = swap_project_short((string)($worker['project_key'] ?? ''));
$title = 'RHRD-EMA · Tertiary RWA — Human Resource Development';
$subtitle = 'Worker Welfare & Contribution Record';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Worker Print</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    @page { size: A4 portrait; margin: 14mm; }
    html, body { margin: 0; padding: 0; background: #fff; color: #111; font: 12px/1.4 Arial, Helvetica, sans-serif; }
    .page { width: 100%; }
    .head { display:flex; justify-content:space-between; gap:16px; border-bottom: 2px solid #222; padding-bottom: 10px; margin-bottom: 12px; }
    .head h1 { margin: 0 0 4px; font-size: 18px; }
    .head .sub { color:#444; font-size: 12px; }
    .qrbox { width: 120px; text-align:center; }
    .qrbox img { width: 100px; height: 100px; display:block; margin: 0 auto 6px; }
    .section { border: 1px solid #bbb; border-radius: 8px; padding: 10px; margin-bottom: 10px; page-break-inside: avoid; }
    .section h2 { margin: 0 0 8px; font-size: 14px; }
    .grid { display:grid; grid-template-columns: 1fr 1fr; gap: 8px 14px; }
    .item .label { display:block; color:#555; font-size:11px; margin-bottom:2px; }
    .item .value { font-weight:600; word-break: break-word; }
    .wide { grid-column: span 2; }
    .memo { min-height: 60px; white-space: pre-wrap; }
    .foot { margin-top: 10px; padding-top: 8px; border-top: 1px solid #bbb; color:#555; font-size:11px; }
    .pill { display:inline-block; padding:2px 8px; border:1px solid #666; border-radius:999px; font-size:11px; }
    @media print {
      .noprint { display:none !important; }
    }
  </style>
</head>
<body>
  <div class="page">
    <div class="head">
      <div>
        <h1><?= h($title) ?></h1>
        <div class="sub"><?= h($subtitle) ?></div>
        <div class="sub">Generated: <?= h(date('Y-m-d H:i:s')) ?></div>
      </div>

      <div class="qrbox">
        <?php if ($waQr !== ''): ?>
          <img src="<?= h($waQr) ?>" alt="WhatsApp QR">
          <div style="font-size:11px;">Scan to Contact via WhatsApp</div>
        <?php else: ?>
          <div class="sub">No WhatsApp QR</div>
        <?php endif; ?>
      </div>
    </div>

    <div class="section">
      <h2>Header</h2>
      <div class="grid">
        <div class="item">
          <span class="label">Worker UID</span>
          <div class="value"><?= h(showv($worker['worker_uid'] ?? '')) ?></div>
        </div>
        <div class="item">
          <span class="label">Project</span>
          <div class="value"><?= h(showv($projectShort)) ?></div>
        </div>
        <div class="item">
          <span class="label">Worker Status</span>
          <div class="value"><span class="pill"><?= h(showv($worker['worker_status'] ?? '')) ?></span></div>
        </div>
        <div class="item">
          <span class="label">Welfare Band</span>
          <div class="value"><?= h(showv($worker['welfare_band'] ?? '')) ?></div>
        </div>
      </div>
    </div>

    <div class="section">
      <h2>Identity</h2>
      <div class="grid">
        <div class="item">
          <span class="label">Full Name</span>
          <div class="value"><?= h(showv($worker['full_name'] ?? '')) ?></div>
        </div>
        <div class="item">
          <span class="label">Passport No</span>
          <div class="value"><?= h(showv($worker['passport_no'] ?? '')) ?></div>
        </div>
        <div class="item">
          <span class="label">Nationality</span>
          <div class="value"><?= h(showv(($worker['nationality'] ?? '') === 'OTHER' ? ($worker['nationality_other'] ?? '') : ($worker['nationality'] ?? ''))) ?></div>
        </div>
        <div class="item">
          <span class="label">Gender</span>
          <div class="value"><?= h(showv($worker['gender'] ?? '')) ?></div>
        </div>
        <div class="item">
          <span class="label">Date of Birth</span>
          <div class="value"><?= h(showv($worker['date_of_birth'] ?? '')) ?></div>
        </div>
      </div>
    </div>

    <div class="section">
      <h2>Contact</h2>
      <div class="grid">
        <div class="item">
          <span class="label">Mobile</span>
          <div class="value"><?= h(showv($worker['mobile_e164'] ?? '')) ?></div>
        </div>
        <div class="item">
          <span class="label">Telegram</span>
          <div class="value"><?= h(showv($worker['tg_username'] ?? '')) ?></div>
        </div>
        <div class="item wide">
          <span class="label">WhatsApp Link</span>
          <div class="value"><?= h(showv($wa)) ?></div>
        </div>
      </div>
    </div>

    <div class="section">
      <h2>Job / Placement</h2>
      <div class="grid">
        <div class="item">
          <span class="label">Employer</span>
          <div class="value"><?= h(showv($worker['employer_name'] ?? '')) ?></div>
        </div>
        <div class="item">
          <span class="label">Job Title</span>
          <div class="value"><?= h(showv($worker['job_title'] ?? '')) ?></div>
        </div>
        <div class="item">
          <span class="label">Sector</span>
          <div class="value"><?= h(showv($worker['sector'] ?? '')) ?></div>
        </div>
        <div class="item">
          <span class="label">Site Name</span>
          <div class="value"><?= h(showv($worker['site_name'] ?? '')) ?></div>
        </div>
        <div class="item">
          <span class="label">Arrival Date</span>
          <div class="value"><?= h(showv($worker['arrival_date'] ?? '')) ?></div>
        </div>
        <div class="item">
          <span class="label">Start Date</span>
          <div class="value"><?= h(showv($worker['start_date'] ?? '')) ?></div>
        </div>
      </div>
    </div>

    <div class="section">
      <h2>Compliance / Welfare</h2>
      <div class="grid">
        <div class="item">
          <span class="label">Permit Status</span>
          <div class="value"><?= h(showv($worker['immigration_status'] ?? '')) ?></div>
        </div>
        <div class="item">
          <span class="label">Permit Expiry</span>
          <div class="value"><?= h(showv($worker['permit_expiry'] ?? '')) ?></div>
        </div>
        <div class="item">
          <span class="label">FOMEMA Status</span>
          <div class="value"><?= h(showv($worker['fomema_status'] ?? '')) ?></div>
        </div>
        <div class="item">
          <span class="label">SOCSO Status</span>
          <div class="value"><?= h(showv($worker['socso_status'] ?? '')) ?></div>
        </div>
        <div class="item">
          <span class="label">Hostel Status</span>
          <div class="value"><?= h(showv($worker['hostel_status'] ?? '')) ?></div>
        </div>
        <div class="item">
          <span class="label">Next Action</span>
          <div class="value"><?= h(showv($worker['next_action'] ?? '')) ?></div>
        </div>
        <div class="item">
          <span class="label">Welfare Score</span>
          <div class="value"><?= h(showv((string)($worker['welfare_score'] ?? ''))) ?></div>
        </div>
        <div class="item">
          <span class="label">Risk Level</span>
          <div class="value"><?= h(showv($worker['risk_level'] ?? '')) ?></div>
        </div>
      </div>
    </div>

    <div class="section">
      <h2>Human Resource Development</h2>
      <div class="grid">
        <div class="item">
          <span class="label">Mint Rule</span>
          <div class="value">10 hours = 1 cert</div>
        </div>
        <div class="item">
          <span class="label">Mint Price</span>
          <div class="value">100 EMA$</div>
        </div>
        <div class="item wide">
          <span class="label">Certificate Rule</span>
          <div class="value">Certificate is permanent. Yearly hours pool is used for mint eligibility.</div>
        </div>
      </div>
    </div>

    <div class="section">
      <h2>Memo</h2>
      <div class="memo"><?= nl2br(h(showv($worker['memo_text'] ?? ''))) ?></div>
    </div>

    <div class="foot">
      RHRD-EMA · Worker print record · Admin/Agent only
    </div>

    <div class="noprint" style="margin-top:12px;">
      <button onclick="window.print()">Print A4</button>
    </div>
  </div>
</body>
</html>