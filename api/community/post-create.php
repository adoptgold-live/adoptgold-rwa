<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/api/community/post-create.php
 * Community post create endpoint
 *
 * Rules:
 * - JSON only
 * - Session required
 * - Uses locked users table
 * - Soft-safe if community_posts table does not exist yet
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../inc/rwa-session.php';
require_once __DIR__ . '/../../../dashboard/inc/bootstrap.php';
require_once __DIR__ . '/../../../dashboard/inc/session-user.php';

if (!function_exists('community_json_out')) {
    function community_json_out(array $payload, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('community_pdo')) {
    function community_pdo(): PDO
    {
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            return $GLOBALS['pdo'];
        }
        if (function_exists('db_connect')) {
            db_connect();
            if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
                return $GLOBALS['pdo'];
            }
        }
        throw new RuntimeException('DB_NOT_READY');
    }
}

if (!function_exists('community_table_exists')) {
    function community_table_exists(PDO $pdo, string $table): bool
    {
        $sql = "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?";
        $stm = $pdo->prepare($sql);
        $stm->execute([$table]);
        return (int)$stm->fetchColumn() > 0;
    }
}

if (!function_exists('community_make_uid')) {
    function community_make_uid(string $prefix = 'POST'): string
    {
        return strtoupper($prefix . '-' . gmdate('YmdHis') . '-' . substr(bin2hex(random_bytes(6)), 0, 12));
    }
}

try {
    $raw = file_get_contents('php://input');
    $in = json_decode($raw ?: '{}', true);
    if (!is_array($in)) {
        $in = [];
    }

    $content = trim((string)($in['content'] ?? ''));
    $title = trim((string)($in['title'] ?? ''));
    $postType = trim((string)($in['post_type'] ?? 'community'));
    $visibility = trim((string)($in['visibility'] ?? 'public'));
    $industryKey = trim((string)($in['industry_key'] ?? ''));
    $countryIso2 = strtoupper(trim((string)($in['country_iso2'] ?? '')));

    if ($content === '') {
        community_json_out([
            'ok' => false,
            'error' => 'CONTENT_REQUIRED'
        ], 422);
    }

    if (mb_strlen($content) > 5000) {
        community_json_out([
            'ok' => false,
            'error' => 'CONTENT_TOO_LONG'
        ], 422);
    }

    $wallet = '';
    if (function_exists('get_wallet_session')) {
        $wallet = (string)get_wallet_session();
    }
    $wallet = trim($wallet);

    if ($wallet === '') {
        community_json_out([
            'ok' => false,
            'error' => 'LOGIN_REQUIRED'
        ], 401);
    }

    $pdo = community_pdo();

    if (!community_table_exists($pdo, 'community_posts')) {
        community_json_out([
            'ok' => false,
            'error' => 'COMMUNITY_POSTS_TABLE_NOT_READY'
        ], 503);
    }

    $sqlUser = "
        SELECT
            id,
            role,
            country_code
        FROM users
        WHERE wallet_address = :wallet
           OR wallet = :wallet
        LIMIT 1
    ";
    $stmUser = $pdo->prepare($sqlUser);
    $stmUser->execute([':wallet' => $wallet]);
    $user = $stmUser->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        community_json_out([
            'ok' => false,
            'error' => 'USER_NOT_FOUND'
        ], 404);
    }

    $userId = (int)$user['id'];
    $role = trim((string)($user['role'] ?? 'user'));
    if ($countryIso2 === '') {
        $countryIso2 = strtoupper(trim((string)($user['country_code'] ?? '')));
    }

    $postUid = community_make_uid('POST');

    $sql = "
        INSERT INTO community_posts
        (
            post_uid,
            user_id,
            role,
            post_type,
            visibility,
            industry_key,
            country_iso2,
            title,
            content,
            media_json,
            meta_json,
            status,
            created_at,
            updated_at
        )
        VALUES
        (
            :post_uid,
            :user_id,
            :role,
            :post_type,
            :visibility,
            :industry_key,
            :country_iso2,
            :title,
            :content,
            :media_json,
            :meta_json,
            'active',
            NOW(),
            NOW()
        )
    ";

    $stm = $pdo->prepare($sql);
    $stm->execute([
        ':post_uid' => $postUid,
        ':user_id' => $userId,
        ':role' => $role,
        ':post_type' => $postType !== '' ? $postType : 'community',
        ':visibility' => $visibility !== '' ? $visibility : 'public',
        ':industry_key' => $industryKey !== '' ? $industryKey : null,
        ':country_iso2' => $countryIso2 !== '' ? $countryIso2 : null,
        ':title' => $title !== '' ? $title : null,
        ':content' => $content,
        ':media_json' => null,
        ':meta_json' => json_encode([
            'created_via' => 'community/post-create.php',
            'wallet' => $wallet
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    ]);

    community_json_out([
        'ok' => true,
        'post_uid' => $postUid,
        'message' => 'POST_CREATED'
    ]);
} catch (Throwable $e) {
    community_json_out([
        'ok' => false,
        'error' => 'COMMUNITY_POST_CREATE_FAILED',
        'detail' => $e->getMessage()
    ], 500);
}
