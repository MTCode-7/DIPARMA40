<?php
/**
 * DI PARMA Gateway configuration.
 * Credentials are read from the environment and must not be committed here.
 */
return [
    'code' => 'diparma',
    'name' => 'DI PARMA Gateway',
    'description' => 'بوابة دفع شاملة مع تكامل Ledger',
    'type' => 'all',
    'category' => 'premium',
    'version' => '3.0.0',
    'status' => true,
    'is_production' => true,
    'setup_complete' => false,
    'requires_ssl' => true,
    'credentials' => [
        'api_key' => ['label' => 'API Key', 'type' => 'text', 'required' => true],
        'api_secret' => ['label' => 'API Secret', 'type' => 'password', 'required' => true],
        'merchant_id' => ['label' => 'Merchant ID', 'type' => 'text', 'required' => true],
        'terminal_id' => ['label' => 'Terminal ID', 'type' => 'text', 'required' => false],
        'ledger_address' => ['label' => 'Ledger TRC20 Address', 'type' => 'text', 'required' => true],
    ],
    'currencies' => ['USD', 'AED', 'SAR', 'EUR', 'GBP', 'KWD', 'QAR', 'EGP', 'USDT', 'BTC', 'ETH'],
    'transaction_types' => [
        'purchase_3d', 'purchase_2d', 'purchase_advice', 'purchase_offline',
        'purchase_online', 'auth_hold', 'auth_capture', 'recurring', 'installment',
        'crypto_purchase', 'gift_card', 'wire_transfer', 'quasi_cash',
    ],
    'fees' => [
        'percentage' => 2.5,
        'fixed' => 0.30,
        'currency' => 'USD',
        'moto' => ['percentage' => 3.0, 'fixed' => 0.50],
        'crypto' => ['percentage' => 0.5, 'fixed' => 0.00],
    ],
    'limits' => [
        'min' => 1.00,
        'max_per_transaction' => 25000.00,
        'max_daily' => 50000.00,
        'max_monthly' => 250000.00,
    ],
    'urls' => [
        'webhook' => 'https://diparmas.com/api/webhooks/diparma.php',
        'success' => 'https://diparmas.com/receipt.php',
        'cancel' => 'https://diparmas.com/checkout.php',
    ],
    'environment' => 'production',
    'features' => [
        '3d_secure' => true,
        'moto' => true,
        'refund' => true,
        'capture' => true,
        'void' => true,
        'recurring' => true,
        'installment' => true,
        'crypto' => true,
        'wire_transfer' => true,
        'ledger_integration' => true,
        'webhook' => true,
    ],
    'ledger' => [
        'enabled' => true,
        'network' => 'TRC20',
        'contract_address' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
        'confirmations_required' => 6,
        'timeout_minutes' => 30,
    ],
];
