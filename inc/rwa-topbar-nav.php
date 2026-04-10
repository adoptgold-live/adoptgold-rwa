<?php
// /var/www/html/public/rwa/inc/rwa-topbar-nav.php
// v2.4.20260318-rwa-topbar-nav-global-ema-sync-locked

if (!defined('RWA_TOPBAR_NAV_LOADED')) {
    define('RWA_TOPBAR_NAV_LOADED', true);
}

$nick = 'Guest';
$wallet = '';
$walletShort = '';

if (isset($_SESSION) && is_array($_SESSION)) {
    if (!empty($_SESSION['nickname'])) {
        $nick = (string) $_SESSION['nickname'];
    } elseif (!empty($_SESSION['user']['nickname'])) {
        $nick = (string) $_SESSION['user']['nickname'];
    }

    if (!empty($_SESSION['wallet'])) {
        $wallet = (string) $_SESSION['wallet'];
    } elseif (!empty($_SESSION['wallet_address'])) {
        $wallet = (string) $_SESSION['wallet_address'];
    } elseif (!empty($_SESSION['user']['wallet'])) {
        $wallet = (string) $_SESSION['user']['wallet'];
    } elseif (!empty($_SESSION['user']['wallet_address'])) {
        $wallet = (string) $_SESSION['user']['wallet_address'];
    }
}

if ($wallet !== '') {
    $walletShort = substr($wallet, 0, 6) . '...' . substr($wallet, -4);
}

$CHAIN_ID = '7709304653';
$ema6 = '0.000000';

try {
    $emaPhp = $_SERVER['DOCUMENT_ROOT'] . '/rwa/api/global/ema-price.php';
    if (is_file($emaPhp)) {
        require_once $emaPhp;

        if (function_exists('poado_ema_price_now')) {
            $ema6 = (string) poado_ema_price_now();
        } elseif (function_exists('poado_ema_price')) {
            $ema6 = (string) poado_ema_price(time());
        }
    }
} catch (Throwable $e) {
    $ema6 = '0.000000';
}

if (!preg_match('/^\d+(\.\d{1,6})?$/', $ema6)) {
    $ema6 = '0.000000';
} else {
    $ema6 = number_format((float) $ema6, 6, '.', '');
}

$hasNFT = false;
try {
    $nftCheck = __DIR__ . '/nft-check-ton.php';
    if (is_file($nftCheck)) {
        require_once $nftCheck;
        if (function_exists('rwa_user_has_adoptgold_nft')) {
            $hasNFT = (bool) rwa_user_has_adoptgold_nft($wallet);
        }
    }
} catch (Throwable $e) {
    $hasNFT = false;
}
?>
<style>
.rwaTopbar{
  position:sticky;
  top:0;
  z-index:50;
  background:linear-gradient(180deg, rgba(8,9,14,.92), rgba(8,9,14,.75));
  border-bottom:1px solid rgba(180,120,255,.18);
  backdrop-filter:blur(10px);
  -webkit-backdrop-filter:blur(10px);
  padding:10px 12px;
  font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
  color:#eaeaf2;
}
.rwaTopbar *{box-sizing:border-box}

.rwaRow{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:8px;
  min-width:0;
}
.rwaLeft,.rwaMid,.rwaRight{
  display:flex;
  align-items:center;
  gap:8px;
  min-width:0;
}
.rwaMid{
  flex:1;
  justify-content:center;
}

.rwaLink{
  color:#eaeaf2;
  text-decoration:none;
  padding:6px 10px;
  border-radius:999px;
  border:1px solid rgba(180,120,255,.12);
  background:rgba(255,255,255,.04);
  display:inline-flex;
  align-items:center;
  gap:6px;
  white-space:nowrap;
}
.rwaLink:hover{
  border-color:rgba(180,120,255,.35);
}
.rwaLogout{
  color:#ff6b97;
  border-color:rgba(255,107,151,.24);
}
.rwaLogout:hover{
  border-color:rgba(255,107,151,.45);
}

.rwaWelcome{
  opacity:.92;
  font-size:13px;
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
  padding:6px 10px;
  border-radius:999px;
  border:1px solid rgba(180,120,255,.10);
  background:rgba(0,0,0,.22);
  max-width:100%;
}

.rwaBtnRow{
  display:flex;
  gap:8px;
  flex-wrap:wrap;
  justify-content:center;
}
.rwaBtn{
  color:#eaeaf2;
  text-decoration:none;
  padding:8px 10px;
  border-radius:12px;
  border:1px solid rgba(180,120,255,.14);
  background:rgba(255,255,255,.04);
  display:inline-flex;
  align-items:center;
  gap:8px;
  font-size:13px;
  white-space:nowrap;
}
.rwaBtn:hover{
  border-color:rgba(180,120,255,.35);
}
.rwaKbd{
  opacity:.65;
  font-size:12px;
  padding-left:2px;
}

.rwaMarket{
  color:#ffd86b;
  border-color:rgba(255,216,107,.20);
  background:rgba(255,216,107,.05);
}
.rwaMarket:hover{
  border-color:rgba(255,216,107,.42);
}
.rwaMarketOwned{
  color:#9cff8a;
  border-color:#34ff9c;
  box-shadow:0 0 12px rgba(52,255,156,.35);
  background:rgba(52,255,156,.09);
}

.rwaPills{
  display:flex;
  align-items:center;
  gap:8px;
  min-width:0;
}
.rwaPill{
  display:inline-flex;
  align-items:center;
  gap:8px;
  padding:7px 10px;
  border-radius:999px;
  border:1px solid rgba(180,120,255,.14);
  background:rgba(0,0,0,.22);
  white-space:nowrap;
}
.rwaDot{
  width:10px;
  height:10px;
  border-radius:50%;
  background:#22c55e;
  box-shadow:0 0 10px rgba(34,197,94,.55);
  flex:0 0 auto;
}
.rwaChain a{
  color:#7aa7ff;
  text-decoration:none;
}
.rwaEmaValue{
  min-width:72px;
  display:inline-block;
  text-align:right;
  font-variant-numeric:tabular-nums;
}

.rwaSfxHost{
  width:0;
  height:0;
  overflow:hidden;
  position:absolute;
  left:-9999px;
  top:-9999px;
}

@media (min-width:860px){
  .rwaDesktopOnly{display:flex !important}
  .rwaMobileOnly{display:none !important}
  .rwaTopbar{padding:10px 14px}
}

@media (max-width:859px){
  .rwaDesktopOnly{display:none !important}
  .rwaMobileOnly{
    display:flex !important;
    flex-direction:column;
    gap:8px;
  }
  .rwaTopbar{padding:10px}
  .rwaLink{padding:6px 8px}
  .rwaPill{padding:7px 8px}
  .rwaBtn{padding:8px 10px}
}
</style>

<div class="rwaTopbar" data-rwa-topbar="1">

  <!-- Desktop -->
  <div class="rwaRow rwaDesktopOnly" style="display:flex;">
    <div class="rwaLeft">
      <a class="rwaLink" href="/rwa/" title="Home (H)">Home <span class="rwaKbd">H</span></a>
      <a class="rwaLink rwaLogout" href="/rwa/logout.php" title="Logout (L)">Logout <span class="rwaKbd">L</span></a>
    </div>

    <div class="rwaMid">
      <div class="rwaWelcome" title="Session">
        Welcome <b><?php echo htmlspecialchars($nick, ENT_QUOTES, 'UTF-8'); ?></b><?php if ($walletShort !== '') { echo ' | ' . htmlspecialchars($walletShort, ENT_QUOTES, 'UTF-8'); } ?>
      </div>

      <div class="rwaBtnRow" aria-label="Quick links">
        <a class="rwaBtn" href="/rwa/book/" title="Booking (B)"><span aria-hidden="true">▦</span> Booking <span class="rwaKbd">B</span></a>
        <a class="rwaBtn" href="/rwa/deal/" title="Deal (D)"><span aria-hidden="true">◎</span> Deal <span class="rwaKbd">D</span></a>
        <a class="rwaBtn" href="/rwa/calc/" title="Calc (C)"><span aria-hidden="true">⌗</span> Calc <span class="rwaKbd">C</span></a>
        <a class="rwaBtn rwaMarket <?php echo $hasNFT ? 'rwaMarketOwned' : ''; ?>" href="https://getgems.io/adoptgold" target="_blank" rel="noopener noreferrer" title="My RWA NFT Market">
          <span aria-hidden="true">◈</span> My RWA NFT Market
        </a>
      </div>
    </div>

    <div class="rwaRight">
      <div class="rwaPills">
        <div class="rwaPill rwaChain">
          <span class="rwaDot" aria-hidden="true"></span>
          <span>Chain ID:</span>
          <a href="/rwa/" title="Chain ID"><?php echo htmlspecialchars($CHAIN_ID, ENT_QUOTES, 'UTF-8'); ?></a>
        </div>
        <div class="rwaPill">
          <span><b>1000X EMA$:</b></span>
          <span class="rwaEmaValue" id="rwaEmaPrice"><?php echo htmlspecialchars($ema6, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
      </div>
      <div class="rwaSfxHost" aria-hidden="true"><div id="rwaSfxHost"></div></div>
    </div>
  </div>

  <!-- Mobile locked 4 rows -->
  <div class="rwaMobileOnly" style="display:none;">

    <!-- Row 1 -->
    <div class="rwaRow">
      <div class="rwaLeft">
        <a class="rwaLink" href="/rwa/" title="Home (H)">Home</a>
        <a class="rwaLink rwaLogout" href="/rwa/logout.php" title="Logout (L)">Logout</a>
      </div>
      <div class="rwaRight">
        <div class="rwaPills">
          <div class="rwaPill rwaChain">
            <span class="rwaDot" aria-hidden="true"></span>
            <span>Chain</span>
            <a href="/rwa/"><?php echo htmlspecialchars($CHAIN_ID, ENT_QUOTES, 'UTF-8'); ?></a>
          </div>
        </div>
      </div>
    </div>

    <!-- Row 2 -->
    <div class="rwaRow" style="justify-content:center;">
      <div class="rwaPills">
        <div class="rwaPill">
          <span><b>1000X EMA$:</b></span>
          <span class="rwaEmaValue" id="rwaEmaPrice_m"><?php echo htmlspecialchars($ema6, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
      </div>
    </div>

    <!-- Row 3 -->
    <div class="rwaRow" style="justify-content:center;">
      <div class="rwaWelcome" title="Session">
        Welcome <b><?php echo htmlspecialchars($nick, ENT_QUOTES, 'UTF-8'); ?></b><?php if ($walletShort !== '') { echo ' | ' . htmlspecialchars($walletShort, ENT_QUOTES, 'UTF-8'); } ?>
      </div>
    </div>

    <!-- Row 4 -->
    <div class="rwaRow" style="justify-content:center;">
      <div class="rwaBtnRow">
        <a class="rwaBtn" href="/rwa/book/" title="Booking (B)"><span aria-hidden="true">▦</span> Booking</a>
        <a class="rwaBtn" href="/rwa/deal/" title="Deal (D)"><span aria-hidden="true">◎</span> Deal</a>
        <a class="rwaBtn" href="/rwa/calc/" title="Calc (C)"><span aria-hidden="true">⌗</span> Calc</a>
        <a class="rwaBtn rwaMarket <?php echo $hasNFT ? 'rwaMarketOwned' : ''; ?>" href="https://getgems.io/adoptgold" target="_blank" rel="noopener noreferrer" title="My NFT">
          <span aria-hidden="true">◈</span> My NFT
        </a>
      </div>
    </div>

    <div class="rwaSfxHost" aria-hidden="true"><div id="rwaSfxHost_m"></div></div>
  </div>
</div>

<script>
(function(){
  function fmt6(v){
    var n = Number(v);
    if(!isFinite(n)) return '0.000000';
    return n.toFixed(6);
  }

  async function refreshEMA(){
    try{
      const r = await fetch('/rwa/api/global/ema-price-json.php?_=' + Date.now(), {
        cache:'no-store',
        headers:{ 'Accept':'application/json' }
      });

      const j = await r.json();
      if(!j || j.ok === false) return;

      var raw = null;
      if (j.price !== undefined && j.price !== null && j.price !== '') {
        raw = j.price;
      } else if (j.meta && j.meta.price !== undefined && j.meta.price !== null && j.meta.price !== '') {
        raw = j.meta.price;
      }

      if(raw === null || raw === undefined || raw === '') return;

      const p = fmt6(raw);
      const d = document.getElementById('rwaEmaPrice');
      const m = document.getElementById('rwaEmaPrice_m');
      if(d) d.textContent = p;
      if(m) m.textContent = p;
    }catch(e){}
  }

  refreshEMA();
  setInterval(refreshEMA, 6000);

  function isTypingTarget(t){
    if(!t) return false;
    const tag = (t.tagName || '').toLowerCase();
    if(t.isContentEditable) return true;
    return tag === 'input' || tag === 'textarea' || tag === 'select';
  }

  document.addEventListener('keydown', function(e){
    if(e.defaultPrevented) return;
    if(e.ctrlKey || e.metaKey || e.altKey) return;
    if(isTypingTarget(e.target)) return;

    const k = (e.key || '').toLowerCase();
    if(k === 'h'){ window.location.href='/rwa/'; e.preventDefault(); }
    else if(k === 'l'){ window.location.href='/rwa/logout.php'; e.preventDefault(); }
    else if(k === 'b'){ window.location.href='/rwa/book/'; e.preventDefault(); }
    else if(k === 'd'){ window.location.href='/rwa/deal/'; e.preventDefault(); }
    else if(k === 'c'){ window.location.href='/rwa/calc/'; e.preventDefault(); }
  }, {passive:false});
})();
</script>