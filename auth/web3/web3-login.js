(function () {
  'use strict';

  const API_NONCE  = '/rwa/auth/web3/nonce.php';
  const API_VERIFY = '/rwa/auth/web3/verify.php';
  const NEXT       = '/rwa/login-select.php';

  function $(id){ return document.getElementById(id); }

  function setStatus(t){
    const el = $('loginStatus');
    if (el) el.textContent = t || '—';
  }

  function setWallet(a){
    const el = $('walletAddr');
    if (el) el.textContent = a || '—';
  }

  function prettyError(json, fallback) {
    if (!json) return fallback || 'Request failed';
    return json.detail || json.error || json.message || fallback || 'Request failed';
  }

  async function fetchJSON(url, opts){
    opts = opts || {};
    opts.credentials = 'include';
    const res = await fetch(url, opts);
    const json = await res.json().catch(() => null);
    if (!res.ok || !json) {
      throw new Error(prettyError(json, 'HTTP ' + res.status));
    }
    return json;
  }

  async function postJSON(url, data){
    const res = await fetch(url, {
      method: 'POST',
      credentials: 'include',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify(data || {})
    });
    const json = await res.json().catch(() => null);
    if (!res.ok || !json || json.ok !== true) {
      throw new Error(prettyError(json, 'Request failed'));
    }
    return json;
  }

  function provider(){
    return window.ethereum || null;
  }

  function buildSiweMessage(domain, uri, address, chainId, nonce){
    return domain + ' wants you to sign in with your Ethereum account:\n' +
      address + '\n\n' +
      'Sign-In With Ethereum to access AdoptGold RWA.\n' +
      'URI: ' + uri + '\n' +
      'Version: 1\n' +
      'Chain ID: ' + chainId + '\n' +
      'Nonce: ' + nonce + '\n' +
      'Issued At: ' + new Date().toISOString();
  }

  async function connectWallet(){
    const eth = provider();
    if (!eth) {
      setStatus('No EVM provider');
      alert('MetaMask or TokenPocket required');
      return;
    }

    setStatus('Connecting…');

    try {
      const accounts = await eth.request({ method:'eth_requestAccounts' });
      const address = (accounts && accounts[0]) ? String(accounts[0]).toLowerCase() : '';
      if (!address) throw new Error('No wallet address');

      setWallet(address);
      setStatus('Wallet connected');

      const signBtn = $('btnSign');
      if (signBtn) signBtn.disabled = false;
    } catch (e) {
      setStatus(e && e.message ? e.message : 'Connect failed');
    }
  }

  async function signIn(){
    const eth = provider();
    if (!eth) {
      setStatus('No EVM provider');
      return;
    }

    try {
      const accounts = await eth.request({ method:'eth_accounts' });
      const address = (accounts && accounts[0]) ? String(accounts[0]).toLowerCase() : '';
      if (!address) {
        setStatus('Connect wallet first');
        return;
      }

      setWallet(address);
      setStatus('Getting nonce…');

      const nonceData = await fetchJSON(API_NONCE, { method:'GET' });
      if (!nonceData || nonceData.ok !== true || !nonceData.nonce) {
        throw new Error(prettyError(nonceData, 'Nonce failed'));
      }

      const chainIdHex = await eth.request({ method:'eth_chainId' }).catch(() => '0x1');
      const chainId = parseInt(chainIdHex, 16) || 1;

      const message = buildSiweMessage(
        window.location.host,
        window.location.origin + '/rwa/web3-login.php',
        address,
        chainId,
        nonceData.nonce
      );

      setStatus('Awaiting signature…');

      const signature = await eth.request({
        method:'personal_sign',
        params:[message, address]
      });

      if (!signature || typeof signature !== 'string') {
        throw new Error('Wallet returned empty signature');
      }

      setStatus('Verifying…');

      const verify = await postJSON(API_VERIFY, {
        address,
        message,
        signature,
        next: NEXT
      });

      setStatus('Login success');
      window.location.href = verify.redirect || NEXT;
    } catch (e) {
      setStatus(e && e.message ? e.message : 'Sign-in failed');
    }
  }

  function init(){
    const btnConnect = $('btnConnect');
    const btnSign = $('btnSign');

    if (btnSign) btnSign.disabled = true;
    if (btnConnect) btnConnect.addEventListener('click', connectWallet);
    if (btnSign) btnSign.addEventListener('click', signIn);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();