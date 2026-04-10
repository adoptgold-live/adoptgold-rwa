<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

manual_claims_require_post();
$input = manual_claims_read_json();
manual_claims_validate_csrf($input);
manual_claims_require_admin();
$pdo = manual_claims_pdo();

$requestUid = trim((string)($input['request_uid'] ?? ''));
$payoutTxHash = mb_substr(trim((string)($input['payout_tx_hash'] ?? '')), 0, 255);
$payoutWallet = mb_substr(trim((string)($input['payout_wallet'] ?? '')), 0, 128);

if ($requestUid === '') {
    manual_claims_fail('REQUEST_UID_REQUIRED', 'request_uid is required.', 422);
}
if ($payoutTxHash === '') {
    manual_claims_fail('PAYOUT_TX_HASH_REQUIRED', 'payout_tx_hash is required.', 422);
}

$row = manual_claims_fetch_request($pdo, $requestUid);
if (!in_array($row['status'], ['approved', 'proof_submitted'], true)) {
    manual_claims_fail('BAD_STATUS_TRANSITION', 'Only approved or proof_submitted rows can be marked paid.', 409, [
        'current_status' => $row['status'],
    ]);
}

$sql = "UPDATE wems_db.poado_token_manual_requests
SET status = 'paid',
    payout_tx_hash = :payout_tx_hash,
    payout_wallet = :payout_wallet,
    paid_at = UTC_TIMESTAMP()
WHERE request_uid = :request_uid
  AND status IN ('approved', 'proof_submitted')
LIMIT 1";

$st = $pdo->prepare($sql);
$st->execute([
    ':payout_tx_hash' => $payoutTxHash,
    ':payout_wallet' => $payoutWallet,
    ':request_uid' => $requestUid,
]);

manual_claims_ok([
    'request_uid' => $requestUid,
    'status' => 'paid',
    'payout_tx_hash' => $payoutTxHash,
    'payout_wallet' => $payoutWallet,
]);
