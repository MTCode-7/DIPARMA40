<?php
/**
 * DIPARMA GATEWAY — settlement brand: net USDT TRC20 to Ledger after an enabled PSP approves.
 */
return [
    'code' => 'diparma_gateway',
    'name' => 'DIPARMA GATEWAY',
    'description' => 'After an enabled gateway approves the card, net USDT TRC20 is sent to Ledger. Destination is the wallet, not a bank IBAN.',
    'type' => 'card',
    'category' => 'ledger',
    'version' => '3.0.0',
    'status' => true,
    'is_production' => true,
    'setup_complete' => true,
    'requires_ssl' => true,
    'credentials' => [
        'ledger_address' => ['label' => 'Ledger TRC20 Address', 'type' => 'text', 'required' => true],
    ],
    'currencies' => ['USD', 'AED', 'EUR', 'GBP', 'SAR', 'KWD', 'QAR', 'EGP', 'USDT'],
    'card_types' => [
        'Visa', 'Mastercard', 'Maestro', 'Visa Electron',
        'American Express', 'Discover', 'Diners Club', 'JCB',
        'UnionPay', 'Mir', 'RuPay', 'Elo', 'Hipercard', 'Troy',
        'Verve', 'Mada', 'Meeza', 'KNET', 'Benefit', 'Jaywan',
        'NAPAS', 'PayPak', 'Dankort', 'Bancontact', 'Girocard',
        'Interac', 'UATP', 'Crypto card', 'Any other network or issuer',
    ],
    'transaction_types' => [
        'purchase_3d', 'purchase_2d', 'purchase_advice', 'purchase_offline',
        'purchase_online', 'auth_hold', 'auth_capture',
    ],
    'fees' => [
        'percentage' => 2.5,
        'fixed' => 0.30,
        'currency' => 'USD',
    ],
    'limits' => [
        'min' => 1.00,
        'max_per_transaction' => 25000.00,
        'max_daily' => 50000.00,
        'max_monthly' => 250000.00,
    ],
    'urls' => [
        'success' => 'https://diparmas.com/receipt.php',
        'cancel' => 'https://diparmas.com/checkout_router.php',
    ],
    'environment' => 'production',
    'features' => [
        '3d_secure' => true,
        'moto' => true,
        'ledger_integration' => true,
        'no_bank_destination' => true,
    ],
];
