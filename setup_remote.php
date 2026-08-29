<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/gateways.php';
require_once __DIR__ . '/includes/crypto_schema.php';
require_once __DIR__ . '/lib/RiskEngine.php';

dp_create_crypto_tables();
RiskEngine::ensureTables();

$db = db();
$tables = $db->query('SHOW TABLES');
echo "✓ عدد الجداول: " . count($tables) . PHP_EOL;
foreach ($tables as $t) {
    echo "  - " . array_values($t)[0] . PHP_EOL;
}

// تحقق من .env
echo PHP_EOL . "✓ APP_ENV = " . APP_ENV . PHP_EOL;
echo "✓ APP_URL = " . SITE_URL . PHP_EOL;
echo "✓ DB = " . DB_NAME . PHP_EOL;
echo "✓ Stripe = " . (getenv('STRIPE_SECRET_KEY') ? 'موجود' : 'فارغ') . PHP_EOL;
echo "✓ TronGrid = " . (getenv('TRONGRID_API_KEY') ? 'موجود' : 'فارغ') . PHP_EOL;
echo "✓ Hot Wallet = " . getenv('HOT_WALLET_TRC20_ADDRESS') . PHP_EOL;
echo PHP_EOL . "النظام جاهز ✓" . PHP_EOL;
