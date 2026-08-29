<?php
/**
 * ============================================================
 * DI PARMA | Payment Handler — معالج الدفع الشامل
 * يتعامل مع: LIVE Card, CLOUD Card, NFC, Apple Pay, Google Pay
 * ============================================================
 */

require_once __DIR__ . '/ProtocolInterface.php';
require_once __DIR__ . '/../includes/gateways.php';
require_once __DIR__ . '/../includes/functions.php';

// ============================================================
// [A] LIVE Card — بطاقة ائتمان حية (Visa/Mastercard مع CVV)
// ============================================================
class LiveCardHandler {
    public function process(array $ctx): array {
        $gateway  = strtolower(trim($ctx['gateway_code'] ?? $ctx['gateway_type'] ?? ''));
        $amount   = floatval($ctx['amount'] ?? 0);
        $currency = strtoupper($ctx['currency'] ?? 'USD');
        $protocol = $ctx['selected_protocol'] ?? '101.0';

        if ($amount <= 0)  return ['success'=>false,'message'=>'❌ المبلغ غير صحيح','card_type'=>'LIVE'];
        if (empty($gateway)) return ['success'=>false,'message'=>'❌ لم يتم تحديد بوابة الدفع','card_type'=>'LIVE'];

        $gwConfig = getGatewayConfig($gateway);
        if (empty($gwConfig['credentials'] ?? [])) {
            return ['success'=>false,'message'=>'❌ بوابة الدفع غير مهيأة','gateway'=>$gateway,'card_type'=>'LIVE'];
        }

        $ref   = 'LIVE_' . strtoupper(bin2hex(random_bytes(6)));
        $fees  = round($amount * 0.025, 2);
        $net   = round($amount - $fees, 2);

        $payload = [
            'order_ref'        => $ref,
            'amount'           => $amount,
            'currency'         => $currency,
            'customer_name'    => $ctx['customer_name']  ?? 'Customer',
            'customer_email'   => $ctx['customer_email'] ?? '',
            'customer_phone'   => $ctx['customer_phone'] ?? '',
            'card_pan'         => $ctx['card_pan']    ?? '',
            'card_expiry'      => $ctx['card_expiry'] ?? '',
            'card_cvv'         => $ctx['card_cvv']    ?? '',
            'description'      => 'LIVE Card Payment — ' . strtoupper($gateway),
            'transaction_type' => 'SALE',
            'payment_method'   => 'card',
            'source'           => 'web',
            'otp_code'         => $ctx['otp_code'] ?? '',
            'allow_otp_bypass' => !empty($ctx['allow_otp_bypass']),
            'secure_mode'      => '3ds',
        ];

        try {
            $gwResp = gateway_service()->createPaymentIntent($gateway, $payload);
        } catch (Throwable $e) {
            return ['success'=>false,'message'=>'❌ '.$e->getMessage(),'card_type'=>'LIVE'];
        }

        $success = !empty($gwResp['success']);
        return [
            'success'          => $success,
            'card_type'        => 'LIVE',
            'protocol'         => $protocol,
            'reference'        => $ref,
            'gateway'          => $gateway,
            'amount'           => $amount,
            'currency'         => $currency,
            'fees'             => $fees,
            'net_amount'       => $net,
            'status'           => $success ? 'captured' : 'failed',
            'message'          => $success
                ? '✅ تم تنفيذ الدفع بنجاح عبر LIVE Card'
                : ('❌ فشل الدفع: ' . ($gwResp['message'] ?? 'Gateway error')),
            'mode'             => 'LIVE',
            'gateway_response' => $gwResp,
        ];
    }
}

// ============================================================
// [B] CLOUD Card — بطاقة رقمية Tokenized (بدون CVV)
// ============================================================
class CloudCardHandler {
    public function process(array $ctx): array {
        $gateway  = strtolower(trim($ctx['gateway_code'] ?? $ctx['gateway_type'] ?? ''));
        $amount   = floatval($ctx['amount'] ?? 0);
        $currency = strtoupper($ctx['currency'] ?? 'USD');

        if ($amount <= 0)  return ['success'=>false,'message'=>'❌ المبلغ غير صحيح','card_type'=>'CLOUD'];
        if (empty($gateway)) return ['success'=>false,'message'=>'❌ لم يتم تحديد بوابة الدفع','card_type'=>'CLOUD'];

        $ref  = 'CLOUD_' . strtoupper(bin2hex(random_bytes(6)));
        $fees = round($amount * 0.020, 2);
        $net  = round($amount - $fees, 2);

        // CLOUD card تعمل عبر Token مُشفَّر (NFC token أو Digital Wallet token)
        $token = $ctx['cloud_token'] ?? $ctx['card_pan'] ?? '';

        $payload = [
            'order_ref'        => $ref,
            'amount'           => $amount,
            'currency'         => $currency,
            'customer_name'    => $ctx['customer_name']  ?? 'Customer',
            'customer_email'   => $ctx['customer_email'] ?? '',
            'customer_phone'   => $ctx['customer_phone'] ?? '',
            'payment_token'    => $token,
            'card_pan'         => $ctx['card_pan'] ?? '',
            'card_expiry'      => $ctx['card_expiry'] ?? '',
            'description'      => 'CLOUD Card Tokenized Payment',
            'transaction_type' => 'SALE',
            'payment_method'   => 'token',
            'source'           => 'cloud',
            'secure_mode'      => 'token',
        ];

        try {
            $gwResp = gateway_service()->createPaymentIntent($gateway, $payload);
        } catch (Throwable $e) {
            return ['success'=>false,'message'=>'❌ '.$e->getMessage(),'card_type'=>'CLOUD'];
        }

        $success = !empty($gwResp['success']);
        return [
            'success'          => $success,
            'card_type'        => 'CLOUD',
            'reference'        => $ref,
            'gateway'          => $gateway,
            'amount'           => $amount,
            'currency'         => $currency,
            'fees'             => $fees,
            'net_amount'       => $net,
            'status'           => $success ? 'captured' : 'failed',
            'message'          => $success
                ? '✅ تم تنفيذ الدفع بنجاح عبر CLOUD Card (Tokenized)'
                : ('❌ فشل الدفع: ' . ($gwResp['message'] ?? 'Gateway error')),
            'mode'             => 'LIVE',
            'gateway_response' => $gwResp,
        ];
    }
}

// ============================================================
// [C] NFC Card — بطاقة لاتلامسية (Contactless)
// ============================================================
class NfcCardHandler {
    public function process(array $ctx): array {
        // NFC تُعالَج كـ LIVE Card لكن بدون CVV
        $ctx['card_cvv'] = '';
        $ctx['payment_method'] = 'nfc';
        $handler = new LiveCardHandler();
        $result  = $handler->process($ctx);
        $result['card_type'] = 'NFC';
        if (!empty($result['success'])) {
            $result['message'] = '✅ تم تنفيذ الدفع بنجاح عبر NFC (Contactless)';
        }
        return $result;
    }
}

// ============================================================
// [D] Apple Pay — دفع بيومتري عبر Apple Secure Element
// ============================================================
class ApplePayHandler {
    public function process(array $ctx): array {
        $gateway  = strtolower(trim($ctx['gateway_code'] ?? $ctx['gateway_type'] ?? ''));
        $amount   = floatval($ctx['amount'] ?? 0);
        $currency = strtoupper($ctx['currency'] ?? 'USD');

        if ($amount <= 0)  return ['success'=>false,'message'=>'❌ المبلغ غير صحيح','card_type'=>'APPLE_PAY'];
        if (empty($gateway)) return ['success'=>false,'message'=>'❌ لم يتم تحديد بوابة الدفع','card_type'=>'APPLE_PAY'];

        $ref  = 'APL_' . strtoupper(bin2hex(random_bytes(6)));
        $fees = round($amount * 0.025, 2);
        $net  = round($amount - $fees, 2);

        // Apple Pay يُرسل payment token من الجهاز مباشرة
        $appleToken = $ctx['apple_pay_token'] ?? $ctx['payment_token'] ?? '';

        $payload = [
            'order_ref'        => $ref,
            'amount'           => $amount,
            'currency'         => $currency,
            'customer_name'    => $ctx['customer_name']  ?? 'Customer',
            'customer_email'   => $ctx['customer_email'] ?? '',
            'customer_phone'   => $ctx['customer_phone'] ?? '',
            'apple_pay_token'  => $appleToken,
            'description'      => 'Apple Pay Payment',
            'transaction_type' => 'SALE',
            'payment_method'   => 'apple_pay',
            'source'           => 'apple_pay',
        ];

        // نوجّه الطلب لـ apple_pay gateway أو للبوابة المختارة
        $targetGw = in_array($gateway, ['apple_pay','stripe','adyen','checkout']) ? $gateway : 'apple_pay';

        try {
            $gwResp = gateway_service()->createPaymentIntent($targetGw, $payload);
        } catch (Throwable $e) {
            return ['success'=>false,'message'=>'❌ '.$e->getMessage(),'card_type'=>'APPLE_PAY'];
        }

        $success = !empty($gwResp['success']);
        return [
            'success'          => $success,
            'card_type'        => 'APPLE_PAY',
            'reference'        => $ref,
            'gateway'          => $targetGw,
            'amount'           => $amount,
            'currency'         => $currency,
            'fees'             => $fees,
            'net_amount'       => $net,
            'status'           => $success ? 'captured' : 'failed',
            'message'          => $success
                ? '✅ تم تنفيذ الدفع بنجاح عبر Apple Pay'
                : ('❌ فشل Apple Pay: ' . ($gwResp['message'] ?? 'Gateway error')),
            'mode'             => 'LIVE',
            'gateway_response' => $gwResp,
        ];
    }
}

// ============================================================
// [E] Google Pay — دفع Tokenized عبر Google
// ============================================================
class GooglePayHandler {
    public function process(array $ctx): array {
        $gateway  = strtolower(trim($ctx['gateway_code'] ?? $ctx['gateway_type'] ?? ''));
        $amount   = floatval($ctx['amount'] ?? 0);
        $currency = strtoupper($ctx['currency'] ?? 'USD');

        if ($amount <= 0)  return ['success'=>false,'message'=>'❌ المبلغ غير صحيح','card_type'=>'GOOGLE_PAY'];
        if (empty($gateway)) return ['success'=>false,'message'=>'❌ لم يتم تحديد بوابة الدفع','card_type'=>'GOOGLE_PAY'];

        $ref  = 'GPY_' . strtoupper(bin2hex(random_bytes(6)));
        $fees = round($amount * 0.025, 2);
        $net  = round($amount - $fees, 2);

        $googleToken = $ctx['google_pay_token'] ?? $ctx['payment_token'] ?? '';

        $payload = [
            'order_ref'         => $ref,
            'amount'            => $amount,
            'currency'          => $currency,
            'customer_name'     => $ctx['customer_name']  ?? 'Customer',
            'customer_email'    => $ctx['customer_email'] ?? '',
            'customer_phone'    => $ctx['customer_phone'] ?? '',
            'google_pay_token'  => $googleToken,
            'description'       => 'Google Pay Payment',
            'transaction_type'  => 'SALE',
            'payment_method'    => 'google_pay',
            'source'            => 'google_pay',
        ];

        $targetGw = in_array($gateway, ['google_pay','stripe','adyen','checkout']) ? $gateway : 'google_pay';

        try {
            $gwResp = gateway_service()->createPaymentIntent($targetGw, $payload);
        } catch (Throwable $e) {
            return ['success'=>false,'message'=>'❌ '.$e->getMessage(),'card_type'=>'GOOGLE_PAY'];
        }

        $success = !empty($gwResp['success']);
        return [
            'success'          => $success,
            'card_type'        => 'GOOGLE_PAY',
            'reference'        => $ref,
            'gateway'          => $targetGw,
            'amount'           => $amount,
            'currency'         => $currency,
            'fees'             => $fees,
            'net_amount'       => $net,
            'status'           => $success ? 'captured' : 'failed',
            'message'          => $success
                ? '✅ تم تنفيذ الدفع بنجاح عبر Google Pay'
                : ('❌ فشل Google Pay: ' . ($gwResp['message'] ?? 'Gateway error')),
            'mode'             => 'LIVE',
            'gateway_response' => $gwResp,
        ];
    }
}

// ============================================================
// [F] PaymentHandlerFactory — يختار المعالج الصحيح
// ============================================================
function resolvePaymentHandler(string $cardType): object {
    return match (strtoupper($cardType)) {
        'CLOUD'      => new CloudCardHandler(),
        'NFC'        => new NfcCardHandler(),
        'APPLE_PAY'  => new ApplePayHandler(),
        'GOOGLE_PAY' => new GooglePayHandler(),
        default      => new LiveCardHandler(),   // LIVE = default
    };
}
