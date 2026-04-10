/**
 * UI Logger bridge
 */

let targetId = 'factoryConsoleLog';

export function configureLogger(cfg = {}) {
  if (cfg.uiTargetId) targetId = cfg.uiTargetId;
}

export function logInfo(msg, meta) {
  write(msg, 'info', meta);
}

export function logSuccess(msg, meta) {
  write(msg, 'ok', meta);
}

export function logWarn(msg, meta) {
  write(msg, 'warn', meta);
}

export function logError(msg, meta) {
  write(msg, 'error', meta);
}

function write(message, tone, meta) {
  const el = document.getElementById(targetId);
  if (!el) return;

  const row = document.createElement('div');
  row.className = `log-row tone-${tone}`;

  const time = document.createElement('div');
  time.className = 'log-time';
  time.textContent = new Date().toLocaleTimeString();

  const msg = document.createElement('div');
  msg.className = 'log-msg';
  msg.textContent = typeof message === 'string'
    ? message
    : JSON.stringify(message);

  row.appendChild(time);
  row.appendChild(msg);
  el.prepend(row);

  if (meta) {
    console.log('[CERT]', message, meta);
  } else {
    console.log('[CERT]', message);
  }
}
