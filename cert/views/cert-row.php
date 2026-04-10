<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/views/cert-row.php
 * Version: v2.0.0-20260406-v2-cert-row-view
 *
 * Pure row renderer (no logic rewrite)
 */

$row = $row ?? [];

function cr_h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$certUid = cr_h($row['cert_uid'] ?? '');
$status  = cr_h($row['status'] ?? '—');
$bucket  = cr_h($row['queue_bucket'] ?? '—');
$minted  = !empty($row['nft_minted']) ? 'YES' : 'NO';
?>
<button type="button" class="cert-row" data-cert-uid="<?= $certUid ?>">
  <div class="cert-row-main">
    <div class="cert-row-title mono"><?= $certUid ?: '—' ?></div>
    <div class="cert-row-sub"><?= $status ?> · <?= $bucket ?></div>
  </div>

  <div class="cert-row-side">
    <span class="cert-row-pill">Minted <?= $minted ?></span>
  </div>
</button>
