<?php
/**
 * Checkout charge endpoint — live gateways only (Square, Nuvei, Stripe, …).
 * POST /api/checkout_charge.php
 * Same pipe as /api/pos_transaction.php and /pos/api/transaction.php
 */
while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start(static function (string $buffer): string {
    return (string) preg_replace('/^\xEF\xBB\xBF+/', '', $buffer);
});
require __DIR__ . '/../pos/api/transaction.php';
