<?php
/**
 * ============================================================
 * DI PARMA | Transaction Types Configuration
 * ============================================================
 * 
 * أنواع المعاملات المالية والدفع
 * 
 * ============================================================
 */

// تعريف أنواع المعاملات
define('TRANSACTION_TYPES', [
    // 1. الشراء 3D
    '1_3d' => [
        'code' => '1_3d',
        'en' => 'Purchase - 3D',
        'ar' => 'شراء 3D',
        'description_en' => 'Direct Card Payment - 3D Secure',
        'description_ar' => 'دفع مباشر بالبطاقة /3D',
        'category' => 'purchase',
        'requires_capture' => false,
        'security_mode' => '3D',
    ],
    
    // 1. الشراء 2D
    '1_2d' => [
        'code' => '1_2d',
        'en' => 'Purchase - 2D',
        'ar' => 'شراء 2D',
        'description_en' => 'Direct Card Payment 2D MOTO',
        'description_ar' => 'دفع مباشر بالبطاقة 2D MOTO',
        'category' => 'purchase',
        'requires_capture' => false,
        'is_moto' => true,
        'security_mode' => '2D',
    ],
    
    // 3. التفويض
    '3' => [
        'code' => '3',
        'en' => 'Authorization',
        'ar' => 'تفويض',
        'description_en' => 'Amount Hold without Deduction',
        'description_ar' => 'حجز المبلغ بدون سحب',
        'category' => 'authorization',
        'requires_capture' => true,
    ],
    
    // 4. إتمام التفويض (Capture)
    '4' => [
        'code' => '4',
        'en' => 'Auth Completion',
        'ar' => 'إتمام تفويض',
        'description_en' => 'Capture Reserved Amount - MOTO 2D RRN',
        'description_ar' => 'سحب المبلغ المحجوز (Capture) MOTO - 2D - RRN',
        'category' => 'capture',
        'requires_rrn' => true,
        'is_moto' => true,
    ],
    
    // 5. إشعار الشراء (Purchase Advice)
    '5' => [
        'code' => '5',
        'en' => 'Purchase Advice',
        'ar' => 'إشعار شراء',
        'description_en' => 'Confirm Offline Transaction - 2D RRN',
        'description_ar' => 'تأكيد عملية أوفلاين (2D) - RRN',
        'category' => 'offline',
        'requires_rrn' => true,
    ],
    
    '5_offline' => [
        'code' => '5_offline',
        'en' => 'Offline Purchase',
        'ar' => 'اوف لاين شراء',
        'description_en' => 'Confirm Offline Transaction MOTO 2D RRN',
        'description_ar' => 'تأكيد عملية أوفلاين MOTO - 2D - RRN',
        'category' => 'offline',
        'requires_rrn' => true,
        'is_moto' => true,
    ],
    
    '5_online' => [
        'code' => '5_online',
        'en' => 'Online Purchase',
        'ar' => 'اولاين شراء',
        'description_en' => 'Confirm Offline Transaction MOTO 2D RRN',
        'description_ar' => 'تأكيد عملية أوفلاين MOTO - 2D - RRN',
        'category' => 'online',
        'requires_rrn' => true,
        'is_moto' => true,
    ],
    
    // 6. استرجاع الرصيد (Refund)
    '6' => [
        'code' => '6',
        'en' => 'Refund',
        'ar' => 'استرداد',
        'description_en' => 'Return Amount to Customer',
        'description_ar' => 'إعادة المبلغ للعميل',
        'category' => 'reversal',
        'requires_original_txn' => true,
    ],
    
    // 7. إلغاء العملية (Reversal)
    '7' => [
        'code' => '7',
        'en' => 'Reversal',
        'ar' => 'إلغاء عملية',
        'description_en' => 'Cancel Transaction on Same Day',
        'description_ar' => 'إلغاء عملية بنفس اليوم',
        'category' => 'reversal',
        'requires_original_txn' => true,
        'same_day_only' => true,
    ],
    
    // 8. استعلام الرصيد
    '8' => [
        'code' => '8',
        'en' => 'Balance Inquiry',
        'ar' => 'استعلام رصيد',
        'description_en' => 'Check Account Balance',
        'description_ar' => 'الاستعلام عن الرصيد',
        'category' => 'inquiry',
        'requires_amount' => false,
    ],
    
    // 9. السلفة النقدية (Cash Advance)
    '9' => [
        'code' => '9',
        'en' => 'Cash Advance',
        'ar' => 'سلفة نقدية',
        'description_en' => 'Cash Withdrawal by Card',
        'description_ar' => 'سحب نقدي بالبطاقة',
        'category' => 'cash',
        'requires_amount' => true,
    ],
    
    // 10. الإلغاء (Void)
    '10' => [
        'code' => '10',
        'en' => 'Void',
        'ar' => 'إلغاء',
        'description_en' => 'Cancel Uncaptured Transaction',
        'description_ar' => 'إلغاء عملية لم تُستوفَ',
        'category' => 'reversal',
        'requires_original_txn' => true,
    ],
    
    // 11. التسوية (Settlement)
    '11' => [
        'code' => '11',
        'en' => 'Settlement',
        'ar' => 'تسوية',
        'description_en' => 'End of Day Settlement EOD',
        'description_ar' => 'تسوية نهاية اليوم EOD',
        'category' => 'settlement',
        'requires_amount' => false,
    ],
    
    // 12. شبه النقدي (Quasi Cash)
    '12' => [
        'code' => '12',
        'en' => 'Quasi Cash',
        'ar' => 'شبه نقدي',
        'description_en' => 'Money Transfer & Financial Fees',
        'description_ar' => 'حوالات ورسوم مالية',
        'category' => 'transfer',
        'requires_amount' => true,
    ],
    
    // 13. التحويل (Transfer)
    '13' => [
        'code' => '13',
        'en' => 'Transfer',
        'ar' => 'تحويل',
        'description_en' => 'P2P Transfer Between Accounts',
        'description_ar' => 'تحويل P2P بين حسابات',
        'category' => 'transfer',
        'requires_amount' => true,
    ],
    
    // 14. دفع الفاتورة (Bill Payment)
    '14' => [
        'code' => '14',
        'en' => 'Bill Payment',
        'ar' => 'دفع فاتورة',
        'description_en' => 'Invoice Payment',
        'description_ar' => 'دفع فاتورة',
        'category' => 'payment',
        'requires_amount' => true,
    ],
]);

/**
 * دالة للحصول على نوع معاملة محدد
 */
function getTransactionType($code) {
    $types = TRANSACTION_TYPES;
    return $types[$code] ?? null;
}

/**
 * دالة للحصول على أنواع المعاملات حسب الفئة
 */
function getTransactionTypesByCategory($category) {
    $types = TRANSACTION_TYPES;
    return array_filter($types, function($type) use ($category) {
        return $type['category'] === $category;
    });
}

/**
 * دالة لتنسيق اسم نوع المعاملة
 */
function formatTransactionType($code, $lang = 'en') {
    $type = getTransactionType($code);
    if (!$type) return $code;
    
    $label = $type[$lang] ?? $type['en'];
    $desc = $type['description_' . $lang] ?? $type['description_en'] ?? '';
    
    return $label . ($desc ? ' — ' . $desc : '');
}

?>
