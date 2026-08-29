<?php
/**
 * ============================================================
 * DI PARMA | GatewayAdapterFactory + GatewayManager
 * يختار المحول المناسب تلقائياً — يدعم 7 بوابات
 * ============================================================
 * البوابات المدعومة:
 *   stripe       — Stripe (2D/3D/hold/capture/cancel)
 *   checkout     — Checkout.com (2D/3D/hold/capture/cancel)
 *   myfatoorah   — MyFatoorah (2D/3D فقط)
 *   paytabs      — PayTabs (2D-MOTO/3D/hold/capture/cancel)
 *   authorizenet — Authorize.Net (2D/3D/hold/capture/cancel)
 *   gate_io      — Gate.io APIv4 (charge/withdraw/balance)
 * ============================================================
 */

require_once __DIR__ . '/GatewayAdapterInterface.php';
require_once __DIR__ . '/GatewayErrorMapper.php';
require_once __DIR__ . '/GatewayLogger.php';
require_once __DIR__ . '/StripeAdapter.php';
require_once __DIR__ . '/MyFatoorahAdapter.php';
require_once __DIR__ . '/CheckoutAdapter.php';
require_once __DIR__ . '/PayTabsAdapter.php';
require_once __DIR__ . '/AuthorizeNetAdapter.php';
require_once __DIR__ . '/BraintreeAdapter.php';
require_once __DIR__ . '/GateIOAdapter.php';
require_once __DIR__ . '/NuveiAdapter.php';
require_once __DIR__ . '/../gateways/DIPARMAGateway.php';

class GatewayAdapterFactory
{
    // ── خريطة الأسماء → الكلاسات ──────────────────────────────
    private static array $map = [
        'stripe'        => StripeAdapter::class,
        'myfatoorah'    => MyFatoorahAdapter::class,
        'checkout'      => CheckoutAdapter::class,
        'checkout.com'  => CheckoutAdapter::class,
        'paytabs'       => PayTabsAdapter::class,
        'pay_tabs'      => PayTabsAdapter::class,
        'authorizenet'  => AuthorizeNetAdapter::class,
        'authorize_net' => AuthorizeNetAdapter::class,
        'authnet'       => AuthorizeNetAdapter::class,
        'braintree'     => BraintreeAdapter::class,
        'paypal'        => BraintreeAdapter::class,
        'gate_io'       => GateIOAdapter::class,
        'gateio'        => GateIOAdapter::class,
        'nuvei'         => NuveiAdapter::class,
        'diparma'       => DIPARMAGateway::class,
    ];

    // ── البوابات التي تدعم كل عملية ──────────────────────────
    private static array $capabilities = [
        'stripe'       => ['2D','3D','hold','capture','cancel'],
        'myfatoorah'   => ['2D','3D'],
        'checkout'     => ['2D','3D','hold','capture','cancel'],
        'paytabs'      => ['2D','3D','hold','capture','cancel'],
        'authorizenet' => ['2D','3D','hold','capture','cancel'],
        'braintree'    => ['2D','3D','hold','capture','cancel'],
        'paypal'       => ['2D','3D','hold','capture','cancel'],
        'gate_io'      => ['charge','withdraw','balance'],
        'gateio'       => ['charge','withdraw','balance'],
        'nuvei'        => ['2D','3D','hold','capture','cancel'],
        'diparma'      => ['2D','3D','hold','capture','cancel'],
    ];

    // ══════════════════════════════════════════════════════════
    // make() — إنشاء المحول المناسب
    // ══════════════════════════════════════════════════════════
    /**
     * @param string|null $gateway  اسم البوابة أو null للقراءة من CARD_PROVIDER
     * @param string      $mode     2D | 3D | HOLD | CAPTURE | CANCEL
     */
    public static function make(?string $gateway = null, string $mode = '3D'): GatewayAdapterInterface
    {
        $mode    = strtoupper(trim($mode));
        $gateway = strtolower(trim($gateway ?? getenv('CARD_PROVIDER') ?: 'nuvei'));

        $adapter = self::build($gateway);

        // Fallback: إذا البوابة لا تدعم الوضع → Nuvei (العمل الفعلي المفضل في المشروع)
        if (!$adapter->supports($mode)) {
            GatewayLogger::quick('factory', 'fallback',
                '', true, "$gateway لا تدعم $mode — fallback لـ Nuvei");
            return new NuveiAdapter();
        }

        return $adapter;
    }

    // ══════════════════════════════════════════════════════════
    // process() — نقطة دخول موحدة لجميع العمليات
    // ══════════════════════════════════════════════════════════
    /**
     * @param array       $universalPayload  الـ Payload الموحد من normalizePayload()
     * @param string      $operation         charge | hold | capture | cancel
     * @param string|null $gateway           اسم البوابة أو null
     */
    public static function process(
        array   $universalPayload,
        string  $operation = 'charge',
        ?string $gateway   = null
    ): array {
        $operation = strtolower(trim($operation));
        $mode      = strtoupper($universalPayload['processing_mode'] ?? '3D');

        $modeForFactory = in_array($operation, ['hold','capture','cancel'])
            ? strtoupper($operation)
            : $mode;

        $adapter = self::make($gateway, $modeForFactory);

        switch ($operation) {
            case 'charge':
                return $adapter->charge($universalPayload);

            case 'hold':
                return $adapter->hold($universalPayload);

            case 'capture':
                return $adapter->capture(
                    $universalPayload['transaction_id'] ?? '',
                    isset($universalPayload['partial_amount'])
                        ? floatval($universalPayload['partial_amount'])
                        : null
                );

            case 'cancel':
                return $adapter->cancel(
                    $universalPayload['transaction_id'] ?? '',
                    $universalPayload['reason'] ?? 'requested_by_customer'
                );

            default:
                return GatewayErrorMapper::buildErrorResponse(
                    'GATEWAY_ERROR', $universalPayload['reference'] ?? '',
                    0, '', "عملية غير معروفة: $operation"
                );
        }
    }

    // ══════════════════════════════════════════════════════════
    // resolve() — GatewayManager: اختيار بوابة من config
    // ══════════════════════════════════════════════════════════
    /**
     * يعمل كـ GatewayManager — يقبل config صريح بدلاً من .env
     * مثال:
     *   $adapter = GatewayAdapterFactory::resolve('paytabs', [
     *       'server_key' => '...', 'profile_id' => '...'
     *   ]);
     *
     * @param string $gatewayType  اسم البوابة
     * @param array  $config       إعدادات البوابة (اختياري — يُكمل من .env)
     */
    public static function resolve(string $gatewayType, array $config = []): GatewayAdapterInterface
    {
        $gateway = strtolower(trim($gatewayType));

        // تمرير config للـ env مؤقتاً إذا كانت مقدمة
        self::injectConfig($gateway, $config);

        return self::build($gateway);
    }

    // ══════════════════════════════════════════════════════════
    // normalizePayload() — توحيد أي payload قادم
    // ══════════════════════════════════════════════════════════
    public static function normalizePayload(array $raw): array
    {
        return [
            'amount'          => floatval($raw['amount']                             ?? 0),
            'currency'        => strtoupper(trim($raw['currency']                    ?? 'USD')),
            'card_number'     => preg_replace('/\D/', '',
                                    $raw['card_number'] ?? $raw['cc_number']         ?? ''),
            'card_expiry'     => trim($raw['card_expiry'] ?? $raw['cc_expiry']       ?? ''),
            'cvv2'            => trim((string)($raw['cvv2']
                                    ?? $raw['card_cvv'] ?? $raw['cc_cvv']            ?? '')),
            'processing_mode' => strtoupper(trim(
                                    $raw['processing_mode'] ?? $raw['security_mode'] ?? '3D')),
            'reference'       => $raw['reference']                                   ?? '',
            'name'            => $raw['name']  ?? $raw['customer_name']              ?? 'Customer',
            'email'           => $raw['email'] ?? $raw['customer_email']             ?? '',
            'approval_code'   => $raw['approval_code']                               ?? '',
            'transaction_id'  => $raw['transaction_id'] ?? $raw['payment_intent_id'] ?? '',
            'partial_amount'  => isset($raw['partial_amount'])
                                    ? floatval($raw['partial_amount']) : null,
            'reason'          => $raw['reason']          ?? 'requested_by_customer',
        ];
    }

    // ══════════════════════════════════════════════════════════
    // capabilities() — قائمة قدرات كل بوابة
    // ══════════════════════════════════════════════════════════
    public static function capabilities(): array
    {
        return self::$capabilities;
    }

    /**
     * هل البوابة تدعم وضعاً معيناً؟
     */
    public static function gatewaySupports(string $gateway, string $mode): bool
    {
        $gw  = strtolower(trim($gateway));
        $cap = self::$capabilities[$gw] ?? null;
        if (!$cap) return false;
        return in_array(strtolower($mode), array_map('strtolower', $cap));
    }

    /**
     * قائمة البوابات التي تدعم وضعاً معيناً
     */
    public static function gatewaysSupportingMode(string $mode): array
    {
        $mode    = strtolower($mode);
        $result  = [];
        foreach (self::$capabilities as $gw => $modes) {
            if (in_array($mode, array_map('strtolower', $modes))) {
                $result[] = $gw;
            }
        }
        return $result;
    }

    // ── مساعدات خاصة ─────────────────────────────────────────

    private static function build(string $gateway): GatewayAdapterInterface
    {
        $class = self::$map[$gateway] ?? null;
        if ($class === null) {
            GatewayLogger::quick('factory', 'build', '', false,
                "بوابة غير معروفة: $gateway — fallback لـ Nuvei");
            return new NuveiAdapter();
        }
        return new $class();
    }

    /**
     * حقن config مؤقت في putenv لدعم resolve() بـ config صريح
     */
    private static function injectConfig(string $gateway, array $config): void
    {
        if (empty($config)) return;

        $envMap = [
            'stripe'       => ['secret_key' => 'STRIPE_SECRET_KEY', 'public_key' => 'STRIPE_PUBLIC_KEY'],
            'paytabs'      => ['server_key' => 'PAYTABS_SERVER_KEY', 'profile_id' => 'PAYTABS_PROFILE_ID'],
            'authorizenet' => ['api_login_id' => 'AUTHNET_API_LOGIN_ID', 'transaction_key' => 'AUTHNET_TRANSACTION_KEY'],
            'checkout'     => ['secret_key' => 'CHECKOUT_API_KEY', 'public_key' => 'CHECKOUT_PUBLIC_KEY'],
            'myfatoorah'   => ['api_key' => 'MYFAOORAH_API_KEY'],
            'diparma'      => ['api_key' => 'DIPARMA_API_KEY', 'api_secret' => 'DIPARMA_API_SECRET', 'merchant_id' => 'DIPARMA_MERCHANT_ID', 'api_url' => 'DIPARMA_API_URL'],
        ];

        $map = $envMap[$gateway] ?? [];
        foreach ($map as $configKey => $envKey) {
            if (!empty($config[$configKey])) {
                putenv("$envKey={$config[$configKey]}");
            }
        }
    }
}

// ── GatewayManager alias — للتوافق مع الاستخدام القديم ──────
class_alias('GatewayAdapterFactory', 'GatewayManager');
