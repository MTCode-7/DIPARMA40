<?php
/**
 * ============================================================
 * DI PARMA | KYCService
 * تكامل مع Sumsub API لإدارة التحقق من الهوية
 * ============================================================
 * المستويات:
 *   Level 1 — بريد + هاتف         → بلا حدود
 *   Level 2 — هوية + صورة         → بلا حدود
 *   Level 3 — عنوان + مستندات     → بلا حدود
 * ============================================================
 */

class KYCService
{
    private const SUMSUB_API   = 'https://api.sumsub.com';
    private const LEVEL_LIMITS = [
        1 => ['daily' => PHP_INT_MAX,  'monthly' => PHP_INT_MAX],
        2 => ['daily' => PHP_INT_MAX,  'monthly' => PHP_INT_MAX],
        3 => ['daily' => PHP_INT_MAX,  'monthly' => PHP_INT_MAX],
    ];
    private const LEVEL_NAMES = [
        1 => 'basic-kyc-level',
        2 => 'id-and-selfie',
        3 => 'full-kyc',
    ];

    private static ?self $instance = null;
    private Database $db;
    private string $appToken;
    private string $secretKey;

    private function __construct()
    {
        $this->db        = db();
        $this->appToken  = getenv('SUMSUB_APP_TOKEN') ?: '';
        $this->secretKey = getenv('SUMSUB_SECRET_KEY') ?: '';
    }

    public static function getInstance(): self
    {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    // ── واجهة عامة ──────────────────────────────────────────

    /**
     * جلب حالة KYC للمستخدم
     */
    public static function getLevelLimits(): array
    {
        return self::LEVEL_LIMITS;
    }

    public function getStatus(int $userId): array
    {
        $kyc = $this->db->find('kyc_verifications', ['user_id' => $userId]);
        if (!$kyc) {
            return [
                'status'        => 'not_started',
                'level'         => 0,
                'daily_limit'   => PHP_INT_MAX,
                'monthly_limit' => PHP_INT_MAX,
                'can_trade'     => true,  // يسمح بعمليات صغيرة
                'limits'        => self::LEVEL_LIMITS,
            ];
        }

        return [
            'status'          => $kyc['status'],
            'level'           => (int)$kyc['level'],
            'daily_limit'     => (float)$kyc['daily_limit'],
            'monthly_limit'   => (float)$kyc['monthly_limit'],
            'applicant_id'    => $kyc['applicant_id'],
            'verified_at'     => $kyc['verified_at'],
            'can_trade'       => in_array($kyc['status'], ['approved']),
            'limits'          => self::LEVEL_LIMITS,
        ];
    }

    /**
     * إنشاء applicant في Sumsub وإرجاع رابط التحقق
     */
    public function initiateKYC(int $userId, int $level = 1): array
    {
        $user = $this->db->find('users', ['id' => $userId]);
        if (!$user) return ['success' => false, 'message' => 'مستخدم غير موجود'];

        // إذا لم يكن Sumsub مضبوطاً → Manual KYC
        if (empty($this->appToken)) {
            return $this->initiateManualKYC($userId, $level);
        }

        try {
            // [1] إنشاء Applicant
            $applicantId = $this->createApplicant($userId, $user, $level);
            if (!$applicantId) throw new RuntimeException('فشل إنشاء applicant');

            // [2] حفظ في DB
            $existing = $this->db->find('kyc_verifications', ['user_id' => $userId]);
            $data = [
                'provider'      => 'sumsub',
                'applicant_id'  => $applicantId,
                'level'         => $level,
                'status'        => 'pending',
                'daily_limit'   => self::LEVEL_LIMITS[$level]['daily'],
                'monthly_limit' => self::LEVEL_LIMITS[$level]['monthly'],
                'created_at'    => date('Y-m-d H:i:s'),
            ];
            if ($existing) {
                $this->db->update('kyc_verifications', $data, ['user_id' => $userId]);
            } else {
                $this->db->insert('kyc_verifications', array_merge($data, ['user_id' => $userId]));
            }

            // [3] إنشاء رابط التحقق
            $sdkToken = $this->generateSDKToken($applicantId);

            return [
                'success'      => true,
                'applicant_id' => $applicantId,
                'sdk_token'    => $sdkToken,
                'level'        => $level,
                'level_name'   => self::LEVEL_NAMES[$level],
                'message'      => 'ابدأ عملية التحقق',
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * استقبال Webhook من Sumsub وتحديث الحالة
     */
    public function handleWebhook(array $data, string $signature, string $rawBody): array
    {
        // التحقق من التوقيع
        if (!empty($this->secretKey)) {
            $expected = hash_hmac('sha256', $rawBody, $this->secretKey);
            if (!hash_equals($expected, $signature)) {
                return ['success' => false, 'message' => 'توقيع غير صالح'];
            }
        }

        $applicantId = $data['applicantId']   ?? '';
        $reviewResult= $data['reviewResult']  ?? [];
        $reviewStatus= $data['reviewStatus']  ?? '';
        $type        = $data['type']           ?? '';

        if (empty($applicantId)) return ['success' => false, 'message' => 'applicantId مفقود'];

        // جلب المستخدم
        $kyc = $this->db->find('kyc_verifications', ['applicant_id' => $applicantId]);
        if (!$kyc) return ['success' => false, 'message' => 'KYC record غير موجود'];

        // تحديث الحالة
        $newStatus = $this->mapSumsubStatus($reviewStatus, $reviewResult);
        $updateData = [
            'status'      => $newStatus,
            'updated_at'  => date('Y-m-d H:i:s'),
        ];
        if ($newStatus === 'approved') {
            $updateData['verified_at'] = date('Y-m-d H:i:s');
            $updateData['daily_limit']   = self::LEVEL_LIMITS[(int)$kyc['level']]['daily'];
            $updateData['monthly_limit'] = self::LEVEL_LIMITS[(int)$kyc['level']]['monthly'];
        }
        if (!empty($reviewResult['rejectLabels'])) {
            $updateData['rejection_reason'] = implode(', ', $reviewResult['rejectLabels']);
        }

        $this->db->update('kyc_verifications', $updateData, ['applicant_id' => $applicantId]);

        // تسجيل حدث
        $this->db->insert('event_log', [
            'event_type' => 'kyc.' . $newStatus,
            'user_id'    => $kyc['user_id'],
            'payload'    => json_encode($data, JSON_UNESCAPED_UNICODE),
            'processed'  => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return ['success' => true, 'status' => $newStatus, 'user_id' => $kyc['user_id']];
    }

    /**
     * KYC يدوي (بدون Sumsub) — للاختبار المحلي
     */
    public function approveManual(int $userId, int $level = 1): array
    {
        $existing = $this->db->find('kyc_verifications', ['user_id' => $userId]);
        $data = [
            'provider'       => 'manual',
            'level'          => $level,
            'status'         => 'approved',
            'daily_limit'    => self::LEVEL_LIMITS[$level]['daily'],
            'monthly_limit'  => self::LEVEL_LIMITS[$level]['monthly'],
            'verified_at'    => date('Y-m-d H:i:s'),
            'created_at'     => date('Y-m-d H:i:s'),
        ];
        if ($existing) {
            $this->db->update('kyc_verifications', $data, ['user_id' => $userId]);
        } else {
            $this->db->insert('kyc_verifications', array_merge($data, ['user_id' => $userId]));
        }
        return ['success' => true, 'level' => $level, 'status' => 'approved'];
    }

    // ── Sumsub API ───────────────────────────────────────────

    private function createApplicant(int $userId, array $user, int $level): ?string
    {
        $levelName = self::LEVEL_NAMES[$level];
        $body = [
            'externalUserId' => 'dp_user_' . $userId,
            'email'          => $user['email']    ?? '',
            'phone'          => $user['phone']    ?? '',
            'fixedInfo'      => [
                'firstName' => $user['first_name'] ?? ($user['username'] ?? 'User'),
                'lastName'  => $user['last_name']  ?? '',
            ],
        ];

        $response = $this->sumsubRequest(
            'POST',
            "/resources/applicants?levelName={$levelName}",
            $body
        );
        return $response['id'] ?? null;
    }

    private function generateSDKToken(string $applicantId): string
    {
        $response = $this->sumsubRequest(
            'POST',
            "/resources/accessTokens?userId={$applicantId}&levelName=basic-kyc-level",
            []
        );
        return $response['token'] ?? '';
    }

    private function sumsubRequest(string $method, string $path, array $body = []): array
    {
        if (empty($this->appToken)) return [];

        $ts      = time();
        $bodyStr = $method === 'GET' ? '' : json_encode($body);
        $sign    = hash_hmac('sha256', $ts . $method . $path . $bodyStr, $this->secretKey);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            "X-App-Token: {$this->appToken}",
            "X-App-Access-Sig: {$sign}",
            "X-App-Access-Ts: {$ts}",
        ];

        $ch = curl_init(self::SUMSUB_API . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CUSTOMREQUEST  => $method,
        ]);
        if ($method !== 'GET') curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyStr);

        $res  = curl_exec($ch);
        curl_close($ch);
        return json_decode($res ?: '{}', true) ?: [];
    }

    private function mapSumsubStatus(string $status, array $result): string
    {
        return match($status) {
            'completed'  => ($result['reviewAnswer'] ?? '') === 'GREEN' ? 'approved' : 'rejected',
            'pending'    => 'pending',
            'onHold'     => 'pending',
            'rejected'   => 'rejected',
            default      => 'pending',
        };
    }

    private function initiateManualKYC(int $userId, int $level): array
    {
        $existing = $this->db->find('kyc_verifications', ['user_id' => $userId]);
        $data = [
            'provider'      => 'manual',
            'level'         => $level,
            'status'        => 'pending',
            'daily_limit'   => self::LEVEL_LIMITS[$level]['daily'],
            'monthly_limit' => self::LEVEL_LIMITS[$level]['monthly'],
            'created_at'    => date('Y-m-d H:i:s'),
        ];
        if ($existing) {
            $this->db->update('kyc_verifications', $data, ['user_id' => $userId]);
        } else {
            $this->db->insert('kyc_verifications', array_merge($data, ['user_id' => $userId]));
        }
        return [
            'success'   => true,
            'level'     => $level,
            'provider'  => 'manual',
            'message'   => 'KYC يدوي — سيتم المراجعة من قِبل الفريق',
            'sdk_token' => '',
        ];
    }
}
