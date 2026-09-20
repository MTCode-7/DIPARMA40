<?php
/**
 * DI PARMA | Canonical POS / Card Operations
 * مسار: واجهة النظام → Acquirer → كل شبكات البطاقات العالمية
 */

if (defined('DI_PARMA_POS_OPS')) {
    return;
}
define('DI_PARMA_POS_OPS', true);

const POS_NO_AMOUNT_LIMIT = true;
const POS_RRN_LEN = 12;

/** Bank lock for AUTH Capture only — 5,000,000 USD. Do not raise. */
function pos_capture_max_amount(): float
{
    return 5000000.00;
}

/** Sum approved capture/advice rows that follow one AUTH hold. Hold stays reusable. */
function pos_auth_followup_totals($db, string $paymentId, string $rrn): array
{
    $out = ['captured_total' => 0.0, 'capture_count' => 0];
    if (!is_object($db) || !method_exists($db, 'query')) {
        return $out;
    }
    $parts = [];
    $bind = [];
    $pid = trim($paymentId);
    $ref = trim($rrn);
    if ($pid !== '') {
        $parts[] = 'gateway_response LIKE ?';
        $bind[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $pid) . '%';
    }
    if ($ref !== '') {
        $parts[] = 'rrn = ?';
        $bind[] = $ref;
    }
    if ($parts === []) {
        return $out;
    }
    $pfx = defined('DB_PREFIX') ? DB_PREFIX : 'dp_';
    try {
        $rows = $db->query(
            "SELECT amount FROM {$pfx}transactions
             WHERE transaction_type IN ('capture','purchase_advice')
               AND LOWER(COALESCE(status,'')) IN ('completed','captured','approved','success','settled')
               AND (" . implode(' OR ', $parts) . ")",
            $bind
        ) ?: [];
        foreach ($rows as $row) {
            $out['captured_total'] += (float) ($row['amount'] ?? 0);
            $out['capture_count']++;
        }
    } catch (Throwable $e) {
        return $out;
    }
    return $out;
}

/**
 * أنواع العمليات المعيارية.
 *
 * قواعد الحقول:
 * - capture (AUTH Capture): مبلغ مساوٍ/أقل/أكثر + RRN(12) + Approval + بطاقة + انتهاء
 * - purchase_advice: غير مرتبط بـ AUTH — فقط RRN(12) + Approval + بطاقة + انتهاء
 * - online_sale_moto: Approval = 4 أرقام
 * - offline_sale_moto: Approval = 6 أرقام + بطاقة + انتهاء
 */
function pos_operation_catalog(): array
{
    return [
        'purchase_3d' => [
            'ar' => 'شراء 3D (OTP)',
            'en' => 'Purchase 3D (OTP)',
            'icon' => 'fa-shield-alt',
            'color' => '#5bc0de',
            'security' => '3D',
            'requires_otp' => true,
            'requires_card' => true,
            'requires_cvv' => true,
            'requires_expiry' => true,
            'requires_rrn' => false,
            'requires_approval' => false,
            'approval_len' => null,
            'linked_to_auth' => false,
            'amount_flexible' => false,
            'method' => 'purchase3d',
            'desc_ar' => 'بطاقة + انتهاء + CVV. البوابة تطلب OTP إن لزم. قبل 3DS لا موافقة ولا Ledger. بعد النجاح: صافي USDT → Ledger.',
            'desc_en' => 'Card + expiry + CVV. Gateway OTP if required. No approval/Ledger before 3DS. After success: net USDT → Ledger.',
        ],
        'purchase_2d' => [
            'ar' => 'شراء 2D',
            'en' => 'Purchase 2D',
            'icon' => 'fa-bolt',
            'color' => '#FFD700',
            'security' => '2D',
            'requires_otp' => false,
            'requires_card' => true,
            'requires_cvv' => true,
            'requires_expiry' => true,
            'requires_rrn' => false,
            'requires_approval' => false,
            'approval_len' => null,
            'linked_to_auth' => false,
            'amount_flexible' => false,
            'method' => 'purchase2d',
            'desc_ar' => 'بطاقة + انتهاء + CVV. بدون OTP. بيع فوري على البوابة. إذا APPROVED: صافي USDT → Ledger.',
            'desc_en' => 'Card + expiry + CVV. No OTP. Immediate gateway sale. If APPROVED: net USDT → Ledger.',
        ],
        'auth' => [
            'ar' => 'تفويض (حجز)',
            'en' => 'Authorization (Hold)',
            'icon' => 'fa-lock',
            'color' => '#3B82F6',
            'security' => '2D',
            'requires_otp' => false,
            'requires_card' => true,
            'requires_cvv' => false,
            'requires_expiry' => true,
            'requires_rrn' => false,
            'requires_approval' => false,
            'approval_len' => 6,
            'linked_to_auth' => false,
            'amount_flexible' => false,
            'method' => 'authorize',
            'is_moto' => true,
            'desc_ar' => 'حجز ثم كابتشر لاحقاً (إيجار منزل/سيارة/فندق). Nuvei: ECOM 3DS مع OTP وCVV واسم وإيميل. MOTO Online بدون OTP. MOTO Offline يحتاج Approval من البنك. لا Ledger حتى Capture.',
            'desc_en' => 'Hold now, capture later (home/car/hotel). Nuvei: ECOM 3DS with OTP, CVV, name and email. MOTO Online skips OTP. MOTO Offline needs a bank Approval. No Ledger until Capture.',
        ],
        'capture' => [
            'ar' => 'AUTH Capture',
            'en' => 'AUTH Capture',
            'icon' => 'fa-check-double',
            'color' => '#9fe870',
            'security' => '2D',
            'requires_otp' => false,
            'requires_card' => true,
            'requires_cvv' => false,
            'requires_expiry' => true,
            'requires_rrn' => true,
            'requires_approval' => true,
            'requires_payment_id' => true,
            'approval_len' => null, // أي طول معتمد (عادة 6)
            'linked_to_auth' => true,
            'amount_flexible' => true,
            'max_amount' => function_exists('pos_capture_max_amount') ? pos_capture_max_amount() : 5000000.00,
            'max_currency' => 'USD',
            'method' => 'capture',
            'desc_ar' => 'أكمل حجز AUTH سابق (إيجار منزل / سيارة / فندق). المبلغ مساوٍ أو أقل أو أكثر من الحجز. يمكن تكرار الكابتشر على نفس الحجز أكثر من مرة. حد البنك للكابتشر فقط: 5,000,000 دولار. بدون CVV. كل كابتشر مقبول → Ledger.',
            'desc_en' => 'Complete a previous AUTH hold (home / car / hotel rental). Amount may be equal, less, or more than the hold. Capture can be used more than once on the same hold. Bank cap for Capture only: 5,000,000 USD. No CVV. Each approved capture → Ledger.',
        ],
        'purchase_advice' => [
            'ar' => 'Purchase Advice — Direct',
            'en' => 'Purchase Advice — Direct',
            'icon' => 'fa-bell',
            'color' => '#F59E0B',
            'security' => '2D',
            'requires_otp' => false,
            'requires_card' => true,
            'requires_cvv' => false,
            'requires_expiry' => true,
            'requires_rrn' => true,
            'requires_approval' => true,
            'requires_payment_id' => true,
            'approval_len' => 6, // bank MOTO: 4 or 6 digits
            'linked_to_auth' => false,
            'amount_flexible' => true,
            'method' => 'purchase',
            'direct_advice' => true,
            'mti' => '0220',
            'auth_type' => 'DIRECT_ADVICE_NO_PRE_AUTH',
            'max_amount' => function_exists('pos_direct_advice_max_amount') ? pos_direct_advice_max_amount() : 5000000.00,
            'desc_ar' => 'إشعار بعد حجز أو من البنك مباشرة. المبلغ مساوٍ أو أقل أو أكثر من الحجز إن وُجد. يمكن تكراره على نفس الحجز. حد الحساب 5,000,000. بطاقة + انتهاء. بدون CVV. RRN + Approval. بعد الموافقة: صافي → Ledger.',
            'desc_en' => 'Advice after a hold or from the bank. Amount may be equal, less, or more than the hold if one is selected. Can repeat on the same hold. Account max 5,000,000. Card + expiry. No CVV. RRN + Approval. After approval: net → Ledger.',
        ],
        'online_sale_moto' => [
            'ar' => 'Online SALE MOTO',
            'en' => 'Online SALE MOTO',
            'icon' => 'fa-globe',
            'color' => '#00B9FF',
            'security' => '2D',
            'requires_otp' => false,
            'requires_card' => true,
            'requires_cvv' => false,
            'requires_expiry' => true,
            'requires_rrn' => true,
            'requires_approval' => true,
            'requires_payment_id' => true,
            'approval_len' => 6,
            'linked_to_auth' => false,
            'amount_flexible' => false,
            'method' => 'purchase2d',
            'is_moto' => true,
            'moto_channel' => 'online',
            'security' => '2D',
            'desc_ar' => '2D MOTO. بطاقة + انتهاء. بدون CVV. بنك: RRN 12 + Approval 4 أو 6. بوابة: Payment ID + Approval. بعد الموافقة: صافي → Ledger.',
            'desc_en' => '2D MOTO. Card + expiry. No CVV. Bank: RRN 12 + Approval 4 or 6. Gateway: Payment ID + Approval. After approval: net → Ledger.',
        ],
        'offline_sale_moto' => [
            'ar' => 'Offline SALE — SAF',
            'en' => 'Offline SALE — SAF',
            'icon' => 'fa-phone',
            'color' => '#F97316',
            'security' => '2D',
            'requires_otp' => false,
            'requires_card' => true,
            'requires_cvv' => false,
            'requires_expiry' => true,
            'requires_rrn' => true,
            'requires_approval' => true,
            'requires_payment_id' => true,
            'approval_len' => 6,
            'linked_to_auth' => false,
            'amount_flexible' => false,
            'method' => 'purchase',
            'is_moto' => true,
            'moto_channel' => 'offline',
            'saf' => true,
            'max_amount' => function_exists('pos_offline_sale_max_amount') ? pos_offline_sale_max_amount() : 2000000.00,
            'desc_ar' => 'بيع أوفلاين يُخصم على البوابة الحية (MOTO). حد 2,000,000. بطاقة + انتهاء. بدون CVV. RRN + Approval. لا موافقة محلية. بعد موافقة البوابة: صافي → Ledger.',
            'desc_en' => 'Offline sale charges the live gateway (MOTO). Limit 2,000,000. Card + expiry. No CVV. RRN + Approval. No local approval. After gateway APPROVED: net → Ledger.',
        ],
        'refund' => [
            'ar' => 'استرداد (Refund)',
            'en' => 'Refund',
            'icon' => 'fa-undo',
            'color' => '#EF4444',
            'security' => '2D',
            'requires_otp' => false,
            'requires_card' => false,
            'requires_cvv' => false,
            'requires_expiry' => false,
            'requires_rrn' => true,
            'requires_approval' => false,
            'approval_len' => null,
            'linked_to_auth' => false,
            'amount_flexible' => true,
            'method' => 'refund',
            'desc_ar' => 'RRN 12 للعملية الأصلية. البوابة ترجع المبلغ. لا USDT إلى Ledger.',
            'desc_en' => 'Original RRN 12. The gateway refunds. No USDT to Ledger.',
        ],
        'avoid' => [
            'ar' => 'Avoid',
            'en' => 'Avoid',
            'icon' => 'fa-ban',
            'color' => '#6B7280',
            'security' => '2D',
            'requires_otp' => false,
            'requires_card' => false,
            'requires_cvv' => false,
            'requires_expiry' => false,
            'requires_rrn' => true,
            'requires_approval' => false,
            'approval_len' => null,
            'linked_to_auth' => false,
            'amount_flexible' => false,
            'method' => 'void',
            'desc_ar' => 'RRN 12. إلغاء المسار بدون خصم جديد. لا Ledger.',
            'desc_en' => 'RRN 12. Void the path with no new charge. No Ledger.',
        ],
        'recurring' => [
            'ar' => 'متكرر',
            'en' => 'Recurring',
            'icon' => 'fa-sync',
            'color' => '#8B5CF6',
            'security' => '2D',
            'requires_otp' => false,
            'requires_card' => true,
            'requires_cvv' => true,
            'requires_expiry' => true,
            'requires_rrn' => false,
            'requires_approval' => false,
            'approval_len' => null,
            'linked_to_auth' => false,
            'amount_flexible' => false,
            'method' => 'purchase2d',
            'desc_ar' => 'تحصيل متكرر على نفس البطاقة عبر البوابة المختارة. بعد الموافقة: USDT → Ledger.',
            'desc_en' => 'Recurring charge on the same card via the selected gateway. After approval: USDT → Ledger.',
        ],
        'installment' => [
            'ar' => 'تقسيط',
            'en' => 'Installment',
            'icon' => 'fa-layer-group',
            'color' => '#A855F7',
            'security' => '2D',
            'requires_otp' => false,
            'requires_card' => true,
            'requires_cvv' => true,
            'requires_expiry' => true,
            'requires_rrn' => false,
            'requires_approval' => false,
            'approval_len' => null,
            'linked_to_auth' => false,
            'amount_flexible' => false,
            'method' => 'purchase2d',
            'desc_ar' => 'تقسيط على البطاقة عبر البوابة. بعد الموافقة: USDT → Ledger.',
            'desc_en' => 'Card installment via the gateway. After approval: USDT → Ledger.',
        ],
        'crypto_purchase' => [
            'ar' => 'شراء كريبتو',
            'en' => 'Crypto purchase',
            'icon' => 'fa-coins',
            'color' => '#F3BA2F',
            'security' => '2D',
            'requires_otp' => false,
            'requires_card' => true,
            'requires_cvv' => true,
            'requires_expiry' => true,
            'requires_rrn' => false,
            'requires_approval' => false,
            'approval_len' => null,
            'linked_to_auth' => false,
            'amount_flexible' => false,
            'method' => 'purchase2d',
            'desc_ar' => 'سحب بالبطاقة ثم التسوية كريبتو إلى Ledger.',
            'desc_en' => 'Card charge then crypto settlement to Ledger.',
        ],
        'gift_card' => [
            'ar' => 'بطاقة هدية',
            'en' => 'Gift card',
            'icon' => 'fa-gift',
            'color' => '#EC4899',
            'security' => '2D',
            'requires_otp' => false,
            'requires_card' => true,
            'requires_cvv' => true,
            'requires_expiry' => true,
            'requires_rrn' => false,
            'requires_approval' => false,
            'approval_len' => null,
            'linked_to_auth' => false,
            'amount_flexible' => false,
            'method' => 'purchase2d',
            'desc_ar' => 'تحصيل بطاقة هدية عبر البوابة. بعد الموافقة: USDT → Ledger.',
            'desc_en' => 'Gift-card charge via the gateway. After approval: USDT → Ledger.',
        ],
        'wire_transfer' => [
            'ar' => 'حوالة',
            'en' => 'Wire transfer',
            'icon' => 'fa-university',
            'color' => '#003087',
            'security' => '2D',
            'requires_otp' => false,
            'requires_card' => false,
            'requires_cvv' => false,
            'requires_expiry' => false,
            'requires_rrn' => false,
            'requires_approval' => false,
            'approval_len' => null,
            'linked_to_auth' => false,
            'amount_flexible' => false,
            'method' => 'purchase',
            'desc_ar' => 'حوالة عبر البوابة المختارة. الوجهة بعد القبول: Ledger.',
            'desc_en' => 'Wire via the selected gateway. After accept: Ledger.',
        ],
        'quasi_cash' => [
            'ar' => 'شبه نقدي',
            'en' => 'Quasi cash',
            'icon' => 'fa-money-bill-wave',
            'color' => '#14B8A6',
            'security' => '2D',
            'requires_otp' => false,
            'requires_card' => true,
            'requires_cvv' => true,
            'requires_expiry' => true,
            'requires_rrn' => false,
            'requires_approval' => false,
            'approval_len' => null,
            'linked_to_auth' => false,
            'amount_flexible' => false,
            'method' => 'purchase2d',
            'desc_ar' => 'شبه نقدي على البطاقة. بعد الموافقة: USDT → Ledger.',
            'desc_en' => 'Quasi-cash on the card. After approval: USDT → Ledger.',
        ],
        'withdrawal_pos' => [
            'ar' => 'سحب عبر POS',
            'en' => 'POS Withdrawal',
            'icon' => 'fa-sim-card',
            'color' => '#F97316',
            'security' => '2D',
            'requires_otp' => false,
            'requires_card' => true,
            'requires_cvv' => true,
            'requires_expiry' => true,
            'requires_rrn' => false,
            'requires_approval' => false,
            'approval_len' => null,
            'linked_to_auth' => false,
            'amount_flexible' => false,
            'method' => 'cash_advance',
            'entry_mode' => 'pos_chip',
            'channel' => 'system_pos',
            'requires_charge_mode' => true,
            'desc_ar' => 'سحب POS بدون OTP. يمكن تفعيل POS وNFC معاً مع مانول أو فيزيكل. اختر وضع التنفيذ 2D. التحصيل → Ledger.',
            'desc_en' => 'POS withdrawal with no OTP. POS and NFC can both be on with Manual or Physical. Pick a 2D charge mode. Capture → Ledger.',
        ],
        'withdrawal_nfc' => [
            'ar' => 'سحب عبر NFC',
            'en' => 'NFC Withdrawal',
            'icon' => 'fa-wifi',
            'color' => '#14B8A6',
            'security' => '2D',
            'requires_otp' => false,
            'requires_card' => true,
            'requires_cvv' => false,
            'requires_expiry' => true,
            'requires_rrn' => false,
            'requires_approval' => false,
            'approval_len' => null,
            'linked_to_auth' => false,
            'amount_flexible' => false,
            'method' => 'cash_advance',
            'entry_mode' => 'nfc_contactless',
            'channel' => 'system_pos',
            'requires_charge_mode' => true,
            'desc_ar' => 'سحب NFC بدون OTP. يعمل مع POS في نفس العملية، مانول أو فيزيكل. نفس قواعد السحب وLedger.',
            'desc_en' => 'NFC withdrawal with no OTP. Can run with POS on the same sale, Manual or Physical. Same Ledger rules.',
        ],
    ];
}

/** أنواع تحتاج مراجع Nuvei Control Panel: بنك RRN+Auth Code وبوابة Transaction ID+Auth Code */
function pos_dual_acquirer_types(): array
{
    return ['offline_sale_moto', 'online_sale_moto', 'purchase_advice', 'capture'];
}

function pos_is_valid_payment_id(?string $id): bool
{
    $id = trim((string) $id);
    return strlen($id) >= 6 && (bool) preg_match('/^[A-Za-z0-9._\-]+$/', $id);
}

/** أوضاع التنفيذ داخل السحب POS/NFC */
function pos_withdrawal_charge_modes(): array
{
    return [
        'purchase_advice_offline' => [
            'ar' => 'Purchase Advice — Offline',
            'en' => 'Purchase Advice — Offline',
            'base' => 'purchase_advice',
            'channel' => 'offline',
            'approval_len' => 6,
            'requires_rrn' => true,
            'requires_approval' => true,
            'requires_card' => true,
            'requires_expiry' => true,
        ],
        'purchase_advice_online' => [
            'ar' => 'Purchase Advice — Online',
            'en' => 'Purchase Advice — Online',
            'base' => 'purchase_advice',
            'channel' => 'online',
            'approval_len' => 6,
            'requires_rrn' => true,
            'requires_approval' => true,
            'requires_card' => true,
            'requires_expiry' => true,
        ],
        'auth' => [
            'ar' => 'AUTH (Hold)',
            'en' => 'AUTH (Hold)',
            'base' => 'auth',
            'channel' => null,
            'approval_len' => null,
            'requires_rrn' => false,
            'requires_approval' => false,
            'requires_card' => true,
            'requires_expiry' => true,
        ],
        'capture' => [
            'ar' => 'AUTH Capture',
            'en' => 'AUTH Capture',
            'base' => 'capture',
            'channel' => null,
            'approval_len' => null,
            'requires_rrn' => true,
            'requires_approval' => true,
            'requires_card' => true,
            'requires_expiry' => true,
        ],
        'purchase_2d' => [
            'ar' => 'Purchase 2D',
            'en' => 'Purchase 2D',
            'base' => 'purchase_2d',
            'channel' => null,
            'approval_len' => null,
            'requires_rrn' => false,
            'requires_approval' => false,
            'requires_card' => true,
            'requires_expiry' => true,
        ],
        'offline_sale_moto' => [
            'ar' => 'Offline SALE MOTO',
            'en' => 'Offline SALE MOTO',
            'base' => 'offline_sale_moto',
            'channel' => 'offline',
            'approval_len' => 6,
            'requires_rrn' => true,
            'requires_approval' => true,
            'requires_card' => true,
            'requires_expiry' => true,
        ],
        'online_sale_moto' => [
            'ar' => 'Online SALE MOTO',
            'en' => 'Online SALE MOTO',
            'base' => 'online_sale_moto',
            'channel' => 'online',
            'approval_len' => 6,
            'requires_rrn' => true,
            'requires_approval' => true,
            'requires_card' => true,
            'requires_expiry' => true,
        ],
    ];
}

function pos_operation_aliases(): array
{
    return [
        'purchase' => 'purchase_3d',
        'purchase_online' => 'online_sale_moto',
        'purchase_offline' => 'offline_sale_moto',
        'online_moto' => 'online_sale_moto',
        'offline_moto' => 'offline_sale_moto',
        'online_SALE_moto' => 'online_sale_moto',
        'offline_SALE_moto' => 'offline_sale_moto',
        'auth_complete' => 'capture',
        'auth_capture' => 'capture',
        'auth_hold' => 'auth',
        'auth_moto' => 'auth',
        'purchase_moto' => 'purchase_2d',
        'offline_purchase' => 'offline_sale_moto',
        'online_purchase' => 'online_sale_moto',
        'direct2d' => 'purchase_2d',
        'direct3d' => 'purchase_3d',
        'withdrawal_physical' => 'withdrawal_pos',
        'withdrawal_manual' => 'offline_sale_moto',
        'cash_advance' => 'withdrawal_pos',
        'void' => 'avoid',
        'reversal' => 'avoid',
    ];
}

function pos_normalize_operation(string $type): string
{
    $type = trim($type);
    if ($type === '') {
        return 'purchase_2d';
    }
    $catalog = pos_operation_catalog();
    if (isset($catalog[$type])) {
        return $type;
    }
    $aliases = pos_operation_aliases();
    if (isset($aliases[$type])) {
        return $aliases[$type];
    }
    $lower = strtolower($type);
    if (isset($aliases[$lower])) {
        return $aliases[$lower];
    }
    return $type;
}

function pos_operation_meta(string $type): ?array
{
    $canonical = pos_normalize_operation($type);
    $catalog = pos_operation_catalog();
    return $catalog[$canonical] ?? null;
}

function pos_withdrawal_types(): array
{
    return ['withdrawal_pos', 'withdrawal_nfc', 'withdrawal_physical', 'cash_advance'];
}

function pos_is_withdrawal(string $type): bool
{
    return in_array(pos_normalize_operation($type), ['withdrawal_pos', 'withdrawal_nfc'], true);
}

/** POS and NFC can both be on. Default both for every withdrawal. */
function pos_parse_channels(array $data, string $txnType = ''): array
{
    $raw = $data['channels'] ?? null;
    if ($raw === null && isset($data['extra']) && is_array($data['extra'])) {
        $raw = $data['extra']['channels'] ?? null;
    }
    if (is_string($raw)) {
        $raw = preg_split('/[\s,|]+/', $raw) ?: [];
    }
    if (!is_array($raw)) {
        $raw = [];
    }
    $pos = false;
    $nfc = false;
    foreach ($raw as $c) {
        $c = strtolower(trim((string) $c));
        if (in_array($c, ['pos', 'pos_chip', 'chip', 'insert'], true)) {
            $pos = true;
        }
        if (in_array($c, ['nfc', 'nfc_contactless', 'contactless', 'tap'], true)) {
            $nfc = true;
        }
    }
    $txn = pos_normalize_operation($txnType !== '' ? $txnType : (string) ($data['txn_type'] ?? ''));
    if ($txn === 'withdrawal_pos') {
        $pos = true;
    }
    if ($txn === 'withdrawal_nfc') {
        $nfc = true;
    }
    if (!$pos && !$nfc && pos_is_withdrawal($txn)) {
        $pos = true;
        $nfc = true;
    }
    $out = [];
    if ($pos) {
        $out[] = 'pos';
    }
    if ($nfc) {
        $out[] = 'nfc';
    }
    return $out;
}

function pos_channels_entry_mode(array $channels): string
{
    $hasPos = in_array('pos', $channels, true);
    $hasNfc = in_array('nfc', $channels, true);
    if ($hasPos && $hasNfc) {
        return 'pos_and_nfc';
    }
    if ($hasNfc) {
        return 'nfc_contactless';
    }
    return 'pos_chip';
}

function pos_is_valid_rrn(?string $rrn): bool
{
    $rrn = preg_replace('/\D/', '', (string)$rrn);
    return strlen($rrn) === POS_RRN_LEN;
}

function pos_normalize_rrn(?string $rrn): string
{
    return preg_replace('/\D/', '', (string)$rrn);
}

/**
 * Bank MOTO / voice approvals are 4 or 6 digits.
 */
function pos_is_valid_approval(?string $code, ?int $expectedLen): bool
{
    $code = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string)$code));
    if ($code === '' || !ctype_digit($code)) {
        return false;
    }
    $len = strlen($code);
    if ($expectedLen === 4 || $expectedLen === 6) {
        return $len === 4 || $len === 6;
    }
    if ($expectedLen !== null) {
        return $len === $expectedLen;
    }
    return $len >= 4 && $len <= 12;
}

/**
 * Square Web Payments sandbox simulation nonce must never charge.
 */
function pos_is_simulation_card_nonce(string $token): bool
{
    $t = strtolower(trim($token));
    return $t !== '' && (str_starts_with($t, 'cnon:card-nonce') || str_starts_with($t, 'cnon:card-nonce-ok'));
}

/**
 * Real gateway card nonce / source_id / cloud_token (not PAN, not Square sandbox simulation).
 */
function pos_is_real_cloud_token(string $token): bool
{
    $token = trim($token);
    if ($token === '' || pos_is_simulation_card_nonce($token)) {
        return false;
    }
    return strlen($token) >= 8;
}

/**
 * يتحقق من حقول العملية ويعيد قائمة أخطاء (فارغة = صالح).
 */
function pos_validate_operation_fields(string $type, array $data): array
{
    $type = pos_normalize_operation($type);
    $meta = pos_operation_meta($type);
    $errors = [];
    if (!$meta) {
        $errors[] = 'Unknown operation type: ' . $type;
        return $errors;
    }

    $rrn = pos_normalize_rrn($data['rrn'] ?? $data['orig_ref'] ?? $data['bank_rrn'] ?? '');
    $approval = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string)($data['approval_code'] ?? $data['bank_approval_code'] ?? '')));
    $paymentId = trim((string)($data['payment_id'] ?? $data['nuvei_txn_id'] ?? $data['transaction_id'] ?? ''));
    $gwApproval = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string)($data['gateway_approval_code'] ?? $data['auth_code'] ?? '')));
    $card = preg_replace('/\D/', '', (string)($data['card_number'] ?? ''));
    $expiry = trim((string)($data['card_expiry'] ?? ''));

    $chargeMode = trim((string)($data['charge_mode'] ?? $data['withdrawal_mode'] ?? ''));
    $modeMeta = null;
    if (!empty($meta['requires_charge_mode'])) {
        $modes = pos_withdrawal_charge_modes();
        if ($chargeMode === 'purchase_3d') {
            $chargeMode = 'purchase_2d';
        }
        if ($chargeMode === '' || !isset($modes[$chargeMode])) {
            $errors[] = 'Select charge mode: purchase_advice_offline | purchase_advice_online | auth | capture | purchase_2d | offline_sale_moto | online_sale_moto';
        } else {
            $modeMeta = $modes[$chargeMode];
        }
    }

    $requiresRrn = !empty($meta['requires_rrn']) || !empty($modeMeta['requires_rrn']);
    $requiresApproval = !empty($meta['requires_approval']) || !empty($modeMeta['requires_approval']);
    $requiresCard = !empty($meta['requires_card']) || !empty($modeMeta['requires_card']);
    $requiresExpiry = !empty($meta['requires_expiry']) || !empty($modeMeta['requires_expiry']);
    $approvalLen = $modeMeta['approval_len'] ?? ($meta['approval_len'] ?? null);

    $cloudToken = trim((string)($data['cloud_token'] ?? $data['source_id'] ?? $data['payment_token'] ?? ''));
    if (pos_is_simulation_card_nonce($cloudToken)) {
        $errors[] = 'Square simulation nonce is rejected. Use a real Web Payments SDK token.';
        $requiresCard = false;
        $requiresExpiry = false;
    } elseif (pos_is_real_cloud_token($cloudToken)) {
        $requiresCard = false;
        $requiresExpiry = false;
    }

    if ($type === 'capture' && pos_is_valid_payment_id($paymentId)) {
        $requiresRrn = false;
    }
    $authCh = strtolower(trim((string) ($data['auth_channel'] ?? $data['moto_channel'] ?? $data['extra']['auth_channel'] ?? '')));
    if ($type === 'auth' && $authCh === 'offline') {
        $requiresApproval = true;
        $approvalLen = 6;
    }

    if ($requiresRrn && !pos_is_valid_rrn($rrn)) {
        $errors[] = 'RRN must be exactly 12 digits.';
    }
    if ($requiresApproval && !pos_is_valid_approval($approval, $approvalLen)) {
        if ($approvalLen === 4 || $approvalLen === 6) {
            $errors[] = 'Bank Auth Code (Nuvei Auth Code) must be 4 or 6 digits.';
        } else {
            $errors[] = 'Bank Auth Code is required (4–12 digits).';
        }
    }
    if (!empty($meta['requires_payment_id']) || in_array($type, pos_dual_acquirer_types(), true)) {
        if (!pos_is_valid_payment_id($paymentId)) {
            $errors[] = 'Nuvei Transaction ID (Payment ID) is required.';
        }
        if ($gwApproval === '') {
            $gwApproval = $approval;
        }
        if (!pos_is_valid_approval($gwApproval, $approvalLen)) {
            $errors[] = 'Gateway Auth Code must be 4 or 6 digits (Nuvei Auth Code).';
        }
    }
    if ($requiresCard && (strlen($card) < 13 || strlen($card) > 19)) {
        $errors[] = 'Card number must be 13–19 digits.';
    } elseif ($requiresCard && !pos_luhn_ok($card)) {
        $errors[] = 'Card number failed checksum.';
    } elseif ($requiresCard && pos_is_blocked_test_card($card)) {
        $errors[] = 'Test and dummy cards are blocked. Use a real card.';
    }
    if ($requiresExpiry && !preg_match('/^(0[1-9]|1[0-2])\/([0-9]{2})$/', $expiry)) {
        $errors[] = 'Expiry date required (MM/YY).';
    }

    return $errors;
}

/** شبكات وشركات البطاقات المقبولة — لا رفض حسب العلامة */
function pos_card_networks(): array
{
    return [
        'auto' => ['ar' => 'تلقائي من الرقم — أي شركة', 'en' => 'Auto from PAN — any issuer', 'scheme' => 'auto'],
        'visa' => ['ar' => 'Visa', 'en' => 'Visa', 'scheme' => 'visa'],
        'mastercard' => ['ar' => 'Mastercard', 'en' => 'Mastercard', 'scheme' => 'mastercard'],
        'maestro' => ['ar' => 'Maestro', 'en' => 'Maestro', 'scheme' => 'maestro'],
        'visa_electron' => ['ar' => 'Visa Electron', 'en' => 'Visa Electron', 'scheme' => 'visa'],
        'amex' => ['ar' => 'American Express', 'en' => 'American Express', 'scheme' => 'amex'],
        'discover' => ['ar' => 'Discover', 'en' => 'Discover', 'scheme' => 'discover'],
        'diners' => ['ar' => 'Diners Club', 'en' => 'Diners Club', 'scheme' => 'diners'],
        'jcb' => ['ar' => 'JCB', 'en' => 'JCB', 'scheme' => 'jcb'],
        'unionpay' => ['ar' => 'UnionPay', 'en' => 'UnionPay', 'scheme' => 'unionpay'],
        'mir' => ['ar' => 'Mir', 'en' => 'Mir', 'scheme' => 'mir'],
        'rupay' => ['ar' => 'RuPay', 'en' => 'RuPay', 'scheme' => 'rupay'],
        'elo' => ['ar' => 'Elo', 'en' => 'Elo', 'scheme' => 'elo'],
        'hipercard' => ['ar' => 'Hipercard', 'en' => 'Hipercard', 'scheme' => 'hipercard'],
        'troy' => ['ar' => 'Troy', 'en' => 'Troy', 'scheme' => 'troy'],
        'verve' => ['ar' => 'Verve', 'en' => 'Verve', 'scheme' => 'verve'],
        'mada' => ['ar' => 'مدى', 'en' => 'Mada', 'scheme' => 'mada'],
        'meeza' => ['ar' => 'ميزة', 'en' => 'Meeza', 'scheme' => 'meeza'],
        'knet' => ['ar' => 'كي نت', 'en' => 'KNET', 'scheme' => 'knet'],
        'benefit' => ['ar' => 'بنفت', 'en' => 'Benefit', 'scheme' => 'benefit'],
        'jaywan' => ['ar' => 'جويوان', 'en' => 'Jaywan', 'scheme' => 'jaywan'],
        'napas' => ['ar' => 'NAPAS', 'en' => 'NAPAS', 'scheme' => 'napas'],
        'paypak' => ['ar' => 'PayPak', 'en' => 'PayPak', 'scheme' => 'paypak'],
        'dankort' => ['ar' => 'Dankort', 'en' => 'Dankort', 'scheme' => 'dankort'],
        'bancontact' => ['ar' => 'Bancontact', 'en' => 'Bancontact', 'scheme' => 'bancontact'],
        'girocard' => ['ar' => 'Girocard', 'en' => 'Girocard', 'scheme' => 'girocard'],
        'interac' => ['ar' => 'Interac', 'en' => 'Interac', 'scheme' => 'interac'],
        'uatp' => ['ar' => 'UATP', 'en' => 'UATP', 'scheme' => 'uatp'],
        'crypto' => ['ar' => 'بطاقة كريبتو / أي مُصدر', 'en' => 'Crypto / any issuer card', 'scheme' => 'crypto'],
        'other' => ['ar' => 'أي بطاقة أو شركة أخرى', 'en' => 'Any other card or issuer', 'scheme' => 'other'],
    ];
}

function pos_accepted_card_types(): array
{
    $out = [];
    foreach (pos_card_networks() as $code => $n) {
        if ($code === 'auto') {
            continue;
        }
        $out[] = $n['en'];
    }
    return $out;
}

function pos_luhn_ok(string $digits): bool
{
    $digits = preg_replace('/\D/', '', $digits);
    $len = strlen($digits);
    if ($len < 13 || $len > 19) {
        return false;
    }
    $sum = 0;
    $alt = false;
    for ($i = $len - 1; $i >= 0; $i--) {
        $n = (int) $digits[$i];
        if ($alt) {
            $n *= 2;
            if ($n > 9) {
                $n -= 9;
            }
        }
        $sum += $n;
        $alt = !$alt;
    }
    return $sum % 10 === 0;
}

/**
 * Strip PAN/CVV/track from arrays before DB, logs, or peer sync.
 * @param mixed $value
 * @return mixed
 */
function pos_redact_pci($value)
{
    if (is_array($value)) {
        $out = [];
        foreach ($value as $k => $v) {
            $lk = strtolower((string) $k);
            if (preg_match('/(^|_)(cvv|cvc|csc|pin|pan|track1|track2|card_number|cc_number|card_cvv|cc_cvv|card_cvc)(_|$)/', $lk)
                || in_array($lk, ['password', 'secret', 'private_key', 'card_name', 'cc_name'], true)) {
                $out[$k] = '[redacted]';
                continue;
            }
            $out[$k] = pos_redact_pci($v);
        }
        return $out;
    }
    if (is_string($value) && preg_match('/(?<!\d)(\d[ -]*){13,19}(?!\d)/', $value)) {
        return preg_replace_callback('/(?<!\d)(?:\d[ -]*){13,19}(?!\d)/', static function ($m) {
            $d = preg_replace('/\D/', '', $m[0]);
            return '****' . substr($d, -4);
        }, $value);
    }
    return $value;
}

function pos_normalize_card_network(?string $code): string
{
    $code = strtolower(trim(str_replace([' ', '-'], '_', (string) $code)));
    $aliases = [
        'mc' => 'mastercard',
        'master' => 'mastercard',
        'master_card' => 'mastercard',
        'visa_mastercard' => 'auto',
        'visa/mastercard' => 'auto',
        'china' => 'unionpay',
        'china_unionpay' => 'unionpay',
        'cup' => 'unionpay',
        'american_express' => 'amex',
        'americanexpress' => 'amex',
        'diners_club' => 'diners',
        'crypto_card' => 'crypto',
        'usdt_card' => 'crypto',
        'binance_card' => 'crypto',
        'world' => 'other',
        'worldwide' => 'other',
        'any' => 'other',
        'all' => 'auto',
        'electron' => 'visa_electron',
        'visa_electron' => 'visa_electron',
        'meza' => 'meeza',
        'k_net' => 'knet',
        'union_pay' => 'unionpay',
        'dinersclub' => 'diners',
        'ban_contact' => 'bancontact',
        'pay_pak' => 'paypak',
        '' => 'auto',
    ];
    if (isset($aliases[$code])) {
        $code = $aliases[$code];
    }
    if (isset(pos_card_networks()[$code])) {
        return $code;
    }
    return $code === '' ? 'auto' : 'other';
}

/** كشف الشبكة من بادئة BIN العامة — أي رقم غير معروف يُقبل كـ other */
function pos_detect_card_network(string $pan): string
{
    $n = preg_replace('/\D/', '', $pan);
    if ($n === '') {
        return 'auto';
    }
    $i2 = (int)substr($n, 0, 2);
    $i3 = (int)substr($n, 0, 3);
    $i4 = (int)substr($n, 0, 4);
    $i6 = substr($n, 0, 6);
    if (str_starts_with($n, '4')) {
        if (in_array(substr($n, 0, 4), ['4026', '4175', '4405', '4508', '4844', '4913', '4917'], true)) {
            return 'visa_electron';
        }
        return 'visa';
    }
    if (($i2 >= 51 && $i2 <= 55) || ($i4 >= 2221 && $i4 <= 2720)) {
        return 'mastercard';
    }
    if (str_starts_with($n, '34') || str_starts_with($n, '37')) {
        return 'amex';
    }
    if (str_starts_with($n, '62') || str_starts_with($n, '81')) {
        return 'unionpay';
    }
    if ($i4 >= 3528 && $i4 <= 3589) {
        return 'jcb';
    }
    if (str_starts_with($n, '6011') || str_starts_with($n, '65') || ($i3 >= 644 && $i3 <= 649)) {
        return 'discover';
    }
    if (str_starts_with($n, '36') || str_starts_with($n, '38') || ($i3 >= 300 && $i3 <= 305)) {
        return 'diners';
    }
    if ($i4 >= 2200 && $i4 <= 2204) {
        return 'mir';
    }
    if (str_starts_with($n, '60') || str_starts_with($n, '82')) {
        return 'rupay';
    }
    if (in_array($i6, ['636368', '438935', '504175'], true)) {
        return 'elo';
    }
    if (str_starts_with($n, '606282') || str_starts_with($n, '3841')) {
        return 'hipercard';
    }
    if (str_starts_with($n, '9792')) {
        return 'troy';
    }
    if (str_starts_with($n, '506') || str_starts_with($n, '650002')) {
        return 'verve';
    }
    if (str_starts_with($n, '588845') || str_starts_with($n, '9682')) {
        return 'mada';
    }
    if (str_starts_with($n, '5078') || str_starts_with($n, '5079')) {
        return 'meeza';
    }
    if (str_starts_with($n, '888822')) {
        return 'knet';
    }
    if (str_starts_with($n, '6703')) {
        return 'bancontact';
    }
    if (str_starts_with($n, '9704')) {
        return 'napas';
    }
    if (str_starts_with($n, '2205') || str_starts_with($n, '5868')) {
        return 'paypak';
    }
    if (str_starts_with($n, '5081')) {
        return 'jaywan';
    }
    if (str_starts_with($n, '4571')) {
        return 'dankort';
    }
    if ($i2 === 1) {
        return 'uatp';
    }
    if ($i2 >= 50 && $i2 <= 69) {
        return 'maestro';
    }
    return 'other';
}

function pos_is_blocked_test_card(string $pan): bool
{
    $n = preg_replace('/\D/', '', $pan);
    if ($n === '') {
        return false;
    }
    $exact = [
        '4111111111111111', '4242424242424242', '4000056655665556',
        '5555555555554444', '2223003122003222', '378282246310005',
        '6011111111111117', '30569309025904', '3566002020360505',
    ];
    if (in_array($n, $exact, true)) {
        return true;
    }
    return in_array(substr($n, 0, 6), ['411111', '424242', '555555', '000000'], true);
}

/**
 * Square / host errors safe for POS JSON (code + detail only — no PAN).
 *
 * @param array<string,mixed> $result
 * @return list<array{code:string,detail:string,category?:string}>
 */
function pos_public_host_errors(array $result): array
{
    if (!empty($result['host_errors']) && is_array($result['host_errors'])) {
        $out = [];
        foreach ($result['host_errors'] as $err) {
            if (!is_array($err)) {
                continue;
            }
            $code = trim((string) ($err['code'] ?? ''));
            $detail = trim((string) ($err['detail'] ?? ''));
            if ($code === '' && $detail === '') {
                continue;
            }
            $out[] = [
                'code' => $code,
                'detail' => $detail,
                'category' => trim((string) ($err['category'] ?? '')),
            ];
        }
        if ($out) {
            return $out;
        }
    }
    $raw = is_array($result['raw'] ?? null) ? $result['raw'] : $result;
    $buckets = [];
    if (is_array($raw['errors'] ?? null)) {
        $buckets[] = $raw['errors'];
    }
    $cardErrs = $raw['payment']['card_details']['errors'] ?? null;
    if (is_array($cardErrs)) {
        $buckets[] = $cardErrs;
    }
    $out = [];
    foreach ($buckets as $bucket) {
        foreach ($bucket as $err) {
            if (!is_array($err)) {
                continue;
            }
            $code = trim((string) ($err['code'] ?? ''));
            $detail = trim((string) ($err['detail'] ?? ''));
            if ($code === '' && $detail === '') {
                continue;
            }
            $out[] = [
                'code' => $code,
                'detail' => $detail,
                'category' => trim((string) ($err['category'] ?? '')),
            ];
        }
    }
    if (!$out) {
        $code = trim((string) ($result['square_error_code'] ?? ''));
        $detail = trim((string) ($result['square_error_detail'] ?? ''));
        if ($code !== '' || $detail !== '') {
            $out[] = ['code' => $code, 'detail' => $detail, 'category' => ''];
        }
    }
    return $out;
}

/**
 * Prefer Square errors[0].code / detail over unified CARD_DECLINED.
 *
 * @param array<string,mixed> $result
 */
function pos_host_decline_line(array $result): string
{
    $errs = pos_public_host_errors($result);
    if ($errs) {
        $code = trim((string) ($errs[0]['code'] ?? ''));
        $detail = trim((string) ($errs[0]['detail'] ?? ''));
        if ($code !== '' && $detail !== '') {
            return stripos($detail, $code) !== false ? $detail : ($code . ' — ' . $detail);
        }
        if ($code !== '') {
            return $code;
        }
        if ($detail !== '') {
            return $detail;
        }
    }
    foreach (['square_error_code', 'square_error_detail', 'raw_message'] as $k) {
        $v = trim((string) ($result[$k] ?? ''));
        if ($v !== '') {
            return pos_plain_host_message($v);
        }
    }
    $raw = is_array($result['raw'] ?? null) ? $result['raw'] : $result;
    $line = pos_plain_host_message($raw);
    if ($line !== '' && strcasecmp($line, 'DECLINED') !== 0) {
        return $line;
    }
    $errCode = trim((string) ($result['error_code'] ?? $result['decline_code'] ?? ''));
    if ($errCode !== '' && !in_array(strtoupper($errCode), ['CARD_DECLINED', 'DECLINED', 'UNKNOWN'], true)) {
        return $errCode;
    }
    $msg = trim((string) ($result['message'] ?? ''));
    return $msg !== '' ? pos_plain_host_message($msg) : 'DECLINED';
}

/**
 * After a host decline: tell the cashier whether this PAN must not be reused.
 *
 * @return array{card_use:string,card_use_ar:string,card_use_en:string}
 */
function pos_card_use_alert(string $reason, string $last4 = ''): array
{
    $digits = preg_replace('/\D/', '', $last4) ?? '';
    $tail = strlen($digits) >= 4 ? (' ****' . substr($digits, -4)) : '';
    $scan = strtoupper($reason);

    $doNot = static function (string $ar, string $en) use ($tail): array {
        return [
            'card_use' => 'do_not_use',
            'card_use_ar' => 'تنبيه: لا تستخدم هذه البطاقة' . $tail . ' مرة أخرى. ' . $ar,
            'card_use_en' => 'Alert: do not use this card' . $tail . ' again. ' . $en,
        ];
    };
    $canUse = static function (string $ar, string $en) use ($tail): array {
        return [
            'card_use' => 'can_use',
            'card_use_ar' => 'تنبيه: يمكن استخدام هذه البطاقة' . $tail . '. ' . $ar,
            'card_use_en' => 'Alert: this card' . $tail . ' can still be used. ' . $en,
        ];
    };

    if (preg_match('/CARDHOLDER|إيميل العميل|يتطلب اسم حامل|payer identity/iu', $reason)) {
        return $canUse('أكمل الاسم كما على البطاقة والإيميل الحقيقي ثم نفّذ.', 'Enter the name on the card and a real email, then process again.');
    }
    if (preg_match('/1019|INVALID FAILURE URL|INVALID URL/i', $scan)) {
        return $canUse('المشكلة في رابط Nuvei وليست في البطاقة.', 'This is a Nuvei URL issue, not the card.');
    }
    if (preg_match('/FILTER\s*ERROR|FRAUD\s*SCREEN|FILTERED|CUSTOM FRAUD/i', $scan)) {
        return $canUse('غيّر المبلغ إلى AED صغير مع شراء 3D. لا تكرر نفس المبلغ.', 'Use a small AED amount with Purchase 3D. Do not repeat the same amount.');
    }
    if (preg_match('/1106|INSUFFICIENT/i', $scan)) {
        return $canUse('الرصيد غير كافٍ — أعد المحاولة لاحقاً أو ببطاقة أخرى.', 'Insufficient funds — retry later or use another card.');
    }
    if (preg_match('/CVV_FAILURE|INVALID CVV|1102/i', $scan)) {
        return $canUse('تحقق من CVV وتاريخ الانتهاء ثم أعد المحاولة.', 'Check CVV and expiry, then retry.');
    }
    if (preg_match('/1007|EXPIRED CARD/i', $scan)) {
        return $doNot('البطاقة منتهية.', 'The card is expired.');
    }
    if (preg_match('/1011|INVALID CARD|PAN_FAILURE/i', $scan)) {
        return $doNot('رقم البطاقة غير مقبول.', 'The card number is not accepted.');
    }
    if (preg_match('/روسيا|بيلاروس|BIN.{0,20}\b(RU|BY)\b|\b(RU|BY)\b.{0,20}BIN/iu', $reason)) {
        return $doNot('BIN محظور على حساب Transcendio.', 'This BIN is blocked on the Transcendio account.');
    }
    if (preg_match('/GENERIC\s*DECLINE|1507|1116|-1100|ISSUER DECLINED|لا تعيد نفس PAN/i', $scan)) {
        return $doNot('بنك الإصدار رفض على حساب Transcendio. لا تعيد نفس الرقم.', 'The issuer declined on the Transcendio account. Do not retry this PAN.');
    }

    return $doNot('رفض المضيف. لا تكرر نفس البطاقة فوراً — استخدم بطاقة أخرى.', 'Host declined. Do not retry this card immediately — use another card.');
}

function pos_plain_host_message($raw, int $depth = 0): string
{
    if ($depth > 5) {
        return 'DECLINED';
    }
    if (is_array($raw)) {
        $sqLine = '';
        if (isset($raw['errors'][0]) && is_array($raw['errors'][0])) {
            $err0 = $raw['errors'][0];
            $detail = trim((string) ($err0['detail'] ?? ''));
            $code = trim((string) ($err0['code'] ?? ''));
            $sqLine = ($code !== '' && $detail !== '' && stripos($detail, $code) === false)
                ? ($code . ' — ' . $detail)
                : ($detail !== '' ? $detail : $code);
        }
        if ($sqLine === '' && isset($raw['payment']['card_details']['errors'][0]) && is_array($raw['payment']['card_details']['errors'][0])) {
            $err0 = $raw['payment']['card_details']['errors'][0];
            $detail = trim((string) ($err0['detail'] ?? ''));
            $code = trim((string) ($err0['code'] ?? ''));
            $sqLine = ($code !== '' && $detail !== '' && stripos($detail, $code) === false)
                ? ($code . ' — ' . $detail)
                : ($detail !== '' ? $detail : $code);
        }
        if ($sqLine !== '') {
            return pos_plain_host_message($sqLine, $depth + 1);
        }
        $keys = ['square_error_code', 'square_error_detail', 'raw_message', 'gwErrorReason', 'errCode', 'gwErrorCode', 'reason', 'response_code', 'detail', 'code', 'error_code'];
        $pick = '';
        foreach ($keys as $k) {
            if (!isset($raw[$k])) {
                continue;
            }
            $v = $raw[$k];
            if (!is_scalar($v) || trim((string) $v) === '') {
                continue;
            }
            $v = trim((string) $v);
            if (in_array(strtoupper($v), ['CARD_DECLINED', 'DECLINED', 'UNKNOWN'], true) && $k === 'error_code') {
                continue;
            }
            $pick = $v;
            break;
        }
        if ($pick === '') {
            foreach (['decline_reason', 'status_message', 'message'] as $k) {
                if (!isset($raw[$k]) || !is_scalar($raw[$k])) {
                    continue;
                }
                $v = trim((string) $raw[$k]);
                if ($v === '' || $v[0] === '{' || $v[0] === '[') {
                    continue;
                }
                $pick = $v;
                break;
            }
        }
        return pos_plain_host_message($pick !== '' ? $pick : 'DECLINED', $depth + 1);
    }
    $text = trim((string) $raw);
    if ($text === '') {
        return 'DECLINED';
    }
    if ($text[0] === '{' || $text[0] === '[') {
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return pos_plain_host_message($decoded, $depth + 1);
        }
        if (preg_match('/\b(1507|1011|1007|1106|1019)\b/', $text, $m)) {
            $text = $m[1];
        } else {
            $text = 'DECLINED';
        }
    }
    $text = preg_replace('/\s+/', ' ', strip_tags($text));
    $text = preg_replace('/(?<!\d)(?:\d[ -]*){13,19}(?!\d)/', '[redacted]', $text);
    if (preg_match('/\b(1507|1011|1007|1106|1019)\b/', $text, $m)) {
        $map = [
            '1507' => 'DECLINED RC 1507 ISSUER',
            '1011' => 'DECLINED RC 1011 INVALID CARD',
            '1007' => 'DECLINED RC 1007 EXPIRED CARD',
            '1106' => 'DECLINED RC 1106 INSUFFICIENT FUNDS',
            '1019' => 'DECLINED RC 1019 INVALID URL',
        ];
        return $map[$m[1]] ?? $text;
    }
    if (preg_match('/GENERIC_DECLINE|CVV_FAILURE|INVALID_EXPIRATION|INSUFFICIENT_FUNDS|PAN_FAILURE|VOICE_FAILURE|CARD_DECLINED_VERIFICATION_REQUIRED/i', $text)) {
        if (strlen($text) > 140) {
            $text = substr($text, 0, 137) . '...';
        }
        return $text;
    }
    if (strlen($text) > 120) {
        $text = substr($text, 0, 117) . '...';
    }
    return $text !== '' ? $text : 'DECLINED';
}

function pos_safe_log(string $tag, $message): void
{
    $s = is_scalar($message) ? (string) $message : json_encode($message);
    $s = preg_replace('/(?<!\d)(?:\d[ -]*){13,19}(?!\d)/', '[redacted]', (string) $s);
    error_log($tag . ' ' . substr($s, 0, 400));
}

/**
 * Burst guard — live card testing, not a business amount cap.
 */
function pos_txn_burst_guard(int $userId): ?string
{
    if ($userId <= 0) {
        return null;
    }
    $dir = (defined('POS_APP_ROOT') ? POS_APP_ROOT : dirname(__DIR__, 2)) . '/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $file = $dir . '/pos_rl_' . $userId . '.json';
    $now = time();
    $hits = [];
    if (is_file($file)) {
        $hits = json_decode((string) file_get_contents($file), true);
        $hits = is_array($hits) ? $hits : [];
    }
    $hits = array_values(array_filter($hits, static function ($t) use ($now) {
        return ($now - (int) $t) < 60;
    }));
    if (count($hits) >= 40) {
        return 'Too many POS charges. Wait one minute.';
    }
    $hits[] = $now;
    @file_put_contents($file, json_encode($hits), LOCK_EX);
    return null;
}

/** Display token for receipt — never print the clear value. */
function pos_receipt_seal($value): string
{
    $s = trim((string) $value);
    if ($s === '') {
        return '••••••••••••••••';
    }
    return strtoupper(substr(hash('sha256', 'diparma-pos-slip|' . $s), 0, 16));
}
