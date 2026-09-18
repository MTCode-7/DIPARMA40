<?php
/**
 * DI PARMA POS — standalone module bootstrap.
 * Loads shared app auth/db only. POS logic stays in /pos.
 */
if (defined('POS_MODULE')) {
    return;
}

define('POS_MODULE', true);
define('POS_PATH', __DIR__);
define('POS_APP_ROOT', dirname(__DIR__));

require_once POS_APP_ROOT . '/includes/config.php';
require_once POS_APP_ROOT . '/includes/database.php';
require_once POS_APP_ROOT . '/includes/functions.php';
require_once POS_PATH . '/lib/operations.php';
require_once POS_PATH . '/lib/ops_sticker.php';
require_once POS_PATH . '/lib/gateways.php';
require_once POS_PATH . '/lib/devices.php';
require_once POS_PATH . '/lib/merchant.php';
require_once POS_PATH . '/lib/catalog.php';
require_once POS_PATH . '/lib/auth.php';
require_once POS_PATH . '/lib/ISO8583AdviceProcessor.php';
require_once POS_PATH . '/lib/ISO8583SecurityHandler.php';
require_once POS_PATH . '/lib/POSAdviceHandler.php';
require_once POS_PATH . '/lib/DirectAdvicePOSProcessor.php';
require_once POS_PATH . '/lib/POSSessionLogger.php';
require_once POS_APP_ROOT . '/lib/PaymentGatewayRouter.php';
require_once POS_APP_ROOT . '/lib/LivePaymentProcessor.php';
require_once POS_APP_ROOT . '/lib/DiParmaMasterEngine.php';
require_once POS_APP_ROOT . '/lib/UniversalTidPaymentRouter.php';
require_once POS_PATH . '/lib/RealOfflineSalesManager.php';
if (is_file(POS_APP_ROOT . '/includes/activity_flow.php')) {
    require_once POS_APP_ROOT . '/includes/activity_flow.php';
}

if (!function_exists('pos_url')) {
    function pos_public_dir(): string
    {
        return '/pos';
    }
    function pos_url(string $file = 'index.php', array $query = []): string
    {
        $query = array_filter($query, static function ($v) {
            return $v !== null && $v !== '';
        });
        return '/pos/' . ltrim($file, '/') . ($query ? ('?' . http_build_query($query)) : '');
    }
    function pos_login_url(array $query = []): string
    {
        $keep = $query;
        if (empty($keep['kiosk'])) {
            $keep['kiosk'] = '1';
        }
        if (empty($keep['device'])) {
            $tidForDevice = (string) ($keep['tid'] ?? '');
            $fromTid = ($tidForDevice !== '' && function_exists('pos_device_resolve'))
                ? pos_device_resolve(['terminal_id' => $tidForDevice, 'pos_model' => ''])
                : [];
            $keep['device'] = (string) ($fromTid['model'] ?? '');
            if ($keep['device'] === '') {
                $keep['device'] = 'bitel_ic3600';
            }
        }
        return pos_url('login.php', $keep);
    }
}

if (is_file(POS_APP_ROOT . '/includes/peer_link.php')) {
    require_once POS_APP_ROOT . '/includes/peer_link.php';
}
