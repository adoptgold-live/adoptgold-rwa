/**
 * /var/www/html/public/rwa/cert/shared/guards.js
 * Version: v2.0.0-20260406-v2-router-guards-contract
 *
 * MASTER LOCK
 * - shared stage-entry guards for V2 router/modules
 * - verify-status read_model / normalized detail is authority
 * - no backend rewrite here
 */

export function canEnterStage(stage, ctx = {}) {
  const nextStage = String(stage || '').trim();
  const snapshot = ctx?.state || {};
  const detail = normalizeDetail(ctx?.detail || {});
  const selectedCertUid = String(
    detail.cert_uid ||
    snapshot?.selected_cert_uid ||
    ''
  ).trim();

  if (!nextStage) {
    return deny('STAGE_REQUIRED', 'Stage is required.');
  }

  if (nextStage === 'issue') {
    return allow();
  }

  if (!selectedCertUid) {
    return deny('CERT_UID_REQUIRED', 'A cert selection is required.');
  }

  if (nextStage === 'payment') {
    return allow();
  }

  if (nextStage === 'repair') {
    if (!detail.payment_ready) {
      return deny('PAYMENT_NOT_READY', 'Payment must be ready first.');
    }
    return allow();
  }

  if (nextStage === 'verify') {
    if (!detail.payment_ready) {
      return deny('PAYMENT_NOT_READY', 'Payment must be ready first.');
    }
    return allow();
  }

  if (nextStage === 'mint-init') {
    if (!detail.payment_ready) {
      return deny('PAYMENT_NOT_READY', 'Payment must be ready first.');
    }
    if (!detail.artifact_ready && !detail.nft_healthy) {
      return deny('ARTIFACT_NOT_READY', 'Artifact is not ready yet.');
    }
    if (detail.nft_minted) {
      return deny('ALREADY_MINTED', 'NFT is already minted.');
    }
    return allow();
  }

  if (nextStage === 'mint-verify') {
    if (detail.nft_minted) {
      return allow();
    }
    if (!detail.payment_ready) {
      return deny('PAYMENT_NOT_READY', 'Payment must be ready first.');
    }
    if (!detail.artifact_ready && !detail.nft_healthy) {
      return deny('ARTIFACT_NOT_READY', 'Artifact is not ready yet.');
    }
    return allow();
  }

  if (nextStage === 'minted') {
    if (!detail.nft_minted) {
      return deny('NOT_MINTED', 'NFT is not minted yet.');
    }
    return allow();
  }

  return allow();
}

export function deriveStageFromDetail(detailInput = {}) {
  const detail = normalizeDetail(detailInput);

  if (detail.nft_minted || detail.queue_bucket === 'issued') {
    return 'minted';
  }

  if (detail.queue_bucket === 'minting_process') {
    return 'mint-verify';
  }

  if (detail.queue_bucket === 'mint_ready_queue') {
    return 'mint-init';
  }

  if (detail.payment_ready) {
    return 'payment';
  }

  return 'issue';
}

function normalizeDetail(input) {
  const read = isObject(input?.read_model) ? input.read_model : input;

  return {
    cert_uid: str(read?.cert_uid || input?.cert_uid || ''),
    queue_bucket: str(read?.queue_bucket || input?.queue_bucket || '').toLowerCase(),
    status: str(read?.status || input?.status || '').toLowerCase(),
    payment_ready: bool(read?.payment_ready ?? input?.payment_ready),
    artifact_ready: bool(read?.artifact_ready ?? input?.artifact_ready),
    nft_healthy: bool(read?.nft_healthy ?? input?.nft_healthy),
    nft_minted: bool(read?.nft_minted ?? input?.nft_minted) ||
      str(read?.status || input?.status || '').toLowerCase() === 'minted'
  };
}

function allow() {
  return {
    ok: true,
    code: '',
    reason: ''
  };
}

function deny(code, reason) {
  return {
    ok: false,
    code: String(code || 'GUARD_BLOCKED'),
    reason: String(reason || 'Guard blocked.')
  };
}

function bool(value) {
  if (value === true || value === 1) return true;
  if (typeof value === 'string') {
    const v = value.trim().toLowerCase();
    return ['1', 'true', 'yes', 'y', 'on'].includes(v);
  }
  return false;
}

function str(value) {
  return String(value || '').trim();
}

function isObject(value) {
  return !!value && Object.prototype.toString.call(value) === '[object Object]';
}
