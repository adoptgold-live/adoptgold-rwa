<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/api/_render-pdf-html.php
 * Version: v2.3.1-20260330-restore-old-premium-layout
 *
 * Restored from old premium PDF layout baseline.
 */

require_once __DIR__ . '/_pdf-template-map.php';

if (!function_exists('poado_pdf_e')) {
    function poado_pdf_e($v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('poado_pdf_mask_wallet')) {
    function poado_pdf_mask_wallet(?string $wallet): string
    {
        $wallet = trim((string)$wallet);
        if ($wallet === '') {
            return '—';
        }
        if (strlen($wallet) <= 26) {
            return $wallet;
        }
        return substr($wallet, 0, 16) . '…' . substr($wallet, -12);
    }
}

if (!function_exists('poado_pdf_fmt_dt')) {
    function poado_pdf_fmt_dt($value): string
    {
        $value = trim((string)$value);
        if ($value === '' || $value === '0000-00-00 00:00:00') {
            return '—';
        }
        $ts = strtotime($value);
        if (!$ts) {
            return $value;
        }
        return gmdate('Y-m-d H:i:s', $ts) . ' UTC';
    }
}

if (!function_exists('poado_pdf_detect_code')) {
    function poado_pdf_detect_code(array $row, array $theme): string
    {
        $raw = trim((string)($row['rwa_code'] ?? ''));
        if ($raw !== '') {
            return strtoupper($raw);
        }
        $fallback = trim((string)($row['rwa_type'] ?? ''));
        return $fallback !== '' ? strtoupper($fallback) : strtoupper((string)($theme['rwa_code'] ?? ''));
    }
}

if (!function_exists('poado_pdf_detect_price_label')) {
    function poado_pdf_detect_price_label(array $row, array $theme): string
    {
        $rwaCode = poado_pdf_detect_code($row, $theme);
        $units   = trim((string)($row['price_units'] ?? ''));
        $amount  = trim((string)($row['price_wems'] ?? ''));

        if ($units !== '' && $amount !== '') {
            return $amount . ' ' . $units;
        }
        if ($amount !== '') {
            return $amount . ' ' . (string)($theme['mint_asset_label'] ?? 'UNIT');
        }

        $genesisDefaults = [
            'RCO2C-EMA'  => '1000 wEMS',
            'RH2O-EMA'   => '5000 wEMS',
            'RBLACK-EMA' => '10000 wEMS',
            'RK92-EMA'   => '50000 wEMS',
        ];

        if (isset($genesisDefaults[$rwaCode])) {
            return $genesisDefaults[$rwaCode];
        }

        if ($rwaCode === 'RHRD-EMA') {
            return '100 EMA$';
        }

        return '100 EMA$';
    }
}

if (!function_exists('poado_pdf_code128_patterns')) {
    function poado_pdf_code128_patterns(): array
    {
        return [
            '212222','222122','222221','121223','121322','131222','122213','122312','132212','221213',
            '221312','231212','112232','122132','122231','113222','123122','123221','223211','221132',
            '221231','213212','223112','312131','311222','321122','321221','312212','322112','322211',
            '212123','212321','232121','111323','131123','131321','112313','132113','132311','211313',
            '231113','231311','112133','112331','132131','113123','113321','133121','313121','211331',
            '231131','213113','213311','213131','311123','311321','331121','312113','312311','332111',
            '314111','221411','431111','111224','111422','121124','121421','141122','141221','112214',
            '112412','122114','122411','142112','142211','241211','221114','413111','241112','134111',
            '111242','121142','121241','114212','124112','124211','411212','421112','421211','212141',
            '214121','412121','111143','111341','131141','114113','114311','411113','411311','113141',
            '114131','311141','411131','211412','211214','211232','2331112',
        ];
    }
}

if (!function_exists('poado_pdf_code128b_html')) {
    function poado_pdf_code128b_html(string $text, string $color = '#ffffff'): string
    {
        $text = trim($text);
        if ($text === '') {
            return '<div class="barcode-empty">NO ADDRESS</div>';
        }

        $patterns = poado_pdf_code128_patterns();

        $codes = [];
        $checksum = 104;
        $codes[] = 104;

        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $ord = ord($text[$i]);
            if ($ord < 32 || $ord > 126) {
                continue;
            }
            $code = $ord - 32;
            $codes[] = $code;
            $checksum += $code * ($i + 1);
        }

        $codes[] = $checksum % 103;
        $codes[] = 106;

        $html = '<div class="barcode-bars">';
        foreach ($codes as $code) {
            $pattern = $patterns[$code] ?? '';
            $pLen = strlen($pattern);
            for ($i = 0; $i < $pLen; $i++) {
                $width = (float)$pattern[$i] * 0.22;
                $isBar = ($i % 2) === 0;
                if ($isBar) {
                    $html .= '<span class="b" style="width:' . number_format($width, 2, '.', '') . 'mm;background:' . poado_pdf_e($color) . ';"></span>';
                } else {
                    $html .= '<span class="s" style="width:' . number_format($width, 2, '.', '') . 'mm;"></span>';
                }
            }
        }
        $html .= '</div>';
        return $html;
    }
}

if (!function_exists('poado_cert_render_pdf_html')) {
    function poado_cert_render_pdf_html(array $row, array $opts = []): string
    {
        $theme = poado_cert_pdf_theme($row);

        $theme['title_glow']   = (string)($theme['title_glow'] ?? $theme['glow'] ?? 'rgba(212,175,55,.12)');
        $theme['glow']         = (string)($theme['glow'] ?? 'rgba(212,175,55,.18)');
        $theme['panel_tint']   = (string)($theme['panel_tint'] ?? 'rgba(212,175,55,.08)');
        $theme['accent_line']  = (string)($theme['accent_line'] ?? '#d4af37');
        $theme['title']        = (string)($theme['title'] ?? 'RWA Responsibility Certificate');
        $theme['subtitle']     = (string)($theme['subtitle'] ?? 'Certified Record issued under the RWA Standard Organisation framework.');
        $theme['family_label'] = (string)($theme['family_label'] ?? 'RWA');
        $theme['footer']       = (string)($theme['footer'] ?? '© 2026 Blockchain Group RWA FZCO (DMCC, Dubai, UAE) · RWA Standard Organisation (RSO). All rights reserved.');
        $theme['disclaimer']   = (string)($theme['disclaimer'] ?? 'This document is the original issued RWA certificate artifact. NFT mint representation, when available, is a separate digital asset linked through the official verification route.');

        $uid         = trim((string)($row['cert_uid'] ?? ''));
        $rwaCode     = poado_pdf_detect_code($row, $theme);
        $verifyUrl   = trim((string)($opts['verify_url'] ?? ''));
        $qrDataUri   = trim((string)($opts['qr_data_uri'] ?? ''));
        $qrPublicUrl = trim((string)($opts['qr_public_url'] ?? ''));
        $ownerWallet = trim((string)($row['ton_wallet'] ?? $row['wallet_address'] ?? $row['wallet'] ?? ''));
        $fingerprint = trim((string)($row['fingerprint_hash'] ?? ''));
        $issuedAt    = poado_pdf_fmt_dt($row['issued_at'] ?? '');
        $mintedAt    = poado_pdf_fmt_dt($row['minted_at'] ?? '');
        $status      = strtoupper(trim((string)($row['status'] ?? 'ISSUED')));
        $weight      = (string)($theme['weight'] ?? '—');
        $priceLabel  = poado_pdf_detect_price_label($row, $theme);
        $unitLabel   = trim((string)($theme['unit'] ?? ''));
        $uidShort    = $uid !== '' ? substr($uid, -8) : 'RWA';
        $sealYear    = gmdate('Y');

        $nftItemAddress = trim((string)($row['nft_item_address'] ?? ''));
        $collectionAddr = 'EQDEFDIF0F1JhiH0hCaGHEIzM6tAKeX0SWK5LuOFQEZ8iD6q';
        $networkLabel   = 'TON (Mainnet)';

        $leftRows = [
            ['Certificate UID', $uid !== '' ? $uid : '—'],
            ['RWA Code', $rwaCode !== '' ? $rwaCode : '—'],
            ['Unit of Responsibility', $unitLabel !== '' ? $unitLabel : '—'],
            ['Family', (string)$theme['family_label']],
            ['Owner TON Wallet', $ownerWallet !== '' ? $ownerWallet : '—'],
            ['Mint Asset / Price', $priceLabel],
            ['Weight', $weight],
            ['Status', $status],
            ['Issued At', $issuedAt],
            ['Minted At', $mintedAt],
        ];

        $auditRows = [
            ['Fingerprint Hash', $fingerprint !== '' ? $fingerprint : '—'],
            ['Verify URL', $verifyUrl !== '' ? $verifyUrl : '—'],
            ['NFT Item', $nftItemAddress !== '' ? $nftItemAddress : '—'],
            ['Collection', $collectionAddr],
            ['Network', $networkLabel],
        ];

        $barcodeHtml = poado_pdf_code128b_html($ownerWallet, (string)$theme['accent_line']);

        ob_start();
        ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= poado_pdf_e($uid !== '' ? $uid : 'RWA Certificate') ?></title>
<style>
@page { size: A4 landscape; margin: 0; }

html, body {
    width: 297mm;
    height: 210mm;
    margin: 0;
    padding: 0;
    overflow: hidden;
    background: #06070a;
    color: #f4f0e8;
    font-family: DejaVu Sans, sans-serif;
}
* { box-sizing: border-box; }

.page {
    width: 297mm;
    height: 210mm;
    position: relative;
    overflow: hidden;
    background:
        radial-gradient(circle at 18% 20%, <?= poado_pdf_e($theme['glow']) ?> 0%, rgba(0,0,0,0) 32%),
        radial-gradient(circle at 85% 18%, <?= poado_pdf_e($theme['title_glow']) ?> 0%, rgba(0,0,0,0) 26%),
        linear-gradient(180deg, #0d0f14 0%, #090b10 45%, #06070a 100%);
}

.outer-frame {
    position: absolute;
    top: 4.2mm; left: 4.2mm; right: 4.2mm; bottom: 4.2mm;
    border: 0.28mm solid <?= poado_pdf_e($theme['accent_line']) ?>;
    box-shadow: inset 0 0 0 0.20mm rgba(255,255,255,0.03), 0 0 6mm <?= poado_pdf_e($theme['glow']) ?>;
}
.inner-frame {
    position: absolute;
    top: 8mm; left: 8mm; right: 8mm; bottom: 8mm;
    border: 0.20mm solid rgba(255,255,255,0.10);
}

.hero {
    position: absolute;
    top: 14mm; left: 19mm; right: 19mm;
    text-align: center;
}
.hero-kicker {
    font-size: 2.9mm;
    letter-spacing: 1.05mm;
    color: #b8c0cf;
    margin-bottom: 1.4mm;
}
.hero-title {
    font-size: 8.7mm;
    line-height: 1.12;
    font-weight: 700;
    color: #fff2d6;
    margin-bottom: 1.2mm;
}
.hero-subtitle {
    font-size: 3.35mm;
    line-height: 1.35;
    color: #d9dbe2;
    max-width: 200mm;
    margin: 0 auto;
}

.uid-strip {
    position: absolute;
    top: 41mm; left: 19mm; right: 19mm;
    height: 14.2mm;
    border: 0.22mm solid <?= poado_pdf_e($theme['accent_line']) ?>;
    background: linear-gradient(
        90deg,
        <?= poado_pdf_e($theme['panel_tint']) ?> 0%,
        rgba(255,255,255,0.02) 40%,
        <?= poado_pdf_e($theme['panel_tint']) ?> 100%
    );
}
.uid-table { width: 100%; height: 100%; border-collapse: collapse; }
.uid-table td { vertical-align: middle; padding: 0 4.8mm; }
.uid-label {
    width: 44mm;
    font-size: 2.7mm;
    letter-spacing: 0.55mm;
    color: #cfd5df;
}
.uid-value {
    font-size: 6.4mm;
    font-weight: 700;
    color: #ffffff;
}
.uid-mini {
    text-align: right;
    font-size: 2.7mm;
    color: <?= poado_pdf_e($theme['accent_line']) ?>;
    letter-spacing: 0.52mm;
}

.columns {
    position: absolute;
    left: 19mm; right: 19mm; top: 59mm; bottom: 28mm;
}
.left-panel {
    position: absolute;
    left: 0; top: 0; bottom: 0;
    width: 61%;
    border: 0.20mm solid rgba(255,255,255,0.12);
    background: linear-gradient(180deg, rgba(255,255,255,0.03), rgba(255,255,255,0.015));
    padding: 3.7mm 4.2mm 3.2mm;
}
.right-panel {
    position: absolute;
    right: 0; top: 0; bottom: 0;
    width: 35.8%;
    border: 0.22mm solid <?= poado_pdf_e($theme['accent_line']) ?>;
    background: linear-gradient(180deg, <?= poado_pdf_e($theme['panel_tint']) ?> 0%, rgba(255,255,255,0.020) 100%);
    padding: 3.2mm 3.6mm 3mm;
}

.panel-title {
    font-size: 2.9mm;
    letter-spacing: 0.58mm;
    color: <?= poado_pdf_e($theme['accent_line']) ?>;
    margin-bottom: 1.8mm;
    font-weight: 700;
}

.kv-table { width: 100%; border-collapse: collapse; }
.kv-table tr { border-bottom: 0.18mm solid rgba(255,255,255,0.07); }
.kv-table tr:last-child { border-bottom: none; }

.kv-key {
    width: 19mm;
    padding: 1.2mm 1.2mm 1.2mm 0;
    font-size: 2.45mm;
    color: #bfc7d3;
    vertical-align: top;
}
.kv-val {
    padding: 1.2mm 0;
    font-size: 2.45mm;
    color: #ffffff;
    line-height: 1.18;
    word-break: break-word;
    vertical-align: top;
}
.right-panel .kv-val.smallmono { word-break: break-all; }
.wallet-mask {
    margin-top: 0.7mm;
    color: #aab3bf;
    font-size: 2.25mm;
}

.barcode-wrap {
    margin: 3.4mm 0 0;
    width: 48%;
    padding: 2.2mm 2.2mm 1.8mm;
    border: 0.20mm solid rgba(255,255,255,0.10);
    background: rgba(255,255,255,0.02);
}
.barcode-bars {
    height: 12.2mm;
    white-space: nowrap;
    overflow: hidden;
    line-height: 0;
}
.barcode-bars .b {
    display: inline-block;
    height: 12.2mm;
    vertical-align: top;
}
.barcode-bars .s {
    display: inline-block;
    height: 12.2mm;
    vertical-align: top;
    background: transparent;
}
.barcode-empty {
    height: 12.2mm;
    line-height: 12.2mm;
    text-align: center;
    color: #9aa4b2;
    font-size: 2.6mm;
}

.qr-wrap {
    text-align: center;
    margin-bottom: 2.2mm;
    padding: 2.4mm 2.2mm 2.2mm;
    border: 0.22mm solid <?= poado_pdf_e($theme['accent_line']) ?>;
    background:
        linear-gradient(180deg, rgba(255,255,255,0.025), rgba(255,255,255,0.010)),
        radial-gradient(circle at 50% 20%, <?= poado_pdf_e($theme['panel_tint']) ?> 0%, rgba(0,0,0,0) 75%);
}
.qr-box {
    display: inline-block;
    width: 36mm;
    height: 36mm;
    padding: 1.8mm;
    background: #ffffff;
    border: 0.55mm solid <?= poado_pdf_e($theme['accent_line']) ?>;
    box-shadow: 0 0 4mm <?= poado_pdf_e($theme['glow']) ?>;
}
.qr-box img {
    width: 100%;
    height: 100%;
    display: block;
}
.qr-box-fallback {
    width: 100%;
    height: 100%;
    border: 0.18mm dashed #999;
    background: #fff;
    position: relative;
}
.qr-box-fallback::after {
    content: "QR";
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #666;
    font-size: 4.2mm;
    font-weight: 700;
    letter-spacing: 0.6mm;
}
.qr-caption {
    margin-top: 1mm;
    font-size: 2.2mm;
    color: #e1e5ec;
}
.verify-chip {
    display: inline-block;
    margin-top: 1mm;
    padding: 0.9mm 2.4mm;
    font-size: 1.95mm;
    color: <?= poado_pdf_e($theme['accent_line']) ?>;
    border: 0.18mm solid <?= poado_pdf_e($theme['accent_line']) ?>;
    background: rgba(255,255,255,0.03);
}

.issuer-seal {
    position: absolute;
    right: 5mm;
    bottom: 11mm;
    width: 40mm;
    height: 40mm;
    border: 0.20mm solid rgba(255,255,255,0.08);
    border-radius: 20mm;
    background:
        radial-gradient(circle at 50% 45%, rgba(255,255,255,0.03) 0%, rgba(0,0,0,0) 62%),
        radial-gradient(circle at 50% 50%, <?= poado_pdf_e($theme['panel_tint']) ?> 0%, rgba(0,0,0,0) 78%);
    box-shadow: inset 0 0 0 0.18mm rgba(255,255,255,0.03), 0 0 5mm <?= poado_pdf_e($theme['glow']) ?>;
    color: <?= poado_pdf_e($theme['accent_line']) ?>;
    text-align: center;
    opacity: 0.96;
}
.issuer-seal::before {
    content: "";
    position: absolute;
    inset: 2.8mm;
    border: 0.18mm dashed rgba(255,255,255,0.18);
    border-radius: 17mm;
}
.issuer-seal-inner {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    padding: 4.2mm;
}
.seal-top {
    font-size: 2mm;
    letter-spacing: 0.32mm;
    margin-bottom: 1.2mm;
    color: #d8dde8;
}
.seal-mid {
    font-size: 3.5mm;
    font-weight: 700;
    line-height: 1.16;
    color: <?= poado_pdf_e($theme['accent_line']) ?>;
}
.seal-sub {
    font-size: 1.9mm;
    letter-spacing: 0.22mm;
    margin-top: 1.4mm;
    color: #e5e8ef;
}
.seal-code {
    font-size: 1.9mm;
    letter-spacing: 0.18mm;
    margin-top: 1.2mm;
    color: #aeb7c5;
}

.disclaimer {
    position: absolute;
    left: 19mm; right: 19mm;
    bottom: 10.6mm;
    font-size: 2mm;
    line-height: 1.20;
    color: #b8bec9;
    text-align: center;
    white-space: nowrap;
    overflow: hidden;
}
.footer {
    position: absolute;
    left: 19mm; right: 19mm;
    bottom: 5.2mm;
    height: 3.6mm;
    border-top: 0.18mm solid rgba(255,255,255,0.10);
    padding-top: 0.7mm;
    font-size: 2mm;
    line-height: 1;
    color: #cdd3dd;
    text-align: center;
    white-space: nowrap;
    overflow: hidden;
}
.smallmono {
    font-family: DejaVu Sans, sans-serif;
    letter-spacing: 0.02mm;
}
</style>
</head>
<body>
<div class="page">
    <div class="outer-frame"></div>
    <div class="inner-frame"></div>

    <div class="hero">
        <div class="hero-kicker">OFFICIAL RWA CERTIFICATE</div>
        <div class="hero-title"><?= poado_pdf_e($theme['title']) ?></div>
        <div class="hero-subtitle"><?= poado_pdf_e($theme['subtitle']) ?></div>
    </div>

    <div class="uid-strip">
        <table class="uid-table">
            <tr>
                <td class="uid-label">CERTIFICATE UID</td>
                <td class="uid-value"><?= poado_pdf_e($uid !== '' ? $uid : '—') ?></td>
                <td class="uid-mini"><?= poado_pdf_e($theme['family_label']) ?></td>
            </tr>
        </table>
    </div>

    <div class="columns">
        <div class="left-panel">
            <div class="panel-title">CERTIFICATE DATA</div>
            <table class="kv-table">
                <?php foreach ($leftRows as $rowItem): ?>
                    <tr>
                        <td class="kv-key"><?= poado_pdf_e($rowItem[0]) ?></td>
                        <td class="kv-val smallmono">
                            <?php
                            $key = (string)$rowItem[0];
                            $val = (string)$rowItem[1];
                            if ($key === 'Owner TON Wallet') {
                                echo poado_pdf_e($val);
                                echo '<div class="wallet-mask">Display: ' . poado_pdf_e(poado_pdf_mask_wallet($ownerWallet)) . '</div>';
                            } elseif ($key === 'Status') {
                                echo '<span style="color:' . poado_pdf_e($theme['accent_line']) . ';font-weight:700;">' . poado_pdf_e($val) . '</span>';
                            } else {
                                echo poado_pdf_e($val);
                            }
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>

            <div class="barcode-wrap"><?= $barcodeHtml ?></div>

            <div class="issuer-seal">
                <div class="issuer-seal-inner">
                    <div class="seal-top">DMCC · DUBAI · UAE</div>
                    <div class="seal-mid">BLOCKCHAIN<br>GROUP<br>RWA FZCO</div>
                    <div class="seal-sub">ISSUER SEAL</div>
                    <div class="seal-code"><?= poado_pdf_e($sealYear) ?> · <?= poado_pdf_e($uidShort) ?></div>
                </div>
            </div>
        </div>

        <div class="right-panel">
            <div class="qr-wrap">
                <div class="qr-box">
                    <?php if ($qrDataUri !== ''): ?>
                        <img src="<?= poado_pdf_e($qrDataUri) ?>" alt="Verify QR">
                    <?php elseif ($qrPublicUrl !== ''): ?>
                        <img src="<?= poado_pdf_e($qrPublicUrl) ?>" alt="Verify QR">
                    <?php else: ?>
                        <div class="qr-box-fallback"></div>
                    <?php endif; ?>
                </div>
                <div class="qr-caption">Scan to verify the original certificate</div>
                <div class="verify-chip">VERIFY ROUTE LOCKED</div>
            </div>

            <div class="panel-title">VERIFY / AUDIT DATA</div>
            <table class="kv-table">
                <?php foreach ($auditRows as $rowItem): ?>
                    <tr>
                        <td class="kv-key"><?= poado_pdf_e($rowItem[0]) ?></td>
                        <td class="kv-val smallmono"><?= poado_pdf_e((string)$rowItem[1]) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>

    <div class="disclaimer"><?= poado_pdf_e($theme['disclaimer']) ?></div>
    <div class="footer"><?= poado_pdf_e($theme['footer']) ?></div>
</div>
</body>
</html>
<?php
        return (string)ob_get_clean();
    }
}
