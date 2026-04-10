(function () {
  'use strict';

  const API_SESSION = '/rwa/auth/web3/session-wallet.php';
  const API_NONCE   = '/rwa/auth/web3/nonce.php';
  const API_VERIFY  = '/rwa/auth/web3/verify.php';
  const NEXT        = '/rwa/login-select.php';

  const el = id => document.getElementById(id);
  const logBox = el('logBox');

  function log(msg, obj){
    const line = '[' + new Date().toISOString() + '] ' + msg + (obj ? ' ' + JSON.stringify(obj) : '');
    if (logBox) {
      logBox.textContent += (logBox.textContent ? '\n' : '') + line;
      logBox.scrollTop = logBox.scrollHeight;
    }
    console.log(line);
  }

  function setDot(id, mode){
    const d = el(id);
    if (!d) return;
    d.className = 'dot' + (mode ? ' ' + mode : '');
  }

  function setTxt(id, txt){
    const x = el(id);
    if (x) x.textContent = txt || '—';
  }

  function shortAddr(a){
    if (!a) return '—';
    return a.length > 12 ? a.slice(0,6) + '...' + a.slice(-4) : a;
  }

  async function postJSON(url, data){
    const res = await fetch(url, {
      method:'POST',
      headers:{'Content-Type':'application/json','Accept':'application/json'},
      credentials:'same-origin',
      body: JSON.stringify(data || {})
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error((json && (json.error || json.message)) ? (json.error || json.message) : ('HTTP ' + res.status));
    return json;
  }

  async function getJSON(url){
    const res = await fetch(url, {
      headers:{'Accept':'application/json'},
      credentials:'same-origin'
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error((json && (json.error || json.message)) ? (json.error || json.message) : ('HTTP ' + res.status));
    return json;
  }

  async function refreshSession(){
    try {
      const j = await postJSON(API_SESSION, {});
      setDot('sessDot', j.loggedIn ? 'ok' : '');
      setTxt('sessTxt', j.loggedIn ? 'Session OK' : 'No session yet');
      setTxt('sidTxt', j.sess_id || '—');
      setTxt('csrfTxt', j.csrf ? shortAddr(j.csrf) : '—');
      log('session handshake', j);
    } catch (e) {
      setDot('sessDot', 'bad');
      setTxt('sessTxt', 'Session API error');
      log('session handshake ERROR', String(e.message || e));
    }
  }

  async function connectWallet(){
    try {
      if (!window.ethereum) {
        setDot('providerDot', 'bad');
        setTxt('providerTxt', 'No provider detected');
        return;
      }
      setDot('providerDot', 'ok');
      setTxt('providerTxt', 'Provider detected');

      const accounts = await window.ethereum.request({ method:'eth_requestAccounts' });
      const address = accounts && accounts[0] ? String(accounts[0]).toLowerCase() : '';
      if (!address) throw new Error('No account');

      setDot('walletDot', 'ok');
      setTxt('walletTxt', address);
      setTxt('walletShort', shortAddr(address));

      const chainId = await window.ethereum.request({ method:'eth_chainId' }).catch(() => '');
      setDot('chainDot', chainId ? 'ok' : '');
      setTxt('chainTxt', chainId || '—');

      el('btnSign').disabled = false;

      log('wallet connected', { address, chainId });
      await refreshSession();
    } catch (e) {
      log('connect ERROR', String(e.message || e));
      setDot('walletDot', 'bad');
      setTxt('walletTxt', 'Connect failed');
    }
  }

  function buildMessage(domain, uri, address, chainId, nonce){
    return domain + ' wants you to sign in with your Ethereum account:\n' +
      address + '\n\n' +
      'Sign-In With Ethereum to access ADOPT.GOLD RWA.\n' +
      'URI: ' + uri + '\n' +
      'Version: 1\n' +
      'Chain ID: ' + chainId + '\n' +
      'Nonce: ' + nonce + '\n' +
      'Issued At: ' + (new Date().toISOString());
  }

  async function signIn(){
    try {
      if (!window.ethereum) throw new Error('No provider');
      const accounts = await window.ethereum.request({ method:'eth_accounts' });
      const address = accounts && accounts[0] ? String(accounts[0]).toLowerCase() : '';
      if (!address) throw new Error('Wallet not connected');

      setTxt('siweTxt', 'Requesting nonce…');
      const nonceJson = await getJSON(API_NONCE);
      const nonce = nonceJson.nonce || '';
      if (!nonce) throw new Error('Nonce failed');

      const chainId = await window.ethereum.request({ method:'eth_chainId' }).catch(() => '0x1');
      const message = buildMessage(window.location.host, window.location.origin + '/rwa/web3-login.php', address, parseInt(chainId,16) || 1, nonce);

      setTxt('siweTxt', 'Signing…');
      const signature = await window.ethereum.request({
        method: 'personal_sign',
        params: [message, address]
      });

      setTxt('siweTxt', 'Verifying…');
      const verify = await postJSON(API_VERIFY, {
        address: address,
        message: message,
        signature: signature,
        next: NEXT
      });

      setDot('siweDot', 'ok');
      setTxt('siweTxt', 'Verified');
      log('SIWE verify ok', verify);

      window.location.href = verify.redirect || NEXT;
    } catch (e) {
      setDot('siweDot', 'bad');
      setTxt('siweTxt', 'Failed');
      log('SIWE ERROR', String(e.message || e));
      await refreshSession();
    }
  }

  document.addEventListener('DOMContentLoaded', function(){
    setTxt('providerTxt', window.ethereum ? 'Provider detected' : 'No provider detected');
    setDot('providerDot', window.ethereum ? 'ok' : 'bad');
    setTxt('walletTxt', 'Not connected');
    setTxt('chainTxt', '—');
    setTxt('siweTxt', 'Awaiting sign');
    setTxt('sessTxt', 'Not established');
    el('btnSign').disabled = true;

    el('btnConnect').addEventListener('click', connectWallet);
    el('btnSign').addEventListener('click', signIn);
    el('btnRefresh').addEventListener('click', refreshSession);
    el('btnCopy').addEventListener('click', function(){
      navigator.clipboard.writeText(logBox ? logBox.textContent : '');
      log('log copied');
    });

    refreshSession();
  });
})();