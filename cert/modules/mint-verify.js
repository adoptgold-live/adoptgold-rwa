/**
 * /var/www/html/public/rwa/cert/modules/mint-verify.js
 * Version: v2.0.0-20260406-v2-module-mint-verify
 *
 * MASTER LOCK
 * - mint verify stage is router-driven
 * - verify-status.php remains read authority
 * - mint-verify.php is action endpoint only
 * - no visible layout drift
 */

import { byId, html, text } from '../shared/dom.js';
import { emitCertEvent } from '../shared/events.js';
import { httpGetJson } from '../shared/http.js';
import { getEndpoint } from '../shared/config.js';

let mountedRoot = null;
let mountedCertUid = '';
let pollTimer = null;
let currentBoot = null;

export function mountMintVerifyStage(ctx) {
  const rootId = ctx?.boot?.stages?.['mint-verify'];
  const root = byId(rootId);
  if (!root) return;

  currentBoot = ctx?.boot || null;
  const certUid = String(ctx?.state?.selected_cert_uid || '').trim();
  const detail = certUid ? (ctx?.state?.detail?.[certUid] || {}) : {};

  mountedRoot = root;
  mountedCertUid = certUid;

  render(root, certUid, detail);
  bind(root, certUid);
}

export function unmountMintVerifyStage() {
  stopPolling();
  mountedRoot = null;
  mountedCertUid = '';
  currentBoot = null;
}

function render(root, certUid, detail) {
  const minted = isMinted(detail);
  const itemAddr = escapeHtml(detail?.nft_item_address || '—');
  const itemIndex = escapeHtml(detail?.nft_item_index || '—');
  const verifyUrl = escapeHtml(detail?.verify_url || '');
  const getgemsUrl = escapeHtml(detail?.getgems_url || '');
  const queueBucket = escapeHtml(detail?.queue_bucket || 'minting_process');
  const mintedAt = escapeHtml(detail?.minted_at || '—');

  html(root, `
    <section class="card-premium stage-card">
      <div class="section-head compact">
        <div>
          <div class="section-kicker">Mint Verify</div>
          <h3 class="section-title">Verify Mint On-chain</h3>
          <p class="section-sub">Refresh and monitor minted truth from verify-status.php read_model.</p>
        </div>
      </div>

      <div class="cert-modal-grid">
        <div class="cert-modal-card">
          <div class="mini-k">Cert UID</div>
          <div class="mini-v mono">${escapeHtml(certUid || '—')}</div>
        </div>
        <div class="cert-modal-card">
          <div class="mini-k">Queue Bucket</div>
          <div class="mini-v">${queueBucket}</div>
        </div>
        <div class="cert-modal-card">
          <div class="mini-k">Minted</div>
          <div class="mini-v" id="mintVerifyMintedText">${minted ? 'YES' : 'NO'}</div>
        </div>
        <div class="cert-modal-card">
          <div class="mini-k">Minted At</div>
          <div class="mini-v">${mintedAt}</div>
        </div>
        <div class="cert-modal-card">
          <div class="mini-k">NFT Item Address</div>
          <div class="mini-v mono">${itemAddr}</div>
        </div>
        <div class="cert-modal-card">
          <div class="mini-k">NFT Item Index</div>
          <div class="mini-v mono">${itemIndex}</div>
        </div>
      </div>

      <div class="stage-actions" style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:16px;">
        <button type="button" id="btnMintVerifyRefresh" class="gold-btn">Refresh Verify</button>
        <button type="button" id="btnMintVerifyAuto" class="gold-btn secondary">Auto Verify 5s</button>
        <button type="button" id="btnMintVerifyStop" class="gold-btn secondary">Stop</button>
        <button type="button" id="btnMintVerifyIssued" class="gold-btn secondary"${minted ? '' : ' disabled'}>Go Issued</button>
      </div>

      <div class="cert-modal-grid" style="margin-top:16px;">
        <div class="cert-modal-card">
          <div class="mini-k">Verify Page</div>
          <div class="mini-v mono" id="mintVerifyVerifyUrl">${verifyUrl || '—'}</div>
        </div>
        <div class="cert-modal-card">
          <div class="mini-k">Getgems URL</div>
          <div class="mini-v mono" id="mintVerifyGetgemsUrl">${getgemsUrl || '—'}</div>
        </div>
      </div>
    </section>
  `);
}

function bind(root, certUid) {
  byId('btnMintVerifyRefresh')?.addEventListener('click', async () => {
    await refresh(certUid, false);
  });

  byId('btnMintVerifyAuto')?.addEventListener('click', async () => {
    stopPolling();
    emitCertEvent('cert:status', {
      message: `Mint auto verify started: ${certUid}`,
      tone: 'info'
    });

    pollTimer = window.setInterval(async () => {
      await refresh(certUid, true);
    }, 5000);

    await refresh(certUid, true);
  });

  byId('btnMintVerifyStop')?.addEventListener('click', () => {
    stopPolling();
    emitCertEvent('cert:status', {
      message: `Mint auto verify stopped: ${certUid}`,
      tone: 'warn'
    });
  });

  byId('btnMintVerifyIssued')?.addEventListener('click', () => {
    emitCertEvent('cert:stage-request', {
      stage: 'minted',
      cert_uid: certUid,
      source: 'mint-verify-issued'
    });
  });
}

async function refresh(certUid, silent) {
  if (!certUid) return;

  try {
    const endpoint = getEndpoint(currentBoot, 'verifyStatus');
    const res = await httpGetJson(endpoint, {
      query: { cert_uid: certUid },
      expect_envelope: false,
      timeout_ms: 20000,
      context: { request_key: `mint-verify:${certUid}` }
    });

    const json = res?.data || res;
    const read = json?.read_model && typeof json.read_model === 'object' ? json.read_model : {};
    const minted = isMinted(read);

    text('mintVerifyMintedText', minted ? 'YES' : 'NO');
    text('mintVerifyVerifyUrl', String(read.verify_url || '—'));
    text('mintVerifyGetgemsUrl', String(read.getgems_url || '—'));

    const btnIssued = byId('btnMintVerifyIssued');
    if (btnIssued) btnIssued.disabled = !minted;

    emitCertEvent('cert:detail-refresh', {
      cert_uid: certUid
    });

    if (!silent) {
      emitCertEvent('cert:status', {
        message: minted
          ? `Mint verified: ${certUid}`
          : `Mint not detected yet: ${certUid}`,
        tone: minted ? 'ok' : 'info'
      });
    }

    if (minted) {
      stopPolling();

      emitCertEvent('cert:server-transition', {
        cert_uid: certUid,
        to_stage: 'minted'
      });
    }
  } catch (error) {
    if (!silent) {
      emitCertEvent('cert:status', {
        message: error?.message || 'Mint verify refresh failed',
        tone: 'error'
      });
    }
  }
}

function stopPolling() {
  if (pollTimer) {
    clearInterval(pollTimer);
    pollTimer = null;
  }
}

function isMinted(detail) {
  return detail?.nft_minted === true ||
    detail?.nft_minted === 1 ||
    String(detail?.status || '').trim().toLowerCase() === 'minted' ||
    String(detail?.queue_bucket || '').trim().toLowerCase() === 'issued' ||
    String(detail?.nft_item_address || '').trim() !== '';
}

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}
