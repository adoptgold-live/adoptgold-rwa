/**
 * /var/www/html/public/rwa/cert/shared/polling.js
 * Version: v2.0.0-20260406-v2-shared-polling-baseline
 *
 * MASTER LOCK — RWA Cert V2 shared polling helper
 * - Maintain previous cert module design layout and functions
 * - Do NOT change visible index layout
 * - Do NOT rewrite backend truth
 * - Canonical polling helper for:
 *   start / stop lifecycle
 *   single in-flight protection
 *   abort on stop
 *   stop conditions
 *   max ticks / duration safeguards
 *   stage-owned polling only
 * - No global singleton polling
 */

const DEFAULT_POLL_INTERVAL_MS = 5000;
const DEFAULT_POLL_TIMEOUT_MS = 15000;
const DEFAULT_MAX_TICKS = 120;
const DEFAULT_MAX_DURATION_MS = 10 * 60 * 1000;

function createPollingController(owner, config = {}) {
  const nextOwner = trimText(owner || '');
  if (!nextOwner) {
    throw new Error('POLLING_OWNER_REQUIRED');
  }

  const runtime = {
    owner: nextOwner,
    active: false,
    timer_id: 0,
    inflight: false,
    tick: 0,
    started_at: 0,
    last_tick_at: 0,
    last_result_at: 0,
    stop_reason: '',
    last_error: null,
    abort_controller: null,
    config: normalizeConfig(config),
    onStart: toFn(config.onStart),
    onTick: toFn(config.onTick),
    onResult: toFn(config.onResult),
    onError: toFn(config.onError),
    onStop: toFn(config.onStop),
    shouldStop: toFn(config.shouldStop)
  };

  return {
    owner: runtime.owner,

    start(meta = {}) {
      return startPolling(runtime, meta);
    },

    stop(reason = 'manual_stop', meta = {}) {
      return stopPolling(runtime, reason, meta);
    },

    restart(meta = {}) {
      stopPolling(runtime, 'restart', { silent: true });
      return startPolling(runtime, meta);
    },

    isActive() {
      return !!runtime.active;
    },

    isInflight() {
      return !!runtime.inflight;
    },

    getState() {
      return snapshot(runtime);
    },

    async runNow(meta = {}) {
      if (!runtime.active) {
        return startPolling(runtime, { ...meta, immediate_only: true });
      }
      return runTick(runtime, { ...meta, forced: true });
    }
  };
}

function startPolling(runtime, meta = {}) {
  if (runtime.active && meta.immediate_only !== true) {
    return snapshot(runtime);
  }

  clearTimer(runtime);
  abortInflight(runtime, 'restart');

  runtime.active = true;
  runtime.inflight = false;
  runtime.tick = 0;
  runtime.started_at = Date.now();
  runtime.last_tick_at = 0;
  runtime.last_result_at = 0;
  runtime.stop_reason = '';
  runtime.last_error = null;

  safeCall(runtime.onStart, snapshot(runtime), normalizeMeta(meta));

  if (meta.immediate_only === true) {
    queueMicrotask(() => {
      runTick(runtime, { ...meta, forced: true }).catch((error) => {
        console.error('[cert polling] immediate tick failed:', error);
      });
    });
    return snapshot(runtime);
  }

  queueMicrotask(() => {
    runTick(runtime, { ...meta, forced: true }).catch((error) => {
      console.error('[cert polling] first tick failed:', error);
    });
  });

  return snapshot(runtime);
}

function stopPolling(runtime, reason = 'stopped', meta = {}) {
  const nextReason = trimText(reason || 'stopped');

  clearTimer(runtime);
  abortInflight(runtime, nextReason);

  const wasActive = runtime.active;
  runtime.active = false;
  runtime.inflight = false;
  runtime.stop_reason = nextReason;

  if (meta.silent === true) {
    return snapshot(runtime);
  }

  if (wasActive || nextReason) {
    safeCall(runtime.onStop, snapshot(runtime), nextReason, normalizeMeta(meta));
  }

  return snapshot(runtime);
}

async function runTick(runtime, meta = {}) {
  if (!runtime.active) {
    return snapshot(runtime);
  }

  if (runtime.inflight) {
    return snapshot(runtime);
  }

  const precheck = evaluateStopConditions(runtime, meta);
  if (precheck.should_stop) {
    stopPolling(runtime, precheck.reason, {
      ...meta,
      stop_meta: precheck.meta || {}
    });
    return snapshot(runtime);
  }

  runtime.inflight = true;
  runtime.tick += 1;
  runtime.last_tick_at = Date.now();
  runtime.abort_controller = createAbortController();

  const tickState = snapshot(runtime);
  const tickMeta = normalizeMeta(meta);

  try {
    const result = await safeCallAsync(
      runtime.onTick,
      tickState,
      runtime.abort_controller.signal,
      tickMeta
    );

    runtime.last_result_at = Date.now();
    runtime.last_error = null;

    safeCall(runtime.onResult, result, snapshot(runtime), tickMeta);

    const postcheck = evaluateStopConditions(runtime, {
      ...meta,
      tick_result: result
    });

    if (postcheck.should_stop) {
      stopPolling(runtime, postcheck.reason, {
        ...meta,
        stop_meta: postcheck.meta || {}
      });
      return snapshot(runtime);
    }

    scheduleNext(runtime);
    return snapshot(runtime);
  } catch (error) {
    const aborted = isAbortError(error);
    runtime.last_error = normalizeError(error);
    runtime.last_result_at = Date.now();

    if (aborted) {
      runtime.inflight = false;
      runtime.abort_controller = null;

      if (!runtime.active) {
        return snapshot(runtime);
      }

      stopPolling(runtime, 'aborted', {
        ...meta,
        error: runtime.last_error
      });
      return snapshot(runtime);
    }

    safeCall(runtime.onError, runtime.last_error, snapshot(runtime), tickMeta);

    const stopOnError = runtime.config.stop_on_error === true;
    const errorReason = trimText(runtime.config.error_stop_reason || 'tick_error');

    if (stopOnError) {
      stopPolling(runtime, errorReason, {
        ...meta,
        error: runtime.last_error
      });
      return snapshot(runtime);
    }

    runtime.inflight = false;
    runtime.abort_controller = null;

    if (runtime.active) {
      scheduleNext(runtime);
    }

    return snapshot(runtime);
  } finally {
    runtime.inflight = false;
    runtime.abort_controller = null;
  }
}

function scheduleNext(runtime) {
  clearTimer(runtime);

  if (!runtime.active) return;

  const intervalMs = Number(runtime.config.interval_ms || DEFAULT_POLL_INTERVAL_MS);
  runtime.timer_id = window.setTimeout(() => {
    runTick(runtime, {}).catch((error) => {
      console.error('[cert polling] scheduled tick failed:', error);
    });
  }, Math.max(250, intervalMs));
}

function evaluateStopConditions(runtime, meta = {}) {
  const now = Date.now();
  const elapsedMs = runtime.started_at > 0 ? now - runtime.started_at : 0;
  const maxTicks = Number(runtime.config.max_ticks || DEFAULT_MAX_TICKS);
  const maxDurationMs = Number(runtime.config.max_duration_ms || DEFAULT_MAX_DURATION_MS);

  if (Number.isFinite(maxTicks) && maxTicks > 0 && runtime.tick >= maxTicks && meta.forced !== true) {
    return {
      should_stop: true,
      reason: 'max_ticks_reached',
      meta: { max_ticks: maxTicks }
    };
  }

  if (
    Number.isFinite(maxDurationMs) &&
    maxDurationMs > 0 &&
    elapsedMs >= maxDurationMs &&
    meta.forced !== true
  ) {
    return {
      should_stop: true,
      reason: 'max_duration_reached',
      meta: { max_duration_ms: maxDurationMs, elapsed_ms: elapsedMs }
    };
  }

  if (typeof runtime.shouldStop === 'function') {
    try {
      const outcome = runtime.shouldStop(snapshot(runtime), normalizeMeta(meta));

      if (outcome === true) {
        return {
          should_stop: true,
          reason: 'custom_stop'
        };
      }

      if (isPlainObject(outcome) && outcome.stop === true) {
        return {
          should_stop: true,
          reason: trimText(outcome.reason || 'custom_stop'),
          meta: isPlainObject(outcome.meta) ? outcome.meta : {}
        };
      }
    } catch (error) {
      return {
        should_stop: true,
        reason: 'should_stop_error',
        meta: { error: normalizeError(error) }
      };
    }
  }

  return { should_stop: false };
}

function normalizeConfig(config = {}) {
  return {
    interval_ms: toPositiveNumber(config.interval_ms, DEFAULT_POLL_INTERVAL_MS),
    timeout_ms: toPositiveNumber(config.timeout_ms, DEFAULT_POLL_TIMEOUT_MS),
    max_ticks: toPositiveNumber(config.max_ticks, DEFAULT_MAX_TICKS),
    max_duration_ms: toPositiveNumber(config.max_duration_ms, DEFAULT_MAX_DURATION_MS),
    stop_on_error: config.stop_on_error === true,
    error_stop_reason: trimText(config.error_stop_reason || 'tick_error')
  };
}

function createAbortController() {
  const ctl = new AbortController();
  return ctl;
}

function abortInflight(runtime, reason = 'aborted') {
  if (!runtime.abort_controller) return false;

  try {
    runtime.abort_controller.abort(reason);
  } catch (_) {
    try {
      runtime.abort_controller.abort();
    } catch (_) {
      return false;
    }
  }
  return true;
}

function clearTimer(runtime) {
  if (runtime.timer_id) {
    window.clearTimeout(runtime.timer_id);
    runtime.timer_id = 0;
  }
}

function snapshot(runtime) {
  return {
    owner: runtime.owner,
    active: !!runtime.active,
    inflight: !!runtime.inflight,
    tick: Number(runtime.tick || 0),
    started_at: Number(runtime.started_at || 0),
    last_tick_at: Number(runtime.last_tick_at || 0),
    last_result_at: Number(runtime.last_result_at || 0),
    stop_reason: trimText(runtime.stop_reason || ''),
    last_error: runtime.last_error ? { ...runtime.last_error } : null,
    config: { ...runtime.config }
  };
}

function normalizeError(error) {
  if (isPlainObject(error)) {
    return {
      code: trimText(error.code || error.error || ''),
      message: trimText(error.message || error.detail || 'Unknown polling error')
    };
  }

  if (error instanceof Error) {
    return {
      code: trimText(error.code || error.name || ''),
      message: trimText(error.message || 'Unknown polling error')
    };
  }

  return {
    code: '',
    message: trimText(error || 'Unknown polling error')
  };
}

function isAbortError(error) {
  if (!error) return false;
  const name = trimText(error.name || '');
  const code = trimText(error.code || '');
  const message = trimText(error.message || '').toLowerCase();

  return (
    name === 'AbortError' ||
    code === 'AbortError' ||
    message.includes('aborted') ||
    message.includes('abort')
  );
}

function normalizeMeta(meta) {
  return isPlainObject(meta) ? { ...meta } : {};
}

function safeCall(fn, ...args) {
  if (typeof fn !== 'function') return undefined;
  return fn(...args);
}

async function safeCallAsync(fn, ...args) {
  if (typeof fn !== 'function') {
    throw new Error('POLLING_TICK_HANDLER_REQUIRED');
  }
  return await fn(...args);
}

function toFn(value) {
  return typeof value === 'function' ? value : null;
}

function toPositiveNumber(value, fallback) {
  const n = Number(value);
  if (Number.isFinite(n) && n > 0) return n;
  return Number(fallback);
}

function trimText(value) {
  return String(value ?? '').trim();
}

function isPlainObject(value) {
  return !!value && Object.prototype.toString.call(value) === '[object Object]';
}

export {
  DEFAULT_POLL_INTERVAL_MS,
  DEFAULT_POLL_TIMEOUT_MS,
  DEFAULT_MAX_TICKS,
  DEFAULT_MAX_DURATION_MS,
  createPollingController
};
