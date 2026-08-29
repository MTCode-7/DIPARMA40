<?php
/**
 * ============================================================
 * DI PARMA | limits.php — إعدادات الحدود المالية
 * ============================================================
 * 200,000,000 USD/يوم = بلا حدود تقنية
 * ============================================================
 */

// ── حدود المعاملة الواحدة ────────────────────────────────
define('LIMIT_MIN_AMOUNT',        (float) env('MIN_AMOUNT', 0.01));
define('LIMIT_MAX_SINGLE',        (float) env('MAX_AMOUNT', 200_000_000));
define('LIMIT_MAX_DAILY',         PHP_INT_MAX);    // بلا حدود يومية
define('LIMIT_MAX_MONTHLY',       PHP_INT_MAX);    // بلا حدود شهرية

// ── حدود KYC ────────────────────────────────────────────
define('KYC_DAILY_LIMIT',         PHP_INT_MAX);
define('KYC_MONTHLY_LIMIT',       PHP_INT_MAX);

// ── حدود Hot Wallet ──────────────────────────────────────
define('HOT_WALLET_MIN_ALERT',    1_000_000);      // تنبيه عند < 1M USDT
define('HOT_WALLET_CRIT_ALERT',   100_000);        // حرج عند < 100K USDT
define('HOT_WALLET_TARGET',       50_000_000);     // الهدف المثالي 50M USDT

// ── Velocity — عدد العمليات ──────────────────────────────
define('VELOCITY_PER_MINUTE',     PHP_INT_MAX);
define('VELOCITY_PER_HOUR',       PHP_INT_MAX);
define('VELOCITY_PER_DAY',        PHP_INT_MAX);

// ── Risk Engine ──────────────────────────────────────────
define('RISK_BLOCK_AMOUNT',       PHP_INT_MAX);    // لا يُرفض بسبب المبلغ
define('RISK_REVIEW_AMOUNT',      PHP_INT_MAX);    // لا مراجعة بسبب المبلغ

// ── Bulk Payment ─────────────────────────────────────────
define('BULK_MAX_RECORDS',        10_000);         // عدد السجلات في الدفعة الواحدة
define('BULK_MAX_AMOUNT',         PHP_INT_MAX);    // بلا حد للمبلغ الكلي
define('BULK_BATCH_SIZE',         100);            // معالجة 100 في كل batch
