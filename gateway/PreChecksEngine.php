<?php
/**
 * ============================================================
 * DI PARMA | Stage 4: Pre-checks Engine
 * ============================================================
 * • Device Authentication
 * • Amount Validation
 * • AML / Risk Rules
 * • Fraud Screening
 * • Duplicate Detection
 * • Merchant Authentication
 * • Routing Logic
 * ============================================================
 */
class PreChecksEngine
{
    private array $config;
    private ?object $db;

    // Thresholds
    const MAX_SINGLE_TXN   = 50000.0;   // USD
    const MAX_DAILY_LIMIT  = 100000.0;  // USD per terminal
    const MIN_AMOUNT       = 1.0;       // USD
    const DUPLICATE_WINDOW = 60;        // seconds

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'aml_enabled'       => true,
            'fraud_enabled'     => true,
            'duplicate_check'   => true,
            'risk_score_limit'  => 75,   // 0-100
        ], $config);

        try {
            require_once __DIR__ . '/../includes/database.php';
            $this->db = db();
        } catch (Exception $e) {
            $this->db = null;
        }
    }

    // ── MAIN ENTRY POINT ────────────────────────────────────
    public function run(array $txn): array
    {
        $checks = [];
        $passed = true;
        $start  = microtime(true);

        // 1. Amount Validation
        $amountCheck = $this->checkAmount($txn);
        $checks['amount_validation'] = $amountCheck;
        if (!$amountCheck['passed']) { $passed = false; }

        // 2. Device Authentication
        $deviceCheck = $this->checkDevice($txn);
        $checks['device_auth'] = $deviceCheck;
        if (!$deviceCheck['passed']) { $passed = false; }

        // 3. Merchant Authentication
        $merchantCheck = $this->checkMerchant($txn);
        $checks['merchant_auth'] = $merchantCheck;
        if (!$merchantCheck['passed']) { $passed = false; }

        // 4. Duplicate Detection
        if ($this->config['duplicate_check'] && $passed) {
            $dupCheck = $this->checkDuplicate($txn);
            $checks['duplicate_detection'] = $dupCheck;
            if (!$dupCheck['passed']) { $passed = false; }
        }

        // 5. AML Rules
        if ($this->config['aml_enabled'] && $passed) {
            $amlCheck = $this->checkAML($txn);
            $checks['aml_rules'] = $amlCheck;
            if (!$amlCheck['passed']) { $passed = false; }
        }

        // 6. Fraud Screening
        if ($this->config['fraud_enabled'] && $passed) {
            $fraudCheck = $this->checkFraud($txn);
            $checks['fraud_screening'] = $fraudCheck;
            if (!$fraudCheck['passed']) { $passed = false; }
        }

        // 7. Routing Logic
        $routing = $this->determineRouting($txn);
        $checks['routing'] = $routing;

        $duration = round((microtime(true) - $start) * 1000);

        $result = [
            'passed'      => $passed,
            'checks'      => $checks,
            'routing'     => $routing['processor'] ?? 'nuvei',
            'risk_score'  => $this->computeRiskScore($checks),
            'duration_ms' => $duration,
            'timestamp'   => date('c'),
        ];

        // Log
        $this->logCheck($txn, $result);

        return $result;
    }

    // ── CHECKS ──────────────────────────────────────────────

    private function checkAmount(array $txn): array
    {
        $amount   = floatval($txn['amount'] ?? 0);
        $currency = strtoupper($txn['currency'] ?? 'USD');

        if ($amount < self::MIN_AMOUNT) {
            return ['passed'=>false,'code'=>'AMOUNT_TOO_LOW',
                    'message'=>"Minimum amount is " . self::MIN_AMOUNT . " USD"];
        }
        if ($amount > self::MAX_SINGLE_TXN) {
            return ['passed'=>false,'code'=>'AMOUNT_EXCEEDS_LIMIT',
                    'message'=>"Single transaction limit is " . self::MAX_SINGLE_TXN . " USD"];
        }

        // Daily limit check per terminal
        if ($this->db) {
            try {
                $tid   = $txn['terminal_id'] ?? '';
                $today = date('Y-m-d');
                $rows  = $this->db->query(
                    "SELECT COALESCE(SUM(amount),0) daily_total FROM dp_transactions
                     WHERE DATE(created_at)=? AND gateway_response LIKE ?
                     AND status='completed'",
                    [$today, '%"terminal_id":"'.$tid.'"%']
                );
                $dailyTotal = floatval($rows[0]['daily_total'] ?? 0);
                if ($dailyTotal + $amount > self::MAX_DAILY_LIMIT) {
                    return ['passed'=>false,'code'=>'DAILY_LIMIT_EXCEEDED',
                            'message'=>'Daily terminal limit exceeded'];
                }
            } catch (Exception $e) {}
        }

        return ['passed'=>true,'code'=>'OK','amount'=>$amount,'currency'=>$currency];
    }

    private function checkDevice(array $txn): array
    {
        $device = $txn['pos_device'] ?? $txn['terminal_id'] ?? '';

        if (empty($device)) {
            return ['passed'=>false,'code'=>'MISSING_DEVICE','message'=>'Terminal ID required'];
        }

        // قائمة الأجهزة المسموحة
        $allowedPrefixes = ['BITEL_', 'TID', 'T0', 'WEB_', 'API_'];
        $allowed = false;
        foreach ($allowedPrefixes as $prefix) {
            if (str_starts_with(strtoupper($device), strtoupper($prefix))) {
                $allowed = true;
                break;
            }
        }

        if (!$allowed) {
            return ['passed'=>false,'code'=>'UNAUTHORIZED_DEVICE',
                    'message'=>'Device not authorized: '.$device];
        }

        return ['passed'=>true,'code'=>'OK','device'=>$device];
    }

    private function checkMerchant(array $txn): array
    {
        $mid = $txn['merchant_id'] ?? $txn['mid'] ?? 'TRANSCENDIO';

        if (empty($mid)) {
            return ['passed'=>false,'code'=>'MISSING_MID','message'=>'Merchant ID required'];
        }

        return ['passed'=>true,'code'=>'OK','mid'=>$mid];
    }

    private function checkDuplicate(array $txn): array
    {
        if (!$this->db) {
            return ['passed'=>true,'code'=>'SKIPPED','message'=>'No DB'];
        }

        $amount    = floatval($txn['amount'] ?? 0);
        $cardLast4 = substr(preg_replace('/\D/', '', $txn['card_number'] ?? ''), -4);
        $window    = date('Y-m-d H:i:s', time() - self::DUPLICATE_WINDOW);

        try {
            $rows = $this->db->query(
                "SELECT COUNT(*) cnt FROM dp_transactions
                 WHERE amount=? AND created_at>=? AND status='completed'
                 AND gateway_response LIKE ?",
                [$amount, $window, '%"card_last4":"'.$cardLast4.'"%']
            );
            if (($rows[0]['cnt'] ?? 0) > 0) {
                return ['passed'=>false,'code'=>'DUPLICATE_TRANSACTION',
                        'message'=>'Duplicate transaction detected within ' . self::DUPLICATE_WINDOW . 's'];
            }
        } catch (Exception $e) {}

        return ['passed'=>true,'code'=>'OK'];
    }

    private function checkAML(array $txn): array
    {
        $amount = floatval($txn['amount'] ?? 0);
        $flags  = [];

        // CTR threshold (Currency Transaction Report)
        if ($amount >= 10000) {
            $flags[] = 'CTR_THRESHOLD_EXCEEDED';
        }

        // Structuring detection (multiple txns just below threshold)
        if ($this->db && $amount >= 8000) {
            try {
                $cardLast4 = substr(preg_replace('/\D/', '', $txn['card_number'] ?? ''), -4);
                $window    = date('Y-m-d H:i:s', strtotime('-24 hours'));
                $rows = $this->db->query(
                    "SELECT COUNT(*) cnt, COALESCE(SUM(amount),0) total FROM dp_transactions
                     WHERE created_at>=? AND gateway_response LIKE ? AND status='completed'",
                    [$window, '%"card_last4":"'.$cardLast4.'"%']
                );
                $cnt   = intval($rows[0]['cnt']   ?? 0);
                $total = floatval($rows[0]['total'] ?? 0);
                if ($cnt >= 3 && $total >= 20000) {
                    $flags[] = 'STRUCTURING_SUSPECTED';
                }
            } catch (Exception $e) {}
        }

        if (!empty($flags) && in_array('STRUCTURING_SUSPECTED', $flags)) {
            return ['passed'=>false,'code'=>'AML_BLOCK','flags'=>$flags,
                    'message'=>'Transaction blocked by AML rules: '.implode(',',$flags)];
        }

        return ['passed'=>true,'code'=>'OK','flags'=>$flags,
                'requires_reporting' => in_array('CTR_THRESHOLD_EXCEEDED',$flags)];
    }

    private function checkFraud(array $txn): array
    {
        $score   = 0;
        $signals = [];
        $ip      = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // Velocity check
        if ($this->db) {
            try {
                $window = date('Y-m-d H:i:s', strtotime('-1 hour'));
                $rows   = $this->db->query(
                    "SELECT COUNT(*) cnt FROM dp_transactions WHERE created_at>=? AND status IN ('completed','pending')",
                    [$window]
                );
                $velocity = intval($rows[0]['cnt'] ?? 0);
                if ($velocity > 20) { $score += 30; $signals[] = 'HIGH_VELOCITY'; }
            } catch (Exception $e) {}
        }

        // Card BIN check (basic)
        $cardNum = preg_replace('/\D/', '', $txn['card_number'] ?? '');
        if (strlen($cardNum) > 0) {
            $bin = substr($cardNum, 0, 6);
            // Test card detection
            if (in_array($bin, ['411111','424242','555555','000000'])) {
                $score += 50;
                $signals[] = 'TEST_CARD_DETECTED';
            }
        }

        // High-risk amount
        $amount = floatval($txn['amount'] ?? 0);
        if ($amount > 10000) { $score += 15; $signals[] = 'HIGH_AMOUNT'; }

        if ($score >= $this->config['risk_score_limit']) {
            return ['passed'=>false,'code'=>'FRAUD_BLOCK','risk_score'=>$score,
                    'signals'=>$signals,
                    'message'=>'Transaction blocked by fraud engine. Score: '.$score];
        }

        return ['passed'=>true,'code'=>'OK','risk_score'=>$score,'signals'=>$signals];
    }

    private function determineRouting(array $txn): array
    {
        $amount   = floatval($txn['amount'] ?? 0);
        $currency = strtoupper($txn['currency'] ?? 'USD');

        // Primary: Nuvei → Mashreq
        $processor = 'nuvei';
        $acquirer  = 'mashreq';
        $reason    = 'default_route';

        // High value → Nuvei Direct
        if ($amount > 5000) {
            $processor = 'nuvei';
            $acquirer  = 'mashreq';
            $reason    = 'high_value_nuvei';
        }

        // Middle East currencies → MyFatoorah
        if (in_array($currency, ['KWD','BHD','QAR','OMR'])) {
            $processor = 'myfatoorah';
            $acquirer  = 'mashreq';
            $reason    = 'gcc_currency_route';
        }

        return [
            'passed'    => true,
            'processor' => $processor,
            'acquirer'  => $acquirer,
            'reason'    => $reason,
            'fallback'  => 'paypal',
        ];
    }

    private function computeRiskScore(array $checks): int
    {
        $score = 0;
        if (!($checks['fraud_screening']['passed'] ?? true))  $score += 50;
        if (!($checks['aml_rules']['passed']       ?? true))  $score += 40;
        if (!($checks['duplicate_detection']['passed'] ?? true)) $score += 30;
        $score += intval($checks['fraud_screening']['risk_score'] ?? 0);
        return min(100, $score);
    }

    private function logCheck(array $txn, array $result): void
    {
        if (!$this->db) return;
        try {
            $this->db->insert('gateway_prechecks_log', [
                'reference'   => $txn['reference'] ?? uniqid(),
                'passed'      => $result['passed'] ? 1 : 0,
                'risk_score'  => $result['risk_score'],
                'routing'     => $result['routing'],
                'checks_json' => json_encode($result['checks']),
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        } catch (Exception $e) {}
    }
}
