<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/api/_meta-image-map.php
 * Version: v1.2.1-20260330-locked-qr-map
 *
 * Master QR position source.
 * This file is the authoritative map for template image file + QR x/y/size.
 */

function cert_v2_meta_image_map(): array
{
    return [
        'green' => [
            'file' => 'rco2c.png',
            'qr' => ['x' => 422, 'y' => 392, 'size' => 200],
        ],
        'blue' => [
            'file' => 'rh2o.png',
            'qr' => ['x' => 425, 'y' => 444, 'size' => 200],
        ],
        'black' => [
            'file' => 'rblack.png',
            'qr' => ['x' => 423, 'y' => 419, 'size' => 200],
        ],
        'gold' => [
            'file' => 'rk92.png',
            'qr' => ['x' => 404, 'y' => 425, 'size' => 216],
        ],
        'yellow' => [
            'file' => 'rhrd.png',
            'qr' => ['x' => 409, 'y' => 510, 'size' => 210],
        ],
        'pink' => [
            'file' => 'rlife.png',
            'qr' => ['x' => 419, 'y' => 557, 'size' => 200],
        ],
        'royal_blue' => [
            'file' => 'rprop.png',
            'qr' => ['x' => 455, 'y' => 505, 'size' => 180],
        ],
        'red' => [
            'file' => 'rtrip.png',
            'qr' => ['x' => 434, 'y' => 531, 'size' => 180],
        ],
    ];
}
