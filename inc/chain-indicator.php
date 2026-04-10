<?php
// /rwa/inc/chain-indicator.php
// v1.1.20260305
// - Right-side compact line: "Chain ID: XXXXX | EMA$: 0.0000"
// - Loads SFX manager (hidden) once

declare(strict_types=1);

$CHAIN_ID = '7709304653';

// EMA$ price source (locked): /rwa/inc/ema-price.php
$ema_price = 0.0;
try {
  $emaPriceFile = __DIR__ . '/ema-price.php';
  if (is_file($emaPriceFile)) {
    require_once $emaPriceFile;

    if (function_exists('rwa_get_ema_price')) {
      $ema_price = (float)rwa_get_ema_price();
    } elseif (function_exists('ema_price')) {
      $ema_price = (float)ema_price();
    } elseif (isset($GLOBALS['EMA_PRICE'])) {
      $ema_price = (float)$GLOBALS['EMA_PRICE'];
    } elseif (isset($ema_price) && is_numeric($ema_price)) {
      $ema_price = (float)$ema_price;
    }
  }
} catch (\Throwable $e) {
  $ema_price = 0.0;
}

$ema_text = number_format($ema_price, 4, '.', '');
?>
<div class="rwa-chainline" role="status" aria-label="Chain and EMA price">
  <span class="rwa-led" aria-hidden="true"></span>
  <span>Chain ID: <?php echo htmlspecialchars($CHAIN_ID, ENT_QUOTES); ?></span>
  <span class="rwa-pipe" aria-hidden="true">|</span>
  <span>EMA$: <?php echo htmlspecialchars($ema_text, ENT_QUOTES); ?></span>
</div>

<script>
/* Hidden SFX manager (load once) */
(function(){
  try{
    if (window.__POADO_SFX_LOADED__) return;
    window.__POADO_SFX_LOADED__ = true;
    var s = document.createElement('script');
    s.src = '/dashboard/assets/js/sfx.js?v=1';
    s.defer = true;
    document.head.appendChild(s);
  }catch(e){}
})();
</script>

<style>
  .rwa-chainline{
    display:flex;
    align-items:center;
    gap:10px;
    padding:8px 10px;
    border-radius:999px;
    border:1px solid rgba(178,120,255,.20);
    background: rgba(255,255,255,.03);
    color: rgba(245,244,255,.88);
    font-size:12px;
    line-height:1;
    white-space:nowrap;
  }
  .rwa-led{
    width:10px;height:10px;border-radius:50%;
    background:#29ff8f;
    box-shadow:0 0 10px rgba(41,255,143,.35);
    flex:0 0 auto;
  }
  .rwa-pipe{ opacity:.55; }
</style>