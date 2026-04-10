<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/api/_queue-engine.php
 * Version: v1.0.0-20260407-unified-queue-engine
 *
 * Canonical queue truth:
 * - minted/listed OR nft_minted=1 OR nft_item_address present OR minted_at present => issued
 * - revoked/blocked/cancelled/expired/failed => blocked
 * - payment confirmed/verified + artifact ready => mint_ready_queue
 * - payment confirmed/verified + artifact not ready => minting_process
 * - otherwise => issuance_factory
 */

function cert_qe_str(mixed $v): string
{
    return trim((string)($v ?? ''));
}

function cert_qe_bool(mixed $v): bool
{
    if (is_bool($v)) return $v;
    if (is_int($v) || is_float($v)) return ((int)$v) !== 0;
    $s = strtolower(trim((string)$v));
    return in_array($s, ['1','true','yes','y','on'], true);
}

function cert_qe_norm_status(mixed $v): string
{
    return strtolower(cert_qe_str($v));
}

function cert_qe_payment_ready(array $payment, array $meta = []): bool
{
    $status = cert_qe_norm_status($payment['status'] ?? ($meta['payment']['status'] ?? ''));
    $verified = cert_qe_bool($payment['verified'] ?? ($meta['payment']['verified'] ?? 0));
    return $status === 'confirmed' || $verified;
}

function cert_qe_artifact_ready(array $row, array $meta = []): bool
{
    if (cert_qe_bool($row['artifact_ready'] ?? false)) return true;
    if (cert_qe_bool($meta['artifact_ready'] ?? false)) return true;

    $nftHealth = $meta['nft_health'] ?? [];
    if (is_array($nftHealth) && (cert_qe_bool($nftHealth['healthy'] ?? false) || cert_qe_bool($nftHealth['ok'] ?? false))) {
        return true;
    }

    $mintArtifact = $meta['mint']['artifact_health'] ?? [];
    if (is_array($mintArtifact) && (cert_qe_bool($mintArtifact['healthy'] ?? false) || cert_qe_bool($mintArtifact['ok'] ?? false))) {
        return true;
    }

    $verify = $meta['verify'] ?? [];
    if (is_array($verify) && (cert_qe_bool($verify['healthy'] ?? false) || cert_qe_bool($verify['ok'] ?? false))) {
        return true;
    }

    return false;
}

function cert_qe_nft_minted(array $row, array $meta = []): bool
{
    if (cert_qe_bool($row['nft_minted'] ?? 0)) return true;
    if (cert_qe_str($row['nft_item_address'] ?? '') !== '') return true;
    if (cert_qe_str($row['minted_at'] ?? '') !== '') return true;

    $status = cert_qe_norm_status($row['status'] ?? '');
    if (in_array($status, ['minted','listed'], true)) return true;

    if (cert_qe_bool($meta['nft_minted'] ?? false)) return true;
    if (cert_qe_str($meta['nft_item_address'] ?? '') !== '') return true;
    if (cert_qe_str($meta['minted_at'] ?? '') !== '') return true;

    return false;
}

function cert_qe_blocked(array $row): bool
{
    $status = cert_qe_norm_status($row['status'] ?? '');
    return in_array($status, ['revoked','blocked','cancelled','expired','failed'], true);
}

function cert_qe_bucket(array $row, array $payment = [], array $meta = []): string
{
    if (cert_qe_nft_minted($row, $meta)) {
        return 'issued';
    }

    if (cert_qe_blocked($row)) {
        return 'blocked';
    }

    $paymentReady = cert_qe_payment_ready($payment, $meta);
    $artifactReady = cert_qe_artifact_ready($row, $meta);

    if ($paymentReady && $artifactReady) {
        return 'mint_ready_queue';
    }

    if ($paymentReady && !$artifactReady) {
        return 'minting_process';
    }

    return 'issuance_factory';
}
