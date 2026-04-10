<?php
// /rwa/inc/rwa-bottom-nav.php
// v1.0.20260317-rwa-bottom-nav-storage-final-locked

$path = $_SERVER['REQUEST_URI'] ?? '';

function nav_active($match, $path){
  return strpos($path, $match) !== false ? 'active' : '';
}
?>

<style>
.rwa-bottom-nav{
  position:fixed;
  bottom:0;
  left:0;
  right:0;
  height:60px;
  background:#050507;
  border-top:1px solid rgba(180,120,255,.25);
  display:flex;
  align-items:center;
  justify-content:space-between;
  padding:6px 10px;
  z-index:9999;
  font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
}

/* translator */
.rwa-nav-left{
  display:flex;
  align-items:center;
  min-width:fit-content;
}

/* nav links */
.rwa-nav-links{
  flex:1;
  display:flex;
  justify-content:space-around;
  align-items:center;
  min-width:0;
}

.rwa-nav-item{
  display:flex;
  flex-direction:column;
  align-items:center;
  justify-content:center;
  text-decoration:none;
  font-size:10px;
  color:#cfc7ff;
  gap:2px;
  min-width:54px;
  line-height:1.1;
  text-align:center;
}

.rwa-nav-item .icon{
  font-size:20px;
  line-height:1;
}

.rwa-nav-item.active{
  color:#b46bff;
  text-shadow:0 0 8px rgba(180,120,255,.8);
}

.rwa-nav-item.storage-item .icon{
  font-size:18px;
}

@media (max-width:420px){
  .rwa-bottom-nav{
    height:58px;
    padding:6px 8px;
  }
  .rwa-nav-item{
    font-size:9px;
    min-width:48px;
  }
  .rwa-nav-item .icon{
    font-size:18px;
  }
  .rwa-nav-item.storage-item .icon{
    font-size:17px;
  }
}
</style>

<div class="rwa-bottom-nav">

  <!-- translator -->
  <div class="rwa-nav-left">
    <?php require __DIR__ . '/../../dashboard/inc/gt-inline.php'; ?>
  </div>

  <!-- nav -->
  <div class="rwa-nav-links">

    <a href="/rwa/mining/" class="rwa-nav-item <?= nav_active('/rwa/mining', $path) ?>" data-nav="mining" data-click-sfx>
      <div class="icon">⛏</div>
      <div>Mining</div>
    </a>

    <a href="/rwa/cert/" class="rwa-nav-item <?= nav_active('/rwa/cert', $path) ?>" data-nav="rwa" data-click-sfx>
      <div class="icon">⬜</div>
      <div>RWA</div>
    </a>

    <a href="/rwa/storage/" class="rwa-nav-item storage-item <?= nav_active('/rwa/storage', $path) ?>" data-nav="storage" data-click-sfx>
      <div class="icon">🗄️</div>
      <div>Storage</div>
    </a>

    <a href="/rwa/profile/" class="rwa-nav-item <?= nav_active('/rwa/profile', $path) ?>" data-nav="profile" data-click-sfx>
      <div class="icon">👤</div>
      <div>Profile</div>
    </a>

  </div>

</div>

<script>
(function(){
  function isTypingTarget(e){
    const el = e.target;
    if (!el) return false;
    const tag = (el.tagName || '').toLowerCase();
    if (tag === 'input' || tag === 'textarea' || tag === 'select') return true;
    if (el.isContentEditable) return true;
    return false;
  }

  function go(sel){
    const a = document.querySelector(sel);
    if (a && a.href) window.location.href = a.href;
  }

  document.addEventListener('keydown', (e)=>{
    if (e.defaultPrevented) return;
    if (e.ctrlKey || e.metaKey || e.altKey) return;
    if (isTypingTarget(e)) return;

    const k = (e.key || '').toLowerCase();
    if (k === 'm') return go('a[data-nav="mining"], a[href="/rwa/mining/"]');
    if (k === 'r') return go('a[data-nav="rwa"], a[href="/rwa/cert/"]');
    if (k === '$') return go('a[data-nav="storage"], a[href="/rwa/storage/"]');
    if (k === 'p') return go('a[data-nav="profile"], a[href="/rwa/profile/"]');
  }, {passive:true});
})();
</script>