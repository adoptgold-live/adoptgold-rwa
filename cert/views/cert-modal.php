<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/views/cert-modal.php
 * Version: v2.0.0-20260406-v2-cert-modal-view
 *
 * Pure modal shell (no business logic)
 * Router controls open/close + content
 */

function cm_h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<div class="cert-modal-overlay" data-role="modal-overlay">

  <div class="cert-modal-container">

    <header class="cert-modal-header">
      <h3 id="certModalTitle">Action</h3>
      <button
        type="button"
        class="cert-modal-close"
        data-action="modal-close"
        aria-label="Close"
      >×</button>
    </header>

    <div class="cert-modal-body" id="certModalBody">
      <!-- dynamic content injected by router -->
      <div class="modal-placeholder">
        <p>Loading...</p>
      </div>
    </div>

    <footer class="cert-modal-footer">
      <button
        type="button"
        class="btn btn-ghost"
        data-action="modal-cancel"
      >Cancel</button>

      <button
        type="button"
        class="btn btn-primary"
        id="certModalConfirmBtn"
        data-action="modal-confirm"
      >Confirm</button>
    </footer>

  </div>

</div>
