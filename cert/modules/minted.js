/**
 * /var/www/html/public/rwa/cert/modules/minted.js
 * Version: v2.0.0-20260406-v2-module-minted
 *
 * MASTER LOCK
 * - minted stage is read-only presentation
 * - verify-status.php read_model remains authority
 * - no backend mutation here
 * - no visible layout drift
 */

import { byId, html } from '../shared/dom.js';
import { emitCertEvent } from '../shared/events.js';

let mountedRoot = null;
let mountedCertUid = '';

export function mountMintedStage(ctx) {
  const rootId = ctx?.boot?.stages?.minted;
  const root = byId(rootId);
  if (!root) return;

  const certUid = String(ctx?.state?.selected_cert_uid || '').trim();
  const detail = certUid ? (ctx?.state?.detail?.[certUid] || {}) : {};

  mountedRoot = root;
  mountedCertUid = certUid;

  render(root, certUid, detail);
  bind(certUid, detail);
}

export function unmountMintedStage() {
  mountedRoot = null;
  mountedCertUid = '';
}

function render(root, certUid, detail) {
  const verifyUrl = String(detail?.verify_url || '').trim();
  const getgemsUrl = String(detail?.getgems_url || '').trim();
  const itemAddr = String(detail?.nft_item_address || '').trim();
  const itemIndex = String(detail?.nft_item_index || '').trim();
  const mintedAt = String(detail?.minted_at || '').trim();
  const collectionAddress = String(detail?.collection_address || '').trim();

  html(root, `
    <section class="card-premium stage-card">
      <div class="section-head compact">
        <div>
          <div class="section-kicker">Minted</div>
          <h3 class="section-title">Issued / Minted NFT</h3>
          <p class="section-sub">Final read-only stage for minted truth and outbound links.</p>
        </div>
      </div>

      <div class="cert-modal-grid">
        <div class="cert-modal-card">
          <div class="mini-k">Cert UID</div>
          <div class="mini-v mono">${escapeHtml(certUid || '—')}</div>
        </div>
        <div class="cert-modal-card">
          <div class="mini-k">Queue Bucket</div>
          <div class="mini-v">${escapeHtml(detail?.queue_bucket || 'issued')}</div>
        </div>
        <div class="cert-modal-card">
          <div class="mini-k">NFT Item Address</div>
          <div class="mini-v mono">${escapeHtml(itemAddr || '—')}</div>
        </div>
        <div class="cert-modal-card">
          <div class="mini-k">NFT Item Index</div>
          <div class="mini-v mono">${escapeHtml(itemIndex || '—')}</div>
        </div>
        <div class="cert-modal-card">
          <div class="mini-k">Minted At</div>
          <div class="mini-v">${escapeHtml(mintedAt || '—')}</div>
        </div>
        <div class="cert-modal-card">
          <div class="mini-k">Collection Address</div>
          <div class="mini-v mono">${escapeHtml(collectionAddress || '—')}</div>
        </div>
      </div>

      <div class="stage-actions" style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-top:16px;">
        <button type="button" id="btnMintedRefresh" class="gold-btn secondary">Refresh</button>
        <button type="button" id="btnMintedOpenVerify" class="gold-btn"${verifyUrl ? '' : ' disabled'}>Open Verify</button>
        <button type="button" id="btnMintedOpenGetgems" class="gold-btn"${getgemsUrl ? '' : ' disabled'}>Open Getgems</button>
      </div>

      <div class="cert-modal-grid" style="margin-top:16px;">
        <div class="cert-modal-card">
          <div class="mini-k">Verify URL</div>
          <div class="mini-v mono">${escapeHtml(verifyUrl || '—')}</div>
        </div>
        <div class="cert-modal-card">
          <div class="mini-k">Getgems URL</div>
          <div class="mini-v mono">${escapeHtml(getgemsUrl || '—')}</div>
        </div>
      </div>
    </section>
  `);
}

function bind(certUid, detail) {
  byId('btnMintedRefresh')?.addEventListener('click', () => {
    emitCertEvent('cert:detail-refresh', {
      cert_uid: certUid
    });

    emitCertEvent('cert:queue-refresh', {
      preserve_selection: true
    });

    emitCertEvent('cert:status', {
      message: `Minted detail refreshed: ${certUid}`,
      tone: 'info'
    });
  });

  byId('btnMintedOpenVerify')?.addEventListener('click', () => {
    const url = String(detail?.verify_url || '').trim();
    if (!url) return;
    window.open(url, '_blank', 'noopener');
  });

  byId('btnMintedOpenGetgems')?.addEventListener('click', () => {
    const url = String(detail?.getgems_url || '').trim();
    if (!url) return;
    window.open(url, '_blank', 'noopener');
  });
}

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}
