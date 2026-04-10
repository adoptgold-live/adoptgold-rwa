<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/api/storage/bind-card.php
 * Storage Master v7.4
 * Final bind-card hotfix: JSON-only, exact 16 digits
 */

ob_start();

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/api/storage/_bootstrap.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

function bindcard_json(array $payload, int $status = 200): void
{
    while (ob_get_level() > 0) {
        $buf = ob_get_clean();
        // discard any stray output completely
        unset($buf);
    }

    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        bindcard_json([
            'ok' => false,
            'error' => 'METHOD_NOT_ALLOWED',
            'message' => 'POST required',
        ], 405);
    }

    // Accept old and new bind scopes
    $csrfToken = isset($_POST['csrf_token']) && !is_array($_POST['csrf_token'])
        ? trim((string)$_POST['csrf_token'])
        : '';

    $csrfOk = false;
    $csrfScopes = ['bind', 'storage_bind_card', 'storage_bind', 'bind_card', 'bind-card'];

    foreach ($csrfScopes as $scope) {
        try {
            if (function_exists('csrf_check')) {
                $rf = new ReflectionFunction('csrf_check');
                $argc = $rf->getNumberOfParameters();

                if ($argc >= 2) {
                    $res = csrf_check($scope, $csrfToken);
                } else {
                    if ($csrfToken !== '') {
                        $_POST['csrf_token'] = $csrfToken;
                    }
                    $res = csrf_check($scope);
                }

                if ($res === true || $res === 1 || $res === '1' || $res === null) {
                    $csrfOk = true;
                    break;
                }
            }

            if (!$csrfOk && function_exists('csrf_verify')) {
                $res = csrf_verify($scope, $csrfToken);
                if ($res === true || $res === 1 || $res === '1' || $res === null) {
                    $csrfOk = true;
                    break;
                }
            }
        } catch (Throwable $e) {
        }
    }

    if (!$csrfOk) {
        bindcard_json([
            'ok' => false,
            'error' => 'CSRF_INVALID',
            'message' => 'Invalid CSRF token',
        ], 403);
    }

    // Resolve user directly from canonical bootstrap helpers
    if (!function_exists('storage_require_user')) {
        bindcard_json([
            'ok' => false,
            'error' => 'AUTH_HELPER_MISSING',
            'message' => 'Auth helper missing',
        ], 500);
    }

    $user = storage_require_user();
    $userId = (int)($user['id'] ?? 0);

    if ($userId <= 0) {
        bindcard_json([
            'ok' => false,
            'error' => 'AUTH_REQUIRED',
            'message' => 'Login required',
        ], 401);
    }

    // Exact live contract: 16 digits only
    $cardNumber = isset($_POST['card_number']) && !is_array($_POST['card_number'])
        ? preg_replace('/\D+/', '', (string)$_POST['card_number'])
        : '';

    if ($cardNumber === '') {
        bindcard_json([
            'ok' => false,
            'error' => 'CARD_NUMBER_REQUIRED',
            'message' => 'Card number required',
        ], 400);
    }

    if (!preg_match('/^\d{16}$/', $cardNumber)) {
        bindcard_json([
            'ok' => false,
            'error' => 'CARD_NUMBER_INVALID',
            'message' => 'Card number must be exactly 16 digits',
            'length' => strlen($cardNumber),
        ], 400);
    }

    if (!function_exists('storage_db')) {
        bindcard_json([
            'ok' => false,
            'error' => 'DB_HELPER_MISSING',
            'message' => 'DB helper missing',
        ], 500);
    }

    $pdo = storage_db();

    // Lock if already active
    $current = null;
    try {
        $stmt = $pdo->prepare("
            SELECT
                user_id,
                card_number,
                is_active,
                activation_ref,
                activation_tx_hash,
                activated_at,
                updated_at
            FROM rwa_storage_cards
            WHERE user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        bindcard_json([
            'ok' => false,
            'error' => 'CARD_LOOKUP_FAILED',
            'message' => $e->getMessage(),
        ], 500);
    }

    if (is_array($current) && (int)($current['is_active'] ?? 0) === 1) {
        bindcard_json([
            'ok' => false,
            'error' => 'CARD_LOCKED',
            'message' => 'Card is locked after activation. Admin release required',
            'card_number' => (string)($current['card_number'] ?? ''),
            'status' => 'active',
            'locked' => 1,
            'is_active' => true,
        ], 409);
    }

    $boundTon = trim((string)($user['wallet_address'] ?? ''));
    $cardHash = hash('sha256', $cardNumber);
    $cardLast4 = substr($cardNumber, -4);
    $cardMasked = str_repeat('*', 12) . $cardLast4;

    try {
        $stmt = $pdo->prepare("
            INSERT INTO rwa_storage_cards (
                user_id,
                bound_ton_address,
                card_number,
                card_hash,
                card_last4,
                card_masked,
                is_active,
                activation_ref,
                activation_tx_hash,
                activated_at,
                created_at,
                updated_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, 0, NULL, NULL, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
            ON DUPLICATE KEY UPDATE
                bound_ton_address = CASE WHEN is_active = 1 THEN bound_ton_address ELSE VALUES(bound_ton_address) END,
                card_number = CASE WHEN is_active = 1 THEN card_number ELSE VALUES(card_number) END,
                card_hash = CASE WHEN is_active = 1 THEN card_hash ELSE VALUES(card_hash) END,
                card_last4 = CASE WHEN is_active = 1 THEN card_last4 ELSE VALUES(card_last4) END,
                card_masked = CASE WHEN is_active = 1 THEN card_masked ELSE VALUES(card_masked) END,
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            $userId,
            $boundTon,
            $cardNumber,
            $cardHash,
            $cardLast4,
            $cardMasked,
        ]);
    } catch (Throwable $e) {
        bindcard_json([
            'ok' => false,
            'error' => 'CARD_BIND_SAVE_FAILED',
            'message' => $e->getMessage(),
        ], 500);
    }

    $_SESSION['storage_card'] = [
        'user_id' => $userId,
        'card_number' => $cardNumber,
        'status' => 'draft',
        'locked' => 0,
        'is_active' => false,
        'activation_ref' => '',
        'activation_tx_hash' => '',
        'activated_at' => '',
        'updated_at' => gmdate('c'),
    ];

    bindcard_json([
        'ok' => true,
        'status' => 'CARD_BOUND',
        'message' => 'CARD_BOUND',
        'user_id' => $userId,
        'card_number' => $cardNumber,
        'locked' => 0,
        'is_active' => false,
        'card' => [
            'card_number' => $cardNumber,
            'status' => 'draft',
            'locked' => 0,
            'is_active' => false,
            'activation_ref' => '',
            'activation_tx_hash' => '',
            'updated_at' => gmdate('c'),
        ],
    ], 200);

} catch (Throwable $e) {
    bindcard_json([
        'ok' => false,
        'error' => 'BIND_CARD_FAILED',
        'message' => $e->getMessage(),
    ], 500);
}