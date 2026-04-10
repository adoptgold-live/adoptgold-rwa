<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

manual_claims_require_post();
$input = manual_claims_read_json();
manual_claims_validate_csrf($input);
manual_claims_require_admin();
$pdo = manual_claims_pdo();

$requestUid = trim((string)($input['request_uid'] ?? ''));
$proofTxHash = mb_substr(trim((string)($input['proof_tx_hash'] ?? '')), 0, 255);
$proofContract = mb_substr(trim((string)($input['proof_contract'] ?? '')), 0, 128);

if ($requestUid === '') {
    manual_claims_fail('REQUEST_UID_REQUIRED', 'request_uid is required.', 422);
}
if ($proofTxHash === '') {
    manual_claims_fail('PROOF_TX_HASH_REQUIRED', 'proof_tx_hash is required.', 422);
}

$row = manual_claims_fetch_request($pdo, $requestUid);
if ($row['status'] !== 'approved') {
    manual_claims_fail('BAD_STATUS_TRANSITION', 'Only approved rows can be marked proof_submitted.', 409, [
        'current_status' => $row['status'],
    ]);
}

$sql = "UPDATE wems_db.poado_token_manual_requests
SET status = 'proof_submitted',
    proof_tx_hash = :proof_tx_hash,
    proof_contract = CASE WHEN :proof_contract <> '' THEN :proof_contract ELSE proof_contract END
WHERE request_uid = :request_uid
  AND status = 'approved'
LIMIT 1";

$st = $pdo->prepare($sql);
$st->execute([
    ':proof_tx_hash' => $proofTxHash,
    ':proof_contract' => $proofContract,
    ':request_uid' => $requestUid,
]);

manual_claims_ok([
    'request_uid' => $requestUid,
    'status' => 'proof_submitted',
    'proof_tx_hash' => $proofTxHash,
    'proof_contract' => $proofContract !== '' ? $proofContract : $row['proof_contract'],
]);
