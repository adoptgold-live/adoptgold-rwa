/**
 * MINT INIT STAGE MODULE
 * - prepare mint payload
 * - no wallet execution here
 */

import { byId, html } from '../shared/dom.js';
import { emitCertEvent } from '../shared/events.js';

let mounted = false;

export function mountMintInitStage(ctx) {
  const rootId = ctx.boot?.stages?.['mint-init'];
  const root = byId(rootId);
  if (!root) return;

  const cert = ctx.state?.detail?.[ctx.state?.selected_cert_uid] || {};

  html(root, `
    <section class="card-premium stage-card">
      <div class="section-head compact">
        <div>
          <div class="section-kicker">Mint Init</div>
          <h3 class="section-title">Prepare Mint</h3>
        </div>
      </div>

      <div class="mint-box">
        <div class="mono">CERT: ${escape(cert.cert_uid || '-')}</div>
        <div>Artifact Ready: ${cert.artifact_ready ? 'YES' : 'NO'}</div>
      </div>

      <div class="stage-actions">
        <button id="btnPrepareMint" class="btn-primary">
          Prepare Mint
        </button>
      </div>
    </section>
  `);

  byId('btnPrepareMint')?.addEventListener('click', () => {
    emitCertEvent('cert:status', {
      message: 'Mint init requested',
      tone: 'info'
    });

    emitCertEvent('cert:stage-request', {
      stage: 'mint-verify'
    });
  });

  mounted = true;
}

export function unmountMintInitStage() {
  if (!mounted) return;
  mounted = false;
}

function escape(v) {
  return String(v ?? '').replace(/</g,'&lt;');
}
