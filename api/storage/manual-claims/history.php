<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$user = manual_claims_require_auth();
$pdo = manual_claims_pdo();

$flowType = strtolower(trim((string)($_GET['flow_type'] ?? '')));
$limit = (int)($_GET['limit'] ?? 50);
$limit = max(1, min(200, $limit));

$params = [':uid' => (int)$user['id']];
$where = "WHERE user_id = :uid";

if ($flowType !== '') {
    manual_claims_require_flow($flowType);
    $where .= " AND flow_type = :flow_type";
    $params[':flow_type'] = $flowType;
}

$sql = "SELECT
  request_uid, flow_type, source_bucket, request_token, settle_token,
  wallet_address, recipient_owner, amount_units, amount_display, decimals,
  claim_nonce, claim_ref, proof_required, proof_contract, proof_tx_hash,
  payout_tx_hash, payout_wallet, status, requested_note, approved_by,
  approved_at, rejected_at, reject_reason, paid_at, meta, created_at, updated_at
FROM wems_db.poado_token_manual_requests
{$where}
ORDER BY id DESC
LIMIT {$limit}";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

foreach ($rows as &$row) {
    $row['proof_required'] = (int)$row['proof_required'];
    $row['meta'] = ($row['meta'] !== null && $row['meta'] !== '') ? json_decode((string)$row['meta'], true) : null;
}
unset($row);

manual_claims_ok([
    'items' => $rows,
    'count' => count($rows),
]);
