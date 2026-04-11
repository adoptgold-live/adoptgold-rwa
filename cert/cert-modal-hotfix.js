/**
 * /var/www/html/public/rwa/cert/cert-modal-hotfix.js
 * Version: v1.0.0-20260410-modal-close-hotfix
 *
 * Purpose:
 * - hard-close stuck cert modals
 * - release body/modal locks
 * - disable auto verify buttons
 * - support overlay click + ESC close
 */

(function () {
  'use strict';

  if (window.CERT_MODAL_HOTFIX_ACTIVE) return;
  window.CERT_MODAL_HOTFIX_ACTIVE = true;

  const IDS = {
    issue: 'issuePayModal',
    repair: 'repairPaymentModal'
  };

  function $(id) {
    return document.getElementById(id);
  }

  function releaseBodyLock() {
    document.body.classList.remove('cert-modal-open');
    document.body.style.overflow = '';
    document.body.style.pointerEvents = '';
    document.documentElement.style.overflow = '';
  }

  function hideModal(modal) {
    if (!modal) return;
    modal.classList.remove('active', 'is-open', 'open', 'show');
    modal.setAttribute('aria-hidden', 'true');
    modal.style.display = 'none';
    modal.style.pointerEvents = 'none';
  }

  function showModal(modal) {
    if (!modal) return;
    modal.style.display = '';
    modal.style.pointerEvents = '';
  }

  function hardCloseAllCertModals() {
    hideModal($(IDS.issue));
    hideModal($(IDS.repair));
    releaseBodyLock();
  }

  function reopenModalIfNeeded(modalId) {
    const modal = $(modalId);
    if (!modal) return;
    showModal(modal);
  }

  function killAutoVerifyButtons() {
    ['issuePayAutoBtn', 'repairPaymentAutoBtn'].forEach((id) => {
      const btn = $(id);
      if (!btn) return;
      btn.disabled = true;
      btn.style.display = 'none';
      btn.setAttribute('aria-hidden', 'true');
      btn.addEventListener('click', function (ev) {
        ev.preventDefault();
        ev.stopPropagation();
        ev.stopImmediatePropagation();
        return false;
      }, true);
    });
  }

  function bindCloseButton(id) {
    const btn = $(id);
    if (!btn) return;

    btn.addEventListener('click', function (ev) {
      ev.preventDefault();
      ev.stopPropagation();
      ev.stopImmediatePropagation();
      hardCloseAllCertModals();
      return false;
    }, true);
  }

  function bindOverlayClose(modalId) {
    const modal = $(modalId);
    if (!modal) return;

    modal.addEventListener('click', function (ev) {
      if (ev.target === modal) {
        ev.preventDefault();
        ev.stopPropagation();
        ev.stopImmediatePropagation();
        hardCloseAllCertModals();
        return false;
      }
    }, true);
  }

  function bindEscClose() {
    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape') {
        hardCloseAllCertModals();
      }
    }, true);
  }

  function guardBlankIssuePayActions() {
    const verifyBtn = $('issuePayVerifyBtn');
    if (verifyBtn) {
      verifyBtn.addEventListener('click', function (ev) {
        const certUid = String($('issuePayCertUid')?.textContent || '').trim();
        const token = String($('issuePayToken')?.textContent || '').trim();
        const amount = String($('issuePayAmount')?.textContent || '').trim();
        const ref = String($('issuePayRef')?.textContent || '').trim();

        const ready = certUid && certUid !== '—' &&
                      token && token !== '—' &&
                      amount && amount !== '—' &&
                      ref && ref !== '—';

        if (!ready) {
          ev.preventDefault();
          ev.stopPropagation();
          ev.stopImmediatePropagation();
          alert('Payment payload not loaded. Please use Repair Payment or reopen Issue & Pay.');
          return false;
        }
      }, true);
    }
  }

  function bootHotfix() {
    killAutoVerifyButtons();
    bindCloseButton('issuePayCloseBtn');
    bindCloseButton('repairPaymentCloseBtn');
    bindOverlayClose(IDS.issue);
    bindOverlayClose(IDS.repair);
    bindEscClose();
    guardBlankIssuePayActions();

    // expose manual emergency close for console use
    window.closeCertPopupsNow = hardCloseAllCertModals;
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootHotfix, { once: true });
  } else {
    bootHotfix();
  }
})();
