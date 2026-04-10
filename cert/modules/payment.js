/**
 * PAYMENT STAGE MODULE
 * - read-only render + trigger verify
 * - backend confirm-payment is authority
 */

import { byId, html } from '../shared/dom.js';
import { emitCertEvent } from '../shared/events.js';

let mounted = false;

export function mountPaymentStage(ctx) {
  const rootId = ctx.boot?.stages?.payment;
  const root = byId(rootId);
  if (!root) return;

  const cert = ctx.state?.detail?.[ctx.state?.selected_cert_uid] || {};

  html(root, `
    <section class="card-premium stage-card">
      <div class="section-head compact">
        <div>
          <div class="section-kicker">Payment</div>
          <h3 class="section-title">Business Payment</h3>
        </div>
      </div>

      <div class="payment-box">
        <div class="mono">CERT: ${escape(cert.cert_uid || '-')}</div>
        <div>Status: ${escape(cert.payment_ready ? 'CONFIRMED' : 'PENDING')}</div>
      </div>

      <div class="stage-actions">
        <button id="btnVerifyPayment" class="btn-primary">Verify Payment</button>
      </div>
    </section>
  `);

  byId('btnVerifyPayment')?.addEventListener('click', () => {
    emitCertEvent('cert:detail-refresh', {
      cert_uid: cert.cert_uid
    });
  });

  mounted = true;
}

export function unmountPaymentStage() {
  if (!mounted) return;
  mounted = false;
}

function escape(v) {
  return String(v ?? '').replace(/</g,'&lt;');
}
