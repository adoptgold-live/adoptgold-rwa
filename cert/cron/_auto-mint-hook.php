<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/cron/_auto-mint-hook.php
 * Version: v1.0.0-20260328-real-auto-mint-hook
 *
 * REAL AUTO MINT HOOK
 *
 * What this hook does:
 * 1) runs the current real artifact/vault mint pipeline via /rwa/cert/api/mint.php
 *    - prepares metadata
 *    - uploads artifacts to vault
 *    - updates pdf_path / metadata_path / nft_image_path
 *
 * 2) runs a real NFT mint executor (no fake NFT address)
 *
 * Supported real executor sources:
 * A. function rwa_cert_real_mint_execute(array $cert, array $payment, array $artifactResult): array
 * B. file /var/www/html/public/rwa/cert/cron/_real-mint-executor.php
 *    exporting the same function above
 * C. HTTP JSON executor endpoint from env:
 *    RWA_CERT_MINT_EXECUTOR_URL=https://...
 *
 * Required success return:
 * [
 *   'ok' => true,
 *   'nft_item_address' => 'EQ...',
 *   'collection_address' => 'EQ...', // optional
 *   'tx_hash' => '0x...',            // optional
 * ]
 *
 * If no real executor exists, this hook returns:
 *   AUTO_MINT_EXECUTOR_NOT_FOUND
 */

if (!function_exists('rwa_cert_hook_root')) {
    function rwa_cert_hook_root(): string
    {
        $root = realpath(__DIR__ . '/../../..');
        if ($root === false) {
            throw new RuntimeException('APP_ROOT_RESOLVE_FAILED');
        }
        return $root;
    }
}

if (!function_exists('rwa_cert_hook_bootstrap')) {
    function rwa_cert_hook_bootstrap(): void
    {
        static $booted = false;
        if ($booted) {
            return;
        }

        $root = rwa_cert_hook_root();
        require_once $root . '/rwa/inc/core/bootstrap.php';
        require_once $root . '/rwa/inc/core/db.php';

        $booted = true;
    }
}

if (!function_exists('rwa_cert_hook_env')) {
    function rwa_cert_hook_env(string $key): string
    {
        rwa_cert_hook_bootstrap();
        $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?? '';
        return is_string($v) ? trim($v) : '';
    }
}

if (!function_exists('rwa_cert_hook_pdo')) {
    function rwa_cert_hook_pdo(): PDO
    {
        rwa_cert_hook_bootstrap();

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
}

if (!function_exists('rwa_cert_hook_json_decode')) {
    function rwa_cert_hook_json_decode(?string $json): array
    {
        if (!$json) {
            return [];
        }
        $arr = json_decode($json, true);
        return is_array($arr) ? $arr : [];
    }
}

if (!function_exists('rwa_cert_hook_json_post')) {
    function rwa_cert_hook_json_post(string $url, array $payload, int $timeout = 60): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('CURL_INIT_FAILED');
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            throw new RuntimeException('JSON_ENCODE_FAILED');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Content-Length: ' . strlen($body),
            ],
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $res = curl_exec($ch);
        $err = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($res === false) {
            throw new RuntimeException('CURL_POST_FAILED: ' . $err);
        }

        $json = json_decode((string)$res, true);
        if (!is_array($json)) {
            throw new RuntimeException('INVALID_JSON_RESPONSE');
        }

        if ($http >= 400) {
            $msg = (string)($json['error'] ?? $json['message'] ?? ('HTTP_' . $http));
            throw new RuntimeException($msg);
        }

        return $json;
    }
}

if (!function_exists('rwa_cert_hook_call_artifact_mint')) {
    function rwa_cert_hook_call_artifact_mint(string $certUid): array
    {
        $base = rwa_cert_hook_env('APP_URL');
        if ($base === '') {
            $base = 'https://adoptgold.app';
        }
        $url = rtrim($base, '/') . '/rwa/cert/api/mint.php';

        $res = rwa_cert_hook_json_post($url, [
            'cert_uid' => $certUid,
        ], 120);

        if (empty($res['cert_uid'])) {
            throw new RuntimeException('ARTIFACT_MINT_NO_CERT_UID');
        }

        return $res;
    }
}

if (!function_exists('rwa_cert_hook_load_executor')) {
    function rwa_cert_hook_load_executor(): void
    {
        static $loaded = false;
        if ($loaded) {
            return;
        }

        $file = __DIR__ . '/_real-mint-executor.php';
        if (is_file($file)) {
            require_once $file;
        }

        $loaded = true;
    }
}

if (!function_exists('rwa_cert_hook_call_real_executor')) {
    function rwa_cert_hook_call_real_executor(array $cert, array $payment, array $artifactResult): array
    {
        rwa_cert_hook_load_executor();

        if (function_exists('rwa_cert_real_mint_execute')) {
            $res = rwa_cert_real_mint_execute($cert, $payment, $artifactResult);
            return is_array($res) ? $res : [
                'ok' => false,
                'error' => 'AUTO_MINT_BAD_RETURN',
            ];
        }

        $executorUrl = rwa_cert_hook_env('RWA_CERT_MINT_EXECUTOR_URL');
        if ($executorUrl !== '') {
            $res = rwa_cert_hook_json_post($executorUrl, [
                'cert_uid' => (string)$cert['cert_uid'],
                'cert' => $cert,
                'payment' => $payment,
                'artifact_result' => $artifactResult,
            ], 180);
            return is_array($res) ? $res : [
                'ok' => false,
                'error' => 'AUTO_MINT_BAD_RETURN',
            ];
        }

        return [
            'ok' => false,
            'error' => 'AUTO_MINT_EXECUTOR_NOT_FOUND',
        ];
    }
}

if (!function_exists('rwa_cert_hook_reload_cert')) {
    function rwa_cert_hook_reload_cert(string $certUid): array
    {
        $pdo = rwa_cert_hook_pdo();
        $st = $pdo->prepare("SELECT * FROM poado_rwa_certs WHERE cert_uid = :uid LIMIT 1");
        $st->execute([':uid' => $certUid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            throw new RuntimeException('CERT_NOT_FOUND_AFTER_ARTIFACT_MINT');
        }

        return $row;
    }
}

if (!function_exists('rwa_cert_auto_mint_hook')) {
    function rwa_cert_auto_mint_hook(array $cert, array $payment): array
    {
        rwa_cert_hook_bootstrap();

        $certUid = trim((string)($cert['cert_uid'] ?? ''));
        if ($certUid === '') {
            return [
                'ok' => false,
                'error' => 'CERT_UID_REQUIRED',
            ];
        }

        if (trim((string)($payment['status'] ?? '')) !== 'confirmed' || (int)($payment['verified'] ?? 0) !== 1) {
            return [
                'ok' => false,
                'error' => 'PAYMENT_NOT_CONFIRMED',
            ];
        }

        // Step 1: run the current real artifact/vault mint pipeline
        $artifactResult = rwa_cert_hook_call_artifact_mint($certUid);

        // Step 2: reload cert after artifact pipeline updated DB paths
        $freshCert = rwa_cert_hook_reload_cert($certUid);

        // Step 3: run real NFT mint executor
        $mintResult = rwa_cert_hook_call_real_executor($freshCert, $payment, $artifactResult);

        if (empty($mintResult['ok'])) {
            return $mintResult;
        }

        $nftItemAddress = trim((string)($mintResult['nft_item_address'] ?? ''));
        if ($nftItemAddress === '') {
            return [
                'ok' => false,
                'error' => 'REAL_MINT_NO_NFT_ITEM_ADDRESS',
            ];
        }

        return [
            'ok' => true,
            'nft_item_address' => $nftItemAddress,
            'collection_address' => (string)($mintResult['collection_address'] ?? ''),
            'tx_hash' => (string)($mintResult['tx_hash'] ?? ($payment['tx_hash'] ?? '')),
            'artifact_result' => $artifactResult,
        ];
    }
}
