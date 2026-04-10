<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/views/queue-summary.php
 * Version: v2.0.0-20260406-v2-queue-summary-view
 *
 * Pure view (no logic)
 */

$summary = $summary ?? [
  'issuance_factory' => 0,
  'mint_ready_queue' => 0,
  'minting_process' => 0,
  'issued' => 0,
  'blocked' => 0
];

function qs_h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<div class="queue-summary-grid">

  <button id="bucketTabIssuanceFactory" class="queue-summary-card" data-bucket-key="issuance_factory">
    <span>Issuance</span>
    <strong id="bucketSummaryIssuanceFactory"><?= qs_h($summary['issuance_factory']) ?></strong>
  </button>

  <button id="bucketTabMintReadyQueue" class="queue-summary-card" data-bucket-key="mint_ready_queue">
    <span>Mint Ready</span>
    <strong id="bucketSummaryMintReadyQueue"><?= qs_h($summary['mint_ready_queue']) ?></strong>
  </button>

  <button id="bucketTabMintingProcess" class="queue-summary-card" data-bucket-key="minting_process">
    <span>Minting</span>
    <strong id="bucketSummaryMintingProcess"><?= qs_h($summary['minting_process']) ?></strong>
  </button>

  <button id="bucketTabIssued" class="queue-summary-card" data-bucket-key="issued">
    <span>Issued</span>
    <strong id="bucketSummaryIssued"><?= qs_h($summary['issued']) ?></strong>
  </button>

  <button id="bucketTabBlocked" class="queue-summary-card" data-bucket-key="blocked">
    <span>Blocked</span>
    <strong id="bucketSummaryBlocked"><?= qs_h($summary['blocked']) ?></strong>
  </button>

</div>
