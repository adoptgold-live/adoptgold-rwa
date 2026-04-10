<?php
declare(strict_types=1);

/**
 * Immutable artifact CDN helper
 */

if (function_exists('cert_artifact_cdn_url_from_local')) {
    return;
}

require_once __DIR__ . '/_metadata-cdn.php';

function cert_artifact_cdn_url_from_local(string $absolutePath): string
{
    return cert_metadata_cdn_url_from_local($absolutePath);
}
