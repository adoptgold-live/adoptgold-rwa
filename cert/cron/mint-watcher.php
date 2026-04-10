<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/inc/core/bootstrap.php';

function mw_db(): PDO {
    return function_exists('rwa_db') ? rwa_db() : $GLOBALS['pdo'];
}

function mw_json_decode($v): array {
    $v = trim((string)$v);
    if ($v === '') return [];
    $x = json_decode($v, true);
    return is_array($x) ? $x : [];
}

function mw_fetch_candidates(PDO $pdo, int $limit): array {
    $sql = "
        SELECT c.*, p.payment_ref, p.status AS payment_status, p.verified AS payment_verified
        FROM poado_rwa_certs c
        JOIN poado_rwa_cert_payments p ON p.cert_uid = c.cert_uid
        WHERE c.status = 'issued'
          AND COALESCE(c.nft_minted,0)=0
          AND p.status='confirmed'
          AND p.verified=1
        ORDER BY c.id ASC
        LIMIT " . (int)$limit;
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function mw_call_mint_verify_cli(string $certUid): array {
    $php = '/usr/bin/php';
    if (!is_executable($php)) {
        $php = PHP_BINARY;
    }

    $script = '/var/www/html/public/rwa/cert/api/mint-verify.php';
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg('cert_uid=' . $certUid) . ' 2>&1';
    $raw = shell_exec($cmd);

    if (!is_string($raw) || trim($raw) === '') {
        return [
            'ok' => false,
            'error' => 'MINT_VERIFY_CLI_EMPTY',
            'detail' => $cmd,
        ];
    }

    $json = json_decode($raw, true);
    if (is_array($json)) {
        return $json;
    }

    if (preg_match('/(\{.*\})/s', $raw, $m)) {
        $json = json_decode($m[1], true);
        if (is_array($json)) {
            return $json;
        }
    }

    return [
        'ok' => false,
        'error' => 'MINT_VERIFY_CLI_INVALID_JSON',
        'detail' => $raw,
    ];
}

$pdo = mw_db();
$rows = mw_fetch_candidates($pdo, (int)($argv[1] ?? 10));

$out = [
    'ok' => true,
    'source' => 'mint-watcher.php',
    'mode' => 'batch_executor_via_mint_verify_cli',
    'processed' => 0,
    'errors' => 0,
    'results' => []
];

foreach ($rows as $r) {
    try {
        $meta = mw_json_decode($r['meta_json'] ?? '');
        $itemIndex = $meta['mint']['mint_request']['item_index'] ?? ($meta['mint_request']['item_index'] ?? '');

        if (!$itemIndex) {
            $out['results'][] = [
                'cert_uid' => $r['cert_uid'],
                'skipped' => true,
                'reason' => 'ITEM_INDEX_REQUIRED'
            ];
            continue;
        }

        $_GET['cert_uid'] = (string)$r['cert_uid'];
        $_REQUEST['cert_uid'] = (string)$r['cert_uid'];

        $res = mw_call_mint_verify_cli((string)$r['cert_uid']);
        $out['results'][] = $res;
        $out['processed']++;

        if (empty($res['ok'])) {
            $out['errors']++;
        }
    } catch (Throwable $e) {
        $out['errors']++;
        $out['results'][] = [
            'cert_uid' => $r['cert_uid'],
            'error' => $e->getMessage()
        ];
    }
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
