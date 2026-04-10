/**
 * /var/www/html/public/rwa/cert/shared/dom.js
 * Version: v2.0.0-20260406-v2-router-dom-contract
 *
 * MASTER LOCK
 * - shared DOM helpers for V2 router/modules
 * - no backend truth here
 * - no business logic truth here
 * - no visible layout drift
 * - only mount/show/hide/text/html/status/log/select helpers
 */

export function byId(id) {
  if (!id || typeof document === 'undefined') return null;
  return document.getElementById(String(id));
}

export function qs(selector, root = document) {
  if (!selector || !root?.querySelector) return null;
  return root.querySelector(selector);
}

export function qsa(selector, root = document) {
  if (!selector || !root?.querySelectorAll) return [];
  return Array.from(root.querySelectorAll(selector));
}

export function text(target, value) {
  const el = resolveEl(target);
  if (!el) return null;
  el.textContent = String(value ?? '');
  return el;
}

export function html(target, value) {
  const el = resolveEl(target);
  if (!el) return null;
  el.innerHTML = String(value ?? '');
  return el;
}

export function show(target, displayValue = '') {
  const el = resolveEl(target);
  if (!el) return null;
  el.hidden = false;
  if (displayValue) {
    el.style.display = displayValue;
  } else if (el.style.display === 'none') {
    el.style.removeProperty('display');
  }
  return el;
}

export function hide(target) {
  const el = resolveEl(target);
  if (!el) return null;
  el.hidden = true;
  el.style.display = 'none';
  return el;
}

export function attr(target, name, value) {
  const el = resolveEl(target);
  if (!el || !name) return null;

  if (value === null || value === undefined || value === false) {
    el.removeAttribute(name);
    return el;
  }

  if (value === true) {
    el.setAttribute(name, '');
    return el;
  }

  el.setAttribute(name, String(value));
  return el;
}

export function dataAttr(target, name, value) {
  const el = resolveEl(target);
  if (!el || !name) return null;

  if (!el.dataset) return attr(el, `data-${toDataName(name)}`, value);

  if (value === null || value === undefined || value === false) {
    delete el.dataset[toDatasetKey(name)];
    return el;
  }

  el.dataset[toDatasetKey(name)] = String(value);
  return el;
}

export function addClass(target, ...names) {
  const el = resolveEl(target);
  if (!el?.classList) return null;
  names.flat().filter(Boolean).forEach((name) => el.classList.add(String(name)));
  return el;
}

export function removeClass(target, ...names) {
  const el = resolveEl(target);
  if (!el?.classList) return null;
  names.flat().filter(Boolean).forEach((name) => el.classList.remove(String(name)));
  return el;
}

export function toggleClass(target, name, force) {
  const el = resolveEl(target);
  if (!el?.classList || !name) return null;
  if (typeof force === 'boolean') {
    el.classList.toggle(String(name), force);
  } else {
    el.classList.toggle(String(name));
  }
  return el;
}

export function empty(target) {
  const el = resolveEl(target);
  if (!el) return null;
  el.replaceChildren();
  return el;
}

export function replace(target, node) {
  const el = resolveEl(target);
  if (!el) return null;
  el.replaceChildren();
  if (node instanceof Node) el.appendChild(node);
  return el;
}

export function append(target, node) {
  const el = resolveEl(target);
  if (!el || !(node instanceof Node)) return null;
  el.appendChild(node);
  return el;
}

export function prepend(target, node) {
  const el = resolveEl(target);
  if (!el || !(node instanceof Node)) return null;
  el.prepend(node);
  return el;
}

export function create(tag, props = {}, children = []) {
  if (typeof document === 'undefined') return null;
  const el = document.createElement(tag);

  Object.entries(props || {}).forEach(([key, value]) => {
    if (key === 'class' || key === 'className') {
      el.className = String(value ?? '');
      return;
    }
    if (key === 'text') {
      el.textContent = String(value ?? '');
      return;
    }
    if (key === 'html') {
      el.innerHTML = String(value ?? '');
      return;
    }
    if (key === 'dataset' && value && typeof value === 'object') {
      Object.entries(value).forEach(([dKey, dVal]) => {
        if (dVal !== undefined && dVal !== null) {
          el.dataset[toDatasetKey(dKey)] = String(dVal);
        }
      });
      return;
    }
    if (key === 'style' && value && typeof value === 'object') {
      Object.entries(value).forEach(([sKey, sVal]) => {
        if (sVal !== undefined && sVal !== null) {
          el.style[sKey] = String(sVal);
        }
      });
      return;
    }
    if (key.startsWith('on') && typeof value === 'function') {
      el.addEventListener(key.slice(2).toLowerCase(), value);
      return;
    }
    if (value === true) {
      el.setAttribute(key, '');
      return;
    }
    if (value === false || value === null || value === undefined) {
      return;
    }
    el.setAttribute(key, String(value));
  });

  const childList = Array.isArray(children) ? children : [children];
  childList.forEach((child) => {
    if (child === null || child === undefined || child === false) return;
    if (child instanceof Node) {
      el.appendChild(child);
      return;
    }
    el.appendChild(document.createTextNode(String(child)));
  });

  return el;
}

export function activateQueuePanel(boot, dom, activeBucket) {
  const buckets = boot?.buckets || {};

  Object.entries(buckets).forEach(([key, cfg]) => {
    const panel = byId(cfg.panel);
    const tab = byId(cfg.tab);
    const isActive = key === activeBucket;

    if (panel) {
      if (isActive) {
        show(panel);
        attr(panel, 'data-active', '1');
      } else {
        hide(panel);
        attr(panel, 'data-active', '0');
      }
    }

    if (tab) {
      toggleClass(tab, 'is-active', isActive);
      attr(tab, 'aria-selected', isActive ? 'true' : 'false');
      attr(tab, 'data-active', isActive ? '1' : '0');
    }
  });

  if (dom?.queuePanels) {
    attr(dom.queuePanels, 'data-active-bucket', activeBucket || '');
  }
}

export function activateStageMount(boot, dom, activeStage) {
  const stages = boot?.stages || {};

  Object.entries(stages).forEach(([key, id]) => {
    const node = byId(id);
    if (!node) return;

    const isActive = key === activeStage;
    if (isActive) {
      show(node);
      attr(node, 'data-active', '1');
    } else {
      hide(node);
      attr(node, 'data-active', '0');
    }
  });

  if (dom?.stageRoot) {
    attr(dom.stageRoot, 'data-active-stage', activeStage || '');
  }
}

export function clearSelectedRows(root = document) {
  qsa('[data-cert-uid].is-selected', root).forEach((node) => {
    removeClass(node, 'is-selected');
    attr(node, 'data-selected', '0');
    attr(node, 'aria-pressed', 'false');
  });
}

export function markSelectedRow(root, certUid) {
  const container = resolveEl(root) || document;
  clearSelectedRows(container);

  const uid = cssEscape(String(certUid || '').trim());
  if (!uid) return null;

  const node = container.querySelector(`[data-cert-uid="${uid}"]`);
  if (!node) return null;

  addClass(node, 'is-selected');
  attr(node, 'data-selected', '1');
  attr(node, 'aria-pressed', 'true');
  return node;
}

export function renderGlobalStatus(target, message, tone = 'info', meta = '') {
  const el = resolveEl(target);
  if (!el) return null;

  const safeTone = normalizeTone(tone);
  const metaHtml = meta ? `<div class="cert-status-meta">${escapeHtml(meta)}</div>` : '';

  html(el, [
    `<div class="cert-status-toast is-${safeTone}">`,
      `<div class="cert-status-message">${escapeHtml(message || '')}</div>`,
      metaHtml,
    `</div>`
  ].join(''));

  return el;
}

export function appendLog(target, message, tone = 'info', options = {}) {
  const el = resolveEl(target);
  if (!el) return null;

  const keep = Number(options.keep || 120);
  const safeTone = normalizeTone(tone);

  const row = create('div', {
    class: `log-row tone-${safeTone}`
  }, [
    create('div', {
      class: 'log-time',
      text: formatTime(options.time || Date.now())
    }),
    create('div', {
      class: 'log-msg',
      text: String(message || '')
    })
  ]);

  if (!row) return null;
  el.prepend(row);

  const rows = Array.from(el.children);
  if (rows.length > keep) {
    rows.slice(keep).forEach((node) => node.remove());
  }

  return row;
}

export function formatTime(value) {
  const date = value instanceof Date ? value : new Date(value);
  try {
    return date.toLocaleTimeString();
  } catch (_) {
    return date.toISOString();
  }
}

export function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function resolveEl(target) {
  if (!target) return null;
  if (typeof target === 'string') return byId(target);
  return target instanceof Element ? target : null;
}

function normalizeTone(tone) {
  const value = String(tone || '').trim().toLowerCase();
  if (['success', 'ok'].includes(value)) return 'ok';
  if (['warn', 'warning'].includes(value)) return 'warn';
  if (['error', 'danger', 'fail', 'failed'].includes(value)) return 'error';
  return 'info';
}

function toDatasetKey(name) {
  return String(name || '')
    .replace(/^data-/, '')
    .replace(/-([a-z])/g, (_, c) => c.toUpperCase());
}

function toDataName(name) {
  return String(name || '')
    .replace(/^data-/, '')
    .replace(/[A-Z]/g, (c) => `-${c.toLowerCase()}`);
}

function cssEscape(value) {
  if (typeof CSS !== 'undefined' && typeof CSS.escape === 'function') {
    return CSS.escape(value);
  }
  return String(value).replace(/"/g, '\\"');
}
