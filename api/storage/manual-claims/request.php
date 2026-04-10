<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

manual_claims_require_post();
$input = manual_claims_read_json();
manual_claims_validate_csrf($input);
$user = manual_claims_require_auth();
$pdo = manual_claims_pdo();

$flowType = strtolower(trim((string)($input['flow_type'] ?? '')));
$flow = manual_claims_require_flow($flowType);

$amountRaw = trim((string)($input['amount'] ?? ''));
if ($amountRaw === '') {
    manual_claims_fail('AMOUNT_REQUIRED', 'Amount is required.', 422);
}

$amountUnits = manual_claims_to_units($amountRaw, (int)$flow['decimals']);
if (bccomp($amountUnits, '0', 0) <= 0) {
    manual_claims_fail('BAD_AMOUNT', 'Amount must be greater than zero.', 422);
}

$availableUnits = manual_claims_get_available_units($pdo, (int)$user['id'], $flowType);
if ($availableUnits === null) {
    manual_claims_fail('BALANCE_SOURCE_UNCONFIGURED', 'Balance source for this flow is not wired yet.', 409, [
        'flow_type' => $flowType,
        'source_bucket' => $flow['source_bucket'],
    ]);
}

if (bccomp($amountUnits, $availableUnits, 0) === 1) {
    manual_claims_fail('AMOUNT_EXCEEDS_AVAILABLE', 'Requested amount exceeds available balance.', 422, [
        'requested_units' => $amountUnits,
        'available_units' => $availableUnits,
    ]);
}

$pendingCount = manual_claims_count_pending_same_flow($pdo, (int)$user['id'], $flowType);
if ($pendingCount > 0) {
    manual_claims_fail('PENDING_EXISTS', 'There is already a pending request for this flow.', 409, [
        'flow_type' => $flowType,
        'pending_count' => $pendingCount,
    ]);
}

$requestUid = manual_claims_make_uid('MTR');
$recipientOwner = (string)($user['ton_address'] ?: $user['wallet_address']);
$walletAddress = (string)$user['wallet_address'];
$requestedNote = trim((string)($input['note'] ?? ''));

$meta = [
    'source_module' => 'manual-claims',
    'flow_type' => $flowType,
    'available_units_at_request' => $availableUnits,
    'available_display_at_request' => null,
    'request_ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
    'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
];

$sql = "INSERT INTO wems_db.poado_token_manual_requests
(
  request_uid, user_id, flow_type, source_bucket, request_token, settle_token,
  wallet_address, recipient_owner, amount_units, amount_display, decimals,
  claim_nonce, claim_ref, proof_required, proof_contract, proof_tx_hash, payout_tx_hash,
  payout_wallet, status, requested_note, approved_by, approved_at, rejected_at,
  reject_reason, paid_at, meta
)
VALUES
(
  :request_uid, :user_id, :flow_type, :source_bucket, :request_token, :settle_token,
  :wallet_address, :recipient_owner, :amount_units, :amount_display, :decimals,
  NULL, '', :proof_required, '', '', '',
  '', 'requested', :requested_note, NULL, NULL, NULL,
  '', NULL, :meta
)";

$st = $pdo->prepare($sql);
$st->execute([
    ':request_uid' => $requestUid,
    ':user_id' => (int)$user['id'],
    ':flow_type' => $flowType,
    ':source_bucket' => $flow['source_bucket'],
    ':request_token' => $flow['request_token'],
    ':settle_token' => $flow['settle_token'],
    ':wallet_address' => $walletAddress,
    ':recipient_owner' => $recipientOwner,
    ':amount_units' => $amountUnits,
    ':amount_display' => $amountRaw,
    ':decimals' => (int)$flow['decimals'],
    ':proof_required' => (int)$flow['proof_required'],
    ':requested_note' => mb_substr($requestedNote, 0, 255),
    ':meta' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
]);

manual_claims_ok([
    'request_uid' => $requestUid,
    'status' => 'requested',
    'flow_type' => $flowType,
    'request_token' => $flow['request_token'],
    'settle_token' => $flow['settle_token'],
    'amount_units' => $amountUnits,
    'amount_display' => $amountRaw,
    'recipient_owner' => $recipientOwner,
]);
