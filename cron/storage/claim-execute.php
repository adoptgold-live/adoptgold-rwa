<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cron/storage/claim-execute.php
 * Claim v7.9
 * Cron executor for outgoing claim settlement
 *
 * Purpose:
 * - scan PREPARED / PENDING_EXECUTION claim rows
 * - submit one claim at a time through Blueprint send script
 * - persist tx_hash
 * - write CLAIM_SENT history
 *
 * Critical locked rules:
 * - exact table names only:
 *     poado_storage_claims
 *     poado_storage_claim_reserves
 * - use filesystem-safe includes only
 * - never use $_SERVER['DOCUMENT_ROOT'] in cron
 * - no silent execution without DB row
 * - idempotent row-level processing
 *
 * Expected external script behavior:
 * - Blueprint script returns single-line JSON:
 *   {"ok":true,"tx_hash":"...","claim_ref":"CLM-..."}
 *
 * Example deployment script path assumed here:
 *   /var/www/html/public/rwa/claim/contract/scripts/sendClaim.ts
 *
 * This cron is production-safe skeleton.
 * You must wire the Blueprint script before real execution.
 */

require_once dirname(__DIR__, 2) . '/inc/core/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI_ONLY\n");
}

date_default_timezone_set('UTC');

const CLAIM_EXECUTE_LIMIT = 10;
const CLAIM_TOKEN = 'EMA';
const CLAIM_BLUEPRINT_ROOT = '/var/www/html/public/rwa/claim/contract';
const CLAIM_SEND_SCRIPT_NAME = 'sendClaim';

function log_line(string $msg): void
{
    $ts = gmdate('Y-m-d H:i:s');
    echo '[' . $ts . ' UTC] ' . $msg . PHP_EOL;
}

function claim_exec_db(): PDO
{
    return db();
}

function history_write_claim_sent(PDO $pdo, array $claim, string $txHash): void
{
    $tables = ['rwa_storage_history', 'poado_storage_history'];
    $metaJson = json_encode([
        'wallet_address' => (string)$claim['wallet_address'],
        'amount_units'   => (string)$claim['amount_units'],
        'source'         => 'claim-execute-cron'
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    foreach ($tables as $table) {
        try {
            $sql = "INSERT INTO {$table}
                    (user_id, event_type, token, amount, ref, tx_hash, meta_json, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
            $st = $pdo->prepare($sql);
            $st->execute([
                (int)$claim['user_id'],
                'CLAIM_SENT',
                (string)$claim['token'],
                (string)$claim['amount_ema'],
                (string)$claim['claim_ref'],
                $txHash,
                $metaJson
            ]);
            return;
        } catch (Throwable $e) {
            // try next table
        }
    }
}

function claim_mark_pending(PDO $pdo, int $id): bool
{
    $sql = "UPDATE poado_storage_claims
            SET status='PENDING_EXECUTION', updated_at=NOW()
            WHERE id=? AND status='PREPARED'
            LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([$id]);
    return $st->rowCount() === 1;
}

function claim_fetch_queue(PDO $pdo, int $limit): array
{
    $sql = "SELECT *
            FROM poado_storage_claims
            WHERE status IN ('PREPARED','PENDING_EXECUTION')
            ORDER BY id ASC
            LIMIT " . (int)$limit;
    $st = $pdo->query($sql);
    return $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
}

function claim_send_via_blueprint(array $claim): array
{
    $blueprintBin = trim((string)shell_exec('command -v npx 2>/dev/null'));
    if ($blueprintBin === '') {
        return ['ok' => false, 'error' => 'NPX_NOT_FOUND'];
    }

    $workDir = CLAIM_BLUEPRINT_ROOT;
    if (!is_dir($workDir)) {
        return ['ok' => false, 'error' => 'CLAIM_BLUEPRINT_ROOT_NOT_FOUND'];
    }

    $args = [
        $blueprintBin,
        'blueprint',
        'run',
        CLAIM_SEND_SCRIPT_NAME,
        '--',
        '--claim-ref=' . (string)$claim['claim_ref'],
        '--to=' . (string)$claim['wallet_address'],
        '--amount-units=' . (string)$claim['amount_units'],
        '--token=' . (string)$claim['token'],
    ];

    $cmd = implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
    $output = [];
    $exitCode = 1;

    exec('cd ' . escapeshellarg($workDir) . ' && ' . $cmd, $output, $exitCode);

    $raw = trim(implode("\n", $output));
    if ($exitCode !== 0) {
        return ['ok' => false, 'error' => 'BLUEPRINT_RUN_FAILED', 'raw' => $raw];
    }

    $lines = preg_split("/\r\n|\n|\r/", $raw);
    $last = trim((string)end($lines));
    $json = json_decode($last, true);

    if (!is_array($json) || empty($json['ok'])) {
        return ['ok' => false, 'error' => 'BLUEPRINT_INVALID_JSON', 'raw' => $raw];
    }

    $txHash = trim((string)($json['tx_hash'] ?? ''));
    if ($txHash === '') {
        return ['ok' => false, 'error' => 'TX_HASH_MISSING', 'raw' => $raw];
    }

    return [
        'ok'       => true,
        'tx_hash'  => $txHash,
        'raw'      => $raw,
        'result'   => $json,
    ];
}

function claim_fail(PDO $pdo, int $id, array $metaPatch): void
{
    $sql = "UPDATE poado_storage_claims
            SET status='FAILED',
                meta_json=?,
                updated_at=NOW()
            WHERE id=?
            LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([
        json_encode($metaPatch, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        $id
    ]);
}

function claim_mark_sent(PDO $pdo, array $claim, string $txHash, array $metaPatch): void
{
    $sql = "UPDATE poado_storage_claims
            SET status='SENT',
                tx_hash=?,
                sent_at=NOW(),
                meta_json=?,
                updated_at=NOW()
            WHERE id=?
            LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([
        $txHash,
        json_encode($metaPatch, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        (int)$claim['id']
    ]);
}

function claim_merge_meta(?string $oldMetaJson, array $patch): array
{
    $old = json_decode((string)$oldMetaJson, true);
    if (!is_array($old)) {
        $old = [];
    }
    return array_merge($old, $patch);
}

function run_claim_execute(): int
{
    $pdo = claim_exec_db();
    $rows = claim_fetch_queue($pdo, CLAIM_EXECUTE_LIMIT);

    if (!$rows) {
      log_line('No queued claim rows.');
      return 0;
    }

    log_line('Found queued claims: ' . count($rows));

    $processed = 0;

    foreach ($rows as $row) {
        $claimId = (int)$row['id'];
        $claimRef = (string)$row['claim_ref'];
        $status = (string)$row['status'];

        log_line("Processing claim {$claimRef} (status={$status})");

        try {
            if ($status === 'PREPARED') {
                $locked = claim_mark_pending($pdo, $claimId);
                if (!$locked) {
                    log_line("Skip {$claimRef}: failed to acquire row lock.");
                    continue;
                }
                $row['status'] = 'PENDING_EXECUTION';
            }

            $send = claim_send_via_blueprint($row);

            if (empty($send['ok'])) {
                $meta = claim_merge_meta((string)($row['meta_json'] ?? ''), [
                    'execute_error' => (string)($send['error'] ?? 'UNKNOWN'),
                    'execute_raw'   => (string)($send['raw'] ?? ''),
                    'execute_at'    => gmdate('c')
                ]);
                claim_fail($pdo, $claimId, $meta);
                log_line("FAILED {$claimRef}: " . (string)($send['error'] ?? 'UNKNOWN'));
                continue;
            }

            $txHash = (string)$send['tx_hash'];
            $meta = claim_merge_meta((string)($row['meta_json'] ?? ''), [
                'execute_at'    => gmdate('c'),
                'execute_raw'   => (string)($send['raw'] ?? ''),
                'executor'      => 'claim-execute-cron',
                'blueprint_ok'  => true
            ]);

            $pdo->beginTransaction();
            try {
                claim_mark_sent($pdo, $row, $txHash, $meta);
                history_write_claim_sent($pdo, $row, $txHash);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            $processed++;
            log_line("SENT {$claimRef} => {$txHash}");
        } catch (Throwable $e) {
            log_line("ERROR {$claimRef}: " . $e->getMessage());
            try {
                $meta = claim_merge_meta((string)($row['meta_json'] ?? ''), [
                    'fatal_error' => $e->getMessage(),
                    'fatal_at'    => gmdate('c')
                ]);
                claim_fail($pdo, $claimId, $meta);
            } catch (Throwable $ignore) {
            }
        }
    }

    log_line("Done. Processed SENT count = {$processed}");
    return 0;
}

exit(run_claim_execute());