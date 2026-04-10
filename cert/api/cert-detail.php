<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/_queue-engine.php';

function cd_json(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function cd_bootstrap(): void
{
    static $done = false;
    if ($done) return;

    $candidates = [
        __DIR__ . '/_bootstrap.php',
        dirname(__DIR__, 2) . '/inc/core/bootstrap.php',
        $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php',
        $_SERVER['DOCUMENT_ROOT'] . '/dashboard/inc/bootstrap.php',
    ];

    foreach ($candidates as $file) {
        if (is_file($file)) {
            require_once $file;
            $done = true;
            return;
        }
    }
}

function cd_db(): PDO
{
    cd_bootstrap();

    foreach (['pdo', 'db'] as $name) {
        if (isset($GLOBALS[$name]) && $GLOBALS[$name] instanceof PDO) {
            $GLOBALS[$name]->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return $GLOBALS[$name];
        }
    }

    if (function_exists('db')) {
        $pdo = db();
        if ($pdo instanceof PDO) {
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return $pdo;
        }
    }

    $host = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1';
    $name = $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: 'wems_db';
    $user = $_ENV['DB_USER'] ?? getenv('DB_USER') ?: 'root';
    $pass = $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: '';
    $charset = $_ENV['DB_CHARSET'] ?? getenv('DB_CHARSET') ?: 'utf8mb4';

    return new PDO(
        "mysql:host={$host};dbname={$name};charset={$charset}",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
}

function cd_columns(PDO $pdo, string $table): array
{
    $st = $pdo->prepare("
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table
    ");
    $st->execute([':table' => $table]);
    return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

function cd_has(array $cols, string $col): bool
{
    return in_array($col, $cols, true);
}

function cd_meta_decode(mixed $v): array
{
    if (!is_string($v) || trim($v) === '') return [];
    try {
        $x = json_decode($v, true, 512, JSON_THROW_ON_ERROR);
        return is_array($x) ? $x : [];
    } catch (Throwable) {
        return [];
    }
}

function cd_bool(mixed $v): bool
{
    if (is_bool($v)) return $v;
    if (is_int($v) || is_float($v)) return ((int)$v) !== 0;
    $s = strtolower(trim((string)$v));
    return in_array($s, ['1','true','yes','y','on'], true);
}

function cd_payment(PDO $pdo, string $certUid): array
{
    try {
        $st = $pdo->prepare("
            SELECT status, verified, tx_hash, paid_at, updated_at
            FROM poado_rwa_cert_payments
            WHERE cert_uid = :uid
            ORDER BY id DESC
            LIMIT 1
        ");
        $st->execute([':uid' => $certUid]);
        $row = $st->fetch();

        if (!$row) {
            return [
                'status' => '',
                'verified' => 0,
                'tx_hash' => '',
                'paid_at' => '',
                'updated_at' => '',
            ];
        }

        return [
            'status' => (string)($row['status'] ?? ''),
            'verified' => (int)($row['verified'] ?? 0),
            'tx_hash' => (string)($row['tx_hash'] ?? ''),
            'paid_at' => (string)($row['paid_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? ''),
        ];
    } catch (Throwable) {
        return [
            'status' => '',
            'verified' => 0,
            'tx_hash' => '',
            'paid_at' => '',
            'updated_at' => '',
        ];
    }
}

try {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }

    $certUid = trim((string)($_REQUEST['cert_uid'] ?? $_REQUEST['uid'] ?? $_REQUEST['cert'] ?? ''));
    if ($certUid === '') {
        cd_json(['ok' => false, 'error' => 'CERT_UID_REQUIRED'], 422);
    }

    $pdo = cd_db();
    $table = 'poado_rwa_certs';
    $cols = cd_columns($pdo, $table);

    $wanted = [
        'id','cert_uid','rwa_type','family','rwa_code','status','queue_bucket',
        'owner_user_id','user_id','wallet','wallet_address','ton_wallet',
        'price_wems','price_ema','poado_amount','payment_ref','payment_token',
        'payment_amount','payment_status','payment_verified','nft_minted',
        'nft_item_address','minted_at','created_at','updated_at','meta_json'
    ];

    $select = array_values(array_filter($wanted, fn($c) => cd_has($cols, $c)));
    if (!$select) {
        throw new RuntimeException('CERT_TABLE_COLUMNS_NOT_FOUND');
    }

    $sql = 'SELECT ' . implode(', ', $select) . ' FROM ' . $table . ' WHERE cert_uid = :cert_uid LIMIT 1';
    $st = $pdo->prepare($sql);
    $st->execute([':cert_uid' => $certUid]);
    $row = $st->fetch();

    if (!$row) {
        cd_json([
            'ok' => false,
            'error' => 'CERT_NOT_FOUND',
            'cert_uid' => $certUid,
        ], 404);
    }

    $meta = cd_meta_decode($row['meta_json'] ?? null);
    $status = strtolower(trim((string)($row['status'] ?? '')));
    $paymentRow = cd_payment($pdo, $certUid);

    $paymentStatus = strtolower(trim((string)(
        $paymentRow['status']
        ?: ($row['payment_status'] ?? ($meta['payment']['status'] ?? ''))
    )));
    $paymentVerified = cd_bool(
        $paymentRow['verified']
        ?: ($row['payment_verified'] ?? ($meta['payment']['verified'] ?? 0))
    );

    $artifactReady = cert_qe_artifact_ready($row, $meta);
    $nftMinted = cert_qe_nft_minted($row, $meta);

    $queueBucket = cert_qe_bucket(
        $row,
        [
            'status' => $paymentStatus,
            'verified' => $paymentVerified ? 1 : 0,
        ],
        $meta
    );

    $paymentToken = (string)($row['payment_token'] ?? ($meta['payment']['token'] ?? (strtoupper((string)($row['family'] ?? 'GENESIS')) === 'GENESIS' ? 'wEMS' : 'EMA$')));
    $paymentAmount = (string)($row['payment_amount'] ?? ($meta['payment']['amount'] ?? ($row['price_wems'] ?? ($row['price_ema'] ?? ''))));
    $paymentRef = (string)($row['payment_ref'] ?? ($meta['payment']['ref'] ?? ''));

    cd_json([
        'ok' => true,
        'cert_uid' => $certUid,
        'detail' => [
            'cert_uid' => (string)($row['cert_uid'] ?? $certUid),
            'rwa_type' => (string)($row['rwa_type'] ?? ''),
            'family' => (string)($row['family'] ?? ''),
            'rwa_code' => (string)($row['rwa_code'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'queue_bucket' => $queueBucket,
            'payment' => [
                'token' => $paymentToken,
                'amount' => $paymentAmount,
                'ref' => $paymentRef,
                'status' => $paymentStatus,
                'verified' => $paymentVerified ? 1 : 0,
            ],
            'nft' => [
                'minted' => $nftMinted ? 1 : 0,
                'item_address' => (string)($row['nft_item_address'] ?? ''),
                'item_index' => '',
                'minted_at' => (string)($row['minted_at'] ?? ''),
                'artifact_ready' => $artifactReady ? 1 : 0,
            ],
            'owner' => [
                'owner_user_id' => (string)($row['owner_user_id'] ?? $row['user_id'] ?? ''),
                'wallet' => (string)($row['wallet'] ?? $row['wallet_address'] ?? $row['ton_wallet'] ?? ''),
            ],
            'urls' => [
                'verify' => '/rwa/cert/verify.php?uid=' . rawurlencode($certUid),
            ],
            'timestamps' => [
                'created_at' => (string)($row['created_at'] ?? ''),
                'updated_at' => (string)($row['updated_at'] ?? ''),
            ],
            'meta' => $meta,
        ],
        'generated_at' => gmdate('c'),
    ]);
} catch (Throwable $e) {
    cd_json([
        'ok' => false,
        'error' => 'CERT_DETAIL_FAILED',
        'detail' => $e->getMessage(),
    ], 500);
}
