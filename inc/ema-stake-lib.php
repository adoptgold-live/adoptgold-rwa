<?php
declare(strict_types=1);

function ema_required_for_tier(string $tier): float
{
    return [
        'sub' => 100,
        'core' => 1000,
        'nodes' => 5000,
        'super_node' => 100000,
    ][$tier] ?? 0;
}

function ema_resolve_tier(float $ema): string
{
    if ($ema >= 100000) return 'super_node';
    if ($ema >= 5000) return 'nodes';
    if ($ema >= 1000) return 'core';
    if ($ema >= 100) return 'sub';
    return 'free';
}
