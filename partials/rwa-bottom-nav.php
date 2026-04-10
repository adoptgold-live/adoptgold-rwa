<?php
// /rwa/inc/rwa-bottom-nav.php
// FINAL FIXED VERSION
// v1.0.20260305

if (defined('POADO_RWA_BOTTOM_NAV')) return;
define('POADO_RWA_BOTTOM_NAV', true);

/*
IMPORTANT:
Use absolute paths so it works from:
 /rwa/
 /rwa/rlife/
 /rwa/rprop/
 /rwa/rtrip/
*/

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/gt-inline.php';

$uri = $_SERVER['REQUEST_URI'] ?? '/';

function nav_active($needle){
    global $uri;
    return strpos($uri,$needle) === 0 ? 'active' : '';
}
?>

<style>

.rwa-bottom-nav{
position:fixed;
bottom:0;
left:0;
right:0;
z-index:999999;
background:#000;
border-top:1px solid rgba(180,120,255,.2);
padding:10px env(safe-area-inset-right) calc(10px + env(safe-area-inset-bottom)) env(safe-area-inset-left);
}

.rwa-bottom-inner{
max-width:1100px;
margin:auto;
display:flex;
align-items:center;
gap:12px;
}

.rwa-nav-left{
flex:0 0 auto;
}

.rwa-tabs{
flex:1;
display:grid;
grid-template-columns:repeat(4,1fr);
gap:10px;
}

.rwa-tab{
height:52px;
display:flex;
align-items:center;
justify-content:center;
gap:6px;
border-radius:14px;
background:rgba(20,10,35,.6);
border:1px solid rgba(180,120,255,.25);
color:#ddd;
text-decoration:none;
font-family:ui-monospace,Menlo,Consolas,monospace;
font-size:13px;
}

.rwa-tab.active{
background:rgba(180,120,255,.25);
border-color:rgba(180,120,255,.5);
}

@media(max-width:600px){
.rwa-tab{font-size:12px}
}

</style>


<nav class="rwa-bottom-nav">

<div class="rwa-bottom-inner">

<div class="rwa-nav-left">
<?php
// translator button
require $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/gt-inline.php';
?>
</div>

<div class="rwa-tabs">

<a class="rwa-tab <?=nav_active('/rwa/mining')?>"
href="/rwa/mining/">⛏ Mining</a>

<a class="rwa-tab <?=nav_active('/rwa/')?>"
href="/rwa/">▢ RWA</a>

<a class="rwa-tab <?=nav_active('/rwa/wallet')?>"
href="/rwa/wallet/">👛 Wallet</a>

<a class="rwa-tab <?=nav_active('/rwa/profile')?>"
href="/rwa/profile/">👤 Profile</a>

</div>

</div>

</nav>