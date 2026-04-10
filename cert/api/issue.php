<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/api/issue.php
 * Version: v8.4.0-20260408-unified-payment-model
 *
 * FINAL LOCK
 * - issue.php is Check & Preview / Issue-side gate
 * - strict canonical payment_ref = PAY-XXXXXXXXXXXX
 * - strict deeplink text = payment_ref exactly
 * - canonical payment row + meta_json payment block synced here
 * - no payment verification / no repair / no mint here
 * - bootstrap create/reuse still allowed
 * - hard sufficient balance guard enforced here
 * - unified payment payload model
 * - amount_units computed from decimal amount + token decimals
 * - qr_payload = deeplink
 * - qr_text = deeplink
 */

header('Content-Type: application/json; charset=utf-8');

if (!defined('RWA_CORE_BOOTSTRAPPED')) {
    $bootstrapCandidates = [
        dirname(__DIR__, 2) . '/inc/core/bootstrap.php',
        dirname(__DIR__, 3) . '/rwa/inc/core/bootstrap.php',
        dirname(__DIR__, 3) . '/dashboard/inc/bootstrap.php',
    ];
    $loaded = false;
    foreach ($bootstrapCandidates as $file) {
        if (is_file($file)) {
            require_once $file;
            $loaded = true;
            break;
        }
    }
    if (!$loaded) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => 'BOOTSTRAP_NOT_FOUND',
            'version' => 'v8.4.0-20260408-unified-payment-model',
            'ts' => time(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

const CI_VERSION = 'v8.4.0-20260408-unified-payment-model';
const CI_WEMS_MASTER = 'EQA8dAgNtnsfGF0M-MJfnqii5AhxcRe73M8nCkkxuq85Tr-Q';
const CI_EMA_MASTER  = 'EQDK-bRI706S1cIIoLhTrTf-e8pL2TpOD5rcP3OaxYyzs74b';

function ci_out(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
    exit;
}

function ci_fail(string $error, string $detail = '', int $status = 400, array $extra = []): never
{
    $out = [
        'ok' => false,
        'error' => $error,
        'version' => CI_VERSION,
        'ts' => time(),
    ];
    if ($detail !== '') {
        $out['detail'] = $detail;
    }
    if ($extra) {
        $out += $extra;
    }
    ci_out($out, $status);
}

function ci_req(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $_GET[$key] ?? $default;

    if ($v === $default) {
        $raw = file_get_contents('php://input');
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && array_key_exists($key, $decoded) && is_string($decoded[$key])) {
                $v = $decoded[$key];
            }
        }
    }

    return is_string($v) ? trim($v) : $default;
}

function ci_req_any(array $keys, string $default = ''): string
{
    foreach ($keys as $key) {
        $v = ci_req((string)$key, '');
        if ($v !== '') {
            return $v;
        }
    }
    return $default;
}

function ci_db(): PDO
{
    if (function_exists('rwa_db')) {
        $pdo = rwa_db();
        if ($pdo instanceof PDO) return $pdo;
    }
    if (function_exists('db_connect')) {
        $pdo = db_connect();
        if ($pdo instanceof PDO) return $pdo;
    }
    if (function_exists('db')) {
        $pdo = db();
        if ($pdo instanceof PDO) return $pdo;
    }
    if (($GLOBALS['pdo'] ?? null) instanceof PDO) {
        return $GLOBALS['pdo'];
    }

    $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
    $name = $_ENV['DB_NAME'] ?? 'wems_db';
    $user = $_ENV['DB_USER'] ?? '';
    $pass = $_ENV['DB_PASS'] ?? '';
    $charset = $_ENV['DB_CHARSET'] ?? 'utf8mb4';

    $dsn = "mysql:host={$host};dbname={$name};charset={$charset}";
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function ci_json_decode(?string $json): array
{
    $json = trim((string)$json);
    if ($json === '') return [];
    try {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return is_array($data) ? $data : [];
    } catch (Throwable) {
        return [];
    }
}

function ci_site_url(): string
{
    $base = trim((string)($_ENV['APP_BASE_URL'] ?? 'https://adoptgold.app'));
    return $base !== '' ? rtrim($base, '/') : 'https://adoptgold.app';
}

function ci_upper(string $v): string
{
    return strtoupper(trim($v));
}

function ci_payment_ref(?string $v = null): string
{
    $v = strtoupper(trim((string)$v));
    if ($v !== '' && preg_match('/^PAY-[A-Z0-9]{12}$/', $v)) {
        return $v;
    }
    return 'PAY-' . strtoupper(substr(bin2hex(random_bytes(8)), 0, 12));
}

function ci_token_symbol_for_family(string $family, string $rwaCode = ''): string
{
    $family = strtolower(trim($family));
    $rwaCode = strtoupper(trim($rwaCode));

    if ($family === 'genesis') return 'WEMS';
    if (in_array($family, ['secondary', 'tertiary'], true)) return 'EMA$';

    return str_contains($rwaCode, '-EMA') ? 'EMA$' : 'WEMS';
}

function ci_token_master(string $tokenSymbol): string
{
    $tokenSymbol = strtoupper(trim($tokenSymbol));
    return match ($tokenSymbol) {
        'WEMS' => trim((string)($_ENV['WEMS_JETTON_MASTER'] ?? CI_WEMS_MASTER)),
        'EMA$', 'EMA' => trim((string)($_ENV['EMA_JETTON_MASTER'] ?? CI_EMA_MASTER)),
        default => '',
    };
}

function ci_token_decimals(string $tokenSymbol): int
{
    $tokenSymbol = strtoupper(trim($tokenSymbol));
    return match ($tokenSymbol) {
        'USDT', 'USDT_TON' => 6,
        default => 9,
    };
}

function ci_decimal_to_units(string $amount, int $decimals): string
{
    $amount = trim($amount);

    if ($amount === '' || !preg_match('/^\d+(?:\.\d+)?$/', $amount)) {
        return '0';
    }

    $parts = explode('.', $amount, 2);
    $int = $parts[0] ?? '0';
    $frac = $parts[1] ?? '';

    $frac = str_pad($frac, $decimals, '0');
    $frac = substr($frac, 0, $decimals);

    $units = ltrim($int . $frac, '0');
    return $units === '' ? '0' : $units;
}

function ci_treasury_address(): string
{
    $v = trim((string)($_ENV['TON_TREASURY_ADDRESS'] ?? ($_ENV['TON_TREASURY'] ?? 'UQBdYfGArtoCUmBs5TjYQtfPFfQuGC2Ydbj2pQr3zIlNrDta')));
    return $v;
}

function ci_deeplink(string $destination, string $jettonMaster, string $amountUnits, string $paymentRef): string
{
    $params = [
        'jetton' => $jettonMaster,
        'amount' => $amountUnits,
        'text'   => $paymentRef,
    ];
    return 'ton://transfer/' . rawurlencode($destination) . '?' . http_build_query($params);
}

function ci_qr_image(string $payload): string
{
    $qrFile = __DIR__ . '/_qr-local.php';
    if (is_file($qrFile)) {
        require_once $qrFile;
    }
    try {
        if (function_exists('cert_local_qr_png_data_uri')) {
            return (string)cert_local_qr_png_data_uri($payload, 320, 8);
        }
    } catch (Throwable) {
    }
    return '';
}

function ci_default_amount_for_rwa(string $rwaType): string
{
    $type = strtolower(trim($rwaType));
    return match ($type) {
        'green' => '1000',
        'blue' => '5000',
        'black' => '10000',
        'gold' => '50000',
        'pink', 'red', 'royal_blue', 'yellow' => '100',
        default => '0',
    };
}

function ci_build_payment_payload(
    string $tokenSymbol,
    string $tokenMaster,
    string $destination,
    string $paymentRef,
    string $amountDecimal,
    string $status = 'pending',
    int $verified = 0
): array {
    $tokenSymbol = trim($tokenSymbol);
    $tokenMaster = trim($tokenMaster);
    $destination = trim($destination);
    $paymentRef = trim($paymentRef);
    $amountDecimal = trim($amountDecimal);

    if ($tokenSymbol === '') {
        throw new RuntimeException('PAYMENT_TOKEN_REQUIRED');
    }
    if ($tokenMaster === '') {
        throw new RuntimeException('PAYMENT_TOKEN_MASTER_REQUIRED');
    }
    if ($destination === '') {
        throw new RuntimeException('PAYMENT_DESTINATION_REQUIRED');
    }
    if ($paymentRef === '') {
        throw new RuntimeException('PAYMENT_REF_REQUIRED');
    }
    if ($amountDecimal === '') {
        throw new RuntimeException('PAYMENT_AMOUNT_REQUIRED');
    }

    $decimals = ci_token_decimals($tokenSymbol);
    $amountUnits = ci_decimal_to_units($amountDecimal, $decimals);

    if ($amountUnits === '0' || $amountUnits === '') {
        throw new RuntimeException('INVALID_AMOUNT_UNITS_COMPUTATION');
    }

    $deeplink = ci_deeplink($destination, $tokenMaster, $amountUnits, $paymentRef);
    $qrImage = ci_qr_image($deeplink);

    return [
        'payment_ref' => $paymentRef,
        'token_symbol' => $tokenSymbol,
        'token_master' => $tokenMaster,
        'decimals' => $decimals,
        'amount' => $amountDecimal,
        'amount_units' => $amountUnits,
        'destination' => $destination,
        'deeplink' => $deeplink,
        'wallet_link' => $deeplink,
        'wallet_url' => $deeplink,
        'qr_payload' => $deeplink,
        'qr_text' => $deeplink,
        'qr_image' => $qrImage,
        'status' => strtolower(trim($status)) ?: 'pending',
        'verified' => $verified ? 1 : 0,
    ];
}

function ci_table_columns(PDO $pdo, string $table): array
{
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];

    $st = $pdo->query("SHOW COLUMNS FROM `{$table}`");
    $cols = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cols[(string)$row['Field']] = $row;
    }
    return $cache[$table] = $cols;
}

function ci_existing_columns(PDO $pdo, string $table): array
{
    return array_keys(ci_table_columns($pdo, $table));
}

function ci_detect_rwa_code(array $row): string
{
    $raw = ci_upper((string)($row['rwa_code'] ?? $row['rwa_type'] ?? ''));
    if ($raw !== '' && str_contains($raw, '-EMA')) {
        return $raw;
    }

    $uid = ci_upper((string)($row['cert_uid'] ?? ''));
    $prefix = explode('-', $uid)[0] ?? '';

    return match ($prefix) {
        'RCO2C' => 'RCO2C-EMA',
        'RH2O' => 'RH2O-EMA',
        'RBLACK' => 'RBLACK-EMA',
        'RK92' => 'RK92-EMA',
        'RHRD' => 'RHRD-EMA',
        'RLIFE' => 'RLIFE-EMA',
        'RTRIP' => 'RTRIP-EMA',
        'RPROP' => 'RPROP-EMA',
        default => '',
    };
}

function ci_rwa_meta_from_request(): array
{
    $type = strtolower(ci_req('rwa_type', ''));
    $family = strtolower(ci_req('family', ''));
    $rwaCode = ci_upper(ci_req('rwa_code', ''));

    $map = [
        'green'      => ['family' => 'genesis',   'rwa_code' => 'RCO2C-EMA',  'uid_prefix' => 'RCO2C-EMA',  'price_wems' => '1000',  'price_units' => '1000'],
        'blue'       => ['family' => 'genesis',   'rwa_code' => 'RH2O-EMA',   'uid_prefix' => 'RH2O-EMA',   'price_wems' => '5000',  'price_units' => '5000'],
        'black'      => ['family' => 'genesis',   'rwa_code' => 'RBLACK-EMA', 'uid_prefix' => 'RBLACK-EMA', 'price_wems' => '10000', 'price_units' => '10000'],
        'gold'       => ['family' => 'genesis',   'rwa_code' => 'RK92-EMA',   'uid_prefix' => 'RK92-EMA',   'price_wems' => '50000', 'price_units' => '50000'],
        'pink'       => ['family' => 'secondary', 'rwa_code' => 'RLIFE-EMA',  'uid_prefix' => 'RLIFE-EMA',  'price_wems' => '0',     'price_units' => '100'],
        'red'        => ['family' => 'secondary', 'rwa_code' => 'RTRIP-EMA',  'uid_prefix' => 'RTRIP-EMA',  'price_wems' => '0',     'price_units' => '100'],
        'royal_blue' => ['family' => 'secondary', 'rwa_code' => 'RPROP-EMA',  'uid_prefix' => 'RPROP-EMA',  'price_wems' => '0',     'price_units' => '100'],
        'yellow'     => ['family' => 'tertiary',  'rwa_code' => 'RHRD-EMA',   'uid_prefix' => 'RHRD-EMA',   'price_wems' => '0',     'price_units' => '100'],
    ];

    if ($type !== '' && isset($map[$type])) {
        $m = $map[$type];
        if ($family === '') $family = $m['family'];
        if ($rwaCode === '') $rwaCode = $m['rwa_code'];
        return [
            'rwa_type' => $type,
            'family' => $family,
            'rwa_code' => $rwaCode,
            'uid_prefix' => $m['uid_prefix'],
            'price_wems' => $m['price_wems'],
            'price_units' => $m['price_units'],
        ];
    }

    $codeMap = [
        'RCO2C-EMA'  => ['rwa_type' => 'green',      'family' => 'genesis',   'uid_prefix' => 'RCO2C-EMA',  'price_wems' => '1000',  'price_units' => '1000'],
        'RH2O-EMA'   => ['rwa_type' => 'blue',       'family' => 'genesis',   'uid_prefix' => 'RH2O-EMA',   'price_wems' => '5000',  'price_units' => '5000'],
        'RBLACK-EMA' => ['rwa_type' => 'black',      'family' => 'genesis',   'uid_prefix' => 'RBLACK-EMA', 'price_wems' => '10000', 'price_units' => '10000'],
        'RK92-EMA'   => ['rwa_type' => 'gold',       'family' => 'genesis',   'uid_prefix' => 'RK92-EMA',   'price_wems' => '50000', 'price_units' => '50000'],
        'RLIFE-EMA'  => ['rwa_type' => 'pink',       'family' => 'secondary', 'uid_prefix' => 'RLIFE-EMA',  'price_wems' => '0',     'price_units' => '100'],
        'RTRIP-EMA'  => ['rwa_type' => 'red',        'family' => 'secondary', 'uid_prefix' => 'RTRIP-EMA',  'price_wems' => '0',     'price_units' => '100'],
        'RPROP-EMA'  => ['rwa_type' => 'royal_blue', 'family' => 'secondary', 'uid_prefix' => 'RPROP-EMA',  'price_wems' => '0',     'price_units' => '100'],
        'RHRD-EMA'   => ['rwa_type' => 'yellow',     'family' => 'tertiary',  'uid_prefix' => 'RHRD-EMA',   'price_wems' => '0',     'price_units' => '100'],
    ];

    if ($rwaCode !== '' && isset($codeMap[$rwaCode])) {
        $m = $codeMap[$rwaCode];
        return [
            'rwa_type' => $type !== '' ? $type : $m['rwa_type'],
            'family' => $family !== '' ? $family : $m['family'],
            'rwa_code' => $rwaCode,
            'uid_prefix' => $m['uid_prefix'],
            'price_wems' => $m['price_wems'],
            'price_units' => $m['price_units'],
        ];
    }

    throw new RuntimeException('RWA_TYPE_REQUIRED');
}

function ci_minted_truth_sql(): string
{
    return "
        (
            COALESCE(nft_minted, 0) = 1
            OR LOWER(COALESCE(status, '')) = 'minted'
            OR COALESCE(nft_item_address, '') <> ''
            OR minted_at IS NOT NULL
        )
    ";
}

function ci_generate_uid(string $prefix): string
{
    return sprintf(
        '%s-%s-%s',
        strtoupper($prefix),
        gmdate('Ymd'),
        strtoupper(bin2hex(random_bytes(4)))
    );
}

function ci_is_canonical_cert_uid(string $uid): bool
{
    return (bool)preg_match('/^([A-Z0-9]+(?:-[A-Z0-9]+)+)-(\d{8})-([A-Z0-9]{8})$/', trim($uid));
}

function ci_bootstrap_stub_paths(string $uid): array
{
    $baseRel = '/rwa/metadata/cert/bootstrap/' . rawurlencode($uid);
    return [
        'pdf_path' => $baseRel . '/pdf/pending.pdf',
        'pdf_url' => ci_site_url() . $baseRel . '/pdf/pending.pdf',
        'nft_image_path' => $baseRel . '/nft/image.png',
        'nft_image_url' => ci_site_url() . $baseRel . '/nft/image.png',
        'metadata_path' => $baseRel . '/meta/metadata.json',
        'metadata_url' => ci_site_url() . $baseRel . '/meta/metadata.json',
        'verify_path' => $baseRel . '/verify/verify.json',
        'verify_url' => ci_site_url() . '/rwa/cert/verify.php?uid=' . rawurlencode($uid),
    ];
}

function ci_fill_required_insert_defaults(array $payload, array $columns, string $uid): array
{
    $stub = ci_bootstrap_stub_paths($uid);

    $preferred = [
        'pdf_path' => $stub['pdf_path'],
        'pdf_url' => $stub['pdf_url'],
        'nft_image_path' => $stub['nft_image_path'],
        'nft_image_url' => $stub['nft_image_url'],
        'metadata_path' => $stub['metadata_path'],
        'metadata_url' => $stub['metadata_url'],
        'verify_path' => $stub['verify_path'],
        'verify_url' => $stub['verify_url'],
        'artifact_path' => $stub['verify_path'],
        'artifact_url' => $stub['verify_url'],
        'preview_image_path' => $stub['nft_image_path'],
        'preview_image_url' => $stub['nft_image_url'],
        'preview_url' => $stub['verify_url'],
        'status' => 'issued',
        'meta_json' => json_encode(['bootstrap_created' => true, 'created_via' => 'issue.php'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'price_wems' => '0',
        'price_units' => '0',
        'nft_minted' => 0,
    ];

    foreach ($columns as $name => $info) {
        if (array_key_exists($name, $payload)) continue;

        $nullable = strtoupper((string)($info['Null'] ?? 'YES')) === 'YES';
        $defaultExists = array_key_exists('Default', $info) && $info['Default'] !== null;
        if ($nullable || $defaultExists) continue;

        if (array_key_exists($name, $preferred)) {
            $payload[$name] = $preferred[$name];
            continue;
        }

        $type = strtolower((string)($info['Type'] ?? ''));
        if (str_contains($type, 'int') || str_contains($type, 'decimal') || str_contains($type, 'float') || str_contains($type, 'double')) {
            $payload[$name] = 0;
        } elseif (str_contains($type, 'json') || str_contains($type, 'text') || str_contains($type, 'char') || str_contains($type, 'varchar')) {
            $payload[$name] = '';
        } elseif (str_contains($type, 'date') || str_contains($type, 'time')) {
            $payload[$name] = '1970-01-01 00:00:00';
        } else {
            $payload[$name] = '';
        }
    }

    return $payload;
}

function ci_resolve_owner_user_id(PDO $pdo, int $ownerUserId, string $wallet, string $tonWallet): int
{
    if ($ownerUserId > 0) return $ownerUserId;

    $candidates = array_values(array_filter([
        trim($wallet),
        trim($tonWallet),
    ], fn($v) => $v !== ''));

    if (!$candidates) return 0;

    $sql = "
        SELECT id
        FROM users
        WHERE wallet_address IN (" . implode(',', array_fill(0, count($candidates), '?')) . ")
           OR wallet IN (" . implode(',', array_fill(0, count($candidates), '?')) . ")
        ORDER BY id ASC
        LIMIT 1
    ";
    $st = $pdo->prepare($sql);
    $st->execute(array_merge($candidates, $candidates));
    $id = (int)($st->fetchColumn() ?: 0);

    return $id > 0 ? $id : 0;
}

function ci_find_reusable_pending_cert(PDO $pdo, int $ownerUserId, string $rwaCode): ?array
{
    if ($ownerUserId <= 0 || trim($rwaCode) === '') return null;

    $sql = "
        SELECT c.*
        FROM poado_rwa_certs c
        LEFT JOIN poado_rwa_cert_payments p
          ON p.cert_uid = c.cert_uid
        WHERE c.owner_user_id = :owner_user_id
          AND c.rwa_code = :rwa_code
          AND LOWER(COALESCE(c.status, '')) NOT IN (
                'revoked','blocked','expired','failed','minted','listed'
          )
          AND LOWER(COALESCE(p.status, 'pending')) NOT IN (
                'expired','failed'
          )
        ORDER BY c.id DESC
        LIMIT 1
    ";

    $st = $pdo->prepare($sql);
    $st->execute([
        ':owner_user_id' => $ownerUserId,
        ':rwa_code' => $rwaCode,
    ]);

    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function ci_sync_payment_state(PDO $pdo, array $cert): array
{
    $uid = trim((string)($cert['cert_uid'] ?? ''));
    if ($uid === '') return $cert;

    $family = strtolower(trim((string)($cert['family'] ?? '')));
    $rwaCode = strtoupper(trim((string)($cert['rwa_code'] ?? '')));
    $rwaType = strtolower(trim((string)($cert['rwa_type'] ?? '')));
    $paymentRef = ci_payment_ref((string)($cert['payment_ref'] ?? ''));
    $tokenSymbol = ci_token_symbol_for_family($family, $rwaCode);
    $tokenMaster = ci_token_master($tokenSymbol);
    $destination = ci_treasury_address();

    $meta = ci_json_decode((string)($cert['meta_json'] ?? ''));
    $prevPayment = is_array($meta['payment'] ?? null) ? $meta['payment'] : [];

    $amountDecimal = trim((string)($cert['payment_amount'] ?? ''));
    if ($amountDecimal === '') {
        $amountDecimal = trim((string)($prevPayment['amount'] ?? ''));
    }
    if ($amountDecimal === '') {
        $amountDecimal = ci_default_amount_for_rwa($rwaType);
    }

    $paymentPayload = ci_build_payment_payload(
        tokenSymbol: $tokenSymbol,
        tokenMaster: $tokenMaster,
        destination: $destination,
        paymentRef: $paymentRef,
        amountDecimal: $amountDecimal,
        status: (string)($prevPayment['status'] ?? 'pending'),
        verified: (int)($prevPayment['verified'] ?? 0)
    );

    $meta['payment'] = array_merge($prevPayment, $paymentPayload);

    $certCols = ci_existing_columns($pdo, 'poado_rwa_certs');
    $bind = [':uid' => $uid];
    $sets = [];

    if (in_array('payment_ref', $certCols, true)) {
        $sets[] = 'payment_ref = :payment_ref';
        $bind[':payment_ref'] = $paymentPayload['payment_ref'];
    }
    if (in_array('payment_token', $certCols, true)) {
        $sets[] = 'payment_token = :payment_token';
        $bind[':payment_token'] = $paymentPayload['token_symbol'];
    }
    if (in_array('payment_amount', $certCols, true)) {
        $sets[] = 'payment_amount = :payment_amount';
        $bind[':payment_amount'] = $paymentPayload['amount'];
    }
    if (in_array('meta_json', $certCols, true)) {
        $sets[] = 'meta_json = :meta_json';
        $bind[':meta_json'] = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    if ($sets) {
        $pdo->prepare("UPDATE poado_rwa_certs SET " . implode(', ', $sets) . " WHERE cert_uid = :uid")->execute($bind);
    }

    $payCols = ci_existing_columns($pdo, 'poado_rwa_cert_payments');
    $st = $pdo->prepare("SELECT id FROM poado_rwa_cert_payments WHERE cert_uid = :uid ORDER BY id DESC LIMIT 1");
    $st->execute([':uid' => $uid]);
    $pay = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($pay) {
        $bind = [':id' => (int)$pay['id']];
        $sets = [];

        $updateMap = [
            'payment_ref' => $paymentPayload['payment_ref'],
            'token_symbol' => $paymentPayload['token_symbol'],
            'token_master' => $paymentPayload['token_master'],
            'decimals' => $paymentPayload['decimals'],
            'amount' => $paymentPayload['amount'],
            'amount_units' => $paymentPayload['amount_units'],
        ];

        foreach ($updateMap as $k => $v) {
            if (in_array($k, $payCols, true)) {
                $sets[] = "{$k} = :{$k}";
                $bind[":{$k}"] = $v;
            }
        }

        if ($sets) {
            $pdo->prepare("UPDATE poado_rwa_cert_payments SET " . implode(', ', $sets) . " WHERE id = :id")->execute($bind);
        }
    } else {
        $insertMap = [
            'cert_uid' => $uid,
            'payment_ref' => $paymentPayload['payment_ref'],
            'owner_user_id' => (int)($cert['owner_user_id'] ?? 0),
            'ton_wallet' => (string)($cert['ton_wallet'] ?? ''),
            'token_symbol' => $paymentPayload['token_symbol'],
            'token_master' => $paymentPayload['token_master'],
            'decimals' => $paymentPayload['decimals'],
            'amount' => $paymentPayload['amount'],
            'amount_units' => $paymentPayload['amount_units'],
            'status' => 'pending',
            'verified' => 0,
        ];

        $cols = [];
        $vals = [];
        $bind = [];

        foreach ($insertMap as $k => $v) {
            if (in_array($k, $payCols, true)) {
                $cols[] = "`{$k}`";
                $vals[] = ":{$k}";
                $bind[":{$k}"] = $v;
            }
        }

        if ($cols) {
            $pdo->prepare("INSERT INTO poado_rwa_cert_payments (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")")->execute($bind);
        }
    }

    $cert['payment_ref'] = $paymentPayload['payment_ref'];
    $cert['payment_token'] = $paymentPayload['token_symbol'];
    $cert['payment_amount'] = $paymentPayload['amount'];
    $cert['meta_json'] = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $cert['meta_json_decoded'] = $meta;

    return $cert;
}

function ci_fetch_cert(PDO $pdo, string $certUid): array
{
    $st = $pdo->prepare("
        SELECT *
        FROM poado_rwa_certs
        WHERE cert_uid = :uid
        LIMIT 1
    ");
    $st->execute([':uid' => $certUid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('CERT_NOT_FOUND');
    }
    $row['meta_json_decoded'] = ci_json_decode((string)($row['meta_json'] ?? ''));
    return ci_sync_payment_state($pdo, $row);
}

function ci_current_user_id_from_request_or_cert(array $cert): int
{
    $ownerUserId = (int)($cert['owner_user_id'] ?? 0);
    if ($ownerUserId > 0) return $ownerUserId;

    $requestUserId = (int)(ci_req_any(['owner_user_id', 'user_id'], '0'));
    return max(0, $requestUserId);
}

function ci_count_owner_minted_by_code(PDO $pdo, int $ownerUserId, string $rwaCode): int
{
    if ($ownerUserId <= 0) return 0;

    $sql = "
        SELECT COUNT(*) AS c
        FROM poado_rwa_certs
        WHERE owner_user_id = :owner_user_id
          AND rwa_code = :rwa_code
          AND " . ci_minted_truth_sql();

    $st = $pdo->prepare($sql);
    $st->execute([
        ':owner_user_id' => $ownerUserId,
        ':rwa_code' => $rwaCode,
    ]);
    return (int)($st->fetchColumn() ?: 0);
}

function ci_unlock_status(PDO $pdo, array $cert): array
{
    $rwaCode = ci_detect_rwa_code($cert);
    $ownerUserId = ci_current_user_id_from_request_or_cert($cert);

    $greenMinted = ci_count_owner_minted_by_code($pdo, $ownerUserId, 'RCO2C-EMA');
    $goldMinted  = ci_count_owner_minted_by_code($pdo, $ownerUserId, 'RK92-EMA');

    $blueEligible = ($greenMinted >= 10);
    $blackEligible = ($goldMinted >= 1);

    $requiredRule = 'none';
    $eligible = true;
    $lockReason = '';

    if ($rwaCode === 'RH2O-EMA') {
        $requiredRule = 'requires_10_green_minted';
        $eligible = $blueEligible;
        if (!$eligible) $lockReason = 'RH2O_REQUIRES_10_GREEN_MINTED';
    } elseif ($rwaCode === 'RBLACK-EMA') {
        $requiredRule = 'requires_1_gold_minted';
        $eligible = $blackEligible;
        if (!$eligible) $lockReason = 'RBLACK_REQUIRES_1_GOLD_MINTED';
    }

    return [
        'owner_user_id' => $ownerUserId,
        'target_rwa_code' => $rwaCode,
        'green_minted' => $greenMinted,
        'gold_minted' => $goldMinted,
        'blue_eligible' => $blueEligible,
        'black_eligible' => $blackEligible,
        'required_rule' => $requiredRule,
        'eligible' => $eligible,
        'lock_reason' => $lockReason,
    ];
}

function ci_assert_unlock_rules(PDO $pdo, array $cert): array
{
    $unlock = ci_unlock_status($pdo, $cert);
    if (!$unlock['eligible']) {
        throw new RuntimeException((string)$unlock['lock_reason']);
    }
    return $unlock;
}

function ci_sufficient_helper_url(string $rwaType, int $ownerUserId, string $wallet): string
{
    $qs = ['rwa_type' => $rwaType];
    if ($ownerUserId > 0) {
        $qs['owner_user_id'] = (string)$ownerUserId;
    }
    if (trim($wallet) !== '') {
        $qs['wallet'] = trim($wallet);
    }
    return ci_site_url() . '/rwa/cert/api/check-sufficient.php?' . http_build_query($qs);
}

function ci_assert_sufficient_balance(PDO $pdo, array $cert): array
{
    $rwaType = strtolower(trim((string)($cert['rwa_type'] ?? '')));
    if ($rwaType === '') {
        throw new RuntimeException('RWA_TYPE_REQUIRED');
    }

    $ownerUserId = (int)($cert['owner_user_id'] ?? 0);
    $wallet = trim((string)($cert['wallet_address'] ?? ''));
    $tonWallet = trim((string)($cert['ton_wallet'] ?? ''));

    if ($wallet === '' && $tonWallet !== '') {
        $wallet = $tonWallet;
    }

    if ($ownerUserId <= 0 && $wallet === '') {
        throw new RuntimeException('BALANCE_CONTEXT_REQUIRED');
    }

    $url = ci_sufficient_helper_url($rwaType, $ownerUserId, $wallet);
    $json = @file_get_contents($url);

    if (!is_string($json) || trim($json) === '') {
        throw new RuntimeException('CHECK_SUFFICIENT_FETCH_FAILED');
    }

    $data = json_decode($json, true);
    if (!is_array($data)) {
        throw new RuntimeException('CHECK_SUFFICIENT_JSON_INVALID');
    }

    if (($data['ok'] ?? false) !== true) {
        $err = trim((string)($data['error'] ?? 'CHECK_SUFFICIENT_FAILED'));
        $detail = trim((string)($data['detail'] ?? ''));
        throw new RuntimeException($detail !== '' ? $err . ':' . $detail : $err);
    }

    if (($data['sufficient'] ?? false) !== true) {
        $token = trim((string)($data['token'] ?? ''));
        $required = trim((string)($data['required'] ?? '0'));
        $available = trim((string)($data['available'] ?? '0'));
        $shortfall = trim((string)($data['shortfall'] ?? '0'));
        $detail = "Need {$required} {$token}, have {$available}, shortfall {$shortfall}";
        throw new RuntimeException('INSUFFICIENT_BALANCE:' . $detail);
    }

    return $data;
}

function ci_build_preview_payload(array $cert, array $unlock, array $sufficient): array
{
    $uid = trim((string)($cert['cert_uid'] ?? ''));
    $rwaCode = ci_detect_rwa_code($cert);
    $verifyUrl = ci_site_url() . '/rwa/cert/verify.php?uid=' . rawurlencode($uid);

    $meta = is_array($cert['meta_json_decoded'] ?? null) ? $cert['meta_json_decoded'] : ci_json_decode((string)($cert['meta_json'] ?? ''));
    $payment = is_array($meta['payment'] ?? null) ? $meta['payment'] : [];

    return [
        'cert_uid' => $uid,
        'rwa_code' => $rwaCode,
        'status' => (string)($cert['status'] ?? 'issued'),
        'family' => (string)($cert['family'] ?? ''),
        'owner_user_id' => (int)($cert['owner_user_id'] ?? 0),
        'ton_wallet' => (string)($cert['ton_wallet'] ?? ''),
        'price_wems' => (string)($cert['price_wems'] ?? ''),
        'price_units' => (string)($cert['price_units'] ?? ''),
        'verify_url' => $verifyUrl,
        'payment_text' => trim((string)($payment['amount'] ?? '')) . ' ' . trim((string)($payment['token_symbol'] ?? '')),
        'payment_ref' => (string)($payment['payment_ref'] ?? ($cert['payment_ref'] ?? '')),
        'unlock_rules' => [
            'target_rwa_code' => (string)$unlock['target_rwa_code'],
            'required_rule' => (string)$unlock['required_rule'],
            'green_minted' => (int)$unlock['green_minted'],
            'gold_minted' => (int)$unlock['gold_minted'],
            'blue_eligible' => (bool)$unlock['blue_eligible'],
            'black_eligible' => (bool)$unlock['black_eligible'],
            'eligible' => (bool)$unlock['eligible'],
        ],
        'sufficient_guard' => [
            'rwa_type' => (string)($sufficient['rwa_type'] ?? ''),
            'token' => (string)($sufficient['token'] ?? ''),
            'required' => (string)($sufficient['required'] ?? ''),
            'available' => (string)($sufficient['available'] ?? ''),
            'sufficient' => (bool)($sufficient['sufficient'] ?? false),
            'shortfall' => (string)($sufficient['shortfall'] ?? ''),
            'ton_ready' => (bool)($sufficient['ton_ready'] ?? false),
        ],
        'payment' => [
            'payment_ref' => (string)($payment['payment_ref'] ?? ''),
            'token' => (string)($payment['token_symbol'] ?? ''),
            'token_symbol' => (string)($payment['token_symbol'] ?? ''),
            'token_master' => (string)($payment['token_master'] ?? ''),
            'amount' => (string)($payment['amount'] ?? ''),
            'amount_units' => (string)($payment['amount_units'] ?? ''),
            'destination' => (string)($payment['destination'] ?? ''),
            'deeplink' => (string)($payment['deeplink'] ?? ''),
            'wallet_link' => (string)($payment['wallet_link'] ?? ($payment['wallet_url'] ?? '')),
            'qr_payload' => (string)($payment['qr_payload'] ?? ''),
            'qr_text' => (string)($payment['qr_text'] ?? ''),
            'qr_image' => (string)($payment['qr_image'] ?? ''),
            'status' => (string)($payment['status'] ?? 'pending'),
            'verified' => (int)($payment['verified'] ?? 0),
        ],
    ];
}

function ci_insert_bootstrap_cert(PDO $pdo, array $meta, int $ownerUserId, string $wallet, string $tonWallet): array
{
    $columns = ci_table_columns($pdo, 'poado_rwa_certs');
    $uid = ci_generate_uid((string)($meta['uid_prefix'] ?? $meta['rwa_code'] ?? 'RWA-CERT'));
    $stub = ci_bootstrap_stub_paths($uid);

    $ownerUserId = ci_resolve_owner_user_id($pdo, $ownerUserId, $wallet, $tonWallet);
    if ($ownerUserId <= 0) {
        throw new RuntimeException('OWNER_USER_ID_REQUIRED');
    }

    $payload = [
        'cert_uid' => $uid,
        'rwa_type' => $meta['rwa_type'],
        'family' => strtoupper($meta['family']),
        'rwa_code' => $meta['rwa_code'],
        'owner_user_id' => $ownerUserId,
        'ton_wallet' => $tonWallet !== '' ? $tonWallet : $wallet,
        'wallet_address' => $wallet,
        'status' => 'issued',
        'price_wems' => $meta['price_wems'],
        'price_units' => $meta['price_units'],
        'pdf_path' => $stub['pdf_path'],
        'pdf_url' => $stub['pdf_url'],
        'nft_image_path' => $stub['nft_image_path'],
        'nft_image_url' => $stub['nft_image_url'],
        'metadata_path' => $stub['metadata_path'],
        'metadata_url' => $stub['metadata_url'],
        'verify_path' => $stub['verify_path'],
        'verify_url' => $stub['verify_url'],
        'meta_json' => json_encode([
            'bootstrap_created' => true,
            'created_via' => 'issue.php',
            'bootstrap_stub_paths' => $stub,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];

    $payload = ci_fill_required_insert_defaults($payload, $columns, $uid);

    $insertCols = [];
    $placeholders = [];
    $bind = [];

    foreach ($payload as $col => $value) {
        if (array_key_exists($col, $columns)) {
            $insertCols[] = "`{$col}`";
            $placeholders[] = ":{$col}";
            $bind[":{$col}"] = $value;
        }
    }

    if (!$insertCols) {
        throw new RuntimeException('CERT_INSERT_COLUMN_MAPPING_FAILED');
    }

    $sql = "INSERT INTO poado_rwa_certs (" . implode(', ', $insertCols) . ")
            VALUES (" . implode(', ', $placeholders) . ")";
    $st = $pdo->prepare($sql);
    $st->execute($bind);

    return ci_fetch_cert($pdo, $uid);
}

function ci_resolve_or_bootstrap_cert(PDO $pdo): array
{
    $certUid = trim(ci_req_any(['cert_uid', 'uid', 'cert'], ''));

    if ($certUid !== '') {
        if (ci_is_canonical_cert_uid($certUid)) {
            try {
                return ci_fetch_cert($pdo, $certUid);
            } catch (Throwable $e) {
                if ($e->getMessage() !== 'CERT_NOT_FOUND') {
                    throw $e;
                }
            }
        }
    }

    $meta = ci_rwa_meta_from_request();
    $ownerUserId = (int)ci_req_any(['owner_user_id', 'user_id'], '0');
    $wallet = ci_req_any(['wallet', 'wallet_address'], '');
    $tonWallet = ci_req_any(['ton_wallet', 'wallet', 'wallet_address'], '');

    if ($ownerUserId <= 0 && $wallet === '' && $tonWallet === '') {
        throw new RuntimeException('CERT_BOOTSTRAP_CONTEXT_REQUIRED');
    }

    $ownerUserId = ci_resolve_owner_user_id($pdo, $ownerUserId, $wallet, $tonWallet);

    $existing = ci_find_reusable_pending_cert($pdo, $ownerUserId, $meta['rwa_code']);
    if (is_array($existing)) {
        return ci_fetch_cert($pdo, (string)$existing['cert_uid']);
    }

    return ci_insert_bootstrap_cert($pdo, $meta, $ownerUserId, $wallet, $tonWallet);
}

try {
    $pdo = ci_db();
    $cert = ci_resolve_or_bootstrap_cert($pdo);
    $unlock = ci_assert_unlock_rules($pdo, $cert);
    $sufficient = ci_assert_sufficient_balance($pdo, $cert);
    $preview = ci_build_preview_payload($cert, $unlock, $sufficient);

    ci_out([
        'ok' => true,
        'version' => CI_VERSION,
        'ts' => time(),
        'check_preview_enabled' => true,
        'issue_pay_enabled' => true,
        'cert_uid' => $preview['cert_uid'],
        'uid' => $preview['cert_uid'],
        'cert' => $preview['cert_uid'],
        'preview' => $preview,
        'preview_row' => [
            'cert_uid' => $preview['cert_uid'],
            'rwa_type' => (string)($cert['rwa_type'] ?? ''),
            'family' => strtolower((string)($cert['family'] ?? '')),
            'rwa_code' => $preview['rwa_code'],
            'status' => (string)($cert['status'] ?? 'issued'),
            'owner_user_id' => (int)($cert['owner_user_id'] ?? 0),
            'ton_wallet' => (string)($cert['ton_wallet'] ?? ''),
            'price_wems' => (string)($cert['price_wems'] ?? ''),
            'price_units' => (string)($cert['price_units'] ?? ''),
            'payment_ref' => (string)($preview['payment']['payment_ref'] ?? ''),
            'payment_token' => (string)($preview['payment']['token_symbol'] ?? ''),
            'payment_amount' => (string)($preview['payment']['amount'] ?? ''),
            'queue_bucket' => 'issuance_factory',
            'sections' => [
                'rwa_factory' => true,
                'mint_ready_queue' => false,
                'minted' => false,
            ],
            'verify_url' => $preview['verify_url'],
        ],
    ]);
} catch (Throwable $e) {
    $msg = $e->getMessage();
    $status = 500;

    if ($msg === 'CERT_NOT_FOUND') {
        $status = 404;
    } elseif ($msg === 'RWA_TYPE_REQUIRED') {
        $status = 422;
    } elseif ($msg === 'CERT_BOOTSTRAP_CONTEXT_REQUIRED') {
        $status = 422;
    } elseif ($msg === 'BALANCE_CONTEXT_REQUIRED') {
        $status = 422;
    } elseif (str_starts_with($msg, 'INSUFFICIENT_BALANCE:')) {
        $status = 409;
    } elseif (in_array($msg, [
        'RH2O_REQUIRES_10_GREEN_MINTED',
        'RBLACK_REQUIRES_1_GOLD_MINTED',
    ], true)) {
        $status = 409;
    }

    $lockState = [
        'check_preview_enabled' => false,
        'issue_pay_enabled' => false,
    ];

    if ($msg === 'RH2O_REQUIRES_10_GREEN_MINTED') {
        ci_fail('CHECK_PREVIEW_LOCKED', $msg, $status, $lockState + [
            'unlock_rules' => ['required_rule' => 'requires_10_green_minted'],
        ]);
    }

    if ($msg === 'RBLACK_REQUIRES_1_GOLD_MINTED') {
        ci_fail('CHECK_PREVIEW_LOCKED', $msg, $status, $lockState + [
            'unlock_rules' => ['required_rule' => 'requires_1_gold_minted'],
        ]);
    }

    if (str_starts_with($msg, 'INSUFFICIENT_BALANCE:')) {
        ci_fail('INSUFFICIENT_BALANCE', substr($msg, strlen('INSUFFICIENT_BALANCE:')), $status, $lockState);
    }

    if ($msg === 'RWA_TYPE_REQUIRED') {
        ci_fail('ISSUE_FAILED', 'rwa_type / rwa_code bootstrap context required', $status, $lockState);
    }

    if ($msg === 'CERT_BOOTSTRAP_CONTEXT_REQUIRED') {
        ci_fail('ISSUE_FAILED', 'owner_user_id or wallet bootstrap context required', $status, $lockState);
    }

    if ($msg === 'BALANCE_CONTEXT_REQUIRED') {
        ci_fail('ISSUE_FAILED', 'owner_user_id or wallet required for sufficient guard', $status, $lockState);
    }

    if ($msg === 'OWNER_USER_ID_REQUIRED') {
        ci_fail('ISSUE_FAILED', 'owner_user_id required or wallet must map to users.id', 422, $lockState);
    }

    ci_fail('ISSUE_FAILED', $msg, $status, $lockState);
}
