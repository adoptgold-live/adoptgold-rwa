<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/session-user.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/guards.php';

if (function_exists('session_user_require')) {
    session_user_require();
}

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$user = function_exists('session_user') ? (session_user() ?: []) : [];
$wallet = (string)($user['wallet_address'] ?? $user['wallet'] ?? '');
$nickname = (string)($user['nickname'] ?? $user['display_name'] ?? 'ACE');

$csrfAccept   = function_exists('csrf_token') ? csrf_token('deal_accept') : bin2hex(random_bytes(16));
$csrfCancel   = function_exists('csrf_token') ? csrf_token('deal_cancel') : bin2hex(random_bytes(16));
$csrfReassign = function_exists('csrf_token') ? csrf_token('deal_reassign') : bin2hex(random_bytes(16));
$csrfCreate   = function_exists('csrf_token') ? csrf_token('deal_create_from_booking') : bin2hex(random_bytes(16));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>RWA Deal</title>
<link rel="stylesheet" href="/rwa/deal/deal.css?v=20260327d">
</head>
<body class="deal-lang-en">

<?php @include $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-topbar-nav.php'; ?>

<div class="rwa-deal-wrap">
  <section class="hero">
    <div class="hero-top">
      <div>
        <div class="hero-title">
          <span class="deal-i18n-en">RWA Deal Desk</span>
          <span class="deal-i18n-zh">RWA 成交总台</span>
        </div>
        <div class="hero-sub">
          <span class="deal-i18n-en">Standalone mobile-first ACE deal console for pending and accepted bookings.</span>
          <span class="deal-i18n-zh">独立移动优先 ACE 成交台，用于待处理与已接受预约。</span>
        </div>
      </div>

      <div class="lang-switch">
        <button type="button" class="lang-btn active" data-lang="en">EN</button>
        <button type="button" class="lang-btn" data-lang="zh">中</button>
      </div>
    </div>

    <div class="hero-meta">
      <div class="meta">
        <span class="k"><span class="deal-i18n-en">Wallet</span><span class="deal-i18n-zh">钱包</span></span>
        <span class="v"><?= h($wallet !== '' ? $wallet : '-') ?></span>
      </div>
      <div class="meta">
        <span class="k"><span class="deal-i18n-en">Operator</span><span class="deal-i18n-zh">操作员</span></span>
        <span class="v"><?= h($nickname !== '' ? $nickname : 'ACE') ?></span>
      </div>
    </div>
  </section>

  <div class="segment">
    <button type="button" class="segment-btn active" id="tabPending">
      <span class="deal-i18n-en">ACE List (Pending)</span>
      <span class="deal-i18n-zh">ACE 列表（待处理）</span>
    </button>
    <button type="button" class="segment-btn" id="tabAccepted">
      <span class="deal-i18n-en">Deal List (Accepted)</span>
      <span class="deal-i18n-zh">成交列表（已接受）</span>
    </button>
  </div>

  <div class="desktop-grid">
    <section class="panel" id="pendingPanel">
      <div class="panel-head">
        <div class="panel-title">
          <span class="deal-i18n-en">ACE List</span>
          <span class="deal-i18n-zh">ACE 列表</span>
        </div>
        <div class="panel-note">
          <span class="deal-i18n-en">Pending bookings ready for accept / assign</span>
          <span class="deal-i18n-zh">待处理预约，可接受 / 分配</span>
        </div>
      </div>
      <div class="panel-body">
        <div class="statusbox" id="pendingMsg">Ready</div>

        <div class="searchbar">
          <input id="pendingSearch" placeholder="Search Pending">
          <button type="button" class="btn" id="pendingSearchBtn"><span class="deal-i18n-en">Search</span><span class="deal-i18n-zh">搜索</span></button>
          <button type="button" class="btn secondary" id="pendingResetBtn"><span class="deal-i18n-en">Reset</span><span class="deal-i18n-zh">重置</span></button>
        </div>

        <div id="pendingList">
          <div class="empty"><span class="deal-i18n-en">Loading...</span><span class="deal-i18n-zh">载入中...</span></div>
        </div>
      </div>
    </section>

    <section class="panel hidden" id="acceptedPanel">
      <div class="panel-head">
        <div class="panel-title">
          <span class="deal-i18n-en">Deal List</span>
          <span class="deal-i18n-zh">成交列表</span>
        </div>
        <div class="panel-note">
          <span class="deal-i18n-en">Accepted bookings with deal action, QR, cancel, reassign</span>
          <span class="deal-i18n-zh">已接受预约，可生成成交、二维码、取消、重新分配</span>
        </div>
      </div>
      <div class="panel-body">
        <div class="statusbox" id="acceptedMsg">Ready</div>

        <div class="searchbar">
          <input id="acceptedSearch" placeholder="Search Accepted">
          <button type="button" class="btn" id="acceptedSearchBtn"><span class="deal-i18n-en">Search</span><span class="deal-i18n-zh">搜索</span></button>
          <button type="button" class="btn secondary" id="acceptedResetBtn"><span class="deal-i18n-en">Reset</span><span class="deal-i18n-zh">重置</span></button>
        </div>

        <div id="acceptedList">
          <div class="empty"><span class="deal-i18n-en">Loading...</span><span class="deal-i18n-zh">载入中...</span></div>
        </div>
      </div>
    </section>
  </div>
</div>

<input type="hidden" id="csrfDealAccept" value="<?= h($csrfAccept) ?>">
<input type="hidden" id="csrfDealCancel" value="<?= h($csrfCancel) ?>">
<input type="hidden" id="csrfDealReassign" value="<?= h($csrfReassign) ?>">
<input type="hidden" id="csrfDealCreate" value="<?= h($csrfCreate) ?>">

<div class="modal-back" id="qrModalBack">
  <div class="modal">
    <div class="modal-head">
      <div id="qrModalTitle">QR</div>
      <button type="button" class="btn secondary" id="qrCloseBtn">Close</button>
    </div>
    <div class="modal-body">
      <div class="qr-box" id="qrBox"></div>
    </div>
  </div>
</div>

<?php @include $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-bottom-nav.php'; ?>
<script src="/rwa/deal/deal.js?v=20260327d"></script>
</body>
</html>
