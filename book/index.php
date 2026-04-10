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
$nickname = (string)($user['nickname'] ?? $user['display_name'] ?? 'User');

$csrf = function_exists('csrf_token')
    ? csrf_token('rwa_book_create')
    : bin2hex(random_bytes(16));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>RWA Booking</title>
<link rel="stylesheet" href="/rwa/book/book.css?v=20260327c">
</head>
<body class="book-lang-en">

<?php @include $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-topbar-nav.php'; ?>

<div class="rwa-book-wrap">
  <div class="rwa-book-top">
    <section class="hero">
      <div class="hero-top">
        <div>
          <div class="hero-title">
            <span class="i18n-en">RWA Booking Desk</span>
            <span class="i18n-zh">RWA 预约总台</span>
          </div>
          <div class="hero-sub">
            <span class="i18n-en">Mobile-first standalone booking module for the RWA app.</span>
            <span class="i18n-zh">RWA 独立应用的移动优先预约模块。</span>
          </div>
        </div>

        <div class="lang-switch">
          <button type="button" class="lang-btn active" data-lang="en">EN</button>
          <button type="button" class="lang-btn" data-lang="zh">中</button>
        </div>
      </div>

      <div class="hero-meta">
        <div class="mini">
          <span class="k"><span class="i18n-en">Wallet</span><span class="i18n-zh">钱包</span></span>
          <span class="v"><?= h($wallet !== '' ? $wallet : '-') ?></span>
        </div>
        <div class="mini">
          <span class="k"><span class="i18n-en">Operator</span><span class="i18n-zh">操作员</span></span>
          <span class="v"><?= h($nickname !== '' ? $nickname : '-') ?></span>
        </div>
      </div>
    </section>

    <div class="searchbar">
      <input id="q" type="text" placeholder="Search Booking No / Name / Email / Mobile">
      <button class="btn" id="btnSearch" type="button"><span class="i18n-en">Search</span><span class="i18n-zh">搜索</span></button>
      <button class="btn secondary" id="btnReset" type="button"><span class="i18n-en">Reset</span><span class="i18n-zh">重置</span></button>
      <button class="btn secondary" id="btnPrint" type="button"><span class="i18n-en">Print</span><span class="i18n-zh">打印</span></button>
    </div>
  </div>

  <div class="grid">
    <div class="card sticky-col">
      <div class="card-head">
        <div class="card-title"><span class="i18n-en">Create Booking</span><span class="i18n-zh">创建预约</span></div>
        <div class="card-note"><span class="i18n-en">Standalone RWA mobile booking form</span><span class="i18n-zh">独立 RWA 移动预约表单</span></div>
      </div>

      <div class="card-body">
        <input type="hidden" id="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" id="action" value="rwa_book_create">

        <div class="statusbox" id="msg">Ready</div>

        <div class="field">
          <label class="label" for="package_key"><span class="i18n-en">RWA Adoption Package</span><span class="i18n-zh">RWA 领养配套</span></label>
          <select class="select" id="package_key"></select>
        </div>

        <div class="field">
          <label class="label" for="customer_name"><span class="i18n-en">Customer Name</span><span class="i18n-zh">客户姓名</span></label>
          <input class="input" id="customer_name" autocomplete="name">
        </div>

        <div class="field">
          <label class="label" for="customer_email"><span class="i18n-en">Email</span><span class="i18n-zh">电邮</span></label>
          <input class="input" id="customer_email" type="email" autocomplete="email">
        </div>

        <div class="field">
          <label class="label" for="country"><span class="i18n-en">Country</span><span class="i18n-zh">国家</span></label>
          <select class="select" id="country"></select>
        </div>

        <div class="row2">
          <div class="field">
            <label class="label" for="prefix"><span class="i18n-en">Prefix</span><span class="i18n-zh">区号</span></label>
            <select class="select" id="prefix"></select>
          </div>
          <div class="field">
            <label class="label"><span class="i18n-en">Country Flag</span><span class="i18n-zh">国旗</span></label>
            <div class="flagbox">
              <img id="phoneFlagImg" src="/rwa/assets/flags/my.png" alt="flag">
            </div>
          </div>
        </div>

        <div class="field">
          <label class="label" for="mobile"><span class="i18n-en">Mobile</span><span class="i18n-zh">手机</span></label>
          <div class="phonebox">
            <div class="flagbox">
              <img id="phoneFlagBoxImg" src="/rwa/assets/flags/my.png" alt="flag">
            </div>
            <div class="prefixbox" id="phonePrefix">+60</div>
            <input class="input" id="mobile" maxlength="15" inputmode="numeric" autocomplete="tel">
          </div>
        </div>

        <div class="row2">
          <div class="field">
            <label class="label" for="state"><span class="i18n-en">State / Province</span><span class="i18n-zh">州 / 省</span></label>
            <select class="select" id="state"></select>
          </div>
          <div class="field">
            <label class="label" for="area"><span class="i18n-en">Area</span><span class="i18n-zh">地区</span></label>
            <select class="select" id="area"></select>
          </div>
        </div>

        <div class="row2">
          <div class="field">
            <label class="label" for="meeting_date"><span class="i18n-en">Date (DD/MM/YYYY)</span><span class="i18n-zh">日期 (DD/MM/YYYY)</span></label>
            <input class="input" id="meeting_date" placeholder="DD/MM/YYYY" autocomplete="off">
          </div>
          <div class="field">
            <label class="label" for="meeting_time"><span class="i18n-en">Time (10:00–22:00)</span><span class="i18n-zh">时间 (10:00–22:00)</span></label>
            <select class="select" id="meeting_time"></select>
          </div>
        </div>

        <div class="field">
          <label class="label" for="meeting_location"><span class="i18n-en">Meeting Location</span><span class="i18n-zh">会面地点</span></label>
          <input class="input" id="meeting_location" autocomplete="street-address">
        </div>

        <div class="field inline-actions">
          <button class="btn secondary" type="button" id="mapSearchBtn"><span class="i18n-en">Google Map Search</span><span class="i18n-zh">Google 地图搜索</span></button>
          <button class="btn secondary" type="button" id="btnNowUTC"><span class="i18n-en">Use Today</span><span class="i18n-zh">使用今天</span></button>
        </div>

        <input type="hidden" id="maps_link">

        <div class="field">
          <label class="label" for="meeting_note"><span class="i18n-en">Meeting MEMO</span><span class="i18n-zh">会面备注</span></label>
          <textarea class="textarea" id="meeting_note" rows="3"></textarea>
        </div>

        <div class="field">
          <label class="label" for="ace_mobile"><span class="i18n-en">Manual Assign ACE (Mobile E164 digits only)</span><span class="i18n-zh">手动分配 ACE（仅 mobile_e164 数字）</span></label>
          <input class="input" id="ace_mobile" inputmode="numeric" maxlength="20">
        </div>

        <div class="field">
          <button class="btn block" id="btnSubmit" type="button"><span class="i18n-en">Create Booking</span><span class="i18n-zh">创建预约</span></button>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-head">
        <div class="card-title"><span class="i18n-en">Booking List</span><span class="i18n-zh">预约列表</span></div>
        <div class="card-note"><span class="i18n-en">Latest booking registry for the standalone RWA module</span><span class="i18n-zh">独立 RWA 模块的最新预约记录</span></div>
      </div>

      <div class="card-body">
        <div class="list-wrap" id="list">
          <div class="empty"><span class="i18n-en">Loading...</span><span class="i18n-zh">载入中...</span></div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php @include $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-bottom-nav.php'; ?>
<script src="/rwa/book/book.js?v=20260327c"></script>
</body>
</html>
