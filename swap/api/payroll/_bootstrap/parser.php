<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/swap/api/payroll/_bootstrap/parser.php
 * Version: v0.1.0-20260409-swap-payroll-parser-stub
 *
 * Temporary stub.
 * Next step will replace this with exact PhpSpreadsheet parser
 * for the uploaded Daywork Claim sheet structure.
 */

if (defined('SWAP_PAYROLL_PARSER_BOOTSTRAPPED')) {
    return;
}
define('SWAP_PAYROLL_PARSER_BOOTSTRAPPED', true);

function swap_payroll_parser_parse_file(string $tmpFile, string $originalName = ''): array
{
    if (!is_file($tmpFile)) {
        throw new RuntimeException('PAYROLL_SOURCE_FILE_NOT_FOUND');
    }

    throw new RuntimeException('PAYROLL_PARSER_NOT_IMPLEMENTED_YET');
}
