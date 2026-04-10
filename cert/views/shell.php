<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/views/shell.php
 * Version: v2.0.0-20260406-v2-shell-view-baseline
 *
 * MASTER LOCK — RWA Cert V2 shell view
 * - preserve current visible layout direction
 * - router owns shell/state authority
 * - view provides mount points only
 * - no backend truth rewrite
 */

$certBoot = $certBoot ?? [];
$activeBucket = (string)($certBoot['active_bucket'] ?? 'issuance_factory');
$activeStage  = (string)($certBoot['active_stage'] ?? 'issue');

if (!function_exists('cert_shell_h')) {
    function cert_shell_h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('cert_shell_json')) {
    function cert_shell_json(array $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?: '{}';
    }
}
?>
<section
  id="certAppShell"
  class="cert-v2-shell"
  data-active-bucket="<?= cert_shell_h($activeBucket) ?>"
  data-active-stage="<?= cert_shell_h($activeStage) ?>"
>
  <input
    type="hidden"
    id="certBootPayload"
    value="<?= cert_shell_h(cert_shell_json($certBoot)) ?>"
  >

  <div id="certGlobalStatusRoot" class="cert-global-status-root">
    <div
      id="cert-global-status-bar"
      class="cert-global-status-bar is-info"
      data-role="global-status-bar"
      data-status-tone="info"
      role="status"
      aria-live="polite"
    >Ready</div>
    <div
      class="cert-global-status-meta"
      data-role="global-status-meta"
      hidden
    ></div>
  </div>

  <section id="certQueueSummaryRoot" class="cert-queue-summary-root">
    <div class="queue-summary-grid">
      <button
        type="button"
        id="bucketTabIssuanceFactory"
        class="queue-summary-card is-active"
        data-bucket-key="issuance_factory"
        data-active="1"
        aria-selected="true"
      >
        <span class="queue-summary-label">Issuance</span>
        <strong id="bucketSummaryIssuanceFactory" class="queue-summary-value">0</strong>
      </button>

      <button
        type="button"
        id="bucketTabMintReadyQueue"
        class="queue-summary-card"
        data-bucket-key="mint_ready_queue"
        data-active="0"
        aria-selected="false"
      >
        <span class="queue-summary-label">Mint Ready</span>
        <strong id="bucketSummaryMintReadyQueue" class="queue-summary-value">0</strong>
      </button>

      <button
        type="button"
        id="bucketTabMintingProcess"
        class="queue-summary-card"
        data-bucket-key="minting_process"
        data-active="0"
        aria-selected="false"
      >
        <span class="queue-summary-label">Minting</span>
        <strong id="bucketSummaryMintingProcess" class="queue-summary-value">0</strong>
      </button>

      <button
        type="button"
        id="bucketTabIssued"
        class="queue-summary-card"
        data-bucket-key="issued"
        data-active="0"
        aria-selected="false"
      >
        <span class="queue-summary-label">Issued</span>
        <strong id="bucketSummaryIssued" class="queue-summary-value">0</strong>
      </button>

      <button
        type="button"
        id="bucketTabBlocked"
        class="queue-summary-card"
        data-bucket-key="blocked"
        data-active="0"
        aria-selected="false"
      >
        <span class="queue-summary-label">Blocked</span>
        <strong id="bucketSummaryBlocked" class="queue-summary-value">0</strong>
      </button>
    </div>
  </section>

  <section
    id="certQueuePanelsRoot"
    class="cert-queue-panels-root"
    data-active-bucket="<?= cert_shell_h($activeBucket) ?>"
  >
    <div
      id="bucketPanelIssuanceFactory"
      class="cert-queue-panel is-active"
      data-bucket-key="issuance_factory"
      data-active="1"
    ></div>

    <div
      id="bucketPanelMintReadyQueue"
      class="cert-queue-panel"
      data-bucket-key="mint_ready_queue"
      data-active="0"
      hidden
    ></div>

    <div
      id="bucketPanelMintingProcess"
      class="cert-queue-panel"
      data-bucket-key="minting_process"
      data-active="0"
      hidden
    ></div>

    <div
      id="bucketPanelIssued"
      class="cert-queue-panel"
      data-bucket-key="issued"
      data-active="0"
      hidden
    ></div>

    <div
      id="bucketPanelBlocked"
      class="cert-queue-panel"
      data-bucket-key="blocked"
      data-active="0"
      hidden
    ></div>
  </section>

  <section
    id="certStageRoot"
    class="cert-stage-root"
    data-active-stage="<?= cert_shell_h($activeStage) ?>"
  >
    <div
      id="cert-stage-issue"
      class="cert-stage-mount<?= $activeStage === 'issue' ? ' is-active' : '' ?>"
      data-stage-key="issue"
      data-active="<?= $activeStage === 'issue' ? '1' : '0' ?>"
      <?= $activeStage === 'issue' ? '' : 'hidden' ?>
    ></div>

    <div
      id="cert-stage-payment"
      class="cert-stage-mount<?= $activeStage === 'payment' ? ' is-active' : '' ?>"
      data-stage-key="payment"
      data-active="<?= $activeStage === 'payment' ? '1' : '0' ?>"
      <?= $activeStage === 'payment' ? '' : 'hidden' ?>
    ></div>

    <div
      id="cert-stage-repair"
      class="cert-stage-mount<?= $activeStage === 'repair' ? ' is-active' : '' ?>"
      data-stage-key="repair"
      data-active="<?= $activeStage === 'repair' ? '1' : '0' ?>"
      <?= $activeStage === 'repair' ? '' : 'hidden' ?>
    ></div>

    <div
      id="cert-stage-verify"
      class="cert-stage-mount<?= $activeStage === 'verify' ? ' is-active' : '' ?>"
      data-stage-key="verify"
      data-active="<?= $activeStage === 'verify' ? '1' : '0' ?>"
      <?= $activeStage === 'verify' ? '' : 'hidden' ?>
    ></div>

    <div
      id="cert-stage-mint-init"
      class="cert-stage-mount<?= $activeStage === 'mint-init' ? ' is-active' : '' ?>"
      data-stage-key="mint-init"
      data-active="<?= $activeStage === 'mint-init' ? '1' : '0' ?>"
      <?= $activeStage === 'mint-init' ? '' : 'hidden' ?>
    ></div>

    <div
      id="cert-stage-mint-verify"
      class="cert-stage-mount<?= $activeStage === 'mint-verify' ? ' is-active' : '' ?>"
      data-stage-key="mint-verify"
      data-active="<?= $activeStage === 'mint-verify' ? '1' : '0' ?>"
      <?= $activeStage === 'mint-verify' ? '' : 'hidden' ?>
    ></div>

    <div
      id="cert-stage-minted"
      class="cert-stage-mount<?= $activeStage === 'minted' ? ' is-active' : '' ?>"
      data-stage-key="minted"
      data-active="<?= $activeStage === 'minted' ? '1' : '0' ?>"
      <?= $activeStage === 'minted' ? '' : 'hidden' ?>
    ></div>
  </section>

  <div
    id="certModalRoot"
    class="cert-modal-root"
    data-open="0"
    hidden
  ></div>

  <div id="certQueueListRoot" class="cert-queue-list-root" hidden></div>
</section>
