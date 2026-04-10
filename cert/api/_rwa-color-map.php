<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/api/_rwa-color-map.php
 *
 * Global RWA Core Color Registry
 *
 * Locked rule:
 * - every RWA must carry BOTH:
 *   1) core_color_word
 *   2) core_color_hex
 *
 * - no free naming
 * - no hex-only usage
 * - no word-only usage
 * - WORD + HEX must always pair
 */

return [

    /*
    |--------------------------------------------------------------------------
    | GENESIS
    |--------------------------------------------------------------------------
    */

    'green' => [
        'family' => 'GENESIS',
        'rwa_code' => 'GREEN',
        'label' => 'Green Cert',
        'core_color_word' => 'Green',
        'core_color_hex' => '#22C55E',
        'support_colors' => [
            '#0B3D20',
            '#A7F3D0',
            '#E8FFF1',
        ],
    ],

    'rco2c' => [
        'family' => 'GENESIS',
        'rwa_code' => 'RCO2C',
        'label' => 'Green Cert',
        'core_color_word' => 'Green',
        'core_color_hex' => '#22C55E',
        'support_colors' => [
            '#0B3D20',
            '#A7F3D0',
            '#E8FFF1',
        ],
    ],

    'blue' => [
        'family' => 'GENESIS',
        'rwa_code' => 'BLUE',
        'label' => 'Blue Cert',
        'core_color_word' => 'Blue',
        'core_color_hex' => '#3B82F6',
        'support_colors' => [
            '#0A2540',
            '#BAE6FD',
            '#EAF8FF',
        ],
    ],

    'rh2o' => [
        'family' => 'GENESIS',
        'rwa_code' => 'RH2O',
        'label' => 'Blue Cert',
        'core_color_word' => 'Blue',
        'core_color_hex' => '#3B82F6',
        'support_colors' => [
            '#0A2540',
            '#BAE6FD',
            '#EAF8FF',
        ],
    ],

    'black' => [
        'family' => 'GENESIS',
        'rwa_code' => 'BLACK',
        'label' => 'Black Cert',
        'core_color_word' => 'Black',
        'core_color_hex' => '#111111',
        'support_colors' => [
            '#2B2B2B',
            '#6B7280',
            '#E5E7EB',
        ],
    ],

    'rblack' => [
        'family' => 'GENESIS',
        'rwa_code' => 'RBLACK',
        'label' => 'Black Cert',
        'core_color_word' => 'Black',
        'core_color_hex' => '#111111',
        'support_colors' => [
            '#2B2B2B',
            '#6B7280',
            '#E5E7EB',
        ],
    ],

    'gold' => [
        'family' => 'GENESIS',
        'rwa_code' => 'GOLD',
        'label' => 'Gold Cert',
        'core_color_word' => 'Gold',
        'core_color_hex' => '#D4AF37',
        'support_colors' => [
            '#7A5A00',
            '#F7E7A1',
            '#FFF8DC',
        ],
    ],

    'rk92' => [
        'family' => 'GENESIS',
        'rwa_code' => 'RK92',
        'label' => 'Gold Cert',
        'core_color_word' => 'Gold',
        'core_color_hex' => '#D4AF37',
        'support_colors' => [
            '#7A5A00',
            '#F7E7A1',
            '#FFF8DC',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | TERTIARY
    |--------------------------------------------------------------------------
    */

    'tertiary' => [
        'family' => 'TERTIARY',
        'rwa_code' => 'TERTIARY',
        'label' => 'Tertiary RWA',
        'core_color_word' => 'Purple',
        'core_color_hex' => '#7C3AED',
        'support_colors' => [
            '#312E81',
            '#C4B5FD',
            '#F3E8FF',
        ],
    ],

    'rhrd' => [
        'family' => 'TERTIARY',
        'rwa_code' => 'RHRD',
        'label' => 'Human Resources RWA',
        'core_color_word' => 'Purple',
        'core_color_hex' => '#7C3AED',
        'support_colors' => [
            '#312E81',
            '#C4B5FD',
            '#F3E8FF',
        ],
    ],

    'rhrdema' => [
        'family' => 'TERTIARY',
        'rwa_code' => 'RHRD-EMA',
        'label' => 'Human Resources RWA',
        'core_color_word' => 'Purple',
        'core_color_hex' => '#7C3AED',
        'support_colors' => [
            '#312E81',
            '#C4B5FD',
            '#F3E8FF',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | SECONDARY
    |--------------------------------------------------------------------------
    */

    'secondary' => [
        'family' => 'SECONDARY',
        'rwa_code' => 'SECONDARY',
        'label' => 'Secondary RWA',
        'core_color_word' => 'Royal Blue',
        'core_color_hex' => '#2563EB',
        'support_colors' => [
            '#1E3A8A',
            '#93C5FD',
            '#EFF6FF',
        ],
    ],

    'rlife' => [
        'family' => 'SECONDARY',
        'rwa_code' => 'RLIFE',
        'label' => 'Health RWA',
        'core_color_word' => 'Pink',
        'core_color_hex' => '#EC4899',
        'support_colors' => [
            '#831843',
            '#F9A8D4',
            '#FFF1F7',
        ],
    ],

    'rprop' => [
        'family' => 'SECONDARY',
        'rwa_code' => 'RPROP',
        'label' => 'Property RWA',
        'core_color_word' => 'Royal Blue',
        'core_color_hex' => '#2563EB',
        'support_colors' => [
            '#1E3A8A',
            '#93C5FD',
            '#EFF6FF',
        ],
    ],

    'rtrip' => [
        'family' => 'SECONDARY',
        'rwa_code' => 'RTRIP',
        'label' => 'Travel RWA',
        'core_color_word' => 'Red',
        'core_color_hex' => '#EF4444',
        'support_colors' => [
            '#7F1D1D',
            '#FCA5A5',
            '#FFF1F2',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | OPTIONAL FUTURE SECONDARY
    |--------------------------------------------------------------------------
    */

    'rfood' => [
        'family' => 'SECONDARY',
        'rwa_code' => 'RFOOD',
        'label' => 'Food RWA',
        'core_color_word' => 'Orange',
        'core_color_hex' => '#F97316',
        'support_colors' => [
            '#7C2D12',
            '#FDBA74',
            '#FFF7ED',
        ],
    ],

    'rcare' => [
        'family' => 'SECONDARY',
        'rwa_code' => 'RCARE',
        'label' => 'Care RWA',
        'core_color_word' => 'Rose',
        'core_color_hex' => '#E11D48',
        'support_colors' => [
            '#881337',
            '#FDA4AF',
            '#FFF1F2',
        ],
    ],

    'rlogi' => [
        'family' => 'SECONDARY',
        'rwa_code' => 'RLOGI',
        'label' => 'Logistics RWA',
        'core_color_word' => 'Sky Blue',
        'core_color_hex' => '#0EA5E9',
        'support_colors' => [
            '#0C4A6E',
            '#7DD3FC',
            '#F0F9FF',
        ],
    ],

    'rcomm' => [
        'family' => 'SECONDARY',
        'rwa_code' => 'RCOMM',
        'label' => 'Commerce RWA',
        'core_color_word' => 'Emerald',
        'core_color_hex' => '#10B981',
        'support_colors' => [
            '#064E3B',
            '#6EE7B7',
            '#ECFDF5',
        ],
    ],

    'redu' => [
        'family' => 'SECONDARY',
        'rwa_code' => 'REDU',
        'label' => 'Education RWA',
        'core_color_word' => 'Indigo',
        'core_color_hex' => '#6366F1',
        'support_colors' => [
            '#312E81',
            '#A5B4FC',
            '#EEF2FF',
        ],
    ],

    'rlegal' => [
        'family' => 'SECONDARY',
        'rwa_code' => 'RLEGAL',
        'label' => 'Legal RWA',
        'core_color_word' => 'Slate',
        'core_color_hex' => '#475569',
        'support_colors' => [
            '#1E293B',
            '#CBD5E1',
            '#F8FAFC',
        ],
    ],
];
