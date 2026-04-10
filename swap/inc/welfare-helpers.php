<?php
declare(strict_types=1);

if (!function_exists('swap_welfare_yes')) {
    function swap_welfare_yes(?string $v): bool
    {
        $s = strtolower(trim((string)$v));
        return in_array($s, ['yes', 'y', 'active', 'valid', 'approved', 'registered', 'passed', 'ready'], true);
    }
}

if (!function_exists('swap_welfare_date_valid')) {
    function swap_welfare_date_valid(?string $date, int $warnDays = 0): array
    {
        $date = trim((string)$date);
        if ($date === '') {
            return ['ok' => false, 'warn' => false];
        }

        try {
            $d = new DateTimeImmutable($date);
            $now = new DateTimeImmutable('today');
            $diff = (int)$now->diff($d)->format('%r%a');

            if ($d < $now) {
                return ['ok' => false, 'warn' => false];
            }

            if ($warnDays > 0 && $diff <= $warnDays) {
                return ['ok' => true, 'warn' => true];
            }

            return ['ok' => true, 'warn' => false];
        } catch (Throwable $e) {
            return ['ok' => false, 'warn' => false];
        }
    }
}

if (!function_exists('swap_welfare_hours_score')) {
    function swap_welfare_hours_score(string $workerUid, PDO $pdo): array
    {
        $today = new DateTimeImmutable('today');
        $weekStart = $today->modify('monday this week')->format('Y-m-d');
        $weekEnd = $today->modify('sunday this week')->format('Y-m-d');

        $stmt = $pdo->prepare("
            SELECT work_date, hours_worked
            FROM rwa_hr_work_logs
            WHERE worker_uid = :worker_uid
              AND approval_status = 'approved'
              AND work_date BETWEEN :week_start AND :week_end
            ORDER BY work_date ASC
        ");
        $stmt->execute([
            ':worker_uid' => $workerUid,
            ':week_start' => $weekStart,
            ':week_end' => $weekEnd,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $weeklyTotal = 0.0;
        $majorFlag = false;
        $minorFlag = false;

        foreach ($rows as $r) {
            $hrs = (float)($r['hours_worked'] ?? 0);
            $weeklyTotal += $hrs;

            if ($hrs > 10) {
                $majorFlag = true;
            } elseif ($hrs > 8) {
                $minorFlag = true;
            }
        }

        if ($weeklyTotal > 45) {
            $minorFlag = true;
        }

        if ($majorFlag) {
            return [
                'score' => 0,
                'status' => 'major_flag',
                'weekly_total' => $weeklyTotal,
            ];
        }

        if ($minorFlag) {
            return [
                'score' => 8,
                'status' => 'minor_flag',
                'weekly_total' => $weeklyTotal,
            ];
        }

        return [
            'score' => 15,
            'status' => 'normal',
            'weekly_total' => $weeklyTotal,
        ];
    }
}

if (!function_exists('swap_welfare_calculate')) {
    function swap_welfare_calculate(array $w, PDO $pdo): array
    {
        $ruleHits = [];

        // A. Legal Status = 25
        $permit = swap_welfare_date_valid((string)($w['permit_expiry'] ?? ''), 60);
        if ($permit['ok'] && !$permit['warn']) {
            $legalScore = 25;
        } elseif ($permit['ok'] && $permit['warn']) {
            $legalScore = 15;
            $ruleHits[] = 'PERMIT_EXPIRING_SOON';
        } else {
            $legalScore = 0;
            $ruleHits[] = 'PERMIT_INVALID';
        }

        // B. Medical = 20
        $fomemaStatus = strtolower(trim((string)($w['fomema_status'] ?? '')));
        $fomemaExpiry = swap_welfare_date_valid((string)($w['fomema_expiry'] ?? ''), 30);
        if ($fomemaStatus === 'passed' && $fomemaExpiry['ok']) {
            $medicalScore = 20;
        } elseif ($fomemaStatus === 'pending') {
            $medicalScore = 10;
            $ruleHits[] = 'FOMEMA_PENDING';
        } else {
            $medicalScore = 0;
            $ruleHits[] = 'FOMEMA_INVALID';
        }

        // C. Social Protection = 20
        $socsoStatus = strtolower(trim((string)($w['socso_status'] ?? '')));
        if (in_array($socsoStatus, ['registered', 'active'], true)) {
            $socialScore = 20;
        } elseif ($socsoStatus === 'pending') {
            $socialScore = 10;
            $ruleHits[] = 'SOCSO_PENDING';
        } else {
            $socialScore = 0;
            $ruleHits[] = 'SOCSO_MISSING';
        }

        // D. Accommodation = 20
        $hostelStatus = strtolower(trim((string)($w['hostel_status'] ?? '')));
        if ($hostelStatus === 'approved') {
            $accommodationScore = 20;
        } elseif (in_array($hostelStatus, ['pending', 'temporary'], true)) {
            $accommodationScore = 10;
            $ruleHits[] = 'HOSTEL_PENDING';
        } else {
            $accommodationScore = 0;
            $ruleHits[] = 'HOSTEL_MISSING';
        }

        // E. Safe Work Conditions = 15
        $hours = swap_welfare_hours_score((string)$w['worker_uid'], $pdo);
        $workScore = (int)$hours['score'];
        if ($hours['status'] === 'minor_flag') {
            $ruleHits[] = 'WORK_HOURS_CAUTION';
        } elseif ($hours['status'] === 'major_flag') {
            $ruleHits[] = 'WORK_HOURS_BREACH';
        }

        $score = $legalScore + $medicalScore + $socialScore + $accommodationScore + $workScore;

        if ($score >= 90) {
            $band = 'Protected';
        } elseif ($score >= 70) {
            $band = 'Stable';
        } elseif ($score >= 50) {
            $band = 'At Risk';
        } else {
            $band = 'Critical';
        }

        $hardStop = false;
        if ($legalScore === 0 || $medicalScore === 0 || $socialScore === 0 || $accommodationScore === 0 || $workScore === 0) {
            $hardStop = true;
        }

        $deployable = $hardStop ? 'no' : 'yes';

        if ($band === 'Critical') {
            $riskLevel = 'high';
        } elseif ($band === 'At Risk') {
            $riskLevel = 'medium';
        } else {
            $riskLevel = 'low';
        }

        $nextAction = 'No action required';
        if (in_array('PERMIT_INVALID', $ruleHits, true)) $nextAction = 'Renew permit';
        elseif (in_array('FOMEMA_INVALID', $ruleHits, true)) $nextAction = 'Complete medical compliance';
        elseif (in_array('SOCSO_MISSING', $ruleHits, true)) $nextAction = 'Register SOCSO';
        elseif (in_array('HOSTEL_MISSING', $ruleHits, true)) $nextAction = 'Update accommodation status';
        elseif (in_array('WORK_HOURS_BREACH', $ruleHits, true)) $nextAction = 'Review work hours immediately';

        return [
            'welfare_score' => $score,
            'welfare_band' => $band,
            'deployable_status' => $deployable,
            'risk_level' => $riskLevel,
            'next_action' => $nextAction,
            'rule_hits' => $ruleHits,
        ];
    }
}