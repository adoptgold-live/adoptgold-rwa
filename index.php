<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/core/session-user.php';

if (session_user_id() > 0) {
    header('Location: /rwa/login-select.php');
    exit;
}

$lang = 'en';

if (isset($_GET['lang']) && in_array($_GET['lang'], ['en','zh'], true)) {
    $lang = $_GET['lang'];
    setcookie('poado_lang', $lang, time()+31536000, '/');
}
elseif (!empty($_COOKIE['poado_lang']) && in_array($_COOKIE['poado_lang'], ['en','zh'], true)) {
    $lang = $_COOKIE['poado_lang'];
}
else {
    $accept = strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
    $lang = str_contains($accept,'zh') ? 'zh' : 'en';
}

$T = [

'en'=>[
'brand'=>'AdoptGold RWA',
'sub'=>'Secure access to the POAdo ecosystem',

'headline'=>'Choose Login',
'desc'=>'Use one of the 3 official entry methods.',

'portal'=>'Back to Public Portal',

'tg'=>'Telegram PIN Login',
'tg_desc'=>'Get a 6-digit PIN from Telegram bot',

'ton'=>'TON Login',
'ton_desc'=>'Primary ecosystem identity',

'web3'=>'Web3 Login',
'web3_desc'=>'Secondary identity access',

'enter'=>'Enter',

'en'=>'English',
'zh'=>'中文',

'footer'=>'© 2025 Blockchain Group Ltd. (Hong Kong) · RWA Standard Organisation (RSO). All rights reserved.',
],

'zh'=>[
'brand'=>'AdoptGold RWA',
'sub'=>'进入 POAdo 生态系统',

'headline'=>'选择登录方式',
'desc'=>'请选择以下官方登录入口',

'portal'=>'返回公共门户',

'tg'=>'Telegram PIN 登录',
'tg_desc'=>'从机器人获取 6 位 PIN',

'ton'=>'TON 登录',
'ton_desc'=>'生态主身份入口',

'web3'=>'Web3 登录',
'web3_desc'=>'辅助身份入口',

'enter'=>'进入',

'en'=>'English',
'zh'=>'中文',

'footer'=>'© 2025 Blockchain Group Ltd. (Hong Kong) · RWA Standard Organisation (RSO). All rights reserved.',
],

];

$t = $T[$lang];
?>
<!doctype html>
<html lang="<?=htmlspecialchars($lang)?>">
<head>

<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">

<title><?=htmlspecialchars($t['brand'])?></title>

<link rel="stylesheet" href="/rwa/assets/css/rwa-auth.css">

<style>

.auth-screen{
min-height:100dvh;
display:flex;
align-items:center;
justify-content:center;
padding:18px 16px 80px;
}

.auth-center{
width:100%;
max-width:560px;
}

.auth-panel{

border:1px solid rgba(179,136,255,.28);
border-radius:24px;

background:rgba(255,255,255,.06);
backdrop-filter:blur(18px);

box-shadow:0 18px 40px rgba(0,0,0,.26);

padding:18px;
}

.auth-top{

display:flex;
justify-content:space-between;
align-items:center;

margin-bottom:12px;
gap:8px;
}

.lang-group{
display:flex;
gap:8px;
}

.btn-lite{

display:inline-flex;
align-items:center;
justify-content:center;

padding:0 12px;
min-height:40px;

border-radius:999px;

text-decoration:none;
color:#fff;

font-size:13px;

border:1px solid rgba(179,136,255,.28);
background:rgba(255,255,255,.05);
}

.hero{
text-align:center;
margin-bottom:14px;
}

.brand{
font-size:28px;
font-weight:800;
}

.sub{
margin-top:6px;
font-size:14px;
color:#c4b8ea;
}

.headline{
margin-top:14px;
font-size:24px;
font-weight:800;
}

.desc{
margin-top:8px;
font-size:14px;
color:#c4b8ea;
}

.login-list{

display:grid;
gap:12px;

margin-top:16px;
}

.login-card{

padding:16px;

border-radius:18px;
border:1px solid rgba(179,136,255,.20);

background:rgba(255,255,255,.03);
}

.login-title{
font-size:17px;
font-weight:800;
margin-bottom:6px;
}

.login-desc{
font-size:14px;
color:#c4b8ea;
margin-bottom:12px;
}

.go-btn{

display:flex;
align-items:center;
justify-content:center;

min-height:46px;

border-radius:14px;

text-decoration:none;
font-weight:800;

background:linear-gradient(135deg,#b388ff,#7dffcf);

color:#140d25;
}

.auth-footer{
margin-top:16px;
text-align:center;
font-size:12px;
color:#c4b8ea;
}

</style>
</head>

<body>

<div class="auth-screen">

<div class="auth-center">

<div class="auth-panel">

<div class="auth-top">

<a class="btn-lite" href="/"><?=htmlspecialchars($t['portal'])?></a>

<div class="lang-group">
<a class="btn-lite" href="/rwa/?lang=en"><?=$t['en']?></a>
<a class="btn-lite" href="/rwa/?lang=zh"><?=$t['zh']?></a>
</div>

</div>

<div class="hero">

<div class="brand"><?=htmlspecialchars($t['brand'])?></div>
<div class="sub"><?=htmlspecialchars($t['sub'])?></div>

<div class="headline"><?=htmlspecialchars($t['headline'])?></div>
<div class="desc"><?=htmlspecialchars($t['desc'])?></div>

</div>

<div class="login-list">

<div class="login-card">

<div class="login-title"><?=$t['tg']?></div>
<div class="login-desc"><?=$t['tg_desc']?></div>

<a class="go-btn" href="/rwa/tg-pin.php"><?=$t['enter']?></a>

</div>

<div class="login-card">

<div class="login-title"><?=$t['ton']?></div>
<div class="login-desc"><?=$t['ton_desc']?></div>

<a class="go-btn" href="/rwa/ton-login.php"><?=$t['enter']?></a>

</div>

<div class="login-card">

<div class="login-title"><?=$t['web3']?></div>
<div class="login-desc"><?=$t['web3_desc']?></div>

<a class="go-btn" href="/rwa/web3-login.php"><?=$t['enter']?></a>

</div>

</div>

<div class="auth-footer">
<?=$t['footer']?>
</div>

</div>
</div>
</div>

<?php
$gt = __DIR__.'/inc/core/gt-inline.php';
if(is_file($gt)) require_once $gt;
?>

<script src="/rwa/inc/core/poado-i18n.js"></script>

</body>
</html>
