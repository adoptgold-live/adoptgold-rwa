<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/rwa-session.php';
require_once __DIR__ . '/inc/core/session-user.php';

if (session_user_id() > 0) {
    header('Location: /rwa/login-select.php');
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Web3 Login</title>
  <link rel="stylesheet" href="/rwa/assets/css/rwa-auth.css">
  <script src="https://cdn.jsdelivr.net/npm/ethers@6.13.2/dist/ethers.umd.min.js"></script>
  <style>
    .auth-screen{
      min-height:100dvh;
      display:flex;
      align-items:center;
      justify-content:center;
      padding:18px 16px 88px;
    }
    .auth-center{
      width:100%;
      max-width:680px;
    }
    .auth-panel{
      border:1px solid rgba(179,136,255,.28);
      border-radius:24px;
      background:rgba(255,255,255,.06);
      backdrop-filter:blur(18px);
      box-shadow:0 18px 40px rgba(0,0,0,.26);
      padding:18px;
    }
    .auth-topline{
      display:flex;
      justify-content:flex-start;
      margin-bottom:12px;
    }
    .back-btn{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-height:40px;
      padding:0 12px;
      border-radius:999px;
      text-decoration:none;
      color:#fff;
      border:1px solid rgba(179,136,255,.28);
      background:rgba(255,255,255,.04);
      font-size:13px;
    }
    .hero{
      text-align:center;
    }
    .title{
      font-size:28px;
      font-weight:800;
      line-height:1.1;
    }
    .sub{
      margin-top:6px;
      color:#c4b8ea;
      font-size:14px;
    }
    .box{
      margin-top:16px;
      padding:18px;
      border-radius:18px;
      border:1px solid rgba(179,136,255,.20);
      background:rgba(255,255,255,.03);
      text-align:center;
    }
    .desc{
      color:#c4b8ea;
      font-size:14px;
      line-height:1.5;
      margin-bottom:14px;
    }
    .btn{
      display:flex;
      align-items:center;
      justify-content:center;
      min-height:54px;
      width:100%;
      border-radius:16px;
      text-decoration:none;
      font-weight:800;
      font-size:16px;
      border:1px solid rgba(179,136,255,.28);
      cursor:pointer;
      margin-top:14px;
    }
    .btn-primary{
      background:linear-gradient(135deg,#b388ff,#7dffcf);
      color:#140d25;
      border:0;
    }
    .btn-secondary{
      background:rgba(255,255,255,.05);
      color:#fff;
    }
    .statusbox{
      margin-top:18px;
      padding:16px 18px;
      border:1px solid rgba(179,136,255,.18);
      border-radius:16px;
      background:rgba(0,0,0,.18);
      text-align:left;
      overflow:hidden;
    }
    .row{
      display:grid;
      grid-template-columns:92px minmax(0,1fr);
      gap:12px;
      margin-top:10px;
      color:#fff;
      align-items:start;
    }
    .row:first-child{
      margin-top:0;
    }
    .muted{
      color:#c4b8ea;
      font-size:14px;
    }
    .value{
      min-width:0;
      overflow-wrap:anywhere;
      word-break:break-word;
      line-height:1.5;
      text-align:left;
    }
    .wallet-value{
      font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
      font-size:15px;
    }
    .status-value{
      font-weight:700;
      font-size:15px;
    }
    .auth-footer{
      margin-top:16px;
      text-align:center;
      color:#c4b8ea;
      font-size:12px;
      line-height:1.6;
    }
    @media (max-width:560px){
      .auth-center{
        max-width:100%;
      }
      .auth-panel{
        padding:16px;
      }
      .box{
        padding:16px;
      }
      .row{
        grid-template-columns:74px minmax(0,1fr);
        gap:10px;
      }
      .wallet-value,
      .status-value{
        font-size:14px;
      }
      .btn{
        min-height:50px;
        font-size:15px;
      }
    }
  </style>
</head>
<body>
<div class="auth-screen">
  <div class="auth-center">
    <div class="auth-panel">
      <div class="auth-topline">
        <a class="back-btn" href="/rwa/">Back to Hub</a>
      </div>

      <div class="hero">
        <div class="title">Web3 Login</div>
        <div class="sub">Secondary login convenience</div>
      </div>

      <div class="box">
        <div class="desc">Connect your EVM wallet, then sign in with SIWE.</div>
        <button id="connectBtn" class="btn btn-primary" type="button">Connect Wallet</button>
        <button id="signBtn" class="btn btn-secondary" type="button">Sign In with SIWE</button>

        <div class="statusbox">
          <div class="row">
            <div class="muted">Wallet</div>
            <div id="walletText" class="value wallet-value" title="-">-</div>
          </div>
          <div class="row">
            <div class="muted">Status</div>
            <div id="statusText" class="value status-value">idle</div>
          </div>
        </div>
      </div>

      <div class="auth-footer">© 2025 Blockchain Group Ltd. (Hong Kong) · RWA Standard Organisation (RSO). All rights reserved.</div>
    </div>
  </div>
</div>

<script>
(() => {
  let wallet = '';
  let nonce = '';
  let provider = null;
  let signer = null;

  const walletText = document.getElementById('walletText');
  const statusText = document.getElementById('statusText');
  const connectBtn = document.getElementById('connectBtn');
  const signBtn = document.getElementById('signBtn');

  function setStatus(t){
    statusText.textContent = t || 'idle';
  }

  function shortWallet(w){
    if(!w || w.length < 14) return w || '-';
    return w.slice(0, 8) + '...' + w.slice(-8);
  }

  function setWallet(w){
    const full = w || '';
    walletText.textContent = full ? shortWallet(full) : '-';
    walletText.title = full || '';
  }

  connectBtn.addEventListener('click', async () => {
    try {
      if (!window.ethereum) {
        setStatus('No wallet provider');
        return;
      }
      provider = new ethers.BrowserProvider(window.ethereum);
      const accounts = await provider.send('eth_requestAccounts', []);
      wallet = (accounts && accounts[0]) ? String(accounts[0]).toLowerCase() : '';
      signer = await provider.getSigner();
      setWallet(wallet);
      setStatus(wallet ? 'connected' : 'connect failed');
    } catch (e) {
      setStatus('connect failed');
    }
  });

  signBtn.addEventListener('click', async () => {
    try {
      if (!wallet || !signer) {
        setStatus('connect wallet first');
        return;
      }

      const nonceRes = await fetch('/rwa/auth/web3/nonce.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({})
      });
      const nonceJson = await nonceRes.json();
      if (!nonceJson || !nonceJson.ok || !nonceJson.nonce) {
        setStatus('nonce failed');
        return;
      }

      nonce = nonceJson.nonce;

      const message =
`adoptgold.app wants you to sign in with your Ethereum account:
${wallet}

Sign in to AdoptGold RWA

URI: ${nonceJson.uri}
Version: 1
Chain ID: ${nonceJson.chain_id}
Nonce: ${nonce}
Issued At: ${new Date().toISOString()}`;

      const signature = await signer.signMessage(message);

      const verifyRes = await fetch('/rwa/auth/web3/verify.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          wallet,
          nonce,
          message,
          signature
        })
      });

      const verifyJson = await verifyRes.json();
      if (verifyJson && verifyJson.ok && verifyJson.next_url) {
        setStatus('verify ok');
        window.location.href = verifyJson.next_url;
        return;
      }

      setStatus((verifyJson && verifyJson.error) ? verifyJson.error : 'verify failed');
    } catch (e) {
      setStatus('verify failed');
    }
  });
})();
</script>
</body>
</html>