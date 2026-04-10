<?php
declare(strict_types=1);

// /var/www/html/public/rwa/swap/dashboard.php
// SWAP 2.0 worker dashboard
// v3.0.0-20260326-dashboard-redesign-mint-cert

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';

function h(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$userId = function_exists('session_user_id') ? (int) session_user_id() : 0;
if ($userId <= 0) {
    header('Location: /rwa/index.php', true, 302);
    exit;
}

$user = function_exists('session_user') ? session_user() : null;
if (!is_array($user)) {
    $user = [];
}

$nickname      = trim((string)($user['nickname'] ?? ($_SESSION['nickname'] ?? '')));
$walletAddress = trim((string)($user['wallet_address'] ?? ($_SESSION['wallet_address'] ?? '')));
$email         = trim((string)($user['email'] ?? ($_SESSION['email'] ?? '')));
$mobileE164    = trim((string)($user['mobile_e164'] ?? ($_SESSION['mobile_e164'] ?? '')));
$countryName   = trim((string)($user['country_name'] ?? ($_SESSION['country_name'] ?? '')));
$stateName     = trim((string)($user['state'] ?? ($_SESSION['state'] ?? '')));
$areaName      = trim((string)($user['region'] ?? ($_SESSION['region'] ?? '')));

$walletShort = $walletAddress !== ''
    ? substr($walletAddress, 0, 6) . '...' . substr($walletAddress, -4)
    : '';

$supportWaDigits = ''; // can be wired later from config/env without changing UI structure
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SWAP 2.0 · Worker Dashboard</title>
<link rel="stylesheet" href="/rwa/swap/assets/css/swap.css?v=2.3.0-20260326">
</head>
<body
  class="swap-page with-nav"
  data-swap-user-id="<?= (int)$userId ?>"
  data-swap-logged-in="1"
  data-swap-jobs-endpoint="/rwa/swap/api/job-alerts.php"
  data-swap-apply-endpoint="/rwa/swap/api/apply.php"
  data-swap-my-application-endpoint="/rwa/swap/api/my-application.php"
  data-swap-my-cert-summary-endpoint="/rwa/swap/api/my-cert-summary.php"
  data-swap-mint-cert-endpoint="/rwa/swap/api/mint-cert.php"
  data-swap-support-wa="<?= h($supportWaDigits) ?>"
>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-topbar-nav.php'; ?>

<div class="swap-shell">

  <div class="swap-headbar">
    <div class="swap-title-wrap">
      <div class="swap-page-title" data-i18n="dashboard_title">SWAP 2.0 Worker Dashboard</div>
      <div class="swap-page-sub" data-i18n="dashboard_sub">Human Resources RWA · Worker Application · RHRD-EMA</div>
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
          <span class="badge cyan" data-i18n="worker_dashboard_badge">Worker Dashboard</span>
        </div>

        <div class="hero-title" data-i18n="hero_dashboard_title">Worker Control Panel</div>
        <div class="hero-sub" data-i18n="hero_dashboard_sub">
          Submit or update your application, review your latest status, track welfare review progress, and check RHRD certificate eligibility from one dashboard.
        </div>

        <div class="hero-badge-row" style="margin-top:14px;">
          <span class="badge green"><?= h($nickname !== '' ? $nickname : ('User '.$userId)) ?></span>
          <?php if ($walletShort !== ''): ?>
            <span class="badge"><?= h($walletShort) ?></span>
          <?php endif; ?>
          <span class="badge" id="swap-selected-job-chip" data-i18n="no_job_selected">No job selected</span>
          <span class="badge gold" id="swap-latest-status-chip" data-i18n="status_pending_load">Status loading...</span>
        </div>

        <div class="hero-actions">
          <a class="btn" href="#swap-application-form" data-i18n="btn_apply_update">Apply / Update</a>
          <button type="button" class="btn secondary" id="btn-refresh-dashboard" data-i18n="btn_refresh">Refresh</button>
          <button type="button" class="btn green" id="btn-hero-mint-cert" data-i18n="btn_mint_cert">Mint Cert</button>
          <a class="btn secondary" href="/rwa/swap/index.php" data-i18n="btn_back_jobs">Back to Jobs</a>
        </div>
      </div>

      <div class="badge" data-swap-session-badge>SESSION READY</div>
    </div>
  </section>

  <div class="main-grid">
    <section class="card">
      <div class="section-title" data-i18n="application_form_title">Worker Application Form</div>
      <div class="section-sub" data-i18n="application_form_sub">
        Your selected job will prefill here automatically. Update your details and submit to the SWAP review pipeline.
      </div>

      <div class="swap-card-soft" style="margin-top:12px;">
        <div class="section-title" style="font-size:14px;" data-i18n="selected_job_summary">Selected Job Summary</div>
        <div class="result-grid" style="margin-top:10px;">
          <div class="result-item">
            <div class="result-key" data-i18n="field_job_title">Job Title</div>
            <div class="result-val" id="job-title-view">-</div>
          </div>
          <div class="result-item">
            <div class="result-key" data-i18n="field_job_uid">Job UID</div>
            <div class="result-val" id="job-uid-view">-</div>
          </div>
          <div class="result-item">
            <div class="result-key" data-i18n="field_industry">Industry</div>
            <div class="result-val" id="job-industry-view">-</div>
          </div>
          <div class="result-item">
            <div class="result-key" data-i18n="field_location">Location</div>
            <div class="result-val" id="job-location-view">-</div>
          </div>
          <div class="result-item">
            <div class="result-key" data-i18n="field_project_code">Project Key</div>
            <div class="result-val" id="job-project-view">-</div>
          </div>
          <div class="result-item">
            <div class="result-key" data-i18n="field_job_action">Change Job</div>
            <div class="result-val">
              <button type="button" class="small-btn secondary" id="btn-clear-selected-job" data-i18n="btn_clear_job">Clear Selected Job</button>
            </div>
          </div>
        </div>
      </div>

      <form id="swap-application-form" class="form-grid single" autocomplete="off" style="margin-top:12px;">
        <input type="hidden" id="job_uid" name="job_uid" value="">
        <input type="hidden" id="project_key" name="project_key" value="">
        <input type="hidden" id="job_title" name="job_title" value="">
        <input type="hidden" id="job_industry" name="job_industry" value="">
        <input type="hidden" id="job_location" name="job_location" value="">

        <div class="form-grid">
          <div class="field">
            <label class="label" for="nickname" data-i18n="label_nickname">Nickname</label>
            <input class="input" id="nickname" name="nickname" type="text" value="<?= h($nickname) ?>" data-i18n-placeholder="ph_nickname" placeholder="Your nickname">
          </div>

          <div class="field">
            <label class="label" for="email" data-i18n="label_email">Email</label>
            <input class="input" id="email" name="email" type="email" value="<?= h($email) ?>" data-i18n-placeholder="ph_email" placeholder="your@email.com">
          </div>
        </div>

        <div class="form-grid">
          <div class="field">
            <label class="label" for="passport_no" data-i18n="label_passport">Passport Number</label>
            <input class="input" id="passport_no" name="passport_no" type="text" data-i18n-placeholder="ph_passport" placeholder="Enter passport number">
          </div>

          <div class="field">
            <label class="label" for="mobile_no" data-i18n="label_mobile">Mobile Number</label>
            <input class="input" id="mobile_no" name="mobile_no" type="text" value="<?= h($mobileE164) ?>" data-i18n-placeholder="ph_mobile" placeholder="60123456789">
          </div>
        </div>

        <div class="form-grid">
          <div class="field">
            <label class="label" for="telegram_handle" data-i18n="label_telegram">Telegram</label>
            <input class="input" id="telegram_handle" name="telegram_handle" type="text" data-i18n-placeholder="ph_telegram" placeholder="@username">
          </div>

          <div class="field">
            <label class="label" for="whatsapp_preview" data-i18n="label_whatsapp_preview">WhatsApp Preview</label>
            <input class="input" id="whatsapp_preview" name="whatsapp_preview" type="text" value="" readonly>
          </div>
        </div>

        <div class="form-grid">
          <div class="field">
            <label class="label" for="country_name" data-i18n="label_country">Country</label>
            <input class="input" id="country_name" name="country_name" type="text" value="<?= h($countryName) ?>" data-i18n-placeholder="ph_country" placeholder="Country">
          </div>

          <div class="field">
            <label class="label" for="state_name" data-i18n="label_state">State / Province</label>
            <input class="input" id="state_name" name="state_name" type="text" value="<?= h($stateName) ?>" data-i18n-placeholder="ph_state" placeholder="State or Province">
          </div>
        </div>

        <div class="form-grid">
          <div class="field">
            <label class="label" for="area_name" data-i18n="label_area">Area</label>
            <input class="input" id="area_name" name="area_name" type="text" value="<?= h($areaName) ?>" data-i18n-placeholder="ph_area" placeholder="Area">
          </div>

          <div class="field">
            <label class="label" for="experience_years" data-i18n="label_experience">Experience (Years)</label>
            <input class="input" id="experience_years" name="experience_years" type="number" min="0" step="1" data-i18n-placeholder="ph_experience" placeholder="0">
          </div>
        </div>

        <div class="form-grid">
          <div class="field">
            <label class="label" for="preferred_industry" data-i18n="label_preferred_industry">Preferred Industry</label>
            <input class="input" id="preferred_industry" name="preferred_industry" type="text" data-i18n-placeholder="ph_preferred_industry" placeholder="Construction / Manufacturing / Services">
          </div>

          <div class="field">
            <label class="label" for="wallet_address" data-i18n="label_wallet">Wallet</label>
            <input class="input" id="wallet_address" name="wallet_address" type="text" value="<?= h($walletAddress) ?>" readonly>
          </div>
        </div>

        <div class="field">
          <label class="label" for="worker_note" data-i18n="label_worker_note">Worker Note</label>
          <textarea class="textarea" id="worker_note" name="worker_note" data-i18n-placeholder="ph_worker_note" placeholder="Add any note for employer / agent / admin review"></textarea>
        </div>

        <div class="form-actions">
          <button type="submit" class="btn" id="btn-submit-application" data-i18n="btn_submit_application">Submit Application</button>
          <button type="button" class="btn secondary" id="btn-reset-application" data-i18n="btn_reset_form">Reset Form</button>
        </div>

        <div class="result-box" id="application-submit-result">
          <div class="result-empty" data-i18n="application_submit_idle">Application result will appear here.</div>
        </div>
      </form>
    </section>

    <section class="card">
      <div class="section-title" data-i18n="worker_panels_title">Worker Dashboard Panels</div>
      <div class="section-sub" data-i18n="worker_panels_sub">
        Review your selected job, latest application status, welfare progress and support actions here.
      </div>

      <div class="jobs-list">
        <div class="swap-card-soft">
          <div class="section-title" style="font-size:14px;" data-i18n="selected_job_summary">Selected Job Summary</div>
          <div class="result-grid" style="margin-top:10px;">
            <div class="result-item">
              <div class="result-key" data-i18n="field_job_title">Job Title</div>
              <div class="result-val" id="side-job-title">-</div>
            </div>
            <div class="result-item">
              <div class="result-key" data-i18n="field_industry">Industry</div>
              <div class="result-val" id="side-job-industry">-</div>
            </div>
            <div class="result-item">
              <div class="result-key" data-i18n="field_location">Location</div>
              <div class="result-val" id="side-job-location">-</div>
            </div>
            <div class="result-item">
              <div class="result-key" data-i18n="field_project_code">Project Key</div>
              <div class="result-val" id="side-job-project">-</div>
            </div>
          </div>
        </div>

        <div class="swap-card-soft">
          <div class="section-title" style="font-size:14px;" data-i18n="my_application_title">My Latest Application</div>
          <div class="result-box" id="my-application-box" style="margin-top:10px;">
            <div class="result-empty" data-i18n="application_loading">Loading application...</div>
          </div>
        </div>

        <div class="swap-card-soft">
          <div class="section-title" style="font-size:14px;" data-i18n="welfare_overview_title">Welfare Overview</div>
          <div class="result-grid" style="margin-top:10px;">
            <div class="result-item">
              <div class="result-key" data-i18n="field_welfare_score">Welfare Score</div>
              <div class="result-val" id="worker-welfare-score" data-i18n="not_calculated">Not calculated</div>
            </div>
            <div class="result-item">
              <div class="result-key" data-i18n="field_deployable_status">Deployable Status</div>
              <div class="result-val" id="worker-deployable-status" data-i18n="pending_review">Pending review</div>
            </div>
            <div class="result-item">
              <div class="result-key" data-i18n="field_risk_level">Risk Level</div>
              <div class="result-val" id="worker-risk-level" data-i18n="pending_review">Pending review</div>
            </div>
            <div class="result-item">
              <div class="result-key" data-i18n="field_next_action">Next Action</div>
              <div class="result-val" id="worker-next-action" data-i18n="pending_review">Pending review</div>
            </div>
          </div>
        </div>

        <div class="swap-card-soft">
          <div class="section-title" style="font-size:14px;" data-i18n="support_title">Support / Contact</div>
          <div class="result-grid" style="margin-top:10px;">
            <div class="result-item">
              <div class="result-key" data-i18n="field_whatsapp_preview">WhatsApp Preview</div>
              <div class="result-val" id="support-whatsapp-view">-</div>
            </div>
            <div class="result-item">
              <div class="result-key" data-i18n="field_support_note">Support Note</div>
              <div class="result-val" data-i18n="support_note_text">Employer / agent will contact after review.</div>
            </div>
          </div>
          <div class="form-actions">
            <a class="btn secondary" href="#" id="btn-open-whatsapp" data-i18n="btn_open_whatsapp">Open WhatsApp</a>
          </div>
        </div>

        <div class="swap-card-soft">
          <div class="section-title" style="font-size:14px;" data-i18n="recent_jobs_title">Recent Jobs</div>
          <div class="jobs-list" id="dashboard-jobs-list" style="margin-top:10px;">
            <div class="empty-note" data-i18n="jobs_loading">Loading jobs...</div>
          </div>
        </div>
      </div>
    </section>
  </div>

  <section class="card swap-section" id="swap-mint-cert-section">
    <div class="section-title" data-i18n="mint_cert_panel_title">RHRD Certificate Eligibility</div>
    <div class="section-sub" data-i18n="mint_cert_panel_sub">
      Track your verified work hours, available yearly hours, and certificate mint eligibility here.
    </div>

    <div class="result-grid" style="margin-top:12px;">
      <div class="result-item">
        <div class="result-key" data-i18n="field_verified_hours">Verified Hours</div>
        <div class="result-val" id="cert-verified-hours">0h</div>
      </div>
      <div class="result-item">
        <div class="result-key" data-i18n="field_consumed_hours">Consumed Hours</div>
        <div class="result-val" id="cert-consumed-hours">0h</div>
      </div>
      <div class="result-item">
        <div class="result-key" data-i18n="field_available_hours">Available Hours</div>
        <div class="result-val" id="cert-available-hours">0h</div>
      </div>
      <div class="result-item">
        <div class="result-key" data-i18n="field_claimable_certs">Claimable Certs</div>
        <div class="result-val" id="cert-claimable-certs">0</div>
      </div>
      <div class="result-item">
        <div class="result-key" data-i18n="field_mint_rule">Mint Rule</div>
        <div class="result-val" data-i18n="mint_rule_text">10 hours = 1 cert</div>
      </div>
      <div class="result-item">
        <div class="result-key" data-i18n="field_ema_cost">EMA$ Required</div>
        <div class="result-val" data-i18n="mint_cost_text">100 EMA$ = 1 cert</div>
      </div>
    </div>

    <div class="notice" id="cert-eligibility-note" data-i18n="mint_cert_idle_note" style="margin-top:12px;">
      Mint status and eligibility note will appear here.
    </div>

    <div class="form-actions">
      <button type="button" class="btn green" id="btn-mint-cert" data-i18n="btn_mint_cert">Mint RHRD Cert</button>
      <button type="button" class="btn secondary" id="btn-refresh-cert" data-i18n="btn_refresh_cert">Refresh Cert Summary</button>
    </div>

    <div class="result-box" id="mint-cert-result">
      <div class="result-empty" data-i18n="mint_cert_result_idle">Mint result will appear here.</div>
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
        <div class="flow-title" data-i18n="step1_title">Select Job</div>
        <div class="flow-note" data-i18n="step1_note_dashboard">Choose a job from the public jobs board and review its details in your dashboard.</div>
      </div>
      <div class="flow-step">
        <div class="flow-no">2</div>
        <div class="flow-title" data-i18n="step2_title">Submit Application</div>
        <div class="flow-note" data-i18n="step2_note_dashboard">Submit or update your worker application with identity, location and worker note.</div>
      </div>
      <div class="flow-step">
        <div class="flow-no">3</div>
        <div class="flow-title" data-i18n="step3_title">Review + Welfare</div>
        <div class="flow-note" data-i18n="step3_note_dashboard">Admin, agent and employer review your application and welfare readiness.</div>
      </div>
      <div class="flow-step">
        <div class="flow-no">4</div>
        <div class="flow-title" data-i18n="step4_title">Work Hours → Cert</div>
        <div class="flow-note" data-i18n="step4_note_dashboard">Verified work hours become yearly eligibility pool for RHRD certificate minting.</div>
      </div>
    </div>
  </section>

</div>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-bottom-nav.php'; ?>

<script src="/rwa/swap/assets/js/swap-dashboard.js?v=3.0.0-20260326"></script>
</body>
</html>