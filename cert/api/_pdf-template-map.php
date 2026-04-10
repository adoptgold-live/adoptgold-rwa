<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/api/_pdf-template-map.php
 *
 * Shared PDF theme / wording map for all 8 RWA certificate families.
 * v3.3 baseline:
 * - PDF = original certificate artifact
 * - PNG = NFT mint artifact
 * - verify.php bridges PDF + NFT
 */

if (!function_exists('poado_cert_pdf_template_map')) {
    function poado_cert_pdf_template_map(): array
    {
        $footer = '© 2026 Blockchain Group RWA FZCO (DMCC, Dubai, UAE) · RWA Standard Organisation (RSO). All rights reserved.';
        $issuer = 'Issued by Blockchain Group RWA FZCO (DMCC, Dubai, UAE) via adoptgold.app';
        $disclaimer = 'This document is the original issued RWA certificate artifact. NFT mint representation, when available, is a separate digital asset linked through the official verification route.';

        return [
            'green' => [
                'family' => 'GENESIS',
                'family_label' => 'GREEN / GENESIS',
                'rwa_code' => 'RCO2C-EMA',
                'title' => 'Carbon Responsibility Certificate',
                'subtitle' => 'Certified Record of Carbon Responsibility Rights issued under the RWA Standard Organisation Framework.',
                'unit' => '10 kg CO₂e',
                'accent' => '#31c46c',
                'accent_soft' => '#163f26',
                'accent_line' => '#68e29a',
                'panel_tint' => 'rgba(49,196,108,0.10)',
                'glow' => 'rgba(49,196,108,0.28)',
                'seal' => 'CARBON',
                'weight' => 1,
                'mint_asset_label' => 'wEMS',
                'footer' => $footer,
                'issuer' => $issuer,
                'disclaimer' => $disclaimer,
            ],
            'blue' => [
                'family' => 'GENESIS',
                'family_label' => 'BLUE / GENESIS',
                'rwa_code' => 'RH2O-EMA',
                'title' => 'Water Responsibility Certificate',
                'subtitle' => 'Certified Record of Water Responsibility Rights issued under the RWA Standard Organisation Framework.',
                'unit' => '100 L Water',
                'accent' => '#38a8ff',
                'accent_soft' => '#15314b',
                'accent_line' => '#86ccff',
                'panel_tint' => 'rgba(56,168,255,0.10)',
                'glow' => 'rgba(56,168,255,0.28)',
                'seal' => 'WATER',
                'weight' => 2,
                'mint_asset_label' => 'wEMS',
                'footer' => $footer,
                'issuer' => $issuer,
                'disclaimer' => $disclaimer,
            ],
            'black' => [
                'family' => 'GENESIS',
                'family_label' => 'BLACK / GENESIS',
                'rwa_code' => 'RBLACK-EMA',
                'title' => 'Energy Responsibility Certificate',
                'subtitle' => 'Certified Record of Energy Responsibility Rights issued under the RWA Standard Organisation Framework.',
                'unit' => '1 MWh Energy',
                'accent' => '#9ea7b3',
                'accent_soft' => '#2b2f35',
                'accent_line' => '#d6dde7',
                'panel_tint' => 'rgba(158,167,179,0.10)',
                'glow' => 'rgba(214,221,231,0.18)',
                'seal' => 'ENERGY',
                'weight' => 3,
                'mint_asset_label' => 'wEMS',
                'footer' => $footer,
                'issuer' => $issuer,
                'disclaimer' => $disclaimer,
            ],
            'gold' => [
                'family' => 'GENESIS',
                'family_label' => 'GOLD / GENESIS',
                'rwa_code' => 'RK92-EMA',
                'title' => 'Gold Mining Responsibility Certificate',
                'subtitle' => 'Certified Record of Gold Mining Responsibility Rights issued under the RWA Standard Organisation Framework.',
                'unit' => '1 g Gold Nugget',
                'accent' => '#d6af36',
                'accent_soft' => '#4f3d10',
                'accent_line' => '#f0d472',
                'panel_tint' => 'rgba(214,175,54,0.11)',
                'glow' => 'rgba(214,175,54,0.25)',
                'seal' => 'GOLD',
                'weight' => 5,
                'mint_asset_label' => 'wEMS',
                'footer' => $footer,
                'issuer' => $issuer,
                'disclaimer' => $disclaimer,
            ],
            'yellow' => [
                'family' => 'TERTIARY',
                'family_label' => 'YELLOW / TERTIARY',
                'rwa_code' => 'RHRD-EMA',
                'title' => 'Human Resources Responsibility Certificate',
                'subtitle' => 'Certified Record of Human Resource Responsibility Rights issued under the RWA Standard Organisation Framework.',
                'unit' => '10 Hours Manpower',
                'accent' => '#ffd84d',
                'accent_soft' => '#574407',
                'accent_line' => '#ffeb99',
                'panel_tint' => 'rgba(255,216,77,0.10)',
                'glow' => 'rgba(255,216,77,0.22)',
                'seal' => 'HR',
                'weight' => 7,
                'mint_asset_label' => 'EMA$',
                'footer' => $footer,
                'issuer' => $issuer,
                'disclaimer' => $disclaimer,
            ],
            'pink' => [
                'family' => 'SECONDARY',
                'family_label' => 'PINK / SECONDARY',
                'rwa_code' => 'RLIFE-EMA',
                'title' => 'Health Responsibility Certificate',
                'subtitle' => 'Certified Record of Health Responsibility Rights issued under the RWA Standard Organisation Framework.',
                'unit' => '1 Day Health Positive',
                'accent' => '#ff6faa',
                'accent_soft' => '#4b1830',
                'accent_line' => '#ffb3cf',
                'panel_tint' => 'rgba(255,111,170,0.11)',
                'glow' => 'rgba(255,111,170,0.22)',
                'seal' => 'HEALTH',
                'weight' => 10,
                'mint_asset_label' => 'EMA$',
                'footer' => $footer,
                'issuer' => $issuer,
                'disclaimer' => $disclaimer,
            ],
            'royal_blue' => [
                'family' => 'SECONDARY',
                'family_label' => 'ROYAL BLUE / SECONDARY',
                'rwa_code' => 'RPROP-EMA',
                'title' => 'Property Responsibility Certificate',
                'subtitle' => 'Certified Record of Property Responsibility Rights issued under the RWA Standard Organisation Framework.',
                'unit' => '1 ft² Property',
                'accent' => '#5378ff',
                'accent_soft' => '#1d2755',
                'accent_line' => '#a9b9ff',
                'panel_tint' => 'rgba(83,120,255,0.11)',
                'glow' => 'rgba(83,120,255,0.22)',
                'seal' => 'PROPERTY',
                'weight' => 10,
                'mint_asset_label' => 'EMA$',
                'footer' => $footer,
                'issuer' => $issuer,
                'disclaimer' => $disclaimer,
            ],
            'red' => [
                'family' => 'SECONDARY',
                'family_label' => 'RED / SECONDARY',
                'rwa_code' => 'RTRIP-EMA',
                'title' => 'Travel Responsibility Certificate',
                'subtitle' => 'Certified Record of Travel Responsibility Rights issued under the RWA Standard Organisation Framework.',
                'unit' => '100 km Travel',
                'accent' => '#ff5a5a',
                'accent_soft' => '#4d1717',
                'accent_line' => '#ffaaaa',
                'panel_tint' => 'rgba(255,90,90,0.11)',
                'glow' => 'rgba(255,90,90,0.22)',
                'seal' => 'TRAVEL',
                'weight' => 10,
                'mint_asset_label' => 'EMA$',
                'footer' => $footer,
                'issuer' => $issuer,
                'disclaimer' => $disclaimer,
            ],
        ];
    }
}

if (!function_exists('poado_cert_pdf_type_from_rwa_code')) {
    function poado_cert_pdf_type_from_rwa_code(?string $rwaCode): ?string
    {
        $rwaCode = strtoupper(trim((string)$rwaCode));
        if ($rwaCode === '') {
            return null;
        }

        $map = [
            'RCO2C-EMA' => 'green',
            'RH2O-EMA'  => 'blue',
            'RBLACK-EMA'=> 'black',
            'RK92-EMA'  => 'gold',
            'RHRD-EMA'  => 'yellow',
            'RLIFE-EMA' => 'pink',
            'RPROP-EMA' => 'royal_blue',
            'RTRIP-EMA' => 'red',
        ];

        return $map[$rwaCode] ?? null;
    }
}

if (!function_exists('poado_cert_pdf_detect_type')) {
    function poado_cert_pdf_detect_type(array $row): string
    {
        $map = poado_cert_pdf_template_map();

        $candidates = [
            (string)($row['cert_type'] ?? ''),
            (string)($row['rwa_type'] ?? ''),
            (string)($row['family_key'] ?? ''),
            (string)($row['theme_key'] ?? ''),
        ];

        foreach ($candidates as $candidate) {
            $candidate = strtolower(trim($candidate));
            if ($candidate !== '' && isset($map[$candidate])) {
                return $candidate;
            }
        }

        $fromCode = poado_cert_pdf_type_from_rwa_code((string)($row['rwa_code'] ?? ''));
        if ($fromCode !== null && isset($map[$fromCode])) {
            return $fromCode;
        }

        return 'gold';
    }
}

if (!function_exists('poado_cert_pdf_theme')) {
    function poado_cert_pdf_theme(array $row): array
    {
        $map = poado_cert_pdf_template_map();
        $type = poado_cert_pdf_detect_type($row);

        return $map[$type] ?? $map['gold'];
    }
}
