<?php
declare(strict_types=1);

$treasury = 'UQBdYfGArtoCUmBs5TjYQtfPFfQuGC2Ydbj2pQr3zIlNrDta';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Claim Tester</title>
<style>
:root{
  --bg:#07090b;
  --panel:#0d1216;
  --panel2:#111820;
  --text:#f4f7fb;
  --muted:#91a0b3;
  --line:rgba(255,255,255,.08);
  --green:#79ffb0;
  --gold:#f6d768;
  --red:#ff8f8f;
  --cyan:#7dd3fc;
}
*{box-sizing:border-box}
html,body{margin:0;padding:0;background:var(--bg);color:var(--text);font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace}
body{
  min-height:100dvh;
  padding:
    max(16px, env(safe-area-inset-top))
    16px
    max(16px, env(safe-area-inset-bottom))
    16px;
}
.wrap{max-width:760px;margin:0 auto}
.card{
  background:linear-gradient(180deg,var(--panel),var(--panel2));
  border:1px solid var(--line);
  border-radius:18px;
  padding:16px;
  margin:0 0 14px;
  box-shadow:0 12px 28px rgba(0,0,0,.25);
}
h1{margin:0 0 8px;font-size:24px;line-height:1.2;color:var(--gold)}
.sub{color:var(--muted);font-size:13px;line-height:1.5}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media (max-width:720px){.grid{grid-template-columns:1fr}}
label{display:block;margin:0 0 6px;font-size:12px;color:var(--muted)}
input,select,textarea{
  width:100%;
  background:#000;
  color:#fff;
  border:1px solid #3b4a5a;
  border-radius:12px;
  padding:12px 14px;
  outline:none;
  min-height:46px;
  font:inherit;
}
input:focus,select:focus,textarea:focus{border-color:var(--green)}
textarea{min-height:88px;resize:vertical}
.row{display:flex;gap:10px;flex-wrap:wrap}
.btn{
  appearance:none;
  border:1px solid rgba(255,255,255,.12);
  border-radius:12px;
  min-height:46px;
  padding:0 16px;
  background:#000;
  color:#fff;
  font:inherit;
  cursor:pointer;
}
.btn:hover{border-color:var(--gold)}
.btn-primary{background:var(--gold);color:#111;border-color:var(--gold);font-weight:700}
.btn-green{background:var(--green);color:#07110b;border-color:var(--green);font-weight:700}
.btn-ghost{background:transparent}
.kv{display:grid;grid-template-columns:140px 1fr;gap:8px 12px}
.kv div{padding:4px 0;border-bottom:1px dashed rgba(255,255,255,.06)}
.k{color:var(--muted)}
.v{word-break:break-all}
.ok{color:var(--green)}
.warn{color:var(--gold)}
.err{color:var(--red)}
.mono{font-family:inherit}
.small{font-size:12px;color:var(--muted)}
.badge{
  display:inline-flex;align-items:center;gap:8px;
  padding:7px 10px;border:1px solid rgba(121,255,176,.22);
  color:var(--green);border-radius:999px;background:rgba(121,255,176,.06);
  font-size:12px
}
.hr{height:1px;background:var(--line);margin:12px 0}
.log{
  background:#000;border:1px solid rgba(255,255,255,.08);
  border-radius:12px;padding:12px;min-height:120px;
  white-space:pre-wrap;word-break:break-word;color:var(--green)
}
.history-item{
  border:1px solid rgba(255,255,255,.08);
  border-radius:12px;padding:10px;margin:0 0 10px;background:rgba(255,255,255,.02)
}
a{color:var(--cyan);text-decoration:none}
</style>
</head>
<body>
<div class="wrap">

  <div class="card">
    <h1>Claim Tester</h1>
    <div class="sub">
      User-side test only. This page prepares a claim request and asks the user wallet to send the locked
      <strong>0.10 TON treasury contribution</strong> with a unique claim memo. It does <strong>not</strong> execute jetton payout directly.
    </div>
    <div style="margin-top:10px" class="badge">Locked model: user pays gas + 0.10 TON treasury, admin/system executes payout later</div>
  </div>

  <div class="card">
    <div class="grid">
      <div>
        <label for="token">Token</label>
        <select id="token">
          <option value="EMA" data-id="1" data-decimals="9">EMA</option>
          <option value="EMX" data-id="2" data-decimals="9" selected>EMX</option>
          <option value="EMS" data-id="3" data-decimals="9">EMS</option>
          <option value="WEMS" data-id="4" data-decimals="9">WEMS</option>
          <option value="USDT" data-id="5" data-decimals="6">USDT-TON</option>
        </select>
      </div>

      <div>
        <label for="amount">Claim Amount</label>
        <input id="amount" type="text" inputmode="decimal" placeholder="0.1" value="0.1">
      </div>

      <div style="grid-column:1/-1">
        <label for="recipient">My Receive Wallet</label>
        <input id="recipient" type="text" spellcheck="false" placeholder="UQ...">
      </div>

      <div style="grid-column:1/-1">
        <label for="note">Optional Note</label>
        <input id="note" type="text" maxlength="120" placeholder="test request / QA / user note">
      </div>
    </div>

    <div class="hr"></div>

    <div class="row">
      <button class="btn btn-primary" id="btnPrepare">PREPARE CLAIM</button>
      <button class="btn btn-ghost" id="btnReset">RESET</button>
    </div>
  </div>

  <div class="card" id="resultCard" style="display:none">
    <div class="row" style="justify-content:space-between;align-items:center">
      <strong>Prepared Claim Request</strong>
      <span class="small">Treasury contribution fixed at 0.10 TON</span>
    </div>
    <div class="hr"></div>

    <div class="kv">
      <div class="k">Claim Ref</div><div class="v" id="vRef">-</div>
      <div class="k">Token</div><div class="v" id="vToken">-</div>
      <div class="k">Token ID</div><div class="v" id="vTokenId">-</div>
      <div class="k">Decimals</div><div class="v" id="vDecimals">-</div>
      <div class="k">Claim Amount</div><div class="v" id="vAmount">-</div>
      <div class="k">Recipient</div><div class="v" id="vRecipient">-</div>
      <div class="k">Treasury</div><div class="v" id="vTreasury"><?= htmlspecialchars($treasury, ENT_QUOTES) ?></div>
      <div class="k">TON Amount</div><div class="v">0.10 TON</div>
      <div class="k">Memo</div><div class="v" id="vMemo">-</div>
      <div class="k">Deep Link</div><div class="v"><a id="vLink" href="#" target="_blank" rel="noopener">Open wallet</a></div>
    </div>

    <div class="hr"></div>

    <div class="row">
      <button class="btn btn-green" id="btnOpenWallet">OPEN TON WALLET</button>
      <button class="btn" id="btnCopyMemo">COPY MEMO</button>
      <button class="btn" id="btnCopyLink">COPY DEEPLINK</button>
      <button class="btn" id="btnSaveLocal">SAVE LOCAL</button>
    </div>

    <div class="hr"></div>

    <div class="small">
      User test flow:
      prepare claim → send 0.10 TON to treasury with memo → keep claim ref → backend/admin verifies and later executes payout.
    </div>
  </div>

  <div class="card">
    <strong>Status Log</strong>
    <div class="hr"></div>
    <div class="log" id="log"></div>
  </div>

  <div class="card">
    <div class="row" style="justify-content:space-between;align-items:center">
      <strong>Local Test History</strong>
      <button class="btn btn-ghost" id="btnClearHistory">CLEAR</button>
    </div>
    <div class="hr"></div>
    <div id="history"></div>
  </div>

</div>

<script>
(function(){
  'use strict';

  const TREASURY = <?= json_encode($treasury, JSON_UNESCAPED_SLASHES) ?>;
  const TON_AMOUNT = '0.10';
  const TON_NANO = '100000000'; // 0.10 TON
  const LS_KEY = 'claim_tester_history_v1';

  const $ = (id) => document.getElementById(id);

  const els = {
    token: $('token'),
    amount: $('amount'),
    recipient: $('recipient'),
    note: $('note'),
    btnPrepare: $('btnPrepare'),
    btnReset: $('btnReset'),

    resultCard: $('resultCard'),
    vRef: $('vRef'),
    vToken: $('vToken'),
    vTokenId: $('vTokenId'),
    vDecimals: $('vDecimals'),
    vAmount: $('vAmount'),
    vRecipient: $('vRecipient'),
    vTreasury: $('vTreasury'),
    vMemo: $('vMemo'),
    vLink: $('vLink'),

    btnOpenWallet: $('btnOpenWallet'),
    btnCopyMemo: $('btnCopyMemo'),
    btnCopyLink: $('btnCopyLink'),
    btnSaveLocal: $('btnSaveLocal'),

    log: $('log'),
    history: $('history'),
    btnClearHistory: $('btnClearHistory')
  };

  let state = {
    prepared: null
  };

  function log(msg, cls){
    const line = `[${new Date().toISOString()}] ${msg}`;
    if (els.log.textContent) els.log.textContent += '\n' + line;
    else els.log.textContent = line;
    if (cls) console.log(cls, line);
  }

  function onlyAmount(v){
    return String(v).replace(/[^\d.]/g, '').replace(/(\..*)\./g, '$1');
  }

  function isFriendlyAddress(v){
    return /^UQ|^EQ/.test(String(v).trim()) && String(v).trim().length >= 48;
  }

  function tsCompact(){
    const d = new Date();
    const p = (n) => String(n).padStart(2,'0');
    return d.getUTCFullYear() +
      p(d.getUTCMonth()+1) +
      p(d.getUTCDate()) +
      p(d.getUTCHours()) +
      p(d.getUTCMinutes()) +
      p(d.getUTCSeconds());
  }

  function randHex(len){
    const arr = new Uint8Array(len);
    crypto.getRandomValues(arr);
    return Array.from(arr).map(x => x.toString(16).padStart(2,'0')).join('').toUpperCase();
  }

  function currentTokenMeta(){
    const opt = els.token.options[els.token.selectedIndex];
    return {
      symbol: opt.value,
      tokenId: opt.getAttribute('data-id') || '',
      decimals: opt.getAttribute('data-decimals') || ''
    };
  }

  function buildClaimRef(){
    return `CLM-REQ-${tsCompact()}-${randHex(4)}`;
  }

  function buildMemo(claim){
    const note = claim.note ? `|${claim.note}` : '';
    return `${claim.ref}|${claim.token}|${claim.amount}|${claim.recipient}${note}`;
  }

  function buildDeepLink(claim){
    const text = encodeURIComponent(claim.memo);
    return `ton://transfer/${TREASURY}?amount=${TON_NANO}&text=${text}`;
  }

  function getHistory(){
    try{
      return JSON.parse(localStorage.getItem(LS_KEY) || '[]');
    }catch(_){
      return [];
    }
  }

  function setHistory(items){
    localStorage.setItem(LS_KEY, JSON.stringify(items));
  }

  function renderHistory(){
    const items = getHistory().slice().reverse();
    if (!items.length){
      els.history.innerHTML = '<div class="small">No saved local test history.</div>';
      return;
    }
    els.history.innerHTML = items.map(item => `
      <div class="history-item">
        <div><strong>${escapeHtml(item.ref)}</strong></div>
        <div class="small">token=${escapeHtml(item.token)} · amount=${escapeHtml(item.amount)} · saved=${escapeHtml(item.savedAt)}</div>
        <div class="small">recipient=${escapeHtml(item.recipient)}</div>
        <div class="small">memo=${escapeHtml(item.memo)}</div>
      </div>
    `).join('');
  }

  function escapeHtml(v){
    return String(v)
      .replace(/&/g,'&amp;')
      .replace(/</g,'&lt;')
      .replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;')
      .replace(/'/g,'&#039;');
  }

  async function copyText(v, label){
    await navigator.clipboard.writeText(v);
    log(`${label} copied`);
  }

  function prepareClaim(){
    const meta = currentTokenMeta();
    const amount = onlyAmount(els.amount.value);
    const recipient = els.recipient.value.trim();
    const note = els.note.value.trim().replace(/\|/g,'/');

    if (!amount || Number(amount) <= 0){
      alert('Enter a valid claim amount.');
      return;
    }

    if (!isFriendlyAddress(recipient)){
      alert('Enter a valid TON friendly receive wallet.');
      return;
    }

    const claim = {
      ref: buildClaimRef(),
      token: meta.symbol,
      tokenId: meta.tokenId,
      decimals: meta.decimals,
      amount,
      recipient,
      note,
      treasury: TREASURY,
      treasuryTon: TON_AMOUNT,
      createdAt: new Date().toISOString()
    };

    claim.memo = buildMemo(claim);
    claim.deeplink = buildDeepLink(claim);

    state.prepared = claim;
    renderPrepared();
    log(`Prepared claim ${claim.ref}`);
  }

  function renderPrepared(){
    const c = state.prepared;
    if (!c) return;

    els.vRef.textContent = c.ref;
    els.vToken.textContent = c.token;
    els.vTokenId.textContent = c.tokenId;
    els.vDecimals.textContent = c.decimals;
    els.vAmount.textContent = c.amount;
    els.vRecipient.textContent = c.recipient;
    els.vMemo.textContent = c.memo;
    els.vLink.href = c.deeplink;
    els.vLink.textContent = c.deeplink;
    els.resultCard.style.display = '';
  }

  function saveLocal(){
    const c = state.prepared;
    if (!c){
      alert('Prepare a claim first.');
      return;
    }
    const items = getHistory();
    items.push({
      ref: c.ref,
      token: c.token,
      amount: c.amount,
      recipient: c.recipient,
      memo: c.memo,
      deeplink: c.deeplink,
      savedAt: new Date().toISOString()
    });
    setHistory(items);
    renderHistory();
    log(`Saved local claim ${c.ref}`);
  }

  function resetForm(){
    els.amount.value = '0.1';
    els.note.value = '';
    state.prepared = null;
    els.resultCard.style.display = 'none';
    log('Reset form');
  }

  els.btnPrepare.addEventListener('click', prepareClaim);
  els.btnReset.addEventListener('click', resetForm);

  els.btnOpenWallet.addEventListener('click', function(){
    const c = state.prepared;
    if (!c){
      alert('Prepare a claim first.');
      return;
    }
    window.location.href = c.deeplink;
  });

  els.btnCopyMemo.addEventListener('click', async function(){
    const c = state.prepared;
    if (!c) return alert('Prepare a claim first.');
    await copyText(c.memo, 'Memo');
  });

  els.btnCopyLink.addEventListener('click', async function(){
    const c = state.prepared;
    if (!c) return alert('Prepare a claim first.');
    await copyText(c.deeplink, 'Deep link');
  });

  els.btnSaveLocal.addEventListener('click', saveLocal);

  els.btnClearHistory.addEventListener('click', function(){
    localStorage.removeItem(LS_KEY);
    renderHistory();
    log('Cleared local history');
  });

  els.amount.addEventListener('input', function(){
    this.value = onlyAmount(this.value);
  });

  renderHistory();
  log('Claim tester ready');
  log(`Treasury fixed: ${TREASURY}`);
  log('This tester is for user claim request only, not direct payout execution');
})();
</script>
</body>
</html>
