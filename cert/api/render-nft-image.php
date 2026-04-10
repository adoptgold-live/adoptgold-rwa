<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/api/render-nft-image.php
 *
 * RWA Cert PDF -> PNG NFT image engine
 *
 * Supports:
 * - CLI:
 *     php /var/www/html/public/rwa/cert/api/render-nft-image.php --cert_uid=RK92-EMA-20260327-REAL0001
 *
 * - HTTP POST JSON:
 *     { "cert_uid": "RK92-EMA-20260327-REAL0001", "csrf_token": "..." }
 *
 * Rendering priority:
 * 1) Imagick PDF raster
 * 2) pdftoppm fallback
 *
 * Output:
 * - /var/www/html/public/rwa/metadata/cert/U{USER_ID}/{CERT_UID}/image.png
 * - updates public metadata.json image field
 * - updates poado_rwa_certs.meta_json image field
 */

require_once __DIR__ . '/../../inc/core/bootstrap.php';

if (!function_exists('json_ok')) {
    function json_ok(array $data = [], int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('json_fail')) {
    function json_fail(string $message, int $status = 400, array $extra = []): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $message] + $extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function render_nft_image_is_cli(): bool
{
    return PHP_SAPI === 'cli';
}

function render_nft_image_input(): array
{
    if (render_nft_image_is_cli()) {
        global $argv;
        $out = [];
        foreach ((array)$argv as $arg) {
            if (strpos((string)$arg, '--') === 0) {
                $pair = explode('=', substr((string)$arg, 2), 2);
                $out[$pair[0]] = $pair[1] ?? '1';
            }
        }
        return $out;
    }

    $raw = file_get_contents('php://input');
    $json = [];
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $json = $decoded;
        }
    }
    return array_merge($_GET ?? [], $_POST ?? [], $json);
}

function render_nft_image_require_post(): void
{
    if (render_nft_image_is_cli()) {
        return;
    }
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'POST') {
        json_fail('Method not allowed', 405);
    }
}

function render_nft_image_require_auth(): void
{
    if (render_nft_image_is_cli()) {
        return;
    }

    $user = null;
    if (function_exists('session_user')) {
        $user = session_user();
    } elseif (function_exists('get_wallet_session')) {
        $user = get_wallet_session();
    }

    if (!is_array($user) || empty($user)) {
        json_fail('Authentication required', 401);
    }

    $role = strtolower((string)($user['role'] ?? ''));
    $isAdmin = (int)($user['is_admin'] ?? 0);
    $isSenior = (int)($user['is_senior'] ?? 0);
    if (!($isAdmin === 1 || $isSenior === 1 || $role === 'admin')) {
        json_fail('Admin or senior permission required', 403);
    }
}

function render_nft_image_require_csrf(array $input): void
{
    if (render_nft_image_is_cli()) {
        return;
    }

    $token = (string)($input['csrf_token'] ?? '');
    if ($token === '') {
        json_fail('Missing CSRF token', 419);
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        json_fail('Session unavailable for CSRF validation', 500);
    }

    $sessionToken = (string)($_SESSION['csrf_token_rwa_ton_mint'] ?? '');
    if ($sessionToken === '' || !hash_equals($sessionToken, $token)) {
        json_fail('Invalid CSRF token', 419);
    }
}

function render_nft_image_pdo(): PDO
{
    if (function_exists('rwa_db')) {
        return rwa_db();
    }
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        return $GLOBALS['pdo'];
    }
    json_fail('Database handle unavailable', 500);
}

function render_nft_image_load_cert(PDO $pdo, string $certUid): array
{
    $stmt = $pdo->prepare("SELECT * FROM poado_rwa_certs WHERE cert_uid = ? LIMIT 1");
    $stmt->execute([$certUid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        json_fail('Certificate not found', 404, ['cert_uid' => $certUid]);
    }
    return $row;
}

function render_nft_image_app_base_url(): string
{
    $candidates = [
        getenv('APP_BASE_URL') ?: null,
        $_ENV['APP_BASE_URL'] ?? null,
        $_SERVER['APP_BASE_URL'] ?? null,
        'https://adoptgold.app',
    ];
    foreach ($candidates as $v) {
        if (is_string($v) && trim($v) !== '') {
            return rtrim($v, '/');
        }
    }
    return 'https://adoptgold.app';
}

function render_nft_image_temp_file(string $ext): string
{
    $path = sys_get_temp_dir() . '/rwa-nft-' . bin2hex(random_bytes(8)) . '.' . ltrim($ext, '.');
    return $path;
}

function render_nft_image_fetch_pdf(string $url): string
{
    $pdfTmp = render_nft_image_temp_file('pdf');
    $context = stream_context_create([
        'http' => [
            'timeout' => 45,
            'ignore_errors' => true,
            'header' => "Accept: application/pdf\r\nUser-Agent: AdoptGold-RWA-ImageEngine/1.0\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    if ($body === false || strlen($body) < 128) {
        json_fail('Unable to fetch PDF for rendering', 422, ['pdf_url' => $url]);
    }

    file_put_contents($pdfTmp, $body);
    return $pdfTmp;
}

function render_nft_image_binary_exists(string $bin): bool
{
    $cmd = 'command -v ' . escapeshellarg($bin) . ' 2>/dev/null';
    $out = shell_exec($cmd);
    return is_string($out) && trim($out) !== '';
}

function render_nft_image_render_imagick(string $pdfPath): ?array
{
    if (!extension_loaded('imagick')) {
        return null;
    }

    $pngTmp = render_nft_image_temp_file('png');

    try {
        $im = new Imagick();
        $im->setResolution(220, 220);
        $im->readImage($pdfPath . '[0]');
        $im->setImageFormat('png');
        $im->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
        $im->setImageBackgroundColor('white');
        $flat = $im->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
        $flat->writeImage($pngTmp);
        $flat->clear();
        $im->clear();

        if (!is_file($pngTmp) || filesize($pngTmp) < 1024) {
            return null;
        }

        return [
            'renderer' => 'imagick',
            'png_tmp' => $pngTmp,
        ];
    } catch (Throwable $e) {
        return null;
    }
}

function render_nft_image_render_pdftoppm(string $pdfPath): ?array
{
    if (!render_nft_image_binary_exists('pdftoppm')) {
        return null;
    }

    $base = sys_get_temp_dir() . '/rwa-nft-' . bin2hex(random_bytes(8));
    $cmd = 'pdftoppm -png -f 1 -singlefile ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($base) . ' 2>&1';
    exec($cmd, $out, $code);
    $pngTmp = $base . '.png';

    if ($code !== 0 || !is_file($pngTmp) || filesize($pngTmp) < 1024) {
        return null;
    }

    return [
        'renderer' => 'pdftoppm',
        'png_tmp' => $pngTmp,
        'stdout' => trim(implode("\n", $out)),
    ];
}

function render_nft_image_load_gd(string $src)
{
    $blob = @file_get_contents($src);
    if ($blob === false) {
        return false;
    }
    return @imagecreatefromstring($blob);
}

function render_nft_image_square_fit(string $srcPng, string $destPng, int $canvas = 1080, int $padding = 40): void
{
    $src = render_nft_image_load_gd($srcPng);
    if (!$src) {
        json_fail('Unable to load rasterized PNG', 500, ['source_png' => $srcPng]);
    }

    $srcW = imagesx($src);
    $srcH = imagesy($src);

    $inner = $canvas - ($padding * 2);
    $ratio = min($inner / max(1, $srcW), $inner / max(1, $srcH));
    $dstW = max(1, (int)floor($srcW * $ratio));
    $dstH = max(1, (int)floor($srcH * $ratio));
    $dstX = (int)floor(($canvas - $dstW) / 2);
    $dstY = (int)floor(($canvas - $dstH) / 2);

    $im = imagecreatetruecolor($canvas, $canvas);
    if (!$im) {
        imagedestroy($src);
        json_fail('Unable to create destination image', 500);
    }

    imagealphablending($im, true);
    imagesavealpha($im, false);
    $bg = imagecolorallocate($im, 5, 6, 10);
    imagefilledrectangle($im, 0, 0, $canvas, $canvas, $bg);

    imagecopyresampled($im, $src, $dstX, $dstY, 0, 0, $dstW, $dstH, $srcW, $srcH);

    if (!imagepng($im, $destPng, 8)) {
        imagedestroy($src);
        imagedestroy($im);
        json_fail('Unable to write output PNG', 500, ['dest_png' => $destPng]);
    }

    imagedestroy($src);
    imagedestroy($im);
}

function render_nft_image_update_public_metadata(string $metadataFile, string $imageUrl): array
{
    if (!is_file($metadataFile)) {
        return [
            'ok' => false,
            'mode' => 'metadata_missing',
            'metadata_file' => $metadataFile,
        ];
    }

    $raw = file_get_contents($metadataFile);
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) {
        return [
            'ok' => false,
            'mode' => 'metadata_invalid_json',
            'metadata_file' => $metadataFile,
        ];
    }

    $data['image'] = $imageUrl;
    file_put_contents(
        $metadataFile,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL
    );

    return [
        'ok' => true,
        'metadata_file' => $metadataFile,
        'image' => $imageUrl,
    ];
}

function render_nft_image_update_db(PDO $pdo, array $cert, string $imageUrl): void
{
    $metaJson = [];
    if (!empty($cert['meta_json'])) {
        $decoded = json_decode((string)$cert['meta_json'], true);
        if (is_array($decoded)) {
            $metaJson = $decoded;
        }
    }

    $metaJson['image'] = $imageUrl;

    $stmt = $pdo->prepare("
        UPDATE poado_rwa_certs
        SET
            meta_json = ?,
            updated_at = UTC_TIMESTAMP()
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([
        json_encode($metaJson, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        (int)$cert['id'],
    ]);
}

$input = render_nft_image_input();
render_nft_image_require_post();
render_nft_image_require_auth();
render_nft_image_require_csrf($input);

$certUid = trim((string)($input['cert_uid'] ?? ''));
if ($certUid === '') {
    json_fail('Missing cert_uid', 422);
}

$pdo = render_nft_image_pdo();
$cert = render_nft_image_load_cert($pdo, $certUid);

$userId = (int)$cert['owner_user_id'];
$appBaseUrl = render_nft_image_app_base_url();

$rwaRoot = dirname(__DIR__, 2);
$publicRoot = dirname($rwaRoot);

$publicMetadataDir = $publicRoot . '/rwa/metadata/cert/U' . $userId . '/' . $certUid;
$publicMetadataFile = $publicMetadataDir . '/metadata.json';
$publicImageFile = $publicMetadataDir . '/image.png';
$publicImageUrl = $appBaseUrl . '/rwa/metadata/cert/U' . $userId . '/' . rawurlencode($certUid) . '/image.png';
$pdfUrl = $appBaseUrl . '/rwa/cert/pdf.php?cert_uid=' . rawurlencode($certUid);

if (!is_dir($publicMetadataDir) && !mkdir($publicMetadataDir, 0775, true) && !is_dir($publicMetadataDir)) {
    json_fail('Unable to create public metadata directory', 500, ['dir' => $publicMetadataDir]);
}

$pdfTmp = render_nft_image_fetch_pdf($pdfUrl);

$rendered = render_nft_image_render_imagick($pdfTmp);
if (!$rendered) {
    $rendered = render_nft_image_render_pdftoppm($pdfTmp);
}
if (!$rendered) {
    json_fail('No PDF renderer available. Need Imagick extension or pdftoppm binary.', 500);
}

render_nft_image_square_fit($rendered['png_tmp'], $publicImageFile, 1080, 40);
$metadataUpdate = render_nft_image_update_public_metadata($publicMetadataFile, $publicImageUrl);
render_nft_image_update_db($pdo, $cert, $publicImageUrl);

@unlink($pdfTmp);
if (!empty($rendered['png_tmp']) && is_file($rendered['png_tmp'])) {
    @unlink($rendered['png_tmp']);
}

json_ok([
    'cert_uid' => $certUid,
    'renderer' => $rendered['renderer'],
    'pdf_url' => $pdfUrl,
    'image_path' => $publicImageFile,
    'image_url' => $publicImageUrl,
    'metadata_update' => $metadataUpdate,
]);
