<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cron/storage/commit-auto-verify.php
 * Storage Master v7.8
 * FINAL-LOCK-1
 *
 * Production-safe Commit auto verify cron
 * - scans pending commits
 * - verifies by token + amount + ref
 * - destination not required
 * - applies ledger exactly once
 * - self-heals already confirmed rows missing ledger_applied
 */

require_once __DIR__ . '/../../inc/core/bootstrap.php';
require_once __DIR__ . '/../../api/storage/commit/_bootstrap.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

function commit_cron_log(string $msg): void
{
    echo '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $msg . PHP_EOL;
}

try {
    $pdo = commit_pdo();

    $st = $pdo->prepare("
        SELECT *
        FROM poado_storage_commits
        WHERE status IN ('PENDING','CONFIRMED')
        ORDER BY id ASC
        LIMIT 100
    ");
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $checked = 0;
    $confirmed = 0;
    $repaired = 0;
    $skipped = 0;
    $failed = 0;

    foreach ($rows as $row) {
        $checked++;

        try {
            $id = (int)($row['id'] ?? 0);
            $uid = (int)($row['user_id'] ?? 0);
            $ref = commit_str($row['commit_ref'] ?? '');
            $status = strtoupper(commit_str($row['status'] ?? ''));
            $wallet = commit_str($row['wallet_address'] ?? '');
            $amountUnits = commit_digits_only(commit_str($row['amount_units'] ?? ''));

            if ($id <= 0 || $uid <= 0 || $ref === '' || $wallet === '' || $amountUnits === '' || $amountUnits === '0') {
                $skipped++;
                commit_cron_log("skip invalid row id={$id} ref={$ref}");
                continue;
            }

            if ($status === COMMIT_STATUS_CONFIRMED) {
                $pdo->beginTransaction();
                $fresh = commit_find_row($pdo, $ref);
                if (!$fresh) {
                    throw new RuntimeException('CONFIRMED_ROW_NOT_FOUND');
                }

                $repair = commit_repair_confirmed_ledger_if_missing($pdo, $uid, $fresh);
                $pdo->commit();

                if (!empty($repair['repaired'])) {
                    $repaired++;
                    commit_cron_log("repaired confirmed ledger ref={$ref}");
                } else {
                    $skipped++;
                }
                continue;
            }

            $verify = rwa_onchain_verify_jetton_transfer([
                'owner_address' => $wallet,
                'token_key' => COMMIT_TOKEN,
                'jetton_master' => commit_jetton_master(),
                'amount_units' => $amountUnits,
                'ref' => $ref,
                'limit' => 100,
            ]);

            if (($verify['ok'] ?? false) !== true) {
                $skipped++;
                continue;
            }

            $txHash = commit_str($verify['tx_hash'] ?? '');
            $confirmations = (int)($verify['confirmations'] ?? COMMIT_REQUIRED_CONFIRMATIONS);
            if ($confirmations <= 0) {
                $confirmations = COMMIT_REQUIRED_CONFIRMATIONS;
            }
            if ($confirmations < COMMIT_REQUIRED_CONFIRMATIONS) {
                $skipped++;
                continue;
            }

            $pdo->beginTransaction();

            $fresh = commit_find_row($pdo, $ref);
            if (!$fresh) {
                throw new RuntimeException('PENDING_ROW_NOT_FOUND_AFTER_LOCK');
            }

            $freshStatus = strtoupper(commit_str($fresh['status'] ?? ''));
            if ($freshStatus === COMMIT_STATUS_CONFIRMED) {
                $repair = commit_repair_confirmed_ledger_if_missing($pdo, $uid, $fresh);
                $pdo->commit();

                if (!empty($repair['repaired'])) {
                    $repaired++;
                    commit_cron_log("repaired race-confirmed ledger ref={$ref}");
                } else {
                    $skipped++;
                }
                continue;
            }

            $ledger = commit_apply_ledger($pdo, $uid, $fresh);

            $meta = [
                'flow' => 'storage_commit',
                'verified_via' => 'cron_auto_verify',
                'version' => STORAGE_COMMIT_VERSION,
                'commit_ref' => $ref,
                'wallet_address' => $wallet,
                'treasury_address' => commit_str($fresh['treasury_address'] ?? ''),
                'jetton_master' => commit_jetton_master(),
                'token_key' => COMMIT_TOKEN,
                'amount_units' => $amountUnits,
                'amount_emx' => $ledger['amount_emx'],
                'tx_hash' => $txHash,
                'confirmations' => $confirmations,
                'payload_text' => (string)($verify['payload_text'] ?? ''),
                'match_jetton' => (bool)($verify['match_jetton'] ?? false),
                'match_amount' => (bool)($verify['match_amount'] ?? false),
                'match_ref' => (bool)($verify['match_ref'] ?? false),
                'verified_at' => commit_now(),
                'raw_transfer' => $verify['raw_transfer'] ?? null,
                'ledger_applied' => true,
                'ledger_applied_at' => commit_now(),
                'ledger_source' => 'cron_auto_verify',
                'ema_price_snapshot' => $ledger['ema_price'],
                'ema_reward' => $ledger['ema_reward'],
            ];

            commit_mark_confirmed(
                $pdo,
                (int)$fresh['id'],
                $txHash,
                $confirmations,
                $meta
            );

            commit_mark_ledger_applied(
                $pdo,
                (int)$fresh['id'],
                $meta,
                $ledger['amount_emx'],
                $ledger['ema_price'],
                $ledger['ema_reward'],
                'cron_auto_verify'
            );

            $pdo->commit();

            $confirmed++;
            commit_cron_log("confirmed ref={$ref} tx={$txHash}");
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $failed++;
            commit_cron_log("error ref=" . commit_str($row['commit_ref'] ?? '') . " detail=" . $e->getMessage());
        }
    }

    commit_cron_log("done checked={$checked} confirmed={$confirmed} repaired={$repaired} skipped={$skipped} failed={$failed}");
    exit(0);
} catch (Throwable $e) {
    commit_cron_log('fatal ' . $e->getMessage());
    exit(1);
}