<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

manual_claims_require_post();
$input = manual_claims_read_json();
manual_claims_validate_csrf($input);
manual_claims_require_admin();
$pdo = manual_claims_pdo();

$requestUid = trim((string)($input['request_uid'] ?? ''));
$reason = mb_substr(trim((string)($input['reason'] ?? '')), 0, 255);

if ($requestUid === '') {
    manual_claims_fail('REQUEST_UID_REQUIRED', 'request_uid is required.', 422);
}
if ($reason === '') {
    manual_claims_fail('REJECT_REASON_REQUIRED', 'Reject reason is required.', 422);
}

$row = manual_claims_fetch_request($pdo, $requestUid);
if ($row['status'] !== 'requested') {
    manual_claims_fail('BAD_STATUS_TRANSITION', 'Only requested rows can be rejected.', 409, [
        'current_status' => $row['status'],
    ]);
}

$sql = "UPDATE wems_db.poado_token_manual_requests
SET status = 'rejected',
    rejected_at = UTC_TIMESTAMP(),
    reject_reason = :reason
WHERE request_uid = :request_uid
  AND status = 'requested'
LIMIT 1";

$st = $pdo->prepare($sql);
$st->execute([
    ':reason' => $reason,
    ':request_uid' => $requestUid,
]);

manual_claims_ok([
    'request_uid' => $requestUid,
    'status' => 'rejected',
    'reject_reason' => $reason,
]);
