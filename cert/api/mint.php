<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/api/mint.php
 * Version: v4.1.0-20260328-finalize-mint-flow
 *
 * Locked flow:
 * Check & Preview -> Pay Now -> Verify Payment -> Finalize Mint
 *
 * Step 4 only:
 * - block mint unless cert is paid or mint_pending
 * - move paid -> mint_pending
 * - store NFT mint result data
 * - finalize minted status
 * - preserve verify / PDF / NFT preview alignment
 *
 * No business payment confirmation here.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/json.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/drive.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/inc/core/vault.php';
require_once __DIR__ . '/_vault-path.php';

date_default_timezone_set('Asia/Kuala_Lumpur');

function mint_json(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mint_fail(string $message, int $status = 400, array $extra = []): never
{
    mint_json([
        'ok' => false,
        'error' => $message,
    ] + $extra, $status);
}

function mint_pdo(): PDO
{
    if (function_exists('rwa_db')) {
        $pdo = rwa_db();
        if ($pdo instanceof PDO) {
            return $pdo;
        }
    }
    if (($GLOBALS['pdo'] ?? null) instanceof PDO) {
        return $GLOBALS['pdo'];
    }
    throw new RuntimeException('PDO_NOT_AVAILABLE');
}

function mint_input(): array
{
    $raw = file_get_contents('php://input');
    $json = json_decode((string)$raw, true);
    return is_array($json) ? ($json + $_POST + $_GET) : ($_POST + $_GET);
}

function mint_require_csrf(array $input): void
{
    if (!function_exists('csrf_check')) {
        return;
    }

    $token = (string)($input['csrf'] ?? '');
    if ($token === '' || !csrf_check('rwa_cert_mint', $token)) {
        throw new RuntimeException('INVALID_CSRF');
    }
}

function mint_columns(PDO $pdo, string $table): array
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $st = $pdo->prepare("
        SELECT COLUMN_NAME
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table
    ");
    $st->execute([':table' => $table]);

    $cols = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $col) {
        $cols[(string)$col] = true;
    }
    return $cache[$table] = $cols;
}

function mint_update(PDO $pdo, string $table, array $data, string $whereSql, array $whereParams = []): void
{
    $cols = mint_columns($pdo, $table);
    $set = [];
    $params = [];

    foreach ($data as $k => $v) {
        if (isset($cols[$k])) {
            $set[] = "{$k} = :set_{$k}";
            $params[":set_{$k}"] = $v;
        }
    }

    if ($set === []) {
        return;
    }

    $sql = "UPDATE {$table} SET " . implode(', ', $set) . " WHERE {$whereSql}";
    $st = $pdo->prepare($sql);
    $st->execute($params + $whereParams);
}

function mint_cert(PDO $pdo, string $certUid): array
{
    $st = $pdo->prepare("SELECT * FROM poado_rwa_certs WHERE cert_uid = :uid LIMIT 1");
    $st->execute([':uid' => $certUid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('CERT_NOT_FOUND');
    }
    return $row;
}

function mint_meta_decode(?string $json): array
{
    if (!$json) {
        return [];
    }
    $arr = json_decode($json, true);
    return is_array($arr) ? $arr : [];
}

function mint_meta_merge(array $a, array $b): array
{
    foreach ($b as $k => $v) {
        if (is_array($v) && isset($a[$k]) && is_array($a[$k])) {
            $a[$k] = mint_meta_merge($a[$k], $v);
        } else {
            $a[$k] = $v;
        }
    }
    return $a;
}

function mint_tmp_file(string $bytes, string $ext): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'mint_');
    if ($tmp === false) {
        throw new RuntimeException('TMP_CREATE_FAILED');
    }

    $path = $tmp . '.' . ltrim($ext, '.');
    @rename($tmp, $path);

    if (file_put_contents($path, $bytes) === false) {
        throw new RuntimeException('TMP_WRITE_FAILED');
    }

    return $path;
}

function mint_extract_url($result): string
{
    if (is_string($result) && preg_match('#^https?://#i', $result)) {
        return $result;
    }
    if (is_array($result)) {
        foreach (['url', 'public_url', 'webViewLink', 'webContentLink', 'link', 'download_url'] as $k) {
            if (!empty($result[$k]) && is_string($result[$k])) {
                return $result[$k];
            }
        }
        if (!empty($result['id']) && is_string($result['id'])) {
            return 'https://drive.google.com/file/d/' . $result['id'] . '/view';
        }
    }
    return '';
}

function mint_vault_upload(string $remotePath, string $bytes, string $mime): string
{
    $tmp = mint_tmp_file($bytes, pathinfo($remotePath, PATHINFO_EXTENSION) ?: 'bin');

    try {
        $candidates = [
            ['poado_vault_upload_file', [$tmp, $remotePath, $mime]],
            ['vault_upload_file', [$tmp, $remotePath, $mime]],
            ['poado_drive_upload_file', [$tmp, $remotePath, $mime]],
            ['drive_upload_file', [$tmp, $remotePath, $mime]],
            ['poado_vault_put_contents', [$remotePath, $bytes, $mime]],
            ['vault_put_contents', [$remotePath, $bytes, $mime]],
        ];

        foreach ($candidates as [$fn, $args]) {
            if (function_exists($fn)) {
                $res = $fn(...$args);
                $url = mint_extract_url($res);
                if ($url !== '') {
                    return $url;
                }
            }
        }

        throw new RuntimeException('VAULT_UPLOAD_HELPER_NOT_FOUND');
    } finally {
        @unlink($tmp);
    }
}

function mint_verify_url(string $certUid): string
{
    return 'https://adoptgold.app/rwa/cert/verify.php?uid=' . rawurlencode($certUid);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        mint_fail('METHOD_NOT_ALLOWED', 405);
    }

    $pdo = mint_pdo();
    $in = mint_input();
    mint_require_csrf($in);

    $certUid = trim((string)($in['cert_uid'] ?? ''));
    if ($certUid === '') {
        mint_fail('CERT_UID_REQUIRED', 422);
    }

    $nftItemAddress = trim((string)($in['nft_item_address'] ?? ''));
    $collectionAddress = trim((string)($in['collection_address'] ?? ''));
    $txHash = trim((string)($in['tx_hash'] ?? ''));
    $mintNote = trim((string)($in['note'] ?? ''));

    $pdo->beginTransaction();

    $cert = mint_cert($pdo, $certUid);
    $status = strtolower(trim((string)($cert['status'] ?? '')));

    if (!in_array($status, ['paid', 'mint_pending', 'minted'], true)) {
        throw new RuntimeException('CERT_NOT_ELIGIBLE_FOR_FINALIZE_MINT');
    }

    if ($status === 'minted' && trim((string)($cert['nft_item_address'] ?? '')) !== '') {
        $pdo->commit();
        mint_json([
            'ok' => true,
            'cert_uid' => $certUid,
            'status' => 'minted',
            'verify_url' => (string)($cert['verify_url'] ?? mint_verify_url($certUid)),
            'nft_item_address' => (string)($cert['nft_item_address'] ?? ''),
            'collection_address' => (string)($cert['collection_address'] ?? ''),
        ]);
    }

    if ($status === 'paid') {
        mint_update($pdo, 'poado_rwa_certs', [
            'status' => 'mint_pending',
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'cert_uid = :uid', [
            ':uid' => $certUid,
        ]);

        $cert['status'] = 'mint_pending';
        $status = 'mint_pending';
    }

    $paths = cert_v2_vault_paths($cert);
    $meta = mint_meta_decode($cert['meta_json'] ?? null);

    $verifyUrl = (string)($cert['verify_url'] ?? '');
    if ($verifyUrl === '') {
        $verifyUrl = mint_verify_url($certUid);
    }

    $mintResult = [
        'cert_uid' => $certUid,
        'status' => 'minted',
        'nft_item_address' => $nftItemAddress,
        'collection_address' => $collectionAddress,
        'tx_hash' => $txHash,
        'verify_url' => $verifyUrl,
        'metadata_url' => (string)($cert['metadata_path'] ?? ''),
        'image_url' => (string)($cert['nft_image_path'] ?? ''),
        'pdf_url' => (string)($cert['pdf_path'] ?? ''),
        'minted_at' => date('c'),
        'note' => $mintNote,
    ];

    $mintUrl = mint_vault_upload(
        $paths['mint'],
        json_encode($mintResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        'application/json'
    );

    $lifecycle = [
        'event' => 'finalize_mint_success',
        'uid' => $certUid,
        'status' => 'minted',
        'tx_hash' => $txHash,
        'nft_item_address' => $nftItemAddress,
        'collection_address' => $collectionAddress,
        'at' => date('c'),
    ];

    $lifecycleUrl = mint_vault_upload(
        $paths['lifecycle'],
        json_encode($lifecycle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        'application/json'
    );

    $meta = mint_meta_merge($meta, [
        'lifecycle' => [
            'current' => 'minted',
            'history' => [[
                'event' => 'finalize_mint_success',
                'status' => 'minted',
                'at' => date('c'),
            ]],
        ],
        'mint' => [
            'tx_hash' => $txHash,
            'nft_item_address' => $nftItemAddress,
            'collection_address' => $collectionAddress,
            'minted_at' => date('c'),
            'metadata_url' => (string)($cert['metadata_path'] ?? ''),
            'mint_result_url' => $mintUrl,
            'note' => $mintNote,
        ],
        'vault' => [
            'mint' => $mintUrl,
            'audit' => $lifecycleUrl,
        ],
    ]);

    mint_update($pdo, 'poado_rwa_certs', [
        'status' => 'minted',
        'nft_item_address' => $nftItemAddress,
        'router_tx_hash' => $txHash !== '' ? $txHash : (string)($cert['router_tx_hash'] ?? ''),
        'nft_minted' => 1,
        'meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        'minted_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ], 'cert_uid = :uid', [
        ':uid' => $certUid,
    ]);

    $extraUpdate = [];
    if (isset(mint_columns($pdo, 'poado_rwa_certs')['collection_address'])) {
        $extraUpdate['collection_address'] = $collectionAddress;
    }
    if ($extraUpdate !== []) {
        mint_update($pdo, 'poado_rwa_certs', $extraUpdate, 'cert_uid = :uid', [
            ':uid' => $certUid,
        ]);
    }

    $pdo->commit();

    mint_json([
        'ok' => true,
        'cert_uid' => $certUid,
        'status' => 'minted',
        'verify_url' => $verifyUrl,
        'nft_item_address' => $nftItemAddress,
        'collection_address' => $collectionAddress,
        'vault' => [
            'mint' => $mintUrl,
            'audit' => $lifecycleUrl,
        ],
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if (function_exists('poado_error')) {
        poado_error('rwa_cert', '/rwa/cert/api/mint.php', 'CERT_FINALIZE_MINT_FAILED', $e->getMessage(), [
            'input' => $in ?? [],
        ]);
    }

    mint_fail($e->getMessage(), 500);
}
