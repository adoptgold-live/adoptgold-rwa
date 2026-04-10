<?php
declare(strict_types=1);

// /var/www/html/public/rwa/swap/index.php
// SWAP 2.0 public landing
// v2.4.0-20260326-clean-shared-css-js

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

function h(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$user = function_exists('session_user') ? session_user() : null;
$isLoggedIn = is_array($user) && !empty($user['id']);

$dashboardHref = '/rwa/swap/dashboard.php';
$employerHref  = '/rwa/swap/employer/index.php';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SWAP 2.0 · Human Resources RWA</title>
<link rel="stylesheet" href="/rwa/swap/assets/css/swap.css?v=2.3.0-20260326">
</head>
<body
  class="swap-page with-nav"
  data-swap-logged-in="<?= $isLoggedIn ? '1' : '0' ?>"
  data-swap-jobs-endpoint="/rwa/swap/api/job-alerts.php"
  data-swap-status-endpoint="/rwa/swap/api/status-search.php"
  data-swap-send-otp-endpoint="/rwa/swap/api/send-tg-otp.php"
>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-topbar-nav.php'; ?>

<div class="swap-shell">

  <div class="swap-headbar">
    <div class="swap-title-wrap">
      <div class="swap-page-title" data-i18n="page_title">SWAP 2.0</div>
      <div class="swap-page-sub" data-i18n="page_sub">Human Resources RWA · Tertiary RWA · RHRD-EMA</div>
    </div>

    <div class="lang-switch">
      <button type="button" class="lang-btn" data-lang-btn="en">EN</button>
      <button type="button" class="lang-btn" data-lang-btn="zh">中文</button>
    </div>
  </div>

  <section class="swap-hero">
    <div class="hero-top">
      <div>
        <div class="hero-badge-row">
          <span class="badge gold">SWAP 2.0</span>
          <span class="badge green">RHRD-EMA</span>
          <span class="badge cyan" data-i18n="tertiary_rwa">Tertiary RWA</span>
        </div>

        <div class="hero-title" data-i18n="hero_title">Human Resources RWA</div>
        <div class="hero-sub" data-i18n="hero_sub">
          Worker protection, application tracking, welfare monitoring, verified work-hour contribution, and future RHRD certificate issuance under the Human Resource Development RWA framework.
        </div>

        <div class="hero-actions">
          <a class="btn" href="<?= h($dashboardHref) ?>" data-i18n="btn_apply_now">Apply Now</a>
          <a class="btn secondary" href="<?= h($dashboardHref) ?>" data-i18n="btn_open_dashboard">Open Worker Dashboard</a>
          <a class="btn green" href="<?= h($employerHref) ?>" data-i18n="btn_employer_portal">Employer Portal</a>
        </div>
      </div>

      <div class="badge" data-swap-session-badge>
        <?= $isLoggedIn ? 'SESSION READY' : 'PUBLIC ACCESS' ?>
      </div>
    </div>

    <div class="kpi-grid">
      <div class="kpi">
        <div class="kpi-label" data-i18n="kpi_search">Worker Status Search</div>
        <div class="kpi-value">3</div>
      </div>
      <div class="kpi">
        <div class="kpi-label" data-i18n="kpi_pillars">Welfare Pillars</div>
        <div class="kpi-value">5</div>
      </div>
      <div class="kpi">
        <div class="kpi-label" data-i18n="kpi_hours">Hours per Cert</div>
        <div class="kpi-value">10h</div>
      </div>
      <div class="kpi">
        <div class="kpi-label" data-i18n="kpi_ema">EMA$ per Cert</div>
        <div class="kpi-value">100</div>
      </div>
    </div>
  </section>

  <div class="main-grid">
    <section class="card">
      <div class="section-title" data-i18n="search_title">Worker Status Search</div>
      <div class="section-sub" data-i18n="search_sub">
        Public-safe search only. Sensitive worker data is never exposed here.
      </div>

      <div class="search-methods">
        <button type="button" class="method-btn is-active" data-method="passport" data-i18n="method_passport">Passport Number</button>
        <button type="button" class="method-btn" data-method="application" data-i18n="method_application">Application ID</button>
        <button type="button" class="method-btn" data-method="mobile" data-i18n="method_mobile">Mobile + Telegram OTP</button>
      </div>

      <form id="swap-status-form" class="form-grid single" autocomplete="off">
        <div id="method-passport" class="field method-panel">
          <label class="label" for="passport_no" data-i18n="label_passport">Passport Number</label>
          <input
            class="input"
            id="passport_no"
            name="passport_no"
            type="text"
            data-i18n-placeholder="ph_passport"
            placeholder="Enter passport number"
          >
        </div>

        <div id="method-application" class="field method-panel" style="display:none;">
          <label class="label" for="application_id" data-i18n="label_application">Application ID</label>
          <input
            class="input"
            id="application_id"
            name="application_id"
            type="text"
            data-i18n-placeholder="ph_application"
            placeholder="Enter application ID"
          >
        </div>

        <div id="method-mobile" class="method-panel" style="display:none;">
          <div class="form-grid">
            <div class="field">
              <label class="label" for="mobile_no" data-i18n="label_mobile">Mobile Number</label>
              <input
                class="input"
                id="mobile_no"
                name="mobile_no"
                type="text"
                data-i18n-placeholder="ph_mobile"
                placeholder="60123456789"
              >
            </div>

            <div class="field">
              <label class="label" for="otp_code" data-i18n="label_otp">Telegram OTP</label>
              <input
                class="input"
                id="otp_code"
                name="otp_code"
                type="text"
                data-i18n-placeholder="ph_otp"
                placeholder="Enter OTP"
              >
            </div>
          </div>
        </div>

        <div class="form-actions">
          <button type="button" class="btn secondary" id="btn-send-otp" style="display:none;" data-i18n="btn_send_otp">
            Send Telegram OTP
          </button>
          <button type="submit" class="btn" data-i18n="btn_search_status">Search Status</button>
        </div>
      </form>

      <div class="result-box" id="status-result-box">
        <div class="result-empty" data-i18n="result_empty">Search result will appear here.</div>
      </div>

      <div class="notice" data-i18n="search_notice">
        Public results only show masked passport, status, stage, industry, location, project short code, next action and updated time.
      </div>
    </section>

    <section class="card">
      <div class="section-title" data-i18n="jobs_title">Jobs Announcement</div>
      <div class="section-sub" data-i18n="jobs_sub">
        Select a job to prefill your worker application dashboard.
      </div>

      <div class="jobs-list" id="jobs-list">
        <div class="empty-note" data-i18n="jobs_loading">Loading jobs...</div>
      </div>
    </section>
  </div>

  <section class="card swap-section">
    <div class="section-title" data-i18n="welfare_title">Worker Welfare Protection Engine</div>
    <div class="section-sub" data-i18n="welfare_sub">
      Welfare score is a worker-protection score, not a productivity score.
    </div>

    <div class="pillar-grid">
      <div class="pillar">
        <div class="pillar-score">25</div>
        <div class="pillar-title" data-i18n="pillar_legal">Legal Status</div>
        <div class="pillar-note" data-i18n="pillar_legal_note">Passport, permit, lawful work readiness.</div>
      </div>

      <div class="pillar">
        <div class="pillar-score">20</div>
        <div class="pillar-title" data-i18n="pillar_medical">Medical</div>
        <div class="pillar-note" data-i18n="pillar_medical_note">Medical screening and health clearance.</div>
      </div>

      <div class="pillar">
        <div class="pillar-score">20</div>
        <div class="pillar-title" data-i18n="pillar_social">Social Protection</div>
        <div class="pillar-note" data-i18n="pillar_social_note">SOCSO and worker protection coverage.</div>
      </div>

      <div class="pillar">
        <div class="pillar-score">20</div>
        <div class="pillar-title" data-i18n="pillar_accommodation">Accommodation</div>
        <div class="pillar-note" data-i18n="pillar_accommodation_note">Hostel or accommodation compliance readiness.</div>
      </div>

      <div class="pillar">
        <div class="pillar-score">15</div>
        <div class="pillar-title" data-i18n="pillar_safe">Safe Work Conditions</div>
        <div class="pillar-note" data-i18n="pillar_safe_note">Basic safety and compliant work placement condition.</div>
      </div>
    </div>
  </section>

  <section class="card swap-section">
    <div class="section-title" data-i18n="flow_title">How SWAP 2.0 Works</div>
    <div class="section-sub" data-i18n="flow_sub">
      Simple worker-facing process from application to certificate eligibility.
    </div>

    <div class="flow">
      <div class="flow-step">
        <div class="flow-no">1</div>
        <div class="flow-title" data-i18n="step1_title">Search or Select Job</div>
        <div class="flow-note" data-i18n="step1_note">Find worker status or choose a live job announcement.</div>
      </div>

      <div class="flow-step">
        <div class="flow-no">2</div>
        <div class="flow-title" data-i18n="step2_title">Submit Application</div>
        <div class="flow-note" data-i18n="step2_note">Application is stored in the SWAP worker request pipeline.</div>
      </div>

      <div class="flow-step">
        <div class="flow-no">3</div>
        <div class="flow-title" data-i18n="step3_title">Welfare + Compliance</div>
        <div class="flow-note" data-i18n="step3_note">Admin, agent and employer workflows update readiness and protection status.</div>
      </div>

      <div class="flow-step">
        <div class="flow-no">4</div>
        <div class="flow-title" data-i18n="step4_title">Work Hours → RHRD Cert</div>
        <div class="flow-note" data-i18n="step4_note">Verified yearly work hours become certificate eligibility pool.</div>
      </div>
    </div>

    <div class="footer-cta">
      <a class="btn" href="<?= h($dashboardHref) ?>" data-i18n="btn_open_dashboard">Open Worker Dashboard</a>
      <a class="btn secondary" href="<?= h($dashboardHref) ?>" data-i18n="btn_apply_selected">Apply with Selected Job</a>
      <a class="btn green" href="<?= h($employerHref) ?>" data-i18n="btn_employer_portal">Employer Portal</a>
    </div>
  </section>

  <div class="public-footnote" data-i18n="footnote">
    SWAP 2.0 public module for worker protection, welfare compliance and Human Resources RWA onboarding.
  </div>
</div>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-bottom-nav.php'; ?>

<script src="/rwa/swap/assets/js/swap.js?v=2.2.0-20260326"></script>
</body>
</html>