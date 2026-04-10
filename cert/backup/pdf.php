<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/pdf.php
 * v3.0.20260307-rwa-cert-intl-blue-rh2o
 *
 * Locked rules:
 * - single-page only
 * - A4 landscape only
 * - issuer fixed
 * - QR + barcode + NFT hash mandatory
 * - one-page institutional RWA cert
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

$autoload = '/var/www/html/public/dashboard/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    exit('Composer autoload not found: ' . $autoload);
}
require_once $autoload;

$qrHelper = '/var/www/html/public/dashboard/inc/qr.php';
if (is_file($qrHelper)) {
    require_once $qrHelper;
}

date_default_timezone_set('UTC');

function h(?string $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function pick(array $src, string $key, string $default = ''): string {
    $v = $src[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function uid_make(string $prefix): string {
    $prefix = strtoupper(trim($prefix));
    $prefix = preg_replace('/[^A-Z]/', '', $prefix) ?: 'GC';
    return $prefix . '-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
}

function sha256u(string $s): string {
    return strtoupper(hash('sha256', $s));
}

function verify_url(string $uid): string {
    return 'https://adoptgold.app/rwa/cert/verify.php?uid=' . rawurlencode($uid);
}

function qr_uri_safe(string $url): string {
    if (function_exists('poado_qr_svg_data_uri')) {
        try {
            $u = poado_qr_svg_data_uri($url);
            if (is_string($u) && $u !== '') return $u;
        } catch (Throwable $e) {}
    }
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="240" height="240" viewBox="0 0 240 240">'
         . '<rect width="240" height="240" fill="#fff"/>'
         . '<rect x="10" y="10" width="220" height="220" fill="none" stroke="#000" stroke-width="4"/>'
         . '<rect x="20" y="20" width="48" height="48" fill="#000"/>'
         . '<rect x="172" y="20" width="48" height="48" fill="#000"/>'
         . '<rect x="20" y="172" width="48" height="48" fill="#000"/>'
         . '</svg>';
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

function code128_svg_uri(string $text, int $height = 72): string {
    $patterns = [
        "212222","222122","222221","121223","121322","131222","122213","122312","132212","221213",
        "221312","231212","112232","122132","122231","113222","123122","123221","223211","221132",
        "221231","213212","223112","312131","311222","321122","321221","312212","322112","322211",
        "212123","212321","232121","111323","131123","131321","112313","132113","132311","211313",
        "231113","231311","112133","112331","132131","113123","113321","133121","313121","211331",
        "231131","213113","213311","213131","311123","311321","331121","312113","312311","332111",
        "314111","221411","431111","111224","111422","121124","121421","141122","141221","112214",
        "112412","122114","122411","142112","142211","241211","221114","413111","241112","134111",
        "111242","121142","121241","114212","124112","124211","411212","421112","421211","212141",
        "214121","412121","111143","111341","131141","114113","114311","411113","411311","113141",
        "114131","311141","411131","211412","211214","211232","2331112"
    ];
    $startB = 104; $stop = 106;
    $vals = [];
    for ($i = 0; $i < strlen($text); $i++) {
        $o = ord($text[$i]);
        if ($o < 32 || $o > 126) $o = 32;
        $vals[] = $o - 32;
    }
    $checksum = $startB;
    foreach ($vals as $i => $v) $checksum += ($i + 1) * $v;
    $checksum %= 103;
    $seq = array_merge([$startB], $vals, [$checksum, $stop]);

    $mods = [];
    foreach ($seq as $code) {
        $p = $patterns[$code] ?? '212222';
        foreach (str_split($p) as $d) $mods[] = (int) $d;
    }

    $unit = 2;
    $quiet = 12;
    $width = ($quiet * 2 + array_sum($mods)) * $unit;
    $svgH = $height + 18;
    $x = $quiet * $unit;
    $bar = true;

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $svgH . '" viewBox="0 0 ' . $width . ' ' . $svgH . '">';
    $svg .= '<rect width="' . $width . '" height="' . $svgH . '" fill="#ffffff"/>';
    foreach ($mods as $m) {
        $w = $m * $unit;
        if ($bar) $svg .= '<rect x="' . $x . '" y="0" width="' . $w . '" height="' . $height . '" fill="#111111"/>';
        $x += $w;
        $bar = !$bar;
    }
    $svg .= '<text x="' . ($width / 2) . '" y="' . ($height + 14) . '" text-anchor="middle" font-family="DejaVu Sans, sans-serif" font-size="12" fill="#111111" letter-spacing="1">' . h($text) . '</text>';
    $svg .= '</svg>';
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

function seal_svg_uri(string $accent, string $code): string {
    $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="220" height="220" viewBox="0 0 220 220">
  <circle cx="110" cy="110" r="98" fill="none" stroke="{$accent}" stroke-width="5"/>
  <circle cx="110" cy="110" r="82" fill="none" stroke="{$accent}" stroke-width="1.6" stroke-dasharray="3 3"/>
  <circle cx="110" cy="110" r="66" fill="none" stroke="{$accent}" stroke-width="1.2"/>
  <path d="M110 48 L120 88 L162 98 L130 122 L140 164 L110 144 L80 164 L90 122 L58 98 L100 88 Z"
        fill="{$accent}" fill-opacity="0.10" stroke="{$accent}" stroke-width="1.5"/>
  <text x="110" y="90" text-anchor="middle" font-family="DejaVu Sans, sans-serif" font-size="15" fill="{$accent}" font-weight="700">DIGITAL SEAL</text>
  <text x="110" y="108" text-anchor="middle" font-family="DejaVu Sans, sans-serif" font-size="12" fill="{$accent}">BLOCKCHAIN GROUP LTD.</text>
  <text x="110" y="124" text-anchor="middle" font-family="DejaVu Sans, sans-serif" font-size="11" fill="{$accent}">HONG KONG · RSO</text>
  <text x="110" y="149" text-anchor="middle" font-family="DejaVu Sans, sans-serif" font-size="23" fill="{$accent}" font-weight="700">{$code}</text>
</svg>
SVG;
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

function logo_svg_uri(string $accent): string {
    $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="72" height="72" viewBox="0 0 72 72">
  <circle cx="36" cy="36" r="33" fill="#111111" stroke="{$accent}" stroke-width="2.2"/>
  <circle cx="36" cy="36" r="24" fill="none" stroke="{$accent}" stroke-width="1.3" stroke-dasharray="2.2 2.2"/>
  <path d="M22 42c5-13 23-13 28 0" fill="none" stroke="{$accent}" stroke-width="2.6" stroke-linecap="round"/>
  <path d="M24 28h24" stroke="{$accent}" stroke-width="2.2" stroke-linecap="round"/>
  <text x="36" y="52" text-anchor="middle" font-size="10" font-family="DejaVu Sans, sans-serif" fill="{$accent}" font-weight="700">RSO</text>
</svg>
SVG;
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

function theme_map(string $type): array {
    $type = strtoupper(trim($type));
    $themes = [
        'GCN' => [
            'prefix'=>'GCN','title'=>'GREEN CERTIFICATE','subtitle'=>'Carbon Responsibility Adoption Record',
            'asset_class'=>'Green Cert / Carbon Responsibility',
            'unit_rule'=>'1 Carbon Responsibility Unit per certificate record.',
            'primary'=>'#0B4C2D','panel'=>'#145F39','highlight'=>'#2EE887','foil'=>'#D8FFE8','paper'=>'#0F241B','paper2'=>'#122B20','code'=>'GCN'
        ],
        'HC' => [
            'prefix'=>'HC','title'=>'HEALTH CERTIFICATE','subtitle'=>'Health Monitoring Right Adoption Record',
            'asset_class'=>'Health Cert / Health Monitoring Right',
            'unit_rule'=>'1 Health Monitoring Right per certificate record.',
            'primary'=>'#531532','panel'=>'#671D40','highlight'=>'#FF78B2','foil'=>'#FFDCEE','paper'=>'#24131E','paper2'=>'#2E1825','code'=>'HC'
        ],
        'GC' => [
            'prefix'=>'GC','title'=>'GOLD CERTIFICATE','subtitle'=>'Gold Mining Responsibility Adoption Record',
            'asset_class'=>'Gold Cert / Gold Mining Responsibility',
            'unit_rule'=>'1 Gold Mining Responsibility Unit per certificate record.',
            'primary'=>'#3A2A04','panel'=>'#4A3507','highlight'=>'#F4C542','foil'=>'#FFF2C7','paper'=>'#221A0A','paper2'=>'#2B2110','code'=>'GC'
        ],
        'BC' => [
            'prefix'=>'BC','title'=>'BLUE WATER CERTIFICATE','subtitle'=>'RH2O Clean Water Responsibility Record',
            'asset_class'=>'Blue Cert / RH2O Clean Water Responsibility',
            'unit_rule'=>'Every 100L of Clean Water = 1 Clean Water Unit.',
            'primary'=>'#072741','panel'=>'#0A3456','highlight'=>'#63D6FF','foil'=>'#D8F4FF','paper'=>'#0C1B29','paper2'=>'#122638','code'=>'BC'
        ],
        'MULTI' => [
            'prefix'=>'GC','title'=>'RWA CERTIFICATE','subtitle'=>'Institutional Responsibility Adoption Record',
            'asset_class'=>'RWA Certificate / Multi-Demo',
            'unit_rule'=>'Locked by certificate type and associated adoption record.',
            'primary'=>'#201430','panel'=>'#2B1841','highlight'=>'#A66CFF','foil'=>'#EEE2FF','paper'=>'#171124','paper2'=>'#211630','code'=>'RWA'
        ],
    ];
    return $themes[$type] ?? $themes['GC'];
}

function blockchain_ref(string $uid): string {
    return 'ANCHOR-' . substr(sha256u('POADO-ANCHOR|' . $uid), 0, 24);
}

$type   = strtoupper(pick($_GET, 'type', pick($_POST, 'type', 'GC')));
$holder = pick($_GET, 'holder', pick($_POST, 'holder', 'Sample Holder'));
$holder = $holder !== '' ? mb_substr($holder, 0, 72) : 'Sample Holder';

$theme = theme_map($type);
$uid = uid_make($theme['prefix']);
$issuedAt = gmdate('Y-m-d H:i:s') . ' UTC';
$verifyUrl = verify_url($uid);
$nftHash = sha256u('POADO-NFT|' . $uid);
$certHash = sha256u($uid . '|' . $holder . '|' . $issuedAt);
$barcodeUri = code128_svg_uri($uid, 72);
$qrUri = qr_uri_safe($verifyUrl);
$sealUri = seal_svg_uri($theme['highlight'], $theme['code']);
$logoUri = logo_svg_uri($theme['highlight']);
$blockRef = blockchain_ref($uid);

$statement = 'This certificate confirms the responsibility adoption record referenced by the certificate UID above. '
    . 'Verification is available through the embedded QR code and the verification URL. '
    . 'The document remains a single-page institutional-grade RWA certificate under the locked POAdo certificate standard.';

$disclaimer = 'Marketing / demonstration certificate only. Not a commodity title, not a financial product, '
    . 'not a yield instrument, and not an offer of investment return.';

$fileName = strtolower($theme['prefix']) . '-' . strtolower(str_replace(' ', '-', preg_replace('/[^A-Za-z0-9\- ]/', '', $holder)));
$fileName = trim($fileName, '-');
$fileName = ($fileName !== '' ? $fileName : 'poado-cert') . '.pdf';

$html = '<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>' . h($uid) . '</title>
<style>
@page { size: A4 landscape; margin: 0; }

html, body {
  margin:0; padding:0; width:297mm; height:210mm;
  font-family: DejaVu Sans, sans-serif; background:#f5f2ea;
}
* { box-sizing:border-box; }

body { background:#f5f2ea; color:#f7f2e8; }

.page{
  position:relative;
  width:1080px;
  height:720px;
  margin:14px auto 0 auto;
  overflow:hidden;
  background:linear-gradient(180deg,' . $theme['paper'] . ' 0%,' . $theme['paper2'] . ' 100%);
  border:3px solid ' . $theme['highlight'] . ';
}
.frame1{ position:absolute; inset:8px; border:1px solid ' . $theme['foil'] . '; }
.frame2{ position:absolute; inset:16px; border:1px solid rgba(255,255,255,.35); }
.corner{ position:absolute; width:64px; height:64px; border-color:' . $theme['highlight'] . '; }
.c1{ top:14px; left:14px; border-top:3px solid; border-left:3px solid; }
.c2{ top:14px; right:14px; border-top:3px solid; border-right:3px solid; }
.c3{ bottom:14px; left:14px; border-bottom:3px solid; border-left:3px solid; }
.c4{ bottom:14px; right:14px; border-bottom:3px solid; border-right:3px solid; }

.guilloche{
  position:absolute; inset:30px;
  background:
    repeating-radial-gradient(circle at center, rgba(255,255,255,.025) 0 1px, transparent 1px 10px),
    repeating-linear-gradient(0deg, rgba(255,255,255,.02) 0 1px, transparent 1px 14px),
    repeating-linear-gradient(90deg, rgba(255,255,255,.015) 0 1px, transparent 1px 11px);
}
.top-strip{
  position:absolute; top:28px; left:34px; right:34px; height:6px;
  background:' . $theme['highlight'] . ';
}
.header{
  position:absolute; top:42px; left:34px; right:34px; height:112px;
  border:1px solid rgba(255,255,255,.28); background:#161616;
}
.header-left{ position:absolute; left:18px; top:16px; width:270px; height:78px; }
.header-center{ position:absolute; left:300px; right:250px; top:14px; text-align:center; }
.header-right{ position:absolute; right:18px; top:16px; width:220px; text-align:right; }

.issuer{ font-size:12px; line-height:1.38; color:#EEE7DA; }
.title{ font-size:33px; font-weight:700; letter-spacing:1.2px; color:#FFFFFF; margin-top:2px; }
.subtitle{ margin-top:5px; font-size:13px; color:' . $theme['foil'] . '; letter-spacing:.4px; }
.uid-label{ font-size:10px; letter-spacing:1.5px; color:#D6CFBF; margin-bottom:6px; }
.uid{ font-size:20px; line-height:1.15; color:' . $theme['highlight'] . '; font-weight:700; }

.main-left{
  position:absolute; top:170px; left:34px; width:712px; height:446px;
  border:1px solid rgba(255,255,255,.30); background:#1A1A1A; padding:18px 20px;
}
.main-right{
  position:absolute; top:170px; right:34px; width:300px; height:446px;
  border:1px solid rgba(255,255,255,.30); background:#1A1A1A; padding:14px;
}
.section-cap{ font-size:10px; letter-spacing:1.7px; color:' . $theme['highlight'] . '; margin-bottom:10px; }

.meta-table{ width:100%; border-collapse:collapse; table-layout:fixed; }
.meta-table td{ vertical-align:top; border-bottom:1px solid rgba(255,255,255,.10); padding:9px 6px; }
.meta-label{ width:150px; color:#CFC7B8; font-size:10px; letter-spacing:1.1px; text-transform:uppercase; }
.meta-value{ color:#FFFFFF; font-size:15px; line-height:1.36; }
.holder{ font-size:26px; font-weight:700; color:#FFFFFF; }
.hash-small{ font-size:11.2px; line-height:1.35; word-break:break-all; color:#F1E8D6; }

.statement-box{
  margin-top:16px; border:1px solid rgba(255,255,255,.18); background:#111111; padding:14px 14px 12px 14px;
}
.statement-title{ font-size:10px; color:' . $theme['highlight'] . '; letter-spacing:1.4px; margin-bottom:7px; }
.statement{ font-size:14px; line-height:1.5; color:#F7F2E8; text-align:justify; }

.sign-wrap{ position:absolute; left:20px; right:20px; bottom:16px; height:78px; }
.sign-left{ position:absolute; left:0; width:47%; top:0; }
.sign-right{ position:absolute; right:0; width:47%; top:0; text-align:right; }
.sign-line{ height:38px; border-bottom:1px solid rgba(255,255,255,.35); margin-bottom:7px; }
.sign-name{ color:#FFFFFF; font-size:12px; font-weight:700; }
.sign-role{ color:#D2C9BA; font-size:10px; line-height:1.4; }

.right-box{
  border:1px solid rgba(255,255,255,.16); background:#111111; padding:10px; margin-bottom:10px;
}
.qr-box{ text-align:center; }
.qr-img{
  width:176px; height:176px; display:block; margin:0 auto 8px auto; background:#FFFFFF; padding:6px;
}
.verify-url{ font-size:10px; line-height:1.32; color:#EEE7DA; word-break:break-all; text-align:center; }
.barcode-img{ display:block; width:100%; height:82px; background:#FFFFFF; padding:4px; }
.hash-label{ font-size:10px; color:' . $theme['highlight'] . '; letter-spacing:1.3px; margin-bottom:5px; }
.hash-val{ font-size:11px; line-height:1.34; color:#F5EDDB; word-break:break-all; }

.seal-wrap{ position:absolute; right:8px; bottom:4px; width:122px; height:122px; }
.seal-img{ width:122px; height:122px; display:block; }

.footer{
  position:absolute; left:34px; right:34px; bottom:34px; height:58px;
  border:1px solid rgba(255,255,255,.28); background:#161616; padding:9px 14px;
}
.footer-left{
  float:left; width:57%; font-size:11.5px; line-height:1.42; color:#EEE7DA;
}
.footer-right{
  float:right; width:41%; font-size:10.8px; line-height:1.42; color:#F1E7D6; text-align:right;
}
.microline{ position:absolute; left:34px; right:34px; bottom:98px; height:2px; background:rgba(255,255,255,.18); }
</style>
</head>
<body>
<div class="page">
  <div class="frame1"></div>
  <div class="frame2"></div>
  <div class="corner c1"></div>
  <div class="corner c2"></div>
  <div class="corner c3"></div>
  <div class="corner c4"></div>
  <div class="guilloche"></div>
  <div class="top-strip"></div>

  <div class="header">
    <div class="header-left">
      <img src="' . h($logoUri) . '" alt="RSO" style="width:62px;height:62px;display:block;float:left;margin-right:12px;">
      <div class="issuer" style="padding-top:2px;">
        <strong>Blockchain Group Ltd. Hong Kong</strong><br>
        RSO – RWA Standard Organisation<br>
        Issuing Organisation / Certification Authority
      </div>
    </div>

    <div class="header-center">
      <div class="title">' . h($theme['title']) . '</div>
      <div class="subtitle">' . h($theme['subtitle']) . '</div>
    </div>

    <div class="header-right">
      <div class="uid-label">CERTIFICATE UID</div>
      <div class="uid">' . h($uid) . '</div>
    </div>
  </div>

  <div class="main-left">
    <div class="section-cap">CERTIFICATION RECORD</div>

    <table class="meta-table">
      <tr><td class="meta-label">Holder</td><td class="meta-value holder">' . h($holder) . '</td></tr>
      <tr><td class="meta-label">Issued UTC</td><td class="meta-value">' . h($issuedAt) . '</td></tr>
      <tr><td class="meta-label">Asset Class</td><td class="meta-value">' . h($theme['asset_class']) . '</td></tr>
      <tr><td class="meta-label">Unit Rule</td><td class="meta-value">' . h($theme['unit_rule']) . '</td></tr>
      <tr><td class="meta-label">Cert Hash</td><td class="meta-value hash-small">' . h($certHash) . '</td></tr>
    </table>

    <div class="statement-box">
      <div class="statement-title">LOCKED CERT STATEMENT</div>
      <div class="statement">' . h($statement) . '</div>
    </div>

    <div class="sign-wrap">
      <div class="sign-left">
        <div class="sign-line"></div>
        <div class="sign-name">Authorised Issuer Signatory</div>
        <div class="sign-role">Blockchain Group Ltd. Hong Kong<br>Issuing Organisation / Certification Authority</div>
      </div>
      <div class="sign-right">
        <div class="sign-line"></div>
        <div class="sign-name">RSO Certification Desk</div>
        <div class="sign-role">POAdo Certificate Standard<br>QR Verifiable · Blockchain Anchored</div>
      </div>
    </div>
  </div>

  <div class="main-right">
    <div class="right-box qr-box">
      <div class="section-cap" style="margin-bottom:7px;">QR VERIFY</div>
      <img class="qr-img" src="' . h($qrUri) . '" alt="QR">
      <div class="verify-url">' . h($verifyUrl) . '</div>
    </div>

    <div class="right-box">
      <div class="hash-label">BARCODE UID / CODE128</div>
      <img class="barcode-img" src="' . h($barcodeUri) . '" alt="Barcode">
    </div>

    <div class="right-box">
      <div class="hash-label">NFT MINT HASH</div>
      <div class="hash-val">' . h($nftHash) . '</div>
    </div>

    <div class="right-box" style="margin-bottom:0;">
      <div class="hash-label">BLOCKCHAIN RECORD</div>
      <div class="hash-val">' . h($blockRef) . '</div>
    </div>

    <div class="seal-wrap">
      <img class="seal-img" src="' . h($sealUri) . '" alt="Digital Seal">
    </div>
  </div>

  <div class="microline"></div>

  <div class="footer">
    <div class="footer-left">
      <strong>Issuer Authority:</strong> Certified by Blockchain Group Ltd. Hong Kong ·
      RSO – RWA Standard Organisation · POAdo Certificate Standard
    </div>
    <div class="footer-right">' . h($disclaimer) . '</div>
  </div>
</div>
</body>
</html>';

try {
    $options = new \Dompdf\Options();
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true);
    $options->set('isPhpEnabled', false);
    $options->set('dpi', 150);

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->render();
    $dompdf->stream($fileName, ['Attachment' => false]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo 'PDF render failed.';
}