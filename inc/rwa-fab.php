<?php
/**
 * /rwa/inc/rwa-fab.php
 * v1.1.20260304
 * Adds Switch Industry -> /rwa/login-select.php
 */
?>
<style>
.rwa-fab{
position:fixed;right:16px;bottom:84px;width:56px;height:56px;border-radius:50%;
background:linear-gradient(90deg,#7e22ce,#a855f7);display:flex;align-items:center;justify-content:center;
font-size:26px;color:white;cursor:pointer;z-index:9998;box-shadow:0 0 14px rgba(168,85,247,.6);
}
.rwa-fab-menu{
position:fixed;right:16px;bottom:150px;display:none;flex-direction:column;gap:10px;z-index:9998;
}
.rwa-fab-menu a{
background:#140a2a;border:1px solid rgba(168,85,247,.35);padding:10px 14px;border-radius:10px;
text-decoration:none;color:#e9d5ff;font-size:12px;font-family:ui-monospace,monospace;
}
.rwa-fab-menu a:hover{background:#1b0d38;}
</style>

<div class="rwa-fab-menu" id="rwaFabMenu">

  <a href="/rwa/login-select.php" data-click-sfx>Switch Industry</a>
  <a href="/rwa/calc/index.php" data-click-sfx>BIG-CALC</a>
  <a href="/rwa/mining/index.php" data-click-sfx>Mining Engine</a>
  <a href="/rwa/wallet/index.php" data-click-sfx>Adoption Wallet</a>
  <a href="/rwa/logout.php" data-click-sfx>Logout</a>

</div>

<div class="rwa-fab" id="rwaFabBtn">+</div>

<script>
(function(){
  const btn=document.getElementById("rwaFabBtn");
  const menu=document.getElementById("rwaFabMenu");
  if(!btn || !menu) return;

  btn.onclick=function(){
    menu.style.display = (menu.style.display==="flex") ? "none" : "flex";
  };

  document.addEventListener("click",function(e){
    if(!btn.contains(e.target) && !menu.contains(e.target)){
      menu.style.display="none";
    }
  });
})();
</script>