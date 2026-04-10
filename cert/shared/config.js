/**
 * /var/www/html/public/rwa/cert/shared/config.js
 * Version: v2.0.0-20260406-v2-router-config-contract
 *
 * MASTER LOCK
 * - shared config authority for V2 router/modules
 * - no visible layout logic here
 * - no backend truth rewrite
 * - boot payload is primary source
 * - safe fallback only when boot payload / hidden inputs missing
 */

export const CERT_BUCKETS = Object.freeze({
  ISSUANCE_FACTORY: 'issuance_factory',
  MINT_READY_QUEUE: 'mint_ready_queue',
  MINTING_PROCESS: 'minting_process',
  ISSUED: 'issued',
  BLOCKED: 'blocked'
});

export const CERT_STAGES = Object.freeze({
  ISSUE: 'issue',
  PAYMENT: 'payment',
  REPAIR: 'repair',
  VERIFY: 'verify',
  MINT_INIT: 'mint-init',
  MINT_VERIFY: 'mint-verify',
  MINTED: 'minted'
});

const DEFAULT_BOOT = Object.freeze({
  version: 'v2.0.0-20260406-v2-router-config-contract',
  identity: {
    wallet: '',
    owner_user_id: '',
    nickname: 'RWA User'
  },
  activeBucket: CERT_BUCKETS.ISSUANCE_FACTORY,
  activeStage: CERT_STAGES.ISSUE,
  selectedCertUid: null,

  roots: {
    app: 'cert-app',
    shell: 'cert-shell-root',
    header: 'cert-header-root',
    queueSummary: 'cert-queue-summary-root',
    workspace: 'cert-workspace-root',
    queueColumn: 'cert-queue-column-root',
    queueTabs: 'cert-queue-tabs-root',
    queuePanels: 'cert-queue-panels-root',
    stageColumn: 'cert-stage-column-root',
    stageContext: 'cert-stage-context-root',
    stageRoot: 'cert-stage-root',
    globalStatus: 'cert-global-status-root',
    globalStatusBar: 'cert-global-status-bar',
    actionStatus: 'cert-action-status-root',
    actionStatusLog: 'cert-action-status-log',
    modalRoot: 'cert-modal-root',
    selectedContext: 'cert-selected-context-root',
    factoryConsole: 'factoryConsoleLog',
    consoleLog: 'factoryConsoleLog'
  },

  selectedContext: {
    cert_uid: 'cert-selected-cert-uid',
    cert_code: 'cert-selected-cert-code',
    bucket: 'cert-selected-cert-bucket',
    stage: 'cert-selected-cert-stage'
  },

  buckets: {
    [CERT_BUCKETS.ISSUANCE_FACTORY]: {
      key: CERT_BUCKETS.ISSUANCE_FACTORY,
      panel: 'cert-panel-issuance-factory',
      list: 'cert-list-issuance-factory',
      empty: 'cert-empty-issuance-factory',
      summary: 'cert-summary-card-issuance-factory',
      tab: 'cert-tab-issuance-factory',
      count: 'cert-panel-count-issuance-factory'
    },
    [CERT_BUCKETS.MINT_READY_QUEUE]: {
      key: CERT_BUCKETS.MINT_READY_QUEUE,
      panel: 'cert-panel-mint-ready',
      list: 'cert-list-mint-ready',
      empty: 'cert-empty-mint-ready',
      summary: 'cert-summary-card-mint-ready',
      tab: 'cert-tab-mint-ready',
      count: 'cert-panel-count-mint-ready'
    },
    [CERT_BUCKETS.MINTING_PROCESS]: {
      key: CERT_BUCKETS.MINTING_PROCESS,
      panel: 'cert-panel-minting-process',
      list: 'cert-list-minting-process',
      empty: 'cert-empty-minting-process',
      summary: 'cert-summary-card-minting-process',
      tab: 'cert-tab-minting-process',
      count: 'cert-panel-count-minting-process'
    },
    [CERT_BUCKETS.ISSUED]: {
      key: CERT_BUCKETS.ISSUED,
      panel: 'cert-panel-issued',
      list: 'cert-list-issued',
      empty: 'cert-empty-issued',
      summary: 'cert-summary-card-issued',
      tab: 'cert-tab-issued',
      count: 'cert-panel-count-issued'
    },
    [CERT_BUCKETS.BLOCKED]: {
      key: CERT_BUCKETS.BLOCKED,
      panel: 'cert-panel-blocked',
      list: 'cert-list-blocked',
      empty: 'cert-empty-blocked',
      summary: 'cert-summary-card-blocked',
      tab: 'cert-tab-blocked',
      count: 'cert-panel-count-blocked'
    }
  },

  stages: {
    [CERT_STAGES.ISSUE]: 'cert-stage-issue',
    [CERT_STAGES.PAYMENT]: 'cert-stage-payment',
    [CERT_STAGES.REPAIR]: 'cert-stage-repair',
    [CERT_STAGES.VERIFY]: 'cert-stage-verify',
    [CERT_STAGES.MINT_INIT]: 'cert-stage-mint-init',
    [CERT_STAGES.MINT_VERIFY]: 'cert-stage-mint-verify',
    [CERT_STAGES.MINTED]: 'cert-stage-minted'
  },

  endpoints: {
    issue: '/rwa/cert/api/issue.php',
    confirmPayment: '/rwa/cert/api/confirm-payment.php',
    repairNft: '/rwa/cert/api/repair-nft.php',
    verifyStatus: '/rwa/cert/api/verify-status.php',
    mintInit: '/rwa/cert/api/mint-init.php',
    mintVerify: '/rwa/cert/api/mint-verify.php',
    queueSummary: '/rwa/cert/api/queue-summary.php',
    certDetail: '/rwa/cert/api/cert-detail.php',
    balanceLocal: '/rwa/cert/api/balance-local.php',
    verifyTool: '/rwa/cert/verify.php',
    storageOverview: '/rwa/api/storage/overview.php',
    tonManifestUrl: 'https://adoptgold.app/tonconnect-manifest.json'
  },

  csrf: {
    issue: '',
    confirmPayment: '',
    repairNft: '',
    mintInit: '',
    mintVerify: ''
  },

  polling: {
    default: {
      interval_ms: 5000,
      timeout_ms: 20000
    },
    queue: {
      interval_ms: 8000,
      timeout_ms: 20000
    },
    payment: {
      interval_ms: 5000,
      timeout_ms: 20000
    },
    mint: {
      interval_ms: 5000,
      timeout_ms: 25000
    }
  }
});

let cachedBoot = null;

export function resolveBootConfig() {
  if (cachedBoot) return cachedBoot;

  const bootFromWindow = parseBootFromWindow();
  const bootFromTextarea = parseBootFromTextarea();
  const merged = deepMerge(clone(DEFAULT_BOOT), bootFromWindow, bootFromTextarea);

  merged.identity = {
    ...DEFAULT_BOOT.identity,
    ...(merged.identity || {})
  };

  merged.roots = {
    ...clone(DEFAULT_BOOT.roots),
    ...(merged.roots || {})
  };

  merged.selectedContext = {
    ...clone(DEFAULT_BOOT.selectedContext),
    ...(merged.selectedContext || {})
  };

  merged.buckets = normalizeBuckets(merged.buckets || {});
  merged.stages = normalizeStages(merged.stages || {});
  merged.endpoints = normalizeEndpoints(merged.endpoints || {});
  merged.csrf = normalizeCsrf(merged.csrf || {});
  merged.polling = normalizePolling(merged.polling || {});

  merged.activeBucket = normalizeBucket(
    merged.activeBucket || merged.active_bucket || DEFAULT_BOOT.activeBucket
  );
  merged.activeStage = normalizeStage(
    merged.activeStage || merged.active_stage || DEFAULT_BOOT.activeStage
  );
  merged.selectedCertUid = normalizeString(
    merged.selectedCertUid || merged.selected_cert_uid || null
  ) || null;

  cachedBoot = merged;
  return cachedBoot;
}

export function getEndpoint(boot, key) {
  const cfg = boot || resolveBootConfig();
  const value = cfg?.endpoints?.[key];

  if (typeof value === 'string' && value.trim() !== '') {
    return value.trim();
  }

  return DEFAULT_BOOT.endpoints[key] || '';
}

export function getPollingConfig(boot, key = 'default') {
  const cfg = boot || resolveBootConfig();
  const polling = cfg?.polling?.[key] || cfg?.polling?.default || DEFAULT_BOOT.polling.default;

  return {
    interval_ms: toPositiveInt(polling?.interval_ms, DEFAULT_BOOT.polling.default.interval_ms),
    timeout_ms: toPositiveInt(polling?.timeout_ms, DEFAULT_BOOT.polling.default.timeout_ms)
  };
}

function parseBootFromWindow() {
  if (typeof window === 'undefined') return {};
  if (!window.CERT_BOOT || typeof window.CERT_BOOT !== 'object') return {};
  return clone(window.CERT_BOOT);
}

function parseBootFromTextarea() {
  if (typeof document === 'undefined') return {};
  const node = document.getElementById('certBootPayload');
  if (!node) return {};

  const raw = String(
    node.value ??
    node.textContent ??
    ''
  ).trim();

  if (!raw) return {};

  try {
    const parsed = JSON.parse(raw);
    return parsed && typeof parsed === 'object' ? parsed : {};
  } catch (_) {
    return {};
  }
}

function normalizeBuckets(input) {
  const out = clone(DEFAULT_BOOT.buckets);

  Object.keys(out).forEach((key) => {
    out[key] = {
      ...out[key],
      ...(input[key] && typeof input[key] === 'object' ? input[key] : {}),
      key
    };
  });

  return out;
}

function normalizeStages(input) {
  const out = {
    ...clone(DEFAULT_BOOT.stages),
    ...(input && typeof input === 'object' ? input : {})
  };

  Object.values(CERT_STAGES).forEach((stage) => {
    if (!normalizeString(out[stage])) {
      out[stage] = DEFAULT_BOOT.stages[stage];
    }
  });

  return out;
}

function normalizeEndpoints(input) {
  const out = {
    ...clone(DEFAULT_BOOT.endpoints),
    ...(input && typeof input === 'object' ? input : {})
  };

  const domFallbacks = {
    issue: 'endpointIssue',
    confirmPayment: 'endpointConfirmPayment',
    repairNft: 'endpointRepairNft',
    verifyStatus: 'endpointVerifyStatus',
    mintInit: 'endpointMintInit',
    mintVerify: 'endpointMintVerify',
    queueSummary: 'endpointQueueSummary',
    certDetail: 'endpointCertDetail',
    balanceLocal: 'endpointBalanceLocal',
    verifyTool: 'endpointVerifyTool',
    storageOverview: 'endpointStorageOverview',
    tonManifestUrl: 'tonManifestUrl'
  };

  Object.entries(domFallbacks).forEach(([key, id]) => {
    const domValue = getInputValue(id);
    if (domValue) out[key] = domValue;
  });

  return out;
}

function normalizeCsrf(input) {
  const out = {
    ...clone(DEFAULT_BOOT.csrf),
    ...(input && typeof input === 'object' ? input : {})
  };

  const domFallbacks = {
    issue: 'csrfIssue',
    confirmPayment: 'csrfConfirmPayment',
    repairNft: 'csrfRepairNft',
    mintInit: 'csrfMintInit',
    mintVerify: 'csrfMintVerify'
  };

  Object.entries(domFallbacks).forEach(([key, id]) => {
    const domValue = getInputValue(id);
    if (domValue) out[key] = domValue;
  });

  return out;
}

function normalizePolling(input) {
  const base = clone(DEFAULT_BOOT.polling);
  const merged = deepMerge(base, input && typeof input === 'object' ? input : {});

  Object.keys(base).forEach((key) => {
    merged[key] = {
      interval_ms: toPositiveInt(merged[key]?.interval_ms, base[key].interval_ms),
      timeout_ms: toPositiveInt(merged[key]?.timeout_ms, base[key].timeout_ms)
    };
  });

  return merged;
}

function getInputValue(id) {
  if (typeof document === 'undefined') return '';
  const el = document.getElementById(id);
  if (!el) return '';
  return normalizeString(
    'value' in el ? el.value : el.textContent
  );
}

function normalizeBucket(value) {
  const v = normalizeString(value);
  return Object.values(CERT_BUCKETS).includes(v) ? v : DEFAULT_BOOT.activeBucket;
}

function normalizeStage(value) {
  const v = normalizeString(value);
  return Object.values(CERT_STAGES).includes(v) ? v : DEFAULT_BOOT.activeStage;
}

function normalizeString(value) {
  return typeof value === 'string' ? value.trim() : '';
}

function toPositiveInt(value, fallback) {
  const n = Number(value);
  return Number.isFinite(n) && n > 0 ? Math.floor(n) : fallback;
}

function clone(value) {
  return JSON.parse(JSON.stringify(value));
}

function deepMerge(target, ...sources) {
  const out = target && typeof target === 'object' ? target : {};

  sources.forEach((src) => {
    if (!src || typeof src !== 'object') return;

    Object.keys(src).forEach((key) => {
      const incoming = src[key];
      const current = out[key];

      if (Array.isArray(incoming)) {
        out[key] = incoming.slice();
        return;
      }

      if (incoming && typeof incoming === 'object') {
        out[key] = deepMerge(
          current && typeof current === 'object' && !Array.isArray(current) ? current : {},
          incoming
        );
        return;
      }

      out[key] = incoming;
    });
  });

  return out;
}
