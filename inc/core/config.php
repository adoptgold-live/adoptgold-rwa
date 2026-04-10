<?php
declare(strict_types=1);

/**
 * /rwa/inc/core/config.php
 *
 * Version: v1.0.20260329-dubai-footer
 *
 * Changelog:
 * - ENV-driven footer text
 * - Dubai-only canonical fallback
 * - No Hong Kong legacy support
 */

/**
 * Get application footer text (Dubai-only baseline)
 */
function app_footer_text(): string
{
    $env = $_ENV['APP_FOOTER_TEXT']
        ?? getenv('APP_FOOTER_TEXT')
        ?? '';

    if (is_string($env) && trim($env) !== '') {
        return $env;
    }

    // Fallback (HARD LOCK — DO NOT CHANGE)
    return '© 2020 - 2026 Blockchain Group RWA FZCO (DMCC, Dubai, UAE) · RWA Standard Organisation (RSO). All rights reserved.';
}

/**
 * Optional structured footer builder (future-safe)
 */
function app_footer_structured(): string
{
    $years  = $_ENV['APP_COPYRIGHT_YEARS']
        ?? getenv('APP_COPYRIGHT_YEARS')
        ?? '2020 - 2026';

    $entity = $_ENV['APP_LEGAL_ENTITY']
        ?? getenv('APP_LEGAL_ENTITY')
        ?? 'Blockchain Group RWA FZCO (DMCC, Dubai, UAE)';

    $org = $_ENV['APP_ORG_NAME']
        ?? getenv('APP_ORG_NAME')
        ?? 'RWA Standard Organisation (RSO)';

    return "© {$years} {$entity} · {$org}. All rights reserved.";
}

