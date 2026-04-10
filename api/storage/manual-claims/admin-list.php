<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

manual_claims_require_admin();
$pdo = manual_claims_pdo();

$status = strtolower(trim((string)($_GET['status'] ?? '')));
$flowType = strtolower(trim((string)($_GET['flow_type'] ?? '')));
$requestUid = trim((string)($_GET['request_uid'] ?? ''));
$userId = (int)($_GET['user_id'] ?? 0);
$limit = (int)($_GET['limit'] ?? 100);
$limit = max(1, min(500, $limit));

$params = [];
$where = [];

if ($status !== '') {
    if (!in_array($status, manual_claims_statuses(), true)) {
        manual_claims_fail('BAD_STATUS', 'Unsupported status filter.', 422);
    }
    $where[] = 'status = :status';
    $params[':status'] = $status;
}

if ($flowType !== '') {
    manual_claims_require_flow($flowType);
    $where[] = 'flow_type = :flow_type';
    $params[':flow_type'] = $flowType;
}

if ($requestUid !== '') {
    $where[] = 'request_uid = :request_uid';
    $params[':request_uid'] = $requestUid;
}

if ($userId > 0) {
    $where[] = 'user_id = :user_id';
    $params[':user_id'] = $userId;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "SELECT
  id, request_uid, user_id, flow_type, source_bucket, request_token, settle_token,
  wallet_address, recipient_owner, amount_units, amount_display, decimals,
  claim_nonce, claim_ref, proof_required, proof_contract, proof_tx_hash,
  payout_tx_hash, payout_wallet, status, requested_note, approved_by,
  approved_at, rejected_at, reject_reason, paid_at, meta, created_at, updated_at
FROM wems_db.poado_token_manual_requests
{$whereSql}
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
