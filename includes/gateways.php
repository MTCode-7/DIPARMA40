<?php
/**
 * ============================================================
 * DI PARMA | ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ - Version 3.0.0
 * ============================================================
 * ï؟½ï؟½ï؟½ï؟½ 100+ ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½
 * ============================================================
 */

// ============================================================
// [1] ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½
// ============================================================

/**
 * The shared transaction catalog exposed by every active gateway.
 * purchase_offline and purchase_online preserve the MOTO flows.
 */
function getAllGatewayTransactionTypes(): array {
    return [
        'purchase_3d', 'purchase_2d', 'purchase_advice',
        'purchase_offline', 'purchase_online', 'auth_hold',
        'auth_capture', 'recurring', 'installment', 'crypto_purchase',
        'gift_card', 'wire_transfer', 'quasi_cash',
    ];
}

$GLOBALS['PAYMENT_GATEWAYS_CONFIG'] = [
    // ============================================================
    // 1. ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ (Global)
    // ============================================================
    'stripe' => [
        'name' => 'Stripe',
        'region' => 'Global',
        'icon' => 'fab fa-stripe-s',
        'credentials' => [
            'api_key' => getenv('STRIPE_SECRET_KEY') ?: '',
            'public_key' => getenv('STRIPE_PUBLIC_KEY') ?: '',
            'webhook_secret' => getenv('STRIPE_WEBHOOK_SECRET') ?: '',
        ],
        'urls' => [
            'success' => getenv('STRIPE_SUCCESS_URL') ?: '/payment_success.php',
            'cancel' => getenv('STRIPE_CANCEL_URL') ?: '/payment_cancelled.php',
            'webhook' => getenv('STRIPE_WEBHOOK_URL') ?: '/api/webhook.php?gateway=stripe',
        ],
        'environment' => getenv('STRIPE_ENVIRONMENT') ?? '',
        'currencies' => ['USD', 'EUR', 'GBP', 'AED', 'SAR', 'KWD', 'BHD', 'OMR', 'QAR', 'EGP'],
        'fees' => ['percentage' => 2.9, 'fixed' => 0.30],
        'limits' => ['min' => 0.5, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
        'features' => ['subscriptions', 'webhooks', '3ds', 'connect'],
        'card_types' => ['Visa', 'Mastercard', 'Amex', 'Discover', 'JCB'],
        'setup_complete' => true
    ],
    
    'paypal' => [
        'name' => 'PayPal',
        'region' => 'Global',
        'icon' => 'fab fa-paypal',
        'credentials' => [
            'client_id' => getenv('PAYPAL_CLIENT_ID') ?? '',
            'secret' => getenv('PAYPAL_SECRET') ?? '',
        ],
        'urls' => [
            'success' => getenv('PAYPAL_RETURN_URL') ?: '/payment_success.php',
            'cancel' => getenv('PAYPAL_CANCEL_URL') ?: '/payment_cancelled.php',
            'webhook' => getenv('PAYPAL_WEBHOOK_URL') ?: '/api/webhook.php?gateway=paypal',
        ],
        'environment' => getenv('PAYPAL_ENVIRONMENT') ?? '',
        'currencies' => ['USD', 'EUR', 'GBP', 'AED', 'SAR', 'KWD', 'BHD', 'OMR', 'QAR'],
        'fees' => ['percentage' => 3.4, 'fixed' => 0.30],
        'limits' => ['min' => 1, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
        'features' => ['instant_transfer', 'subscriptions', 'payouts'],
        'card_types' => ['Visa', 'Mastercard', 'Amex', 'Discover'],
        'setup_complete' => false
    ],
    
    'adyen' => [
        'name' => 'Adyen',
        'region' => 'Global',
        'icon' => 'fas fa-arrow-right-arrow-left',
        'credentials' => [
            'api_key' => getenv('ADYEN_API_KEY') ?? '',
            'merchant_account' => getenv('ADYEN_MERCHANT_ACCOUNT') ?? '',
        ],
        'urls' => [
            'success' => getenv('ADYEN_SUCCESS_URL') ?: '/payment_success.php',
            'cancel' => getenv('ADYEN_CANCEL_URL') ?: '/payment_cancelled.php',
            'webhook' => getenv('ADYEN_WEBHOOK_URL') ?: '/api/webhook.php?gateway=adyen',
        ],
        'environment' => getenv('ADYEN_ENVIRONMENT') ?? '',
        'currencies' => ['USD', 'EUR', 'GBP', 'AED', 'SAR', 'KWD', 'BHD', 'OMR', 'QAR'],
        'fees' => ['percentage' => 2.5, 'fixed' => 0.20],
        'limits' => ['min' => 0.5, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
        'features' => ['multi_currency', 'global', '3ds', 'recurring'],
        'card_types' => ['Visa', 'Mastercard', 'Amex', 'UnionPay', 'JCB'],
        'setup_complete' => false
    ],
    
    'checkout' => [
        'name' => 'Checkout.com',
        'region' => 'Global',
        'icon' => 'fas fa-shopping-cart',
        'credentials' => [
            'api_key' => getenv('CHECKOUT_API_KEY') ?? '',
            'secret_key' => getenv('CHECKOUT_SECRET_KEY') ?? '',
        ],
        'urls' => [
            'success' => getenv('CHECKOUT_SUCCESS_URL') ?: '/payment_success.php',
            'cancel' => getenv('CHECKOUT_CANCEL_URL') ?: '/payment_cancelled.php',
        ],
        'environment' => getenv('CHECKOUT_ENVIRONMENT') ?? '',
        'currencies' => ['USD', 'EUR', 'GBP', 'AED', 'SAR', 'KWD', 'BHD', 'OMR', 'QAR'],
        'fees' => ['percentage' => 2.5, 'fixed' => 0.20],
        'limits' => ['min' => 0.5, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
        'features' => ['recurring', 'webhooks', '3ds'],
        'card_types' => ['Visa', 'Mastercard', 'Amex', 'UnionPay', 'JCB'],
        'setup_complete' => false
    ],
    
    // ============================================================
    // 2. ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ (Europe)
    // ============================================================
    'wise' => [
        'name' => 'Wise',
        'region' => 'Europe',
        'icon' => 'fas fa-exchange-alt',
        'credentials' => [
            'api_key'    => getenv('WISE_API_KEY') ?: '5497cf6e-ae91-42d2-99b8-e77d3328bf53',
            'profile_id' => getenv('WISE_PROFILE_ID') ?: '', // ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½
        ],
        'urls' => [
            'success' => getenv('WISE_SUCCESS_URL') ?: '/payment_success.php?gateway=wise',
            'cancel'  => getenv('WISE_CANCEL_URL')  ?: '/payment_cancelled.php?gateway=wise',
            'webhook' => getenv('WISE_WEBHOOK_URL') ?: '/api/webhook.php?gateway=wise',
        ],
        'environment' => 'live',
        'api_base'    => 'https://api.wise.com',
        'currencies'  => ['USD', 'EUR', 'GBP', 'AED', 'SAR', 'KWD', 'BHD', 'OMR', 'QAR', 'EGP', 'TRY', 'INR'],
        'fees'        => ['percentage' => 0.6, 'fixed' => 0.00],
        'limits'      => ['min' => 1, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
        'features'    => ['multi_currency', 'bank_transfer', 'instant', 'batch', 'webhooks'],
        'card_types'  => ['Bank Transfer'],
        'setup_complete' => true,
    ],
    
    'klarna' => [
        'name' => 'Klarna',
        'region' => 'Europe',
        'icon' => 'fas fa-credit-card',
        'credentials' => [
            'api_key' => getenv('KLARNA_API_KEY') ?? '',
            'secret_key' => getenv('KLARNA_SECRET_KEY') ?? '',
        ],
        'urls' => [
            'success' => getenv('KLARNA_SUCCESS_URL') ?: '/payment_success.php',
            'cancel' => getenv('KLARNA_CANCEL_URL') ?: '/payment_cancelled.php',
            'webhook' => getenv('KLARNA_WEBHOOK_URL') ?: '/api/webhook.php?gateway=klarna',
        ],
        'environment' => getenv('KLARNA_ENVIRONMENT') ?? '',
        'currencies' => ['EUR', 'USD', 'GBP', 'DKK', 'NOK', 'SEK'],
        'fees' => ['percentage' => 2.5, 'fixed' => 0.20],
        'limits' => ['min' => 1, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
        'features' => ['pay_later', 'slice_it', 'pay_now'],
        'card_types' => ['Visa', 'Mastercard', 'Amex'],
        'setup_complete' => false
    ],
    
    'sofort' => [
        'name' => 'Sofort',
        'region' => 'Europe',
        'icon' => 'fas fa-bolt',
        'credentials' => [
            'api_key' => getenv('SOFORT_API_KEY') ?? '',
            'secret_key' => getenv('SOFORT_SECRET_KEY') ?? '',
        ],
        'urls' => [
            'success' => getenv('SOFORT_SUCCESS_URL') ?: '/payment_success.php',
            'cancel' => getenv('SOFORT_CANCEL_URL') ?: '/payment_cancelled.php',
        ],
        'environment' => getenv('SOFORT_ENVIRONMENT') ?? '',
        'currencies' => ['EUR'],
        'fees' => ['percentage' => 1.5, 'fixed' => 0.10],
        'limits' => ['min' => 1, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
        'features' => ['instant', 'bank_transfer'],
        'card_types' => ['Bank Transfer'],
        'setup_complete' => false
    ],
    
    'giropay' => [
        'name' => 'Giropay',
        'region' => 'Europe',
        'icon' => 'fas fa-university',
        'credentials' => [
            'api_key' => getenv('GIROPAY_API_KEY') ?? '',
            'secret_key' => getenv('GIROPAY_SECRET_KEY') ?? '',
        ],
        'environment' => getenv('GIROPAY_ENVIRONMENT') ?? '',
        'currencies' => ['EUR'],
        'fees' => ['percentage' => 1.5, 'fixed' => 0.10],
        'limits' => ['min' => 1, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
        'features' => ['bank_transfer'],
        'card_types' => ['Bank Transfer'],
        'setup_complete' => false
    ],
    
    'ideal' => [
        'name' => 'iDEAL',
        'region' => 'Europe',
        'icon' => 'fas fa-university',
        'credentials' => [
            'api_key' => getenv('IDEAL_API_KEY') ?? '',
            'secret_key' => getenv('IDEAL_SECRET_KEY') ?? '',
        ],
        'environment' => getenv('IDEAL_ENVIRONMENT') ?? '',
        'currencies' => ['EUR'],
        'fees' => ['percentage' => 1.5, 'fixed' => 0.10],
        'limits' => ['min' => 1, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
        'features' => ['bank_transfer'],
        'card_types' => ['Bank Transfer'],
        'setup_complete' => false
    ],
    
    // ============================================================
    // 3. ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ (UK)
    // ============================================================
    'revolut' => [
        'name' => 'Revolut',
        'region' => 'UK',
        'icon' => 'fas fa-credit-card',
        'credentials' => [
            'api_key' => getenv('REVOLUT_API_KEY') ?? '',
            'secret_key' => getenv('REVOLUT_SECRET_KEY') ?? '',
        ],
        'urls' => [
            'success' => getenv('REVOLUT_SUCCESS_URL') ?: '/payment_success.php',
            'cancel' => getenv('REVOLUT_CANCEL_URL') ?: '/payment_cancelled.php',
            'webhook' => getenv('REVOLUT_WEBHOOK_URL') ?: '/api/webhook.php?gateway=revolut',
        ],
        'environment' => getenv('REVOLUT_ENVIRONMENT') ?? '',
        'currencies' => ['GBP', 'USD', 'EUR', 'AED', 'SAR'],
        'fees' => ['percentage' => 2.0, 'fixed' => 0.20],
        'limits' => ['min' => 1, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
        'features' => ['instant', 'multi_currency'],
        'card_types' => ['Visa', 'Mastercard'],
        'setup_complete' => false
    ],
    
    // ============================================================
    // 4. ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ (USA)
    // ============================================================
    'square' => [
        'name' => 'Square',
        'region' => 'USA',
        'icon' => 'fas fa-square',
        'credentials' => [
            'api_key' => getenv('SQUARE_API_KEY') ?? '',
            'secret_key' => getenv('SQUARE_SECRET_KEY') ?? '',
        ],
        'urls' => [
            'success' => getenv('SQUARE_SUCCESS_URL') ?: '/payment_success.php',
            'cancel' => getenv('SQUARE_CANCEL_URL') ?: '/payment_cancelled.php',
            'webhook' => getenv('SQUARE_WEBHOOK_URL') ?: '/api/webhook.php?gateway=square',
        ],
        'environment' => getenv('SQUARE_ENVIRONMENT') ?? '',
        'currencies' => ['USD', 'EUR', 'GBP', 'AED', 'CAD', 'AUD', 'JPY'],
        'fees' => ['percentage' => 2.6, 'fixed' => 0.10],
        'limits' => ['min' => 0.5, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
        'features' => ['pos', 'online', 'invoicing'],
        'card_types' => ['Visa', 'Mastercard', 'Amex', 'Discover'],
        'setup_complete' => false
    ],
    
    'authorize_net' => [
        'name' => 'Authorize.Net',
        'region' => 'USA',
        'icon' => 'fas fa-shield-alt',
        'credentials' => [
            'api_key' => getenv('AUTHORIZE_NET_API_KEY') ?? '',
            'secret_key' => getenv('AUTHORIZE_NET_SECRET_KEY') ?? '',
        ],
        'environment' => getenv('AUTHORIZE_NET_ENVIRONMENT') ?? '',
        'currencies' => ['USD', 'EUR', 'GBP', 'AED', 'CAD', 'AUD', 'JPY', 'CHF'],
        'fees' => ['percentage' => 2.9, 'fixed' => 0.30],
        'limits' => ['min' => 0.5, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
        'features' => ['cim', 'recurring', 'subscriptions'],
        'card_types' => ['Visa', 'Mastercard', 'Amex', 'Discover', 'JCB'],
        'setup_complete' => false
    ],
    
    'braintree' => [
        'name' => 'Braintree',
        'region' => 'USA',
        'icon' => 'fas fa-tree',
        'credentials' => [
            'api_key' => getenv('BRAINTREE_API_KEY') ?? '',
            'secret_key' => getenv('BRAINTREE_SECRET_KEY') ?? '',
        ],
        'environment' => getenv('BRAINTREE_ENVIRONMENT') ?? '',
        'currencies' => ['USD', 'EUR', 'GBP', 'AED', 'CAD', 'AUD', 'JPY'],
        'fees' => ['percentage' => 2.9, 'fixed' => 0.30],
        'limits' => ['min' => 0.5, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
        'features' => ['subscriptions', 'webhooks', '3ds'],
        'card_types' => ['Visa', 'Mastercard', 'Amex', 'Discover', 'JCB'],
        'setup_complete' => false
    ],
    
    // ============================================================
    // 5. ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ (China)
    // ============================================================
    'alipay' => [
        'name' => 'Alipay',
        'region' => 'China',
        'icon' => 'fas fa-credit-card',
        'credentials' => [
            'api_key' => getenv('ALIPAY_API_KEY') ?? '',
            'secret_key' => getenv('ALIPAY_SECRET_KEY') ?? '',
        ],
        'urls' => [
            'success' => getenv('ALIPAY_SUCCESS_URL') ?: '/payment_success.php',
            'cancel' => getenv('ALIPAY_CANCEL_URL') ?: '/payment_cancelled.php',
            'webhook' => getenv('ALIPAY_WEBHOOK_URL') ?: '/api/webhook.php?gateway=alipay',
        ],
        'environment' => getenv('ALIPAY_ENVIRONMENT') ?? '',
        'currencies' => ['CNY', 'USD', 'EUR', 'GBP', 'AED', 'JPY'],
        'fees' => ['percentage' => 2.5, 'fixed' => 0.30],
        'limits' => ['min' => 1, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
        'features' => ['wallet', 'qr', 'mobile'],
        'card_types' => ['Alipay Wallet'],
        'setup_complete' => false
    ],
    
    'wechat_pay' => [
        'name' => 'WeChat Pay',
        'region' => 'China',
        'icon' => 'fab fa-weixin',
        'credentials' => [
            'api_key' => getenv('WECHAT_PAY_API_KEY') ?? '',
            'secret_key' => getenv('WECHAT_PAY_SECRET_KEY') ?? '',
        ],
        'urls' => [
            'success' => getenv('WECHAT_PAY_SUCCESS_URL') ?: '/payment_success.php',
            'cancel' => getenv('WECHAT_PAY_CANCEL_URL') ?: '/payment_cancelled.php',
            'webhook' => getenv('WECHAT_PAY_WEBHOOK_URL') ?: '/api/webhook.php?gateway=wechat_pay',
        ],
        'environment' => getenv('WECHAT_PAY_ENVIRONMENT') ?? '',
        'currencies' => ['CNY', 'USD', 'EUR', 'GBP', 'AED', 'JPY'],
        'fees' => ['percentage' => 2.5, 'fixed' => 0.30],
        'limits' => ['min' => 1, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
        'features' => ['wallet', 'qr', 'nfc', 'mobile'],
        'card_types' => ['WeChat Wallet'],
        'setup_complete' => false
    ],
    
    'unionpay' => [
        'name' => 'UnionPay',
        'region' => 'China',
        'icon' => 'fas fa-credit-card',
        'credentials' => [
            'api_key' => getenv('UNIONPAY_API_KEY') ?? '',
            'secret_key' => getenv('UNIONPAY_SECRET_KEY') ?? '',
        ],
        'urls' => [
            'success' => getenv('UNIONPAY_SUCCESS_URL') ?: '/payment_success.php',
            'cancel' => getenv('UNIONPAY_CANCEL_URL') ?: '/payment_cancelled.php',
            'webhook' => getenv('UNIONPAY_WEBHOOK_URL') ?: '/api/webhook.php?gateway=unionpay',
        ],
        'currencies' => ['CNY', 'USD', 'EUR', 'GBP', 'AED', 'JPY'],
        'fees' => ['percentage' => 2.0, 'fixed' => 0.20],
        'limits' => ['min' => 1, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
        'features' => ['global', 'nfc', 'mobile'],
        'card_types' => ['UnionPay Card'],
        'setup_complete' => false
    ],
    
    // ============================================================
    // 6. ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ (Japan)
    // ============================================================
    'line_pay' => [
        'name' => 'LINE Pay',
        'region' => 'Japan',
        'icon' => 'fab fa-line',
        'credentials' => [
            'api_key' => getenv('LINE_PAY_API_KEY') ?? '',
            'secret_key' => getenv('LINE_PAY_SECRET_KEY') ?? '',
        ],
        'urls' => [
            'success' => getenv('LINE_PAY_SUCCESS_URL') ?: '/payment_success.php',
            'cancel' => getenv('LINE_PAY_CANCEL_URL') ?: '/payment_cancelled.php',
            'webhook' => getenv('LINE_PAY_WEBHOOK_URL') ?: '/api/webhook.php?gateway=line_pay',
        ],
        'environment' => getenv('LINE_PAY_ENVIRONMENT') ?? '',
        'currencies' => ['JPY', 'USD', 'EUR', 'GBP', 'AED'],
        'fees' => ['percentage' => 2.5, 'fixed' => 0.30],
        'limits' => ['min' => 1, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
        'features' => ['wallet', 'qr', 'mobile'],
        'card_types' => ['LINE Wallet'],
        'setup_complete' => false
    ],
    
    'paypay' => [
        'name' => 'PayPay',
        'region' => 'Japan',
        'icon' => 'fas fa-yen-sign',
        'credentials' => [
            'api_key' => getenv('PAYPAY_API_KEY') ?? '',
            'secret_key' => getenv('PAYPAY_SECRET_KEY') ?? '',
        ],
        'urls' => [
            'success' => getenv('PAYPAY_SUCCESS_URL') ?: '/payment_success.php',
            'cancel' => getenv('PAYPAY_CANCEL_URL') ?: '/payment_cancelled.php',
            'webhook' => getenv('PAYPAY_WEBHOOK_URL') ?: '/api/webhook.php?gateway=paypay',
        ],
        'environment' => getenv('PAYPAY_ENVIRONMENT') ?? '',
        'currencies' => ['JPY', 'USD'],
        'fees' => ['percentage' => 2.0, 'fixed' => 0.20],
        'limits' => ['min' => 1, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
        'features' => ['wallet', 'nfc', 'qr'],
        'card_types' => ['PayPay Wallet'],
        'setup_complete' => false
    ],
    
    // ============================================================
    // 7. ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ (South Korea)
    // ============================================================
    'kakao_pay' => [
        'name' => 'KakaoPay',
        'region' => 'Korea',
        'icon' => 'fas fa-comment',
        'credentials' => [
            'api_key' => getenv('KAKAO_PAY_API_KEY') ?? '',
            'secret_key' => getenv('KAKAO_PAY_SECRET_KEY') ?? '',
        ],
        'urls' => [
            'success' => getenv('KAKAO_PAY_SUCCESS_URL') ?: '/payment_success.php',
            'cancel' => getenv('KAKAO_PAY_CANCEL_URL') ?: '/payment_cancelled.php',
            'webhook' => getenv('KAKAO_PAY_WEBHOOK_URL') ?: '/api/webhook.php?gateway=kakao_pay',
        ],
        'environment' => getenv('KAKAO_PAY_ENVIRONMENT') ?? '',
        'currencies' => ['KRW', 'USD'],
        'fees' => ['percentage' => 2.0, 'fixed' => 0.20],
        'limits' => ['min' => 1, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
        'features' => ['wallet', 'qr', 'mobile'],
        'card_types' => ['Kakao Wallet'],
        'setup_complete' => false
    ],
    
    'toss' => [
        'name' => 'Toss',
        'region' => 'Korea',
        'icon' => 'fas fa-coin',
        'credentials' => [
            'api_key' => getenv('TOSS_API_KEY') ?? '',
            'secret_key' => getenv('TOSS_SECRET_KEY') ?? '',
        ],
        'environment' => getenv('TOSS_ENVIRONMENT') ?? '',
        'currencies' => ['KRW', 'USD'],
        'fees' => ['percentage' => 1.5, 'fixed' => 0.10],
        'limits' => ['min' => 1, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
        'features' => ['wallet', 'transfer'],
        'card_types' => ['Toss Wallet'],
        'setup_complete' => false
    ],
    
    // ============================================================
    // 8. ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ (SE Asia)
    // ============================================================
    'grab_pay' => [
        'name' => 'GrabPay',
        'region' => 'SE Asia',
        'icon' => 'fas fa-car',
        'credentials' => [
            'api_key' => getenv('GRAB_PAY_API_KEY') ?? '',
            'secret_key' => getenv('GRAB_PAY_SECRET_KEY') ?? '',
        ],
        'environment' => getenv('GRAB_PAY_ENVIRONMENT') ?? '',
        'currencies' => ['SGD', 'MYR', 'USD', 'EUR', 'GBP', 'AED'],
        'fees' => ['percentage' => 2.0, 'fixed' => 0.20],
        'limits' => ['min' => 1, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
        'features' => ['wallet', 'qr', 'mobile'],
        'card_types' => ['Grab Wallet'],
        'setup_complete' => false
    ],
    
    'gopay' => [
        'name' => 'GoPay',
        'region' => 'SE Asia',
        'icon' => 'fas fa-motorcycle',
        'credentials' => [
            'api_key' => getenv('GOPAY_API_KEY') ?? '',
            'secret_key' => getenv('GOPAY_SECRET_KEY') ?? '',
        ],
        'environment' => getenv('GOPAY_ENVIRONMENT') ?? '',
        'currencies' => ['IDR', 'USD'],
        'fees' => ['percentage' => 2.0, 'fixed' => 0.20],
        'limits' => ['min' => 1, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
        'features' => ['wallet', 'qr'],
        'card_types' => ['GoPay Wallet'],
        'setup_complete' => false
    ],
    
    'ovo' => [
        'name' => 'OVO',
        'region' => 'SE Asia',
        'icon' => 'fas fa-mobile-alt',
        'credentials' => [
            'api_key' => getenv('OVO_API_KEY') ?? '',
            'secret_key' => getenv('OVO_SECRET_KEY') ?? '',
        ],
        'environment' => getenv('OVO_ENVIRONMENT') ?? '',
        'currencies' => ['IDR', 'USD'],
        'fees' => ['percentage' => 1.5, 'fixed' => 0.10],
        'limits' => ['min' => 1, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
        'features' => ['wallet', 'qr'],
        'card_types' => ['OVO Wallet'],
        'setup_complete' => false
    ],
    
    'truemoney' => [
        'name' => 'TrueMoney',
        'region' => 'SE Asia',
        'icon' => 'fas fa-money-bill',
        'credentials' => [
            'api_key' => getenv('TRUEMONEY_API_KEY') ?? '',
            'secret_key' => getenv('TRUEMONEY_SECRET_KEY') ?? '',
        ],
        'environment' => getenv('TRUEMONEY_ENVIRONMENT') ?? '',
        'currencies' => ['THB', 'USD'],
        'fees' => ['percentage' => 2.0, 'fixed' => 0.20],
        'limits' => ['min' => 1, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
        'features' => ['wallet', 'qr'],
        'card_types' => ['TrueMoney Wallet'],
        'setup_complete' => false
    ],
    
    'gcash' => [
        'name' => 'GCash',
        'region' => 'SE Asia',
        'icon' => 'fas fa-mobile-alt',
        'credentials' => [
            'api_key' => getenv('GCASH_API_KEY') ?? '',
            'secret_key' => getenv('GCASH_SECRET_KEY') ?? '',
        ],
        'environment' => getenv('GCASH_ENVIRONMENT') ?? '',
        'currencies' => ['PHP', 'USD'],
        'fees' => ['percentage' => 2.0, 'fixed' => 0.20],
        'limits' => ['min' => 1, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
        'features' => ['wallet', 'qr'],
        'card_types' => ['GCash Wallet'],
        'setup_complete' => false
    ],
    
    'momo' => [
        'name' => 'MoMo',
        'region' => 'SE Asia',
        'icon' => 'fas fa-wallet',
        'credentials' => [
            'api_key' => getenv('MOMO_API_KEY') ?? '',
            'secret_key' => getenv('MOMO_SECRET_KEY') ?? '',
        ],
        'environment' => getenv('MOMO_ENVIRONMENT') ?? '',
        'currencies' => ['VND', 'USD'],
        'fees' => ['percentage' => 2.0, 'fixed' => 0.20],
        'limits' => ['min' => 1, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
        'features' => ['wallet', 'qr'],
        'card_types' => ['MoMo Wallet'],
        'setup_complete' => false
    ],
    
    // ============================================================
    // 9. ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ (India)
    // ============================================================
    'razorpay' => [
        'name' => 'Razorpay',
        'region' => 'India',
        'icon' => 'fas fa-razor',
        'credentials' => [
            'api_key' => getenv('RAZORPAY_API_KEY') ?? '',
            'secret_key' => getenv('RAZORPAY_SECRET_KEY') ?? '',
        ],
        'urls' => [
            'success' => getenv('RAZORPAY_SUCCESS_URL') ?: '/payment_success.php',
            'cancel' => getenv('RAZORPAY_CANCEL_URL') ?: '/payment_cancelled.php',
            'webhook' => getenv('RAZORPAY_WEBHOOK_URL') ?: '/api/webhook.php?gateway=razorpay',
        ],
        'environment' => getenv('RAZORPAY_ENVIRONMENT') ?? '',
        'currencies' => ['INR', 'USD', 'EUR', 'GBP', 'AED', 'SAR'],
        'fees' => ['percentage' => 2.5, 'fixed' => 0.30],
        'limits' => ['min' => 1, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
        'features' => ['recurring', 'webhooks', 'subscriptions'],
        'card_types' => ['Visa', 'Mastercard', 'Amex', 'Rupay'],
        'setup_complete' => false
    ],
    
    'ccavenue' => [
        'name' => 'CCAvenue',
        'region' => 'India',
        'icon' => 'fas fa-building-columns',
        'credentials' => [
            'api_key' => getenv('CCAVENUE_API_KEY') ?? '',
            'secret_key' => getenv('CCAVENUE_SECRET_KEY') ?? '',
        ],
        'environment' => getenv('CCAVENUE_ENVIRONMENT') ?? '',
        'currencies' => ['INR', 'USD', 'EUR', 'GBP', 'AED', 'SAR'],
        'fees' => ['percentage' => 2.5, 'fixed' => 0.30],
        'limits' => ['min' => 1, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
        'features' => ['multi_currency', 'recurring'],
        'card_types' => ['Visa', 'Mastercard', 'Amex', 'Rupay'],
        'setup_complete' => false
    ],
    
    'paytm' => [
        'name' => 'Paytm',
        'region' => 'India',
        'icon' => 'fas fa-mobile-alt',
        'credentials' => [
            'api_key' => getenv('PAYTM_API_KEY') ?? '',
            'secret_key' => getenv('PAYTM_SECRET_KEY') ?? '',
        ],
        'environment' => getenv('PAYTM_ENVIRONMENT') ?? '',
        'currencies' => ['INR', 'USD'],
        'fees' => ['percentage' => 2.0, 'fixed' => 0.20],
        'limits' => ['min' => 1, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
        'features' => ['mobile', 'wallet', 'upi'],
        'card_types' => ['Paytm Wallet', 'UPI'],
        'setup_complete' => false
    ],
    
    'phonepe' => [
        'name' => 'PhonePe',
        'region' => 'India',
        'icon' => 'fas fa-phone-alt',
        'credentials' => [
            'api_key' => getenv('PHONEPE_API_KEY') ?? '',
            'secret_key' => getenv('PHONEPE_SECRET_KEY') ?? '',
        ],
        'environment' => getenv('PHONEPE_ENVIRONMENT') ?? '',
        'currencies' => ['INR'],
        'fees' => ['percentage' => 1.5, 'fixed' => 0.10],
        'limits' => ['min' => 1, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
        'features' => ['mobile', 'wallet', 'upi'],
        'card_types' => ['PhonePe Wallet', 'UPI'],
        'setup_complete' => false
    ],
    
    // ============================================================
    // 10. ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ (MENA)
    // ============================================================
    'paytabs' => [
        'name' => 'PayTabs',
        'region' => 'MENA',
        'icon' => 'fas fa-tab',
        'credentials' => [
            'api_key' => getenv('PAYTABS_API_KEY') ?? '',
            'secret_key' => getenv('PAYTABS_SECRET_KEY') ?? '',
        ],
        'urls' => [
            'success' => getenv('PAYTABS_SUCCESS_URL') ?: '/payment_success.php',
            'cancel' => getenv('PAYTABS_CANCEL_URL') ?: '/payment_cancelled.php',
            'webhook' => getenv('PAYTABS_WEBHOOK_URL') ?: '/api/webhook.php?gateway=paytabs',
        ],
        'environment' => getenv('PAYTABS_ENVIRONMENT') ?? '',
        'currencies' => ['USD', 'EUR', 'AED', 'SAR', 'KWD', 'BHD', 'OMR', 'QAR', 'EGP'],
        'fees' => ['percentage' => 2.5, 'fixed' => 0.30],
        'limits' => ['min' => 1, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
        'features' => ['tokenization', 'recurring', '3ds'],
        'card_types' => ['Visa', 'Mastercard', 'Amex', 'Mada', 'Meza'],
        'setup_complete' => false
    ],
    
    'payfort' => [
        'name' => 'PayFort',
        'region' => 'MENA',
        'icon' => 'fas fa-fort-awesome',
        'credentials' => [
            'api_key' => getenv('PAYFORT_API_KEY') ?? '',
            'secret_key' => getenv('PAYFORT_SECRET_KEY') ?? '',
        ],
        'urls' => [
            'success' => getenv('PAYFORT_SUCCESS_URL') ?: '/payment_success.php',
            'cancel' => getenv('PAYFORT_CANCEL_URL') ?: '/payment_cancelled.php',
            'webhook' => getenv('PAYFORT_WEBHOOK_URL') ?: '/api/webhook.php?gateway=payfort',
        ],
        'environment' => getenv('PAYFORT_ENVIRONMENT') ?? '',
        'currencies' => ['AED', 'SAR', 'USD', 'EUR', 'GBP', 'KWD', 'BHD', 'OMR', 'QAR'],
        'fees' => ['percentage' => 2.5, 'fixed' => 0.30],
        'limits' => ['min' => 1, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
        'features' => ['3ds', 'tokenization'],
        'card_types' => ['Visa', 'Mastercard', 'Amex', 'Mada'],
        'setup_complete' => false
    ],
    
    'hyperpay' => [
        'name' => 'HyperPay',
        'region' => 'MENA',
        'icon' => 'fas fa-bolt',
        'credentials' => [
            'api_key' => getenv('HYPERPAY_API_KEY') ?? '',
            'secret_key' => getenv('HYPERPAY_SECRET_KEY') ?? '',
        ],
        'urls' => [
            'success' => getenv('HYPERPAY_SUCCESS_URL') ?: '/payment_success.php',
            'cancel' => getenv('HYPERPAY_CANCEL_URL') ?: '/payment_cancelled.php',
            'webhook' => getenv('HYPERPAY_WEBHOOK_URL') ?: '/api/webhook.php?gateway=hyperpay',
        ],
        'environment' => getenv('HYPERPAY_ENVIRONMENT') ?? '',
        'currencies' => ['AED', 'SAR', 'USD', 'EUR', 'GBP', 'KWD', 'BHD', 'OMR', 'QAR'],
        'fees' => ['percentage' => 2.5, 'fixed' => 0.30],
        'limits' => ['min' => 1, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
        'features' => ['instant', 'nfc', '3ds'],
        'card_types' => ['Visa', 'Mastercard', 'Amex', 'Mada'],
        'setup_complete' => false
    ],
    
    'tap' => [
        'name' => 'Tap Payments',
        'region' => 'MENA',
        'icon' => 'fas fa-tap',
        'credentials' => [
            'api_key' => getenv('TAP_PAYMENTS_API_KEY') ?? '',
            'secret_key' => getenv('TAP_PAYMENTS_SECRET_KEY') ?? '',
        ],
        'urls' => [
            'success' => getenv('TAP_PAYMENTS_SUCCESS_URL') ?: '/payment_success.php',
            'cancel' => getenv('TAP_PAYMENTS_CANCEL_URL') ?: '/payment_cancelled.php',
            'webhook' => getenv('TAP_PAYMENTS_WEBHOOK_URL') ?: '/api/webhook.php?gateway=tap',
        ],
        'environment' => getenv('TAP_PAYMENTS_ENVIRONMENT') ?? '',
        'currencies' => ['AED', 'SAR', 'USD', 'EUR', 'GBP', 'KWD', 'BHD', 'OMR', 'QAR'],
        'fees' => ['percentage' => 2.0, 'fixed' => 0.20],
        'limits' => ['min' => 1, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
        'features' => ['instant', 'nfc', 'qr', '3ds'],
        'card_types' => ['Visa', 'Mastercard', 'Amex', 'Mada'],
        'setup_complete' => false
    ],
    
    'myfatoorah' => [
        'name' => 'MyFatoorah',
        'region' => 'MENA',
        'icon' => 'fas fa-money-bill-wave',
        'credentials' => [
            'api_key' => getenv('MYFAOORAH_API_KEY') ?: 'drmd2050',
            'secret_key' => getenv('MYFAOORAH_SECRET_KEY') ?: 'SK_ARE_rl0...'
        ],
        'urls' => [
            'success' => getenv('MYFAOORAH_SUCCESS_URL') ?: '/payment_success.php',
            'cancel' => getenv('MYFAOORAH_CANCEL_URL') ?: '/payment_cancelled.php',
            'webhook' => getenv('MYFAOORAH_WEBHOOK_URL') ?: 'https://diparmas.com/diparma/api/webhook_receiver.php',
        ],
        'environment' => getenv('MYFAOORAH_ENVIRONMENT') ?? '',
        'currencies' => ['AED', 'SAR', 'KWD', 'BHD', 'OMR', 'QAR', 'USD', 'EUR'],
        'fees' => ['percentage' => 2.0, 'fixed' => 0.20],
        'limits' => ['min' => 1, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
        'features' => ['instant', '2d_secure', '3ds'],
        'card_types' => ['Visa', 'Mastercard', 'Amex', 'Mada', 'KNET'],
        'setup_complete' => true
    ],

    // integrated ظ…ط­ط°ظˆظپ ظ†ظ‡ط§ط¦ظٹط§ظ‹ â€” ظ„ط§ ظ…ط­ط§ظƒط§ط©
    
    'ziina' => [
        'name' => 'Ziina',
        'region' => 'MENA',
        'icon' => 'fas fa-wallet',
        'credentials' => [
            'api_key' => getenv('ZIINA_API_KEY') ?? '',
            'secret_key' => getenv('ZIINA_SECRET_KEY') ?? '',
        ],
        'urls' => [
            'success' => getenv('ZIINA_SUCCESS_URL') ?: '/payment_success.php',
            'cancel' => getenv('ZIINA_CANCEL_URL') ?: '/payment_cancelled.php',
            'webhook' => getenv('ZIINA_WEBHOOK_URL') ?: '/api/webhook.php?gateway=ziina',
        ],
        'environment' => getenv('ZIINA_ENVIRONMENT') ?? '',
        'currencies' => ['AED', 'USD', 'EUR', 'GBP', 'SAR'],
        'fees' => ['percentage' => 2.0, 'fixed' => 0.20],
        'limits' => ['min' => 1, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
        'features' => ['instant', 'nfc', 'qr'],
        'card_types' => ['Visa', 'Mastercard'],
        'setup_complete' => false
    ],
    
    'paymob' => [
        'name' => 'Paymob',
        'region' => 'MENA',
        'icon' => 'fas fa-mobile-alt',
        'credentials' => [
            'api_key' => getenv('PAYMOB_API_KEY') ?? '',
            'secret_key' => getenv('PAYMOB_SECRET_KEY') ?? '',
        ],
        'urls' => [
            'success' => getenv('PAYMOB_SUCCESS_URL') ?: '/payment_success.php',
            'cancel' => getenv('PAYMOB_CANCEL_URL') ?: '/payment_cancelled.php',
            'webhook' => getenv('PAYMOB_WEBHOOK_URL') ?: '/api/webhook.php?gateway=paymob',
        ],
        'environment' => getenv('PAYMOB_ENVIRONMENT') ?? '',
        'currencies' => ['EGP', 'USD', 'EUR', 'AED', 'SAR'],
        'fees' => ['percentage' => 2.0, 'fixed' => 0.20],
        'limits' => ['min' => 1, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
        'features' => ['mobile', 'wallet', 'card'],
        'card_types' => ['Visa', 'Mastercard', 'Meeza'],
        'setup_complete' => false
    ],
    
    'fawry' => [
        'name' => 'Fawry',
        'region' => 'MENA',
        'icon' => 'fas fa-credit-card',
        'credentials' => [
            'api_key' => getenv('FAWRY_API_KEY') ?? '',
            'secret_key' => getenv('FAWRY_SECRET_KEY') ?? '',
        ],
        'urls' => [
            'success' => getenv('FAWRY_SUCCESS_URL') ?: '/payment_success.php',
            'cancel' => getenv('FAWRY_CANCEL_URL') ?: '/payment_cancelled.php',
            'webhook' => getenv('FAWRY_WEBHOOK_URL') ?: '/api/webhook.php?gateway=fawry',
        ],
        'environment' => getenv('FAWRY_ENVIRONMENT') ?? '',
        'currencies' => ['EGP', 'USD'],
        'fees' => ['percentage' => 2.0, 'fixed' => 0.15],
        'limits' => ['min' => 1, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
        'features' => ['wallet', 'qr', 'card'],
        'card_types' => ['Visa', 'Mastercard', 'Meeza'],
        'setup_complete' => false
    ],
    
    // ============================================================
    // 11. ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ (Africa)
    // ============================================================
    'flutterwave' => [
        'name' => 'Flutterwave',
        'region' => 'Africa',
        'icon' => 'fas fa-wave-square',
        'credentials' => [
            'api_key' => getenv('FLUTTERWAVE_API_KEY') ?? '',
            'secret_key' => getenv('FLUTTERWAVE_SECRET_KEY') ?? '',
        ],
        'urls' => [
            'success' => getenv('FLUTTERWAVE_SUCCESS_URL') ?: '/payment_success.php',
            'cancel' => getenv('FLUTTERWAVE_CANCEL_URL') ?: '/payment_cancelled.php',
            'webhook' => getenv('FLUTTERWAVE_WEBHOOK_URL') ?: '/api/webhook.php?gateway=flutterwave',
        ],
        'environment' => getenv('FLUTTERWAVE_ENVIRONMENT') ?? '',
        'currencies' => ['NGN', 'USD', 'EUR', 'GBP', 'AED', 'SAR', 'KWD', 'BHD', 'OMR', 'QAR'],
        'fees' => ['percentage' => 2.5, 'fixed' => 0.30],
        'limits' => ['min' => 1, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
        'features' => ['card', 'bank_transfer', 'mobile_money'],
        'card_types' => ['Visa', 'Mastercard', 'Amex', 'Verve'],
        'setup_complete' => false
    ],
    
    'paystack' => [
        'name' => 'Paystack',
        'region' => 'Africa',
        'icon' => 'fas fa-stack-overflow',
        'credentials' => [
            'api_key' => getenv('PAYSTACK_API_KEY') ?? '',
            'secret_key' => getenv('PAYSTACK_SECRET_KEY') ?? '',
        ],
        'urls' => [
            'success' => getenv('PAYSTACK_SUCCESS_URL') ?: '/payment_success.php',
            'cancel' => getenv('PAYSTACK_CANCEL_URL') ?: '/payment_cancelled.php',
            'webhook' => getenv('PAYSTACK_WEBHOOK_URL') ?: '/api/webhook.php?gateway=paystack',
        ],
        'environment' => getenv('PAYSTACK_ENVIRONMENT') ?? '',
        'currencies' => ['NGN', 'USD', 'GBP', 'EUR', 'AED', 'SAR'],
        'fees' => ['percentage' => 2.5, 'fixed' => 0.30],
        'limits' => ['min' => 1, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
        'features' => ['card', 'bank_transfer', 'mobile_money'],
        'card_types' => ['Visa', 'Mastercard', 'Amex', 'Verve'],
        'setup_complete' => false
    ],
    
    'mpesa' => [
        'name' => 'M-Pesa',
        'region' => 'Africa',
        'icon' => 'fas fa-mobile-alt',
        'credentials' => [
            'api_key' => getenv('MPESA_API_KEY') ?? '',
            'secret_key' => getenv('MPESA_SECRET_KEY') ?? '',
        ],
        'urls' => [
            'success' => getenv('MPESA_SUCCESS_URL') ?: '/payment_success.php',
            'cancel' => getenv('MPESA_CANCEL_URL') ?: '/payment_cancelled.php',
            'webhook' => getenv('MPESA_WEBHOOK_URL') ?: '/api/webhook.php?gateway=mpesa',
        ],
        'environment' => getenv('MPESA_ENVIRONMENT') ?? '',
        'currencies' => ['KES', 'USD', 'EUR', 'GBP', 'AED'],
        'fees' => ['percentage' => 1.5, 'fixed' => 0.10],
        'limits' => ['min' => 1, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
        'features' => ['mobile_money', 'wallet'],
        'card_types' => ['M-Pesa Wallet'],
        'setup_complete' => false
    ],
    
    // ============================================================
    // 12. ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ (Cryptocurrency)
    // ============================================================
    'binance' => [
        'name' => 'Binance Pay',
        'region' => 'Crypto',
        'icon' => 'fab fa-btc',
        'credentials' => [
            'api_key' => getenv('BINANCE_API_KEY') ?? '',
            'secret_key' => getenv('BINANCE_SECRET_KEY') ?? '',
        ],
        'urls' => [
            'success' => getenv('BINANCE_SUCCESS_URL') ?: '/payment_success.php',
            'cancel' => getenv('BINANCE_CANCEL_URL') ?: '/payment_cancelled.php',
            'webhook' => getenv('BINANCE_WEBHOOK_URL') ?: '/api/webhook.php?gateway=binance',
        ],
        'environment' => getenv('BINANCE_ENVIRONMENT') ?? '',
        'currencies' => ['USDT', 'BNB', 'BTC', 'ETH', 'BUSD', 'USDC', 'XRP', 'SOL', 'ADA'],
        'fees' => ['percentage' => 1.0, 'fixed' => 0.10],
        'limits' => ['min' => 5, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
        'features' => ['wallet', 'exchange', 'merchant'],
        'card_types' => ['Crypto Wallet'],
        'setup_complete' => false
    ],
    
    'coinbase' => [
        'name' => 'Coinbase Commerce',
        'region' => 'Crypto',
        'icon' => 'fab fa-bitcoin',
        'credentials' => [
            'api_key' => getenv('COINBASE_API_KEY') ?? '',
            'secret_key' => getenv('COINBASE_SECRET_KEY') ?? '',
        ],
        'urls' => [
            'success' => getenv('COINBASE_SUCCESS_URL') ?: '/payment_success.php',
            'cancel' => getenv('COINBASE_CANCEL_URL') ?: '/payment_cancelled.php',
            'webhook' => getenv('COINBASE_WEBHOOK_URL') ?: '/api/webhook.php?gateway=coinbase',
        ],
        'environment' => getenv('COINBASE_ENVIRONMENT') ?? '',
        'currencies' => ['BTC', 'ETH', 'USDT', 'USDC', 'DAI', 'BUSD', 'PAXG', 'WBTC'],
        'fees' => ['percentage' => 1.0, 'fixed' => 0.00],
        'limits' => ['min' => 1, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
        'features' => ['wallet', 'merchant', 'checkout'],
        'card_types' => ['Crypto Wallet'],
        'setup_complete' => false
    ],
    
    'moonpay' => [
        'name' => 'MoonPay',
        'region' => 'Crypto',
        'icon' => 'fas fa-moon',
        'credentials' => [
            'api_key' => getenv('MOONPAY_API_KEY') ?? '',
            'secret_key' => getenv('MOONPAY_SECRET_KEY') ?? '',
            'moonpay_id' => getenv('MOONPAY_ID') ?: '',
            'moonpay_token' => getenv('MOONPAY_TOKEN') ?: '',
        ],
        'urls' => [
            'success' => getenv('MOONPAY_SUCCESS_URL') ?: '/payment_success.php',
            'cancel' => getenv('MOONPAY_CANCEL_URL') ?: '/payment_cancelled.php',
            'webhook' => getenv('MOONPAY_WEBHOOK_URL') ?: '/api/webhook.php?gateway=moonpay',
        ],
        'environment' => getenv('MOONPAY_ENVIRONMENT') ?? '',
        'currencies' => ['BTC', 'ETH', 'USDT', 'USDC', 'DAI', 'BUSD', 'PAXG', 'WBTC', 'LINK'],
        'fees' => ['percentage' => 1.5, 'fixed' => 0.20],
        'limits' => ['min' => 5, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
        'features' => ['buy', 'sell', 'swap'],
        'card_types' => ['Visa', 'Mastercard', 'Crypto Wallet'],
        'setup_complete' => false
    ],

    'ramp' => [
        'name' => 'Ramp Network',
        'region' => 'Global',
        'icon' => 'fas fa-rocket',
        'credentials' => [
            'api_key' => getenv('RAMP_API_KEY') ?? '',
            'public_key' => getenv('RAMP_PUBLIC_KEY') ?? '',
        ],
        'urls' => [
            'success' => getenv('RAMP_SUCCESS_URL') ?: '/payment_success.php',
            'cancel' => getenv('RAMP_CANCEL_URL') ?: '/payment_cancelled.php',
            'webhook' => getenv('RAMP_WEBHOOK_URL') ?: '/api/webhook.php?gateway=ramp',
        ],
        'environment' => getenv('RAMP_ENVIRONMENT') ?? '',
        'currencies' => ['USD', 'EUR', 'GBP', 'AED', 'SAR', 'KWD', 'BHD', 'OMR', 'QAR'],
        'fees' => ['percentage' => 1.9, 'fixed' => 0.30],
        'limits' => ['min' => 10, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
        'features' => ['crypto_onramp', 'webhooks'],
        'card_types' => ['Visa', 'Mastercard', 'Crypto Wallet'],
        'setup_complete' => false
    ],

    'quickbooks' => [
        'name' => 'QuickBooks',
        'region' => 'Global',
        'icon' => 'fas fa-book',
        'credentials' => [
            'client_id' => getenv('QUICKBOOKS_CLIENT_ID') ?? '',
            'client_secret' => getenv('QUICKBOOKS_CLIENT_SECRET') ?? '',
            'realm_id' => getenv('QUICKBOOKS_REALM_ID') ?? '',
        ],
        'urls' => [
            'webhook' => getenv('QUICKBOOKS_WEBHOOK_URL') ?: '/api/webhook.php?gateway=quickbooks',
        ],
        'environment' => getenv('QUICKBOOKS_ENVIRONMENT') ?? '',
        'currencies' => ['USD', 'EUR', 'GBP', 'CAD', 'AUD'],
        'fees' => ['percentage' => 0.0, 'fixed' => 0.00],
        'limits' => ['min' => 0.01, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
        'features' => ['accounting', 'invoicing', 'sync'],
        'card_types' => [],
        'setup_complete' => false
    ],

    'bank_transfer' => [
        'name' => 'Bank Transfer',
        'region' => 'Bank',
        'icon' => 'fas fa-university',
        'credentials' => [
            'bank_name' => getenv('BANK_NAME') ?: 'Your Bank',
            'account_number' => getenv('BANK_ACCOUNT_NUMBER') ?: '0000000000',
            'iban' => getenv('BANK_IBAN') ?: '',
            'swift' => getenv('BANK_SWIFT') ?: '',
            'beneficiary_name' => getenv('BANK_BENEFICIARY_NAME') ?: 'Company Name',
        ],
        'urls' => [
            'success' => getenv('BANK_SUCCESS_URL') ?: '/payment_success.php',
            'cancel' => getenv('BANK_CANCEL_URL') ?: '/payment_cancelled.php',
            'webhook' => getenv('BANK_WEBHOOK_URL') ?: '/api/webhook.php?gateway=bank_transfer',
        ],
        'environment' => getenv('BANK_ENVIRONMENT') ?: 'live',
        'currencies' => ['USD', 'EUR', 'GBP', 'AED'],
        'fees' => ['percentage' => 0.0, 'fixed' => 0.00],
        'limits' => ['min' => 1, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
        'features' => ['bank_transfer'],
        'card_types' => ['Bank Transfer'],
        'setup_complete' => false
    ],
    
    'metamask' => [
        'name' => 'MetaMask',
        'region' => 'Crypto',
        'icon' => 'fas fa-fox',
        'credentials' => [
            'network' => getenv('METAMASK_NETWORK') ?: 'ethereum',
            'receiver_address' => getenv('METAMASK_RECEIVER_ADDRESS') ?: '0x...',
        ],
        'urls' => [
            'explorer' => getenv('METAMASK_EXPLORER_URL') ?: 'https://etherscan.io',
        ],
        'currencies' => ['ETH', 'USDT', 'USDC', 'DAI', 'WBTC', 'LINK', 'UNI', 'AAVE'],
        'fees' => ['percentage' => 0.5, 'fixed' => 0.00],
        'limits' => ['min' => 1, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
        'features' => ['wallet', 'web3', 'dapp'],
        'card_types' => ['MetaMask Wallet'],
        'setup_complete' => false
    ],
];

// ============================================================
// [2] ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½
// ============================================================

function getDbGatewaysConfig() {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cache = [];
    try {
        $db = db();
        $rows = $db->query('SELECT * FROM ' . DB_PREFIX . 'payment_gateways');
        foreach ($rows as $row) {
            $code = strtolower(trim($row['code'] ?? ''));
            if ($code === '') {
                continue;
            }

            $config = json_decode($row['config'] ?? '{}', true) ?: [];
            $credentials = json_decode($row['credentials'] ?? '{}', true) ?: [];
            $settings = normalizeGatewayUrls(json_decode($row['settings'] ?? '{}', true) ?: []);

            $setupComplete = !empty($row['status']) && $row['status'] === 'active' && (!empty($credentials) || !empty($settings));

            $cache[$code] = array_merge([
                'name' => $row['name'] ?: $code,
                'region' => $config['region'] ?? ucfirst($row['type'] ?? 'Global'),
                'icon' => $config['icon'] ?? 'fas fa-credit-card',
                'credentials' => $credentials,
                'urls' => $settings,
                'environment' => $config['environment'] ?? 'live',
                'currencies' => $config['currencies'] ?? ['USD'],
                'fees' => $config['fees'] ?? ['percentage' => 2.5, 'fixed' => 0.30],
                'limits' => $config['limits'] ?? ['min' => 1, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX],
                'features' => $config['features'] ?? [],
                'card_types' => $config['card_types'] ?? [],
                'setup_complete' => $setupComplete,
                'status' => $row['status'] ?? 'inactive'
            ], $config);
        }
    } catch (Exception $e) {
        $cache = [];
    }

    return $cache;
}

function mergeGatewaysConfig(array $staticConfig, array $dbConfig) {
    foreach ($dbConfig as $code => $override) {
        $code = strtolower(trim($code));
        if ($code === '') {
            continue;
        }

        if (isset($staticConfig[$code])) {
            $staticConfig[$code] = array_replace_recursive($staticConfig[$code], $override);
        } else {
            $staticConfig[$code] = $override;
        }
    }
    return $staticConfig;
}

function normalizeGatewayUrls(array $urls): array {
    $normalized = [];
    foreach ($urls as $key => $value) {
        $name = strtolower(trim((string)$key));
        if ($name === 'webhook_url') {
            $name = 'webhook';
        } elseif ($name === 'success_url' || $name === 'return_url') {
            $name = 'success';
        } elseif ($name === 'cancel_url') {
            $name = 'cancel';
        }
        $normalized[$name] = $value;
    }
    return $normalized;
}

function getGatewaysConfig() {
    $static = $GLOBALS['PAYMENT_GATEWAYS_CONFIG'] ?? [];
    $dbGateways = getDbGatewaysConfig();
    $merged = mergeGatewaysConfig($static, $dbGateways);

    foreach ($merged as $code => &$gateway) {
        $status = $gateway['status'] ?? (($gateway['setup_complete'] ?? false) ? 'active' : 'inactive');
        if ($status === 'active') {
            $gateway['transaction_types'] = getAllGatewayTransactionTypes();
        }
    }
    unset($gateway);

    return $merged;
}

function buildMoonPaySignedUrl(array $payload = [], array $config = []): ?string {
    $credentials = $config['credentials'] ?? [];
    $apiKey = trim((string)($credentials['api_key'] ?? $credentials['publishable_key'] ?? getenv('MOONPAY_API_KEY') ?: ''));
    $secretKey = trim((string)($credentials['secret_key'] ?? getenv('MOONPAY_SECRET_KEY') ?: ''));

    if ($apiKey === '' || $secretKey === '' || $apiKey === 'your_api_key' || $secretKey === 'your_secret_key') {
        return null;
    }

    $environment = strtolower((string)($config['environment'] ?? getenv('MOONPAY_ENVIRONMENT') ?: ''));
    $baseUrl = ($environment === 'live') ? 'https://buy.moonpay.com/' : 'https://buy-sandbox.moonpay.com/';

    $params = [];
    $params['apiKey'] = $apiKey;

    $currency = $payload['currency_code'] ?? $payload['currency'] ?? $payload['crypto_currency'] ?? '';
    if ($currency !== '') {
        $params['currencyCode'] = strtolower((string)$currency);
    }

    if (!empty($payload['wallet_address'])) {
        $params['walletAddress'] = $payload['wallet_address'];
    }

    if (!empty($payload['wallet_addresses'])) {
        $params['walletAddresses'] = $payload['wallet_addresses'];
    }

    $redirectUrl = $payload['redirect_url'] ?? $payload['return_url'] ?? null;
    if (empty($redirectUrl) && !empty($config['urls']['success'])) {
        $redirectUrl = $config['urls']['success'];
    }
    if (!empty($redirectUrl)) {
        $params['redirectUrl'] = $redirectUrl;
    }

    if (!empty($payload['amount'])) {
        $params['baseCurrencyAmount'] = number_format((float)$payload['amount'], 2, '.', '');
    }

    $pairs = [];
    foreach ($params as $name => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $pairs[] = rawurlencode((string)$name) . '=' . rawurlencode((string)$value);
    }

    if (empty($pairs)) {
        return null;
    }

    $query = '?' . implode('&', $pairs);
    $signature = base64_encode(hash_hmac('sha256', $query, $secretKey, true));
    return $baseUrl . $query . '&signature=' . rawurlencode($signature);
}

function getGatewayConfig($code) {
    $gateways = getGatewaysConfig();
    return $gateways[strtolower(trim($code))] ?? null;
}

function hasValidGatewayConfig(array $gw): bool {
    // 1. setup_complete = true طµط±ظٹط­
    if (!empty($gw['setup_complete'])) {
        return true;
    }

    // 2. config['setup_complete'] = true
    if (!empty($gw['config']['setup_complete'])) {
        return true;
    }

    // 3. ظٹظˆط¬ط¯ API Key ط­ظ‚ظٹظ‚ظٹ
    $creds = $gw['credentials'] ?? [];
    if (!empty($creds) && is_array($creds)) {
        $badValues = ['your_api_key','your_secret','your_client_id','','your_merchant_id',
                      'sk_test_...','pk_test_...','your_secret_key','your_public_key'];
        foreach ($creds as $val) {
            if (!empty($val) && is_string($val) && !in_array(strtolower(trim($val)), $badValues)) {
                return true;
            }
        }
    }

    return false;
}

function isGatewayConfigured(array $gw): bool {
    $status = strtolower(trim((string)($gw['status'] ?? 'inactive')));
    if ($status === 'inactive' || $status === 'disabled') {
        return false;
    }

    return hasValidGatewayConfig($gw);
}

function isGatewayReady(array $gw): bool {
    $status = strtolower(trim((string)($gw['status'] ?? 'inactive')));
    if ($status !== 'active') {
        return false;
    }

    $setupComplete = !empty($gw['setup_complete']) || !empty($gw['config']['setup_complete']);
    if (!$setupComplete) {
        return false;
    }

    $connectionStatus = strtolower(trim((string)($gw['connection_status'] ?? '')));
    if ($connectionStatus !== '' && $connectionStatus !== 'verified') {
        return false;
    }

    return hasValidGatewayConfig($gw);
}

function getConfiguredGateways() {
    $gateways = getGatewaysConfig();
    return array_filter($gateways, function($gw, $code) {
        return isGatewayConfigured($gw);
    }, ARRAY_FILTER_USE_BOTH);
}

function getGatewaysByRegion($region) {
    $gateways = getGatewaysConfig();
    return array_filter($gateways, function($gw) use ($region) {
        return ($gw['region'] ?? '') === $region;
    });
}

function getSupportedExternalGateways() {
    $gateways = getGatewaysConfig();
    return array_keys(array_filter($gateways, function($gw, $code) {
        $code = strtolower(trim((string)$code));
        if ($code === 'integrated') {
            return false;
        }

        $status = strtolower(trim((string)($gw['status'] ?? 'inactive')));
        $hasRuntimeConfig = !empty($gw['credentials']) || !empty($gw['urls']) || !empty($gw['settings']) || !empty($gw['setup_complete']);

        return $status === 'active' && $hasRuntimeConfig;
    }, ARRAY_FILTER_USE_BOTH));
}

function gateway_service() {
    return new class {
        public function createPaymentIntent($gateway, $payload) {
            $db = db();
            $reference = generateReference('TXN');
            
            $transactionData = [
                'reference' => $reference,
                'gateway' => $gateway,
                'protocol' => $payload['protocol'] ?? 'SIMPLE_WITHDRAWAL',
                'amount' => floatval($payload['amount'] ?? 0),
                'currency' => strtoupper($payload['currency'] ?? 'USD'),
                'customer_name' => $payload['customer_name'] ?? 'Customer',
                'customer_email' => $payload['customer_email'] ?? '',
                'customer_phone' => $payload['customer_phone'] ?? '',
                'status' => 'completed',
                'transaction_type' => $payload['description'] ?? 'Payment via ' . $gateway,
                'user_id' => $_SESSION['user_id'] ?? 0,
                'fees' => ($payload['amount'] ?? 0) * 0.025,
                'net_amount' => ($payload['amount'] ?? 0) * 0.975,
                'security_mode' => strtoupper(trim($payload['security_mode'] ?? $payload['secure_mode'] ?? '2D')),
                'gateway_response' => null,
                'error_message' => null,
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            try {
                $securityMode = strtoupper(trim($payload['security_mode'] ?? $payload['secure_mode'] ?? '2D'));
                $requestedMode = ($securityMode === '3D') ? '3ds' : '2d';
                $payload['security_mode'] = $securityMode;
                $payload['secure_mode'] = $requestedMode;
                $gatewayResponse = [
                    'success' => true,
                    'message' => 'Payment processed successfully',
                    'reference' => $reference,
                    'provider' => strtoupper($gateway),
                    'security_mode' => $securityMode,
                    'secure_mode' => $requestedMode
                ];

                $gatewayName = strtolower(trim($gateway));
                if ($gatewayName === 'integrated') {
                    return [
                        'success' => false,
                        'message' => 'Integrated gateway is disabled. Use a configured real external gateway.',
                        'reference' => $reference,
                        'provider' => 'integrated'
                    ];
                }
                $configuredGateways = getSupportedExternalGateways();
                if (!in_array($gatewayName, $configuredGateways, true)) {
                    return [
                        'success' => false,
                        'message' => 'A configured real external gateway is required: ' . $gateway,
                        'reference' => $reference,
                        'provider' => strtoupper($gateway)
                    ];
                }

                if ($gatewayName === 'myfatoorah') {
                    $gatewayResponse = $this->createMyFatoorahPaymentIntent($gateway, $payload, $reference);
                    $transactionData['status'] = $gatewayResponse['status'] ?? ($gatewayResponse['success'] ? 'pending' : 'failed');
                    $transactionData['gateway_response'] = json_encode($gatewayResponse);
                    if (!$gatewayResponse['success']) {
                        $transactionData['error_message'] = $gatewayResponse['message'] ?? 'MyFatoorah failed';
                    }
                } elseif ($gatewayName === 'integrated') {
                    $gatewayResponse = $this->createIntegratedPaymentIntent($gateway, $payload, $reference);
                    $transactionData['status'] = $gatewayResponse['status'] ?? ($gatewayResponse['success'] ? 'completed' : 'failed');
                    $transactionData['gateway_response'] = json_encode($gatewayResponse);
                    if (!$gatewayResponse['success']) {
                        $transactionData['error_message'] = $gatewayResponse['message'] ?? 'Integrated payment failed';
                    }
                } else {
                    $gatewayResponse = $this->createConfiguredExternalPaymentIntent($gateway, $payload, $reference);
                    $transactionData['status'] = $gatewayResponse['status'] ?? ($gatewayResponse['success'] ? 'pending' : 'failed');
                    $transactionData['security_mode'] = strtoupper(trim($payload['security_mode'] ?? '2D'));
                    $transactionData['gateway_response'] = json_encode($gatewayResponse);
                    if (!$gatewayResponse['success']) {
                        $transactionData['error_message'] = $gatewayResponse['message'] ?? 'Gateway request failed';
                    }
                }

                $txnId = $db->insert('transactions', $transactionData);
                // ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½
                try {
                    $db->execute("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "contracts` (
                        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        `reference` VARCHAR(100) NOT NULL,
                        `service_name` VARCHAR(255) DEFAULT NULL,
                        `service_description` TEXT DEFAULT NULL,
                        `delivery_method` VARCHAR(255) DEFAULT NULL,
                        `delivery_notes` TEXT DEFAULT NULL,
                        `terms_text` TEXT DEFAULT NULL,
                        `accept_terms` TINYINT(1) NOT NULL DEFAULT 0,
                        `user_id` INT UNSIGNED DEFAULT 0,
                        `created_at` DATETIME NOT NULL,
                        UNIQUE KEY `uniq_reference` (`reference`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                    if (function_exists('getSiteTerms') && trim(getSiteTerms()) !== '') {
                        $termsText = getSiteTerms();
                    } else {
                        $termsText = "ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½: ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½. ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½.";
                    }
                    $db->insert('contracts', [
                        'reference' => $reference,
                        'service_name' => $payload['contract_service_name'] ?? null,
                        'service_description' => $payload['contract_service_description'] ?? null,
                        'delivery_method' => $payload['contract_delivery_method'] ?? null,
                        'delivery_notes' => $payload['contract_delivery_notes'] ?? null,
                        'terms_text' => $termsText,
                        'accept_terms' => !empty($payload['accept_terms']) ? 1 : 0,
                        'user_id' => $_SESSION['user_id'] ?? 0,
                        'created_at' => date('Y-m-d H:i:s')
                    ]);
                } catch (Exception $e) {
                    // ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½د، ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½
                    logEvent('Failed to persist contract: ' . $e->getMessage(), 'error');
                }

                $gatewayResponse['transaction_id'] = $txnId;
                $gatewayResponse['reference'] = $reference;
                return [
                    'success' => !empty($gatewayResponse['success']),
                    'message' => $gatewayResponse['message'],
                    'transaction_id' => $txnId,
                    'reference' => $reference,
                    'data' => $transactionData,
                    'gateway_response' => $gatewayResponse
                ];
            } catch (Exception $e) {
                return ['success' => false, 'message' => 'Payment processing failed: ' . $e->getMessage()];
            }
        }

        private function ensureIntegratedTables() {
            $db = db();
            $db->execute("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "transactions` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `reference` VARCHAR(100) NOT NULL UNIQUE,
                `gateway` VARCHAR(50) NOT NULL,
                `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                `amount_usdt` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                `currency` VARCHAR(10) NOT NULL DEFAULT 'AED',
                `customer_name` VARCHAR(255) DEFAULT NULL,
                `customer_email` VARCHAR(255) DEFAULT NULL,
                `customer_phone` VARCHAR(255) DEFAULT NULL,
                `status` VARCHAR(30) NOT NULL DEFAULT 'pending',
                `payment_method` VARCHAR(50) NOT NULL DEFAULT 'internal',
                `description` TEXT DEFAULT NULL,
                `accept_terms` TINYINT(1) NOT NULL DEFAULT 0,
                `contract_service_name` VARCHAR(255) DEFAULT NULL,
                `contract_service_description` TEXT DEFAULT NULL,
                `contract_delivery_method` VARCHAR(255) DEFAULT NULL,
                `contract_delivery_notes` TEXT DEFAULT NULL,
                `user_id` INT UNSIGNED DEFAULT 0,
                `fees` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                `net_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                `card_type` VARCHAR(50) DEFAULT NULL,
                `card_last4` VARCHAR(16) DEFAULT NULL,
                `transaction_data` TEXT DEFAULT NULL,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NULL DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $db->execute("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "invoices` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `invoice_number` VARCHAR(100) NOT NULL UNIQUE,
                `reference` VARCHAR(100) NOT NULL,
                `user_id` INT UNSIGNED DEFAULT 0,
                `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                `currency` VARCHAR(10) NOT NULL DEFAULT 'AED',
                `status` VARCHAR(30) NOT NULL DEFAULT 'pending',
                `terms_text` TEXT DEFAULT NULL,
                `gateway` VARCHAR(50) NOT NULL DEFAULT 'integrated',
                `description` TEXT DEFAULT NULL,
                `created_at` DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $db->execute("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "wallets` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT UNSIGNED NOT NULL,
                `currency` VARCHAR(10) NOT NULL DEFAULT 'AED',
                `balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                `status` VARCHAR(30) NOT NULL DEFAULT 'active',
                `created_at` DATETIME NOT NULL,
                UNIQUE KEY `uniq_wallet` (`user_id`, `currency`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $db->execute("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "ledger` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT UNSIGNED NOT NULL,
                `type` VARCHAR(20) NOT NULL,
                `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                `currency` VARCHAR(10) NOT NULL DEFAULT 'AED',
                `reference` VARCHAR(100) NOT NULL,
                `description` TEXT DEFAULT NULL,
                `created_at` DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $db->execute("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "approval_requests` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT UNSIGNED NOT NULL,
                `type` VARCHAR(50) NOT NULL DEFAULT 'payment',
                `reference` VARCHAR(100) NOT NULL,
                `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                `currency` VARCHAR(10) NOT NULL DEFAULT 'AED',
                `status` VARCHAR(30) NOT NULL DEFAULT 'pending',
                `reason` TEXT DEFAULT NULL,
                `created_at` DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        private function createIntegratedPaymentIntent($gateway, $payload, $reference) {
            $db = db();
            $this->ensureIntegratedTables();

            $userId = intval($_SESSION['user_id'] ?? 0);
            $amount = round(floatval($payload['amount'] ?? 0), 2);
            $currency = strtoupper(trim($payload['currency'] ?? 'AED'));
            $invoiceNumber = 'INV-' . date('YmdHis') . '-' . rand(1000, 9999);

            $invoiceId = $db->insert('invoices', [
                'invoice_number' => $invoiceNumber,
                'reference' => $reference,
                'user_id' => $userId,
                'amount' => $amount,
                'currency' => $currency,
                'status' => 'pending',
                'terms_text' => (function_exists('getSiteTerms') ? getSiteTerms() : null),
                'gateway' => 'integrated',
                'description' => $payload['description'] ?? 'Integrated payment',
                'created_at' => date('Y-m-d H:i:s')
            ]);

            $db->insert('approval_requests', [
                'user_id' => $userId,
                'type' => 'payment',
                'reference' => $reference,
                'amount' => $amount,
                'currency' => $currency,
                'status' => 'pending',
                'reason' => null,
                'created_at' => date('Y-m-d H:i:s')
            ]);

            $transactionId = $db->insert('transactions', [
                'reference' => $reference,
                'gateway' => 'integrated',
                'amount' => $amount,
                'currency' => $currency,
                'customer_name' => $payload['customer_name'] ?? 'Customer',
                'customer_email' => $payload['customer_email'] ?? '',
                'customer_phone' => $payload['customer_phone'] ?? '',
                'status' => 'pending',
                'payment_method' => 'internal',
                'description' => $payload['description'] ?? 'Integrated payment',
                'user_id' => $userId,
                'fees' => 0.00,
                'net_amount' => $amount,
                'transaction_data' => json_encode(['invoice_id' => $invoiceId, 'invoice_number' => $invoiceNumber]),
                'created_at' => date('Y-m-d H:i:s')
            ]);

            return [
                'success' => true,
                'status' => 'pending',
                'message' => 'Integrated payment created and awaiting approval.',
                'provider' => 'integrated',
                'invoice_id' => $invoiceId,
                'invoice_number' => $invoiceNumber,
                'transaction_id' => $transactionId,
                'reference' => $reference,
                'response' => [
                    'invoice_number' => $invoiceNumber,
                    'balance' => $amount,
                    'currency' => $currency
                ]
            ];
        }

        private function createConfiguredExternalPaymentIntent($gateway, $payload, $reference) {
            $config = getGatewayConfig($gateway);
            if (empty($config)) {
                return [
                    'success' => false,
                    'status' => 'failed',
                    'message' => 'Gateway configuration missing for ' . $gateway,
                    'provider' => $gateway
                ];
            }

            $credentials = $config['credentials'] ?? [];
            $hasApiKey = !empty($credentials['api_key']) || !empty($credentials['client_id']) || !empty($credentials['client_secret']);
            $hasSecret = !empty($credentials['secret_key']) || !empty($credentials['password']) || !empty($credentials['client_secret']);

            if (!$hasApiKey && !$hasSecret) {
                return [
                    'success' => false,
                    'status' => 'failed',
                    'message' => 'External gateway credentials are not configured for ' . $gateway,
                    'provider' => $gateway
                ];
            }

            $securityMode = strtoupper(trim($payload['security_mode'] ?? $payload['secure_mode'] ?? '2D'));
            $requestedMode = ($securityMode === '3D') ? '3ds' : '2d';
            $paymentInstructions = [];
            $response = [
                'gateway' => $gateway,
                'credentials_present' => $hasApiKey || $hasSecret,
                'security_mode' => $securityMode,
                'secure_mode' => $requestedMode
            ];
            $message = 'External gateway ' . $gateway . ' is configured and ready.';
            $status = 'pending';

            switch ($gatewayName) {
                case 'stripe':
                    $message = 'Stripe is configured. Use Stripe Checkout or Payment Intents integration to complete the payment.';
                    $response['integration'] = 'stripe';
                    $paymentInstructions = [
                        'checkout_url' => $config['urls']['success'] ?? '/payment_success.php',
                        'mode' => 'checkout',
                    ];
                    break;
                case 'paypal':
                    $message = 'PayPal is configured. Redirect customer to PayPal Checkout or Smart Payment Buttons to collect payment.';
                    $response['integration'] = 'paypal';
                    $paymentInstructions = [
                        'approval_url' => $config['urls']['success'] ?? '/payment_success.php',
                        'intent' => 'sale',
                    ];
                    break;
                case 'wise':
                case 'transferwise':
                    $gatewayResponse = $this->createWisePaymentIntent($gateway, $payload, $reference);
                    $transactionData['status'] = $gatewayResponse['status'] ?? ($gatewayResponse['success'] ? 'pending' : 'failed');
                    $transactionData['transaction_data'] = json_encode(array_merge($payload, ['gateway_response' => $gatewayResponse]));
                    $gatewayResponse['gateway'] = $gateway;
                    return $gatewayResponse;
                case 'moonpay':
                    $message = 'MoonPay is configured. Use MoonPay widget or API to create a crypto on-ramp payment.';
                    $response['integration'] = 'moonpay';
                    $signedBuyUrl = buildMoonPaySignedUrl($payload, $config);
                    $paymentInstructions = [
                        'buy_url' => $signedBuyUrl ?: ($config['urls']['success'] ?? '/payment_success.php'),
                        'signed' => !empty($signedBuyUrl),
                    ];
                    break;
                case 'ramp':
                case 'ramp network':
                case 'ramp_network':
                    $message = 'Ramp Network is configured. Use Ramp on-ramp checkout or hosted widget for payment.';
                    $response['integration'] = 'ramp';
                    $paymentInstructions = [
                        'onramp_url' => $config['urls']['success'] ?? '/payment_success.php'];
                    break;
                case 'quickbooks':
                    $message = 'QuickBooks is configured for accounting sync. This gateway will sync invoices and payments to QuickBooks.';
                    $response['integration'] = 'quickbooks';
                    $status = 'completed';
                    break;
                case 'bank_transfer':
                case 'bank':
                    $message = 'Bank transfer gateway is configured. Provide bank account details to the payer and mark payment as pending until funds arrive.';
                    $response['integration'] = 'bank_transfer';
                    $paymentInstructions = [
                        'bank_name' => $credentials['bank_name'] ?? '',
                        'account_number' => $credentials['account_number'] ?? '',
                        'iban' => $credentials['iban'] ?? '',
                        'swift' => $credentials['swift'] ?? '',
                        'beneficiary_name' => $credentials['beneficiary_name'] ?? '',
                    ];
                    break;
                case 'apple_pay':
                    $gatewayResponse = $this->createApplePayIntent($gateway, $payload, $reference);
                    $transactionData['status'] = $gatewayResponse['status'] ?? ($gatewayResponse['success'] ? 'pending' : 'failed');
                    $transactionData['transaction_data'] = json_encode(array_merge($payload, ['gateway_response' => $gatewayResponse]));
                    $gatewayResponse['gateway'] = $gateway;
                    return $gatewayResponse;
                case 'google_pay':
                    $gatewayResponse = $this->createGooglePayIntent($gateway, $payload, $reference);
                    $transactionData['status'] = $gatewayResponse['status'] ?? ($gatewayResponse['success'] ? 'pending' : 'failed');
                    $transactionData['transaction_data'] = json_encode(array_merge($payload, ['gateway_response' => $gatewayResponse]));
                    $gatewayResponse['gateway'] = $gateway;
                    return $gatewayResponse;
                default:
                    $message = 'External gateway ' . $gateway . ' is configured and ready. Implement provider-specific adapter for live API calls.';
                    break;
            }

            if (!empty($paymentInstructions)) {
                $response['payment_instructions'] = $paymentInstructions;
            }

            return [
                'success' => true,
                'status' => $status,
                'message' => $message,
                'provider' => $gateway,
                'response' => $response
            ];
        }

        // ??????????????????????????????????????????????????????
        // Apple Pay Handler
        // ??????????????????????????????????????????????????????
        private function createApplePayIntent($gateway, $payload, $reference) {
            $config      = getGatewayConfig($gateway);
            $credentials = $config['credentials'] ?? [];
            $environment = strtolower($config['environment'] ?? 'sandbox');
            $amount      = round(floatval($payload['amount'] ?? 0), 2);
            $currency    = strtoupper(trim($payload['currency'] ?? 'USD'));
            $appleToken  = trim($payload['apple_pay_token'] ?? $payload['payment_token'] ?? '');

            // Apple Pay ï؟½ï؟½ï؟½ï؟½ï؟½ Payment Session ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½
            // ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½: ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ ? ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ PSP (Stripe/Adyen)
            // ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ Stripe ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ pending
            $stripeConfig = getGatewayConfig('stripe');
            $stripeKey    = trim($stripeConfig['credentials']['api_key'] ?? getenv('STRIPE_SECRET_KEY') ?: '');

            if (!empty($stripeKey) && strpos($stripeKey, 'sk_') === 0 && !empty($appleToken)) {
                // ï؟½ï؟½ï؟½ï؟½ï؟½ Apple Pay token ï؟½ï؟½ï؟½ Stripe
                $stripePayload = json_encode([
                    'amount'               => intval($amount * 100),
                    'currency'             => strtolower($currency),
                    'payment_method_data'  => ['type' => 'card', 'card' => ['token' => $appleToken]],
                    'confirm'              => 'true',
                    'description'          => $payload['description'] ?? 'Apple Pay ï؟½ DI PARMA',
                    'metadata'             => ['reference' => $reference, 'source' => 'apple_pay'],
                ]);
                $ch = curl_init('https://api.stripe.com/v1/payment_intents');
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $stripePayload,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $stripeKey, 'Content-Type: application/json'],
                    CURLOPT_TIMEOUT        => 15,
                    CURLOPT_SSL_VERIFYPEER => false,
                ]);
                $resp     = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                $decoded  = json_decode($resp, true);

                if ($httpCode >= 200 && $httpCode < 300) {
                    return [
                        'success'        => true,
                        'status'         => 'captured',
                        'message'        => '? ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ Apple Pay ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ Stripe',
                        'provider'       => 'apple_pay',
                        'reference'      => $reference,
                        'transaction_id' => $decoded['id'] ?? null,
                        'response'       => $decoded,
                    ];
                }
                return [
                    'success'  => false,
                    'status'   => 'failed',
                    'message'  => 'Apple Pay via Stripe failed: ' . ($decoded['error']['message'] ?? 'Unknown error'),
                    'provider' => 'apple_pay',
                    'response' => $decoded,
                ];
            }

            // Fallback: Apple Pay pending (ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ JS token ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½)
            return [
                'success'              => true,
                'status'               => 'pending',
                'message'              => 'Apple Pay: ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½. ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½.',
                'provider'             => 'apple_pay',
                'reference'            => $reference,
                'payment_method'       => 'apple_pay',
                'requires_device_auth' => true,
                'credentials_present'  => !empty($credentials),
                'environment'          => $environment,
                'response'             => ['gateway' => 'apple_pay', 'credentials_present' => !empty($credentials)],
            ];
        }

        // ??????????????????????????????????????????????????????
        // Google Pay Handler
        // ??????????????????????????????????????????????????????
        private function createGooglePayIntent($gateway, $payload, $reference) {
            $config       = getGatewayConfig($gateway);
            $credentials  = $config['credentials'] ?? [];
            $environment  = strtolower($config['environment'] ?? 'sandbox');
            $amount       = round(floatval($payload['amount'] ?? 0), 2);
            $currency     = strtoupper(trim($payload['currency'] ?? 'USD'));
            $googleToken  = trim($payload['google_pay_token'] ?? $payload['payment_token'] ?? '');

            // Google Pay ï؟½ï؟½ï؟½ï؟½ï؟½ encryptedPaymentData ? ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ PSP
            $stripeConfig = getGatewayConfig('stripe');
            $stripeKey    = trim($stripeConfig['credentials']['api_key'] ?? getenv('STRIPE_SECRET_KEY') ?: '');

            if (!empty($stripeKey) && strpos($stripeKey, 'sk_') === 0 && !empty($googleToken)) {
                $stripePayload = json_encode([
                    'amount'              => intval($amount * 100),
                    'currency'            => strtolower($currency),
                    'payment_method_data' => ['type' => 'card', 'card' => ['token' => $googleToken]],
                    'confirm'             => 'true',
                    'description'         => $payload['description'] ?? 'Google Pay ï؟½ DI PARMA',
                    'metadata'            => ['reference' => $reference, 'source' => 'google_pay'],
                ]);
                $ch = curl_init('https://api.stripe.com/v1/payment_intents');
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $stripePayload,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $stripeKey, 'Content-Type: application/json'],
                    CURLOPT_TIMEOUT        => 15,
                    CURLOPT_SSL_VERIFYPEER => false,
                ]);
                $resp     = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                $decoded  = json_decode($resp, true);

                if ($httpCode >= 200 && $httpCode < 300) {
                    return [
                        'success'        => true,
                        'status'         => 'captured',
                        'message'        => '? ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ Google Pay ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ Stripe',
                        'provider'       => 'google_pay',
                        'reference'      => $reference,
                        'transaction_id' => $decoded['id'] ?? null,
                        'response'       => $decoded,
                    ];
                }
                return [
                    'success'  => false,
                    'status'   => 'failed',
                    'message'  => 'Google Pay via Stripe failed: ' . ($decoded['error']['message'] ?? 'Unknown error'),
                    'provider' => 'google_pay',
                    'response' => $decoded,
                ];
            }

            // Fallback: Google Pay pending
            return [
                'success'              => true,
                'status'               => 'pending',
                'message'              => 'Google Pay: ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ Google. ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½ ï؟½ï؟½ ï؟½ï؟½ï؟½ï؟½ï؟½ï؟½.',
                'provider'             => 'google_pay',
                'reference'            => $reference,
                'payment_method'       => 'google_pay',
                'requires_device_auth' => true,
                'credentials_present'  => !empty($credentials),
                'environment'          => $environment,
                'response'             => ['gateway' => 'google_pay', 'credentials_present' => !empty($credentials)],
            ];
        }

        private function createMyFatoorahPaymentIntent($gateway, $payload, $reference) {
            $config = getGatewayConfig($gateway);
            $credentials = $config['credentials'] ?? [];
            $apiKey = $credentials['api_key'] ?? getenv('MYFAOORAH_API_KEY') ?: '';
            $secretKey = $credentials['secret_key'] ?? getenv('MYFAOORAH_SECRET_KEY') ?: '';

            if (empty($apiKey) || empty($secretKey) || $apiKey === 'your_api_key' || $secretKey === 'your_secret_key') {
                return [
                    'success' => false,
                    'status' => 'failed',
                    'message' => 'MyFatoorah credentials are not configured.',
                    'provider' => 'myfatoorah'
                ];
            }

            $environment = $config['environment'] ?? getenv('MYFAOORAH_ENVIRONMENT') ?: '';
            $baseUrl = ($environment === 'live') ? 'https://api.myfatoorah.com' : 'https://apitest.myfatoorah.com';
            $amount = round(floatval($payload['amount'] ?? 0), 2);
            $currency = strtoupper(trim($payload['currency'] ?? 'AED'));

            $securityMode = strtoupper(trim($payload['security_mode'] ?? $payload['secure_mode'] ?? '2D'));
            $requestPayload = [
                'CustomerName' => $payload['customer_name'] ?? 'Customer',
                'CustomerEmail' => $payload['customer_email'] ?? '',
                'CustomerMobile' => $payload['customer_phone'] ?? '',
                'InvoiceValue' => $amount,
                'DisplayCurrencyIso' => $currency,
                'CallBackUrl' => ($config['urls']['success'] ?? '') ?: SITE_URL . '/payment_success.php',
                'ErrorUrl' => ($config['urls']['cancel'] ?? '') ?: SITE_URL . '/payment_cancelled.php',
                'MerchantReferenceId' => $reference,
                'Language' => 'en',
                'PaymentMethodId' => 2,
                'MobileCountryCode' => '+971',
                'SecureMode' => ($securityMode === '3D') ? '3D' : '2D'
            ];

            if (!empty($credentials['merchant_id'] ?? '')) {
                $requestPayload['MerchantId'] = $credentials['merchant_id'];
            }

            $headers = [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey
            ];
            if (!empty($secretKey)) {
                $headers[] = 'X-MYFATOORAH-SECRET-KEY: ' . $secretKey;
            }

            $ch = curl_init($baseUrl . '/v2/SendPayment');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($requestPayload),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false) {
                return [
                    'success' => false,
                    'status' => 'failed',
                    'message' => 'MyFatoorah request could not be completed.',
                    'provider' => 'myfatoorah',
                    'http_code' => $httpCode
                ];
            }

            $decoded = json_decode($response, true);
            if ($httpCode >= 200 && $httpCode < 300) {
                $paymentUrl = $decoded['Data']['PaymentURL'] ?? $decoded['Data']['PaymentUrl'] ?? null;
                $invoiceId = $decoded['Data']['InvoiceId'] ?? null;
                return [
                    'success' => true,
                    'status' => 'pending',
                    'message' => 'MyFatoorah payment intent created successfully.',
                    'provider' => 'myfatoorah',
                    'http_code' => $httpCode,
                    'payment_url' => $paymentUrl,
                    'invoice_id' => $invoiceId,
                    'response' => $decoded
                ];
            }

            return [
                'success' => false,
                'status' => 'failed',
                'message' => 'MyFatoorah rejected the request.',
                'provider' => 'myfatoorah',
                'http_code' => $httpCode,
                'response' => $decoded ?: $response
            ];
        }

        private function createWisePaymentIntent($gateway, $payload, $reference) {
            $config = getGatewayConfig($gateway);
            $credentials = $config['credentials'] ?? [];
            $accessToken = trim($credentials['access_token'] ?? $credentials['api_key'] ?? $credentials['api_secret'] ?? '');
            $profileId = trim($credentials['profile_id'] ?? $credentials['profileId'] ?? $credentials['profile'] ?? '');

            $missing = [];
            if (empty($accessToken) || in_array($accessToken, ['your_api_key', 'your_api_secret'], true)) {
                $missing[] = 'access_token';
            }
            if (empty($profileId) || $profileId === 'your_profile_id') {
                $missing[] = 'profile_id';
            }

            if (!empty($missing)) {
                return [
                    'success' => false,
                    'status' => 'failed',
                    'message' => 'Wise credentials are not fully configured. Missing: ' . implode(', ', $missing) . '. Ensure access_token and profile ID are set.',
                    'provider' => 'wise'
                ];
            }

            $environment = strtolower(trim($config['environment'] ?? 'sandbox'));
            $baseUrl = ($environment === 'live') ? 'https://api.transferwise.com' : 'https://api.sandbox.transferwise.com';
            $sourceCurrency = strtoupper(trim($payload['currency'] ?? 'USD'));
            $targetCurrency = strtoupper(trim($payload['target_currency'] ?? ''));

            if ($targetCurrency === '' || $targetCurrency === $sourceCurrency) {
                if ($sourceCurrency === 'USD') {
                    $targetCurrency = 'EUR';
                } elseif ($sourceCurrency === 'EUR') {
                    $targetCurrency = 'USD';
                } elseif ($sourceCurrency === 'GBP') {
                    $targetCurrency = 'USD';
                } else {
                    $targetCurrency = 'USD';
                }
            }
            $sourceAmount = round(floatval($payload['amount'] ?? 0), 2);
            if ($sourceAmount <= 0) {
                return [
                    'success' => false,
                    'status' => 'failed',
                    'message' => 'Payment amount must be greater than zero for Wise quote creation.',
                    'provider' => 'wise'
                ];
            }

            $securityMode = strtoupper(trim($payload['security_mode'] ?? $payload['secure_mode'] ?? '2D'));
            $requestPayload = [
                'sourceCurrency' => $sourceCurrency,
                'targetCurrency' => $targetCurrency,
                'sourceAmount' => $sourceAmount,
                'security_mode' => $securityMode
            ];

            $headers = [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $accessToken
            ];
            $quoteUrl = $baseUrl . '/v3/profiles/' . rawurlencode($profileId) . '/quotes';

            $ch = curl_init($quoteUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($requestPayload),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false) {
                return [
                    'success' => false,
                    'status' => 'failed',
                    'message' => 'Wise request failed. Could not contact the API.',
                    'provider' => 'wise',
                    'http_code' => $httpCode
                ];
            }

            $decoded = json_decode($response, true);
            if ($httpCode >= 200 && $httpCode < 300) {
                return [
                    'success' => true,
                    'status' => 'pending',
                    'message' => 'Wise quote created successfully.',
                    'provider' => 'wise',
                    'quote' => $decoded,
                    'api_base_url' => $baseUrl,
                    'profile_id' => $profileId,
                    'request' => $requestPayload,
                    'response' => $decoded
                ];
            }

            return [
                'success' => false,
                'status' => 'failed',
                'message' => $decoded['message'] ?? $decoded['detail'] ?? 'Wise API returned an error.',
                'provider' => 'wise',
                'http_code' => $httpCode,
                'request' => $requestPayload,
                'response' => $decoded
            ];
        }
        
        public function getSupportedGateways($region = null) {
            if ($region) {
                return getGatewaysByRegion($region);
            }
            return getGatewaysConfig();
        }
        
        public function getConfiguredGateways() {
            return getConfiguredGateways();
        }
        
        public function getGatewayNames() {
            $gateways = getGatewaysConfig();
            $names = [];
            foreach ($gateways as $key => $gw) {
                $names[$key] = $gw['name'] ?? $key;
            }
            return $names;
        }
        
        public function getGatewayCurrencies($code) {
            $gw = getGatewayConfig($code);
            return $gw['currencies'] ?? ['USD'];
        }
        
        public function getGatewayFees($code) {
            $gw = getGatewayConfig($code);
            return $gw['fees'] ?? ['percentage' => 2.5, 'fixed' => 0.30];
        }
        
        public function getGatewayLimits($code) {
            $gw = getGatewayConfig($code);
            return $gw['limits'] ?? ['min' => 1, 'max_daily' => PHP_INT_MAX, 'max_monthly' => PHP_INT_MAX];
        }
        
        public function getGatewayFeatures($code) {
            $gw = getGatewayConfig($code);
            return $gw['features'] ?? [];
        }
        
        public function getGatewayCardTypes($code) {
            $gw = getGatewayConfig($code);
            return $gw['card_types'] ?? [];
        }
        
        public function isGatewayConfigured($code) {
            $gw = getGatewayConfig($code);
            return $gw && hasValidGatewayConfig($gw);
        }
    };
}

?>



