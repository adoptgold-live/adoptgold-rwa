<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

manual_claims_require_post();
$input = manual_claims_read_json();
manual_claims_validate_csrf($input);
$admin = manual_claims_require_admin();
$pdo = manual_claims_pdo();

$requestUid = trim((string)($input['request_uid'] ?? ''));
if ($requestUid === '') {
    manual_claims_fail('REQUEST_UID_REQUIRED', 'request_uid is required.', 422);
}

$row = manual_claims_fetch_request($pdo, $requestUid);
if ($row['status'] !== 'requested') {
    manual_claims_fail('BAD_STATUS_TRANSITION', 'Only requested rows can be approved.', 409, [
        'current_status' => $row['status'],
    ]);
}

$claimNonce = isset($input['claim_nonce']) && $input['claim_nonce'] !== '' ? (int)$input['claim_nonce'] : null;
$claimRef = trim((string)($input['claim_ref'] ?? ''));
$proofRequired = array_key_exists('proof_required', $input) ? (int)((bool)$input['proof_required']) : (int)$row['proof_required'];
$proofContract = trim((string)($input['proof_contract'] ?? ''));
$meta = ($row['meta'] !== null && $row['meta'] !== '') ? (json_decode((string)$row['meta'], true) ?: []) : [];
$meta['approved_ip'] = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$meta['approved_user_agent'] = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

$sql = "UPDATE wems_db.poado_token_manual_requests
SET status = 'approved',
    approved_by = :approved_by,
    approved_at = UTC_TIMESTAMP(),
    claim_nonce = :claim_nonce,
    claim_ref = :claim_ref,
    proof_required = :proof_required,
    proof_contract = :proof_contract,
    meta = :meta
WHERE request_uid = :request_uid
  AND status = 'requested'
LIMIT 1";

$st = $pdo->prepare($sql);
$st->bindValue(':approved_by', (int)$admin['id'], PDO::PARAM_INT);
$st->bindValue(':claim_nonce', $claimNonce, $claimNonce === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
$st->bindValue(':claim_ref', $claimRef);
$st->bindValue(':proof_required', $proofRequired, PDO::PARAM_INT);
$st->bindValue(':proof_contract', $proofContract);
$st->bindValue(':meta', json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$st->bindValue(':request_uid', $requestUid);
$st->execute();

manual_claims_ok([
    'request_uid' => $requestUid,
    'status' => 'approved',
    'approved_by' => (int)$admin['id'],
    'claim_nonce' => $claimNonce,
    'claim_ref' => $claimRef,
    'proof_required' => $proofRequired,
    'proof_contract' => $proofContract,
]);
