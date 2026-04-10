<?php
declare(strict_types=1);

/**
 * POAdo RWA Cert Engine
 * File: /var/www/html/public/rwa/cert/templates/nft/default-metadata.php
 *
 * Purpose:
 * - Shared NFT metadata builder for cert engine
 * - Returns normalized metadata array for staging/final JSON generation
 * - Used by issue.php / mint.php / later vault pipeline
 */

if (!function_exists('poado_rwa_cert_meta_type_label')) {
    function poado_rwa_cert_meta_type_label(string $type): string
    {
        $type = strtolower(trim($type));

        return match ($type) {
            'green'    => 'Genesis Green RWA Certificate',
            'gold'     => 'Genesis Gold RWA Certificate',
            'blue'     => 'Genesis Blue RWA Certificate',
            'black'    => 'Genesis Black RWA Certificate',
            'health'   => 'Secondary Health RWA Certificate',
            'travel'   => 'Secondary Travel RWA Certificate',
            'property' => 'Secondary Property RWA Certificate',
            default    => 'POAdo RWA Certificate',
        };
    }
}

if (!function_exists('poado_rwa_cert_meta_short_label')) {
    function poado_rwa_cert_meta_short_label(string $type): string
    {
        $type = strtolower(trim($type));

        return match ($type) {
            'green'    => 'GREEN CERT',
            'gold'     => 'GOLD CERT',
            'blue'     => 'BLUE CERT',
            'black'    => 'BLACK CERT',
            'health'   => 'HEALTH CERT',
            'travel'   => 'TRAVEL CERT',
            'property' => 'PROPERTY CERT',
            default    => 'RWA CERT',
        };
    }
}

if (!function_exists('poado_rwa_cert_meta_group')) {
    function poado_rwa_cert_meta_group(string $type): string
    {
        $type = strtolower(trim($type));

        if (in_array($type, ['green', 'gold', 'blue', 'black'], true)) {
            return 'Genesis';
        }
        if (in_array($type, ['health', 'travel', 'property'], true)) {
            return 'Secondary';
        }
        return 'Unknown';
    }
}

if (!function_exists('poado_rwa_cert_meta_weight')) {
    function poado_rwa_cert_meta_weight(string $type): int
    {
        $type = strtolower(trim($type));

        return match ($type) {
            'green'    => 1,
            'blue'     => 2,
            'black'    => 3,
            'gold'     => 5,
            'health'   => 10,
            'travel'   => 10,
            'property' => 10,
            default    => 0,
        };
    }
}

if (!function_exists('poado_rwa_cert_meta_payment_asset')) {
    function poado_rwa_cert_meta_payment_asset(string $type): string
    {
        $type = strtolower(trim($type));

        return in_array($type, ['green', 'gold', 'blue', 'black'], true) ? 'wEMS' : 'EMA$';
    }
}

if (!function_exists('poado_rwa_cert_meta_price')) {
    function poado_rwa_cert_meta_price(string $type): string
    {
        $type = strtolower(trim($type));

        return match ($type) {
            'green'    => '1000',
            'blue'     => '5000',
            'black'    => '10000',
            'gold'     => '50000',
            'health'   => '100',
            'travel'   => '100',
            'property' => '100',
            default    => '0',
        };
    }
}

if (!function_exists('poado_rwa_cert_meta_subtitle')) {
    function poado_rwa_cert_meta_subtitle(string $type): string
    {
        $type = strtolower(trim($type));

        return match ($type) {
            'green'    => 'Carbon Responsibility / ESG Participation Record',
            'gold'     => 'Gold Mining Responsibility / Resource Participation Record',
            'blue'     => 'Clean Water Responsibility / Water Stewardship Record',
            'black'    => 'Energy Responsibility / Infrastructure Participation Record',
            'health'   => 'Health Monitoring Right / Secondary RWA Record',
            'travel'   => 'Travel Experience Right / Secondary RWA Record',
            'property' => 'Property Rights / Secondary RWA Record',
            default    => 'Real World Asset Certificate Record',
        };
    }
}

if (!function_exists('poado_rwa_cert_build_default_metadata')) {
    /**
     * Build normalized NFT metadata array.
     *
     * Supported context keys:
     * - uid
     * - type
     * - owner_user_id
     * - owner_wallet
     * - owner_nickname
     * - ton_wallet
     * - verify_url
     * - pdf_url
     * - preview_url
     * - qr_url
     * - image
     * - external_url
     * - description
     * - issued_at
     * - paid_at
     * - minted_at
     * - payment_ref
     * - tx_hash
     * - nft_item_address
     * - industry_key
     * - treasury
     * - holder_pool
     * - issuer
     * - attributes (custom extra traits)
     */
    function poado_rwa_cert_build_default_metadata(array $ctx): array
    {
        $uid           = (string)($ctx['uid'] ?? '');
        $type          = strtolower(trim((string)($ctx['type'] ?? 'unknown')));
        $ownerUserId   = (int)($ctx['owner_user_id'] ?? 0);
        $ownerWallet   = (string)($ctx['owner_wallet'] ?? '');
        $ownerNickname = (string)($ctx['owner_nickname'] ?? '');
        $tonWallet     = (string)($ctx['ton_wallet'] ?? '');

        $verifyUrl     = (string)($ctx['verify_url'] ?? '');
        $pdfUrl        = (string)($ctx['pdf_url'] ?? '');
        $previewUrl    = (string)($ctx['preview_url'] ?? '');
        $qrUrl         = (string)($ctx['qr_url'] ?? '');
        $image         = (string)($ctx['image'] ?? $previewUrl);
        $externalUrl   = (string)($ctx['external_url'] ?? $verifyUrl);

        $issuedAt      = (string)($ctx['issued_at'] ?? '');
        $paidAt        = (string)($ctx['paid_at'] ?? '');
        $mintedAt      = (string)($ctx['minted_at'] ?? '');
        $paymentRef    = (string)($ctx['payment_ref'] ?? '');
        $txHash        = (string)($ctx['tx_hash'] ?? '');
        $nftItemAddr   = (string)($ctx['nft_item_address'] ?? '');
        $industryKey   = (string)($ctx['industry_key'] ?? '');
        $treasury      = (string)($ctx['treasury'] ?? '');
        $holderPool    = (string)($ctx['holder_pool'] ?? '');
        $issuer        = (string)($ctx['issuer'] ?? '');

        $name       = poado_rwa_cert_meta_type_label($type) . ($uid !== '' ? (' · ' . $uid) : '');
        $shortLabel = poado_rwa_cert_meta_short_label($type);
        $group      = poado_rwa_cert_meta_group($type);
        $weight     = poado_rwa_cert_meta_weight($type);
        $asset      = poado_rwa_cert_meta_payment_asset($type);
        $price      = poado_rwa_cert_meta_price($type);
        $subtitle   = poado_rwa_cert_meta_subtitle($type);

        $description = (string)($ctx['description'] ?? '');
        if ($description === '') {
            $description = $name
                . ' issued under the POAdo / AdoptGold RWA certification framework. '
                . 'This metadata records the certificate UID, certificate family, weight model, '
                . 'verification endpoint, owner context, and mint context where applicable.';
        }

        $attributes = [
            ['trait_type' => 'Certificate UID',    'value' => $uid],
            ['trait_type' => 'Certificate Type',   'value' => $type],
            ['trait_type' => 'Certificate Label',  'value' => $shortLabel],
            ['trait_type' => 'Certificate Group',  'value' => $group],
            ['trait_type' => 'Weight',             'value' => $weight],
            ['trait_type' => 'Payment Asset',      'value' => $asset],
            ['trait_type' => 'Price',              'value' => $price],
            ['trait_type' => 'Subtitle',           'value' => $subtitle],
        ];

        if ($industryKey !== '') {
            $attributes[] = ['trait_type' => 'Industry Key', 'value' => $industryKey];
        }
        if ($ownerUserId > 0) {
            $attributes[] = ['trait_type' => 'Owner User ID', 'value' => (string)$ownerUserId];
        }
        if ($ownerNickname !== '') {
            $attributes[] = ['trait_type' => 'Owner Nickname', 'value' => $ownerNickname];
        }
        if ($ownerWallet !== '') {
            $attributes[] = ['trait_type' => 'Owner Wallet', 'value' => $ownerWallet];
        }
        if ($tonWallet !== '') {
            $attributes[] = ['trait_type' => 'TON Wallet', 'value' => $tonWallet];
        }
        if ($issuedAt !== '') {
            $attributes[] = ['trait_type' => 'Issued At', 'value' => $issuedAt];
        }
        if ($paidAt !== '') {
            $attributes[] = ['trait_type' => 'Paid At', 'value' => $paidAt];
        }
        if ($mintedAt !== '') {
            $attributes[] = ['trait_type' => 'Minted At', 'value' => $mintedAt];
        }
        if ($paymentRef !== '') {
            $attributes[] = ['trait_type' => 'Payment Ref', 'value' => $paymentRef];
        }
        if ($txHash !== '') {
            $attributes[] = ['trait_type' => 'Mint Tx Hash', 'value' => $txHash];
        }
        if ($nftItemAddr !== '') {
            $attributes[] = ['trait_type' => 'NFT Item Address', 'value' => $nftItemAddr];
        }
        if ($treasury !== '') {
            $attributes[] = ['trait_type' => 'Treasury', 'value' => $treasury];
        }
        if ($holderPool !== '') {
            $attributes[] = ['trait_type' => 'Holder Pool', 'value' => $holderPool];
        }
        if ($issuer !== '') {
            $attributes[] = ['trait_type' => 'Issuer Vault', 'value' => $issuer];
        }

        $customAttributes = $ctx['attributes'] ?? [];
        if (is_array($customAttributes)) {
            foreach ($customAttributes as $trait) {
                if (!is_array($trait)) {
                    continue;
                }
                $traitType = isset($trait['trait_type']) ? trim((string)$trait['trait_type']) : '';
                if ($traitType === '') {
                    continue;
                }
                $attributes[] = [
                    'trait_type' => $traitType,
                    'value'      => isset($trait['value']) ? (string)$trait['value'] : '',
                ];
            }
        }

        return [
            'name' => $name,
            'symbol' => 'POADO-RWA',
            'short_label' => $shortLabel,
            'description' => $description,
            'image' => $image,
            'external_url' => $externalUrl,

            'animation_url' => '',
            'background_color' => '',

            'attributes' => $attributes,

            'properties' => [
                'uid' => $uid,
                'type' => $type,
                'group' => $group,
                'weight' => $weight,

                'payment' => [
                    'asset' => $asset,
                    'price' => $price,
                    'payment_ref' => $paymentRef,
                ],

                'owner' => [
                    'user_id' => $ownerUserId,
                    'nickname' => $ownerNickname,
                    'wallet' => $ownerWallet,
                    'ton_wallet' => $tonWallet,
                ],

                'issue' => [
                    'issued_at' => $issuedAt,
                    'paid_at' => $paidAt,
                    'minted_at' => $mintedAt,
                    'industry_key' => $industryKey,
                ],

                'mint' => [
                    'tx_hash' => $txHash,
                    'nft_item_address' => $nftItemAddr,
                ],

                'links' => [
                    'verify' => $verifyUrl,
                    'pdf' => $pdfUrl,
                    'preview' => $previewUrl,
                    'qr' => $qrUrl,
                ],

                'royalty' => [
                    'treasury' => $treasury,
                    'holder_pool' => $holderPool,
                    'issuer' => $issuer,
                ],

                'issuer' => [
                    'name' => 'Blockchain Group Ltd. (Hong Kong)',
                    'registry' => 'RWA Standard Organisation (RSO)',
                    'project' => 'POAdo / AdoptGold',
                ],
            ],
        ];
    }
}