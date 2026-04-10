/**
 * Router state container (single source of UI state)
 */

export function createState(init = {}) {
  return {
    active_bucket: init.active_bucket || '',
    active_stage: init.active_stage || '',
    selected_cert_uid: null,
    queue: {},
    detail: {},
    modal: { open: false, type: '', payload: {} },
    requests: {},
    ui: { status: '', tone: 'info', log: [] }
  };
}

export function getStateSnapshot(state) {
  return JSON.parse(JSON.stringify(state));
}

export function getActiveBucket(state) {
  return state.active_bucket;
}

export function setActiveBucket(state, bucket) {
  state.active_bucket = bucket;
}

export function getActiveStage(state) {
  return state.active_stage;
}

export function setActiveStage(state, stage) {
  state.active_stage = stage;
}

export function setMountedStage(state, stage) {
  state.mounted_stage = stage;
}

export function getSelectedCertUid(state) {
  return state.selected_cert_uid;
}

export function setSelectedCert(state, uid, detail = {}) {
  state.selected_cert_uid = uid;
  if (uid) state.detail[uid] = detail;
}

export function setQueueSummary(state, summary) {
  state.summary = summary;
}

export function setQueueCache(state, bucket, rows) {
  state.queue[bucket] = rows;
}

export function getQueueCache(state, bucket) {
  return state.queue[bucket] || [];
}

export function setDetailCache(state, uid, detail) {
  state.detail[uid] = detail;
}

export function getDetailCache(state, uid) {
  return { detail: state.detail[uid] || {} };
}

export function openModalState(state, type, payload = {}) {
  state.modal = { open: true, type, payload };
}

export function closeModalState(state) {
  state.modal = { open: false, type: '', payload: {} };
}

export function startRequest(state, key) {
  state.requests[key] = { loading: true };
}

export function finishRequest(state, key, ok, error = null) {
  state.requests[key] = { loading: false, ok, error };
}

export function setUiStatus(state, msg, tone = 'info') {
  state.ui.status = msg;
  state.ui.tone = tone;
}

export function appendUiLogState(state, msg, tone = 'info') {
  state.ui.log.unshift({
    time: Date.now(),
    msg,
    tone
  });
}
