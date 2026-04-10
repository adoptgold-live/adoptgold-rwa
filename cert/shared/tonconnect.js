/**
 * /var/www/html/public/rwa/cert/shared/tonconnect.js
 * Version: v2.0.0-20260406-v2-shared-tonconnect-baseline
 *
 * MASTER LOCK — RWA Cert V2 TonConnect helper
 * - send transaction via TonConnect (if SDK present)
 * - fallback to deeplink (ton://transfer) if SDK not available
 * - no business logic
 */

let _connected = false;
let _connector = null;

/**
 * Try to detect TonConnect UI instance if already injected globally
 */
function detectConnector() {
  if (_connector) return _connector;

  if (window.tonConnectUI) {
    _connector = window.tonConnectUI;
    _connected = true;
    return _connector;
  }

  if (window.TonConnectUI) {
    try {
      _connector = new window.TonConnectUI({
        manifestUrl: '/tonconnect-manifest.json'
      });
      _connected = true;
      return _connector;
    } catch (_) {
      return null;
    }
  }

  return null;
}

/**
 * Send transaction via TonConnect (preferred)
 */
async function sendTonConnectTx(tonconnect) {
  const connector = detectConnector();

  if (!connector) {
    throw new Error('TONCONNECT_NOT_AVAILABLE');
  }

  const message = {
    validUntil: tonconnect.validUntil || Math.floor(Date.now() / 1000) + 600,
    messages: [
      {
        address: tonconnect.to,
        amount: String(tonconnect.amount),
        payload: tonconnect.payload || ''
      }
    ]
  };

  return await connector.sendTransaction(message);
}

/**
 * Fallback deeplink (ton://transfer)
 */
function buildTonDeeplink(tonconnect) {
  const params = new URLSearchParams();

  if (tonconnect.amount) {
    params.set('amount', String(tonconnect.amount));
  }

  if (tonconnect.payload) {
    params.set('bin', tonconnect.payload);
  }

  if (tonconnect.text) {
    params.set('text', tonconnect.text);
  }

  return 'ton://transfer/' + tonconnect.to + '?' + params.toString();
}

/**
 * Open deeplink
 */
function openTonDeeplink(tonconnect) {
  const url = buildTonDeeplink(tonconnect);
  window.location.href = url;
  return url;
}

/**
 * Main unified send function
 */
async function sendTransaction(tonconnect) {
  if (!tonconnect || !tonconnect.to) {
    throw new Error('TONCONNECT_PAYLOAD_INVALID');
  }

  try {
    return await sendTonConnectTx(tonconnect);
  } catch (err) {
    // fallback
    const deeplink = openTonDeeplink(tonconnect);
    return {
      fallback: true,
      deeplink
    };
  }
}

/**
 * Simple connect check
 */
function isConnected() {
  return !!detectConnector();
}

/**
 * Optional manual connect trigger
 */
async function connectWallet() {
  const connector = detectConnector();
  if (!connector) return false;

  if (connector.connectWallet) {
    try {
      await connector.connectWallet();
      return true;
    } catch (_) {
      return false;
    }
  }

  return false;
}

export {
  sendTransaction,
  buildTonDeeplink,
  openTonDeeplink,
  isConnected,
  connectWallet
};
