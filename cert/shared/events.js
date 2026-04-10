/**
 * /var/www/html/public/rwa/cert/shared/events.js
 * Version: v2.0.0-20260406-v2-router-events-contract
 *
 * MASTER LOCK
 * - shared event bus helpers for V2 router/modules
 * - no backend truth here
 * - no DOM layout logic here
 */

const CERT_EVENT_PREFIX = 'cert:';

export function onCertEvent(eventName, handler, options = {}) {
  const name = normalizeEventName(eventName);
  if (!name || typeof handler !== 'function') {
    return () => {};
  }

  const listener = (event) => {
    try {
      handler(event);
    } catch (error) {
      console.error('[CERT][EVENT][HANDLER_ERROR]', name, error);
    }
  };

  document.addEventListener(name, listener, options);

  return () => {
    document.removeEventListener(name, listener, options);
  };
}

export function emitCertEvent(eventName, detail = {}, options = {}) {
  const name = normalizeEventName(eventName);
  if (!name) return null;

  const event = new CustomEvent(name, {
    detail: isObject(detail) ? detail : { value: detail },
    bubbles: options.bubbles !== false,
    cancelable: options.cancelable === true,
    composed: options.composed === true
  });

  document.dispatchEvent(event);
  return event;
}

export function getEventDetail(event) {
  if (!event || !isObject(event.detail)) return {};
  return event.detail;
}

export function onceCertEvent(eventName, handler, options = {}) {
  const off = onCertEvent(eventName, (event) => {
    off();
    handler(event);
  }, options);

  return off;
}

export function waitForCertEvent(eventName, timeoutMs = 15000) {
  return new Promise((resolve, reject) => {
    let timer = null;
    const off = onCertEvent(eventName, (event) => {
      if (timer) clearTimeout(timer);
      off();
      resolve(event);
    });

    if (timeoutMs > 0) {
      timer = setTimeout(() => {
        off();
        reject(new Error(`EVENT_TIMEOUT:${normalizeEventName(eventName)}`));
      }, timeoutMs);
    }
  });
}

function normalizeEventName(eventName) {
  const raw = String(eventName || '').trim();
  if (!raw) return '';
  return raw.startsWith(CERT_EVENT_PREFIX) ? raw : `${CERT_EVENT_PREFIX}${raw}`;
}

function isObject(value) {
  return !!value && Object.prototype.toString.call(value) === '[object Object]';
}
