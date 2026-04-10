/**
 * HTTP helper (router-safe)
 */

export async function httpGetJson(url, opts = {}) {
  const finalUrl = buildUrl(url, opts.query || {});
  const controller = new AbortController();
  const timeout = opts.timeout_ms || 20000;

  const t = setTimeout(() => controller.abort(), timeout);

  try {
    const res = await fetch(finalUrl, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' },
      signal: controller.signal
    });

    const text = await res.text();
    let json = null;

    try { json = JSON.parse(text); } catch (_) {}

    if (!res.ok || !json) {
      throw new Error(json?.error || `HTTP_${res.status}`);
    }

    return { data: json };
  } finally {
    clearTimeout(t);
  }
}

function buildUrl(base, query) {
  const url = new URL(base, window.location.origin);
  Object.entries(query || {}).forEach(([k, v]) => {
    if (v !== undefined && v !== null && v !== '') {
      url.searchParams.set(k, v);
    }
  });
  return url.toString();
}
