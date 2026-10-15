<?php
/**
 * DI PARMA PayPal POS merchant copy.
 * Thermal slip for gateway=paypal. Layout only — no municipal or third-party receipt data.
 */
declare(strict_types=1);

if (defined('DI_PARMA_PAYPAL_MERCHANT_RECEIPT')) {
    return;
}
define('DI_PARMA_PAYPAL_MERCHANT_RECEIPT', true);

function pos_paypal_merchant_receipt_document(array $txn, array $gwResp, bool $ar, string $ref = '', bool $nav = true): string
{
    $model = pos_paypal_merchant_receipt_model($txn, $gwResp, $ar, $ref);
    $dir = $ar ? 'rtl' : 'ltr';
    $lang = $ar ? 'ar' : 'en';
    $title = 'DI PARMA | PayPal Merchant Copy';
    $printLabel = $ar ? 'طباعة' : 'Print';
    $backLabel = $ar ? 'رجوع' : 'Back';
    $navHtml = '';
    if ($nav) {
        $navHtml = '<div class="pp-actions no-print">'
            . '<button type="button" class="pp-print" onclick="window.print()">' . $printLabel . '</button>'
            . '<a class="pp-back" href="dashboard.php">' . $backLabel . '</a>'
            . '</div>';
    }
    return '<!DOCTYPE html><html lang="' . $lang . '" dir="' . $dir . '"><head>'
        . '<meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<title>' . pos_paypal_h($title) . '</title>'
        . '<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">'
        . '<style>' . pos_paypal_merchant_receipt_css() . '</style>'
        . '</head><body><div class="pp-page">'
        . $navHtml
        . pos_paypal_merchant_receipt_slip_html($model)
        . '</div></body></html>';
}

function pos_paypal_merchant_receipt_preview(string $op, bool $ar): string
{
    $op = strtolower(trim($op));
    $samples = pos_paypal_merchant_receipt_samples();
    if ($op !== '' && $op !== 'all' && isset($samples[$op])) {
        $sample = $samples[$op];
        return pos_paypal_merchant_receipt_document($sample['txn'], $sample['gw'], $ar, (string) $sample['txn']['reference'], false);
    }
    $dir = $ar ? 'rtl' : 'ltr';
    $lang = $ar ? 'ar' : 'en';
    $slips = '';
    foreach ($samples as $sample) {
        $model = pos_paypal_merchant_receipt_model($sample['txn'], $sample['gw'], $ar, (string) $sample['txn']['reference']);
        $slips .= pos_paypal_merchant_receipt_slip_html($model);
    }
    $note = $ar
        ? 'عينات عرض فقط. الإيصال الحقيقي يفتح بعد عملية PayPal من receipt.php?ref='
        : 'Sample slips only. A live PayPal charge opens receipt.php?ref=';
    return '<!DOCTYPE html><html lang="' . $lang . '" dir="' . $dir . '"><head>'
        . '<meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<title>DI PARMA | PayPal Merchant Copy</title>'
        . '<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">'
        . '<style>' . pos_paypal_merchant_receipt_css() . '</style>'
        . '</head><body><div class="pp-page">'
        . '<p class="pp-sample-note no-print">' . pos_paypal_h($note) . '</p>'
        . '<div class="pp-actions no-print"><button type="button" class="pp-print" onclick="window.print()">'
        . ($ar ? 'طباعة' : 'Print') . '</button></div>'
        . $slips
        . '</div></body></html>';
}

/**
 * @return array<string, array{txn: array<string, mixed>, gw: array<string, mixed>}>
 */
function pos_paypal_merchant_receipt_samples(): array
{
    $txn = static function (string $type, float $amount, string $status, string $ref, string $when, string $brand, string $last4): array {
        return [
            'reference' => $ref,
            'gateway' => 'paypal',
            'transaction_type' => $type,
            'amount' => $amount,
            'currency' => 'USD',
            'status' => $status,
            'created_at' => $when,
            'card_brand' => $brand,
            'card_last4' => $last4,
        ];
    };
    return [
        'auth' => [
            'txn' => $txn('auth', 150.00, 'authorized', 'DPPAYAUTH0001', '2026-10-15 09:15:02', 'Visa', '4242'),
            'gw' => [
                'rrn' => '482910337261',
                'approval_code' => '583921',
                'authorization_id' => '7PP41AUTH9K2LMQ0',
                'terminal_id' => 'TID10001',
                'merchant' => 'DI PARMA',
                'pos_model' => 'Desk 3200',
            ],
        ],
        'capture' => [
            'txn' => $txn('capture', 90.00, 'completed', 'DPPAYCAP0001', '2026-10-15 09:22:41', 'Visa', '4242'),
            'gw' => [
                'rrn' => '482910337261',
                'approval_code' => '583921',
                'capture_id' => '7PP41CAP9K2LMQ0Z',
                'authorized_amount' => 150.00,
                'amount_vs_auth' => 'less',
                'linked_to_auth' => true,
                'terminal_id' => 'TID10001',
                'merchant' => 'DI PARMA',
                'pos_model' => 'Desk 3200',
            ],
        ],
        'purchase_advice' => [
            'txn' => $txn('purchase_advice', 320.00, 'completed', 'DPPAYADV0001', '2026-10-15 10:04:18', 'Mastercard', '5512'),
            'gw' => [
                'rrn' => '773001928844',
                'approval_code' => '1044',
                'capture_id' => '7PP41ADV9K2LMQ11',
                'advice_independent' => true,
                'terminal_id' => 'TID10001',
                'merchant' => 'DI PARMA',
            ],
        ],
        'offline_sale_moto' => [
            'txn' => $txn('offline_sale_moto', -25.50, 'completed', 'DPPAYOFF0001', '2026-10-15 11:40:07', 'Visa', '1881'),
            'gw' => [
                'approval_code' => '448211',
                'capture_id' => '7PP41OFF9K2LMQ22',
                'terminal_id' => 'TID10008',
                'merchant' => 'DI PARMA',
                'pos_model' => 'Move 5000',
            ],
        ],
    ];
}

/**
 * @param array<string, mixed> $txn
 * @param array<string, mixed> $gw
 * @return array<string, mixed>
 */
function pos_paypal_merchant_receipt_model(array $txn, array $gw, bool $ar, string $ref = ''): array
{
    $type = function_exists('pos_normalize_operation')
        ? pos_normalize_operation((string) ($txn['transaction_type'] ?? $gw['type'] ?? $gw['transaction_type'] ?? ''))
        : strtolower(trim((string) ($txn['transaction_type'] ?? '')));
    $meta = function_exists('pos_operation_meta') ? pos_operation_meta($type) : null;
    $typeLabel = $ar
        ? (string) ($meta['ar'] ?? strtoupper(str_replace('_', ' ', $type)))
        : (string) ($meta['en'] ?? strtoupper(str_replace('_', ' ', $type)));
    if ($typeLabel === '') {
        $typeLabel = 'SALE';
    }

    $statusTxn = $txn;
    $statusTxn['transaction_type'] = $type;
    $status = function_exists('pos_receipt_status')
        ? pos_receipt_status($statusTxn)
        : strtoupper((string) ($txn['status'] ?? 'PENDING'));

    $currency = strtoupper(trim((string) ($txn['currency'] ?? $gw['currency'] ?? 'USD')));
    if (!preg_match('/^[A-Z]{3}$/', $currency)) {
        $currency = 'USD';
    }
    $amount = (float) ($txn['amount'] ?? $gw['amount'] ?? 0);
    $created = trim((string) ($txn['created_at'] ?? $gw['created_at'] ?? ''));
    try {
        $when = $created !== '' ? new DateTime($created) : new DateTime();
    } catch (Exception $e) {
        $when = new DateTime();
    }

    $merchant = pos_paypal_first_text([
        $txn['merchant_name'] ?? '',
        $txn['merchant'] ?? '',
        $gw['merchant_name'] ?? '',
        $gw['merchant'] ?? '',
    ]);
    $terminal = pos_paypal_first_text([
        $txn['terminal_name'] ?? '',
        $gw['terminal_name'] ?? '',
        $gw['pos_model'] ?? '',
        $gw['pos_device'] ?? '',
    ]);
    $tid = pos_paypal_first_text([
        $txn['terminal_id'] ?? '',
        $gw['terminal_id'] ?? '',
        $gw['tid'] ?? '',
    ]);
    $reference = pos_paypal_first_text([
        $ref,
        $txn['reference'] ?? '',
        $gw['reference'] ?? '',
    ]);

    $brand = pos_paypal_card_brand($txn, $gw);
    $last4 = pos_paypal_last4($txn['card_last4'] ?? ($gw['card_last4'] ?? ''));
    $card = '';
    if ($last4 !== '') {
        $card = $brand !== '' ? ($brand . '  •••• ' . $last4) : ('•••• ' . $last4);
    }

    $rrn = pos_paypal_rrn($txn, $gw);
    $approvalRaw = pos_paypal_approval($txn, $gw);
    $approval = function_exists('pos_approval_receipt_label')
        ? pos_approval_receipt_label($approvalRaw)
        : $approvalRaw;
    if ($approval === '—') {
        $approval = '';
    }
    $txnId = pos_paypal_txn_id($gw, $type, $reference);

    $showRrn = $type !== 'offline_sale_moto';
    $showApproval = in_array($type, ['auth', 'capture', 'purchase_advice', 'offline_sale_moto'], true) || $approval !== '';
    $saleLine = $type === 'auth' ? 'AUTH' : 'SALE';
    $amountCaption = $ar ? 'المبلغ' : 'BASE AMOUNT';
    $note = '';
    if ($type === 'auth') {
        $amountCaption = $ar ? 'مبلغ الحجز' : 'HOLD AMOUNT';
        $note = $ar ? 'حجز — لم يُحصَّل بعد' : 'Hold — not captured yet';
    } elseif ($type === 'capture') {
        $amountCaption = $ar ? 'المبلغ المحصّل' : 'CAPTURED AMOUNT';
        $note = $ar ? 'نفس RRN ورمز الموافقة من إيصال الحجز' : 'Same RRN and approval code as the hold receipt';
    } elseif ($type === 'purchase_advice') {
        $note = $ar ? 'تسوية مباشرة — بلا حجز' : 'Direct settlement — no hold';
    } elseif ($type === 'offline_sale_moto') {
        $note = $ar ? 'شحن بطاقة أوفلاين' : 'Offline card charge';
    }

    $rows = [
        ['label' => $ar ? 'الحالة' : 'Status', 'value' => $status],
        ['label' => $ar ? 'نوع العملية' : 'Txn Type', 'value' => $typeLabel],
        ['label' => $ar ? 'البوابة' : 'Gateway', 'value' => 'PayPal'],
    ];
    if ($terminal !== '' && strcasecmp($terminal, $merchant) !== 0) {
        $rows[] = ['label' => $ar ? 'الجهاز' : 'Terminal', 'value' => $terminal];
    }
    if ($card !== '') {
        $rows[] = ['label' => $ar ? 'البطاقة' : 'Card', 'value' => $card];
    }
    if ($tid !== '') {
        $rows[] = ['label' => 'TID', 'value' => $tid];
    }
    if ($reference !== '') {
        $rows[] = ['label' => $ar ? 'المرجع' : 'Reference', 'value' => $reference];
    }
    if ($showRrn) {
        $rows[] = ['label' => 'RRN', 'value' => $rrn !== '' ? $rrn : '—'];
    }
    if ($showApproval) {
        $rows[] = ['label' => 'APPROVAL CODE', 'value' => $approval !== '' ? $approval : '—'];
    }
    if ($txnId !== '') {
        $rows[] = ['label' => 'Txn ID', 'value' => $txnId];
    }
    if ($type === 'capture') {
        $authorized = pos_paypal_authorized_amount($gw);
        if ($authorized !== null) {
            $rows[] = [
                'label' => $ar ? 'مبلغ الحجز' : 'Auth Amount',
                'value' => pos_paypal_money($authorized, $currency),
            ];
        }
        $relation = pos_paypal_capture_relation($amount, $authorized, (string) ($gw['amount_vs_auth'] ?? ''));
        $relationLabel = pos_paypal_relation_label($relation, $ar);
        if ($relationLabel !== '') {
            $rows[] = ['label' => $ar ? 'مقارنة بالحجز' : 'Vs Hold', 'value' => $relationLabel];
        }
    } elseif ($type === 'purchase_advice') {
        $rows[] = ['label' => $ar ? 'الحجز' : 'Hold', 'value' => $ar ? 'لا يوجد — تسوية مباشرة' : 'None — direct settlement'];
    } elseif ($type === 'offline_sale_moto') {
        $rows[] = ['label' => $ar ? 'الشحن' : 'Charge', 'value' => $ar ? 'بطاقة أوفلاين' : 'Offline card'];
    }

    return [
        'sale_line' => $saleLine,
        'amount_caption' => $amountCaption,
        'amount_line' => pos_paypal_money($amount, $currency),
        'note' => $note,
        'date' => $when->format('Y-m-d'),
        'time' => $when->format('H:i:s'),
        'merchant' => $merchant,
        'rows' => $rows,
        'copy_label' => $ar ? 'نسخة التاجر' : 'Merchant Copy',
        'show_english_copy' => $ar,
        'sign_label' => $ar ? 'التوقيع غير مطلوب' : 'SIGNATURE NOT REQUIRED',
        'paid_by_label' => $ar ? 'الدفع عبر' : 'PAID BY',
    ];
}

function pos_paypal_capture_relation(float $amount, ?float $authorized, string $stored = ''): string
{
    if ($amount < 0) {
        return 'negative';
    }
    if ($authorized !== null) {
        if (abs($amount - $authorized) < 0.005) {
            return 'equal';
        }
        return $amount < $authorized ? 'under' : 'over';
    }
    $stored = strtolower(trim($stored));
    if ($stored === 'less' || $stored === 'under') {
        return 'under';
    }
    if ($stored === 'equal') {
        return 'equal';
    }
    if ($stored === 'more' || $stored === 'over') {
        return 'over';
    }
    return '';
}

function pos_paypal_relation_label(string $relation, bool $ar): string
{
    $labels = [
        'under' => [$ar ? 'أقل من الحجز' : 'Under hold'],
        'equal' => [$ar ? 'مساوٍ للحجز' : 'Equal to hold'],
        'over' => [$ar ? 'أكبر من الحجز' : 'Over hold'],
        'negative' => [$ar ? 'مبلغ سالب' : 'Negative amount'],
    ];
    return $labels[$relation][0] ?? '';
}

/**
 * @param array<string, mixed> $model
 */
function pos_paypal_merchant_receipt_slip_html(array $model): string
{
    $rows = '';
    foreach ($model['rows'] as $row) {
        $rows .= '<div class="pp-row"><span class="pp-k">' . pos_paypal_h((string) $row['label'])
            . '</span><span class="pp-v"><bdi dir="ltr">' . pos_paypal_h((string) $row['value']) . '</bdi></span></div>';
    }
    $merchant = trim((string) ($model['merchant'] ?? ''));
    $merchantHtml = ($merchant !== '' && strcasecmp($merchant, 'DI PARMA') !== 0)
        ? '<div class="pp-merchant">' . pos_paypal_h($merchant) . '</div>'
        : '';
    $note = trim((string) ($model['note'] ?? ''));
    $noteHtml = $note !== '' ? '<div class="pp-note">' . pos_paypal_h($note) . '</div>' : '';
    $englishCopy = !empty($model['show_english_copy'])
        ? '<div class="pp-copy-en">Merchant Copy</div>'
        : '';
    return '<article class="pp-slip">'
        . '<header class="pp-head">'
        . '<div class="pp-brand-name">DI PARMA</div>'
        . $merchantHtml
        . '<div class="pp-sub">PayPal POS</div>'
        . '<div class="pp-when" dir="ltr"><span>Date: ' . pos_paypal_h((string) $model['date'])
        . '</span><span>Time: ' . pos_paypal_h((string) $model['time']) . '</span></div>'
        . '</header>'
        . '<div class="pp-sale">' . pos_paypal_h((string) $model['sale_line']) . '</div>'
        . '<div class="pp-row pp-amount"><span class="pp-k">' . pos_paypal_h((string) $model['amount_caption'])
        . '</span><span class="pp-v"><bdi dir="ltr">' . pos_paypal_h((string) $model['amount_line']) . '</bdi></span></div>'
        . '<div class="pp-row"><span class="pp-k">' . pos_paypal_h((string) ($model['paid_by_label'] ?? 'PAID BY'))
        . '</span><span class="pp-v"><bdi dir="ltr">PAYPAL</bdi></span></div>'
        . '<div class="pp-sign">' . pos_paypal_h((string) ($model['sign_label'] ?? 'SIGNATURE NOT REQUIRED')) . '</div>'
        . $noteHtml
        . '<div class="pp-rule"></div>'
        . $rows
        . '<footer class="pp-foot">'
        . '<div class="pp-wordmark" dir="ltr"><span class="pay">Pay</span><span class="pal">Pal</span></div>'
        . '<div class="pp-copy">**** ' . pos_paypal_h((string) $model['copy_label']) . ' ****</div>'
        . $englishCopy
        . '</footer>'
        . '</article>';
}

function pos_paypal_merchant_receipt_css(): string
{
    return <<<'CSS'
*{box-sizing:border-box}
body{margin:0;background:#d9d3cc;color:#111;font-family:'Cairo','Courier New',ui-monospace,monospace}
.pp-page{max-width:420px;margin:0 auto;padding:16px 12px 28px}
.pp-actions{display:flex;gap:8px;justify-content:center;margin-bottom:12px}
.pp-print,.pp-back{font-family:'Cairo',sans-serif;font-weight:700;font-size:14px;border-radius:8px;padding:8px 16px;text-decoration:none;cursor:pointer}
.pp-print{background:#003087;color:#fff;border:0}
.pp-back{background:#fff;color:#111;border:1px solid #ccc}
.pp-sample-note{font-family:'Cairo',sans-serif;font-size:13px;text-align:center;margin:0 0 8px}
.pp-slip{width:80mm;max-width:100%;margin:0 auto 18px;background:#fff;color:#111;padding:10px 12px 14px;box-shadow:0 6px 18px rgba(0,0,0,.12);font-family:'Courier New',ui-monospace,monospace;font-size:11px;line-height:1.35}
.pp-head{text-align:center;padding-bottom:6px;border-bottom:1px dashed #222}
.pp-brand-name{font-family:'Cairo',sans-serif;font-weight:800;letter-spacing:.14em;font-size:16px}
.pp-merchant{font-family:'Cairo',sans-serif;font-weight:700;font-size:12px;margin-top:2px}
.pp-sub{font-size:11px;letter-spacing:.08em;margin-top:2px}
.pp-when{display:flex;justify-content:space-between;gap:8px;margin-top:6px;font-size:10px}
.pp-sale{text-align:center;font-weight:800;letter-spacing:.2em;font-size:16px;margin:8px 0 4px}
.pp-row{display:flex;justify-content:space-between;gap:10px;padding:2px 0}
.pp-k{flex:0 1 auto}
.pp-v{flex:1 1 auto;text-align:right;font-weight:700;word-break:break-all}
html[dir=rtl] .pp-v{text-align:left}
.pp-amount .pp-k,.pp-amount .pp-v{font-weight:800;font-size:12px}
.pp-sign,.pp-note{text-align:center;font-size:10px;margin-top:4px}
.pp-rule{border-top:1px dashed #222;margin:8px 0}
.pp-foot{text-align:center;margin-top:10px;padding-top:8px;border-top:1px dashed #222}
.pp-wordmark{font-family:Arial,'Segoe UI',sans-serif;font-weight:800;font-style:italic;font-size:22px;letter-spacing:-.04em;line-height:1}
.pp-wordmark .pay{color:#003087}
.pp-wordmark .pal{color:#009cde}
.pp-copy{margin-top:4px;font-weight:800;letter-spacing:.04em}
.pp-copy-en{font-size:10px;letter-spacing:.08em}
@media print{
  body{background:#fff}
  .pp-page{max-width:none;padding:0}
  .no-print{display:none !important}
  .pp-slip{box-shadow:none;margin:0 auto;width:80mm;page-break-after:always}
  @page{size:80mm auto;margin:4mm}
}
CSS;
}

function pos_paypal_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function pos_paypal_money(float $amount, string $currency): string
{
    $sign = $amount < 0 ? '-' : '';
    return $sign . number_format(abs($amount), 2, '.', ',') . ' ' . $currency;
}

/**
 * @param list<mixed> $values
 */
function pos_paypal_first_text(array $values): string
{
    foreach ($values as $value) {
        if (!is_scalar($value)) {
            continue;
        }
        $text = trim((string) $value);
        if ($text !== '' && $text !== '—') {
            return $text;
        }
    }
    return '';
}

/**
 * @param array<string, mixed> $txn
 * @param array<string, mixed> $gw
 */
function pos_paypal_card_brand(array $txn, array $gw): string
{
    $brand = pos_paypal_first_text([
        $txn['card_brand'] ?? '',
        $gw['card_brand'] ?? '',
        $gw['card_network'] ?? '',
        $gw['card_type'] ?? '',
    ]);
    if ($brand === '' || preg_match('/\d{8,}/', $brand)) {
        return '';
    }
    $skip = ['cloud', 'card', 'credit', 'debit', 'unknown', '—'];
    if (in_array(strtolower($brand), $skip, true)) {
        return '';
    }
    return $brand;
}

function pos_paypal_last4($value): string
{
    $digits = preg_replace('/\D/', '', (string) $value) ?? '';
    if (strlen($digits) < 4) {
        return '';
    }
    return substr($digits, -4);
}

/**
 * @param array<string, mixed> $txn
 * @param array<string, mixed> $gw
 */
function pos_paypal_rrn(array $txn, array $gw): string
{
    $nodes = [$txn, $gw];
    foreach (['gateway_details', 'raw', 'response'] as $key) {
        if (isset($gw[$key]) && is_array($gw[$key])) {
            $nodes[] = $gw[$key];
        }
    }
    foreach ($nodes as $node) {
        foreach (['rrn', 'original_rrn', 'retrieval_reference_number', 'retrievalReferenceNumber', 'acquirer_reference_number'] as $key) {
            $digits = preg_replace('/\D/', '', (string) ($node[$key] ?? '')) ?? '';
            if (strlen($digits) >= 12) {
                return substr($digits, 0, 12);
            }
        }
    }
    return '';
}

/**
 * @param array<string, mixed> $txn
 * @param array<string, mixed> $gw
 */
function pos_paypal_approval(array $txn, array $gw): string
{
    $nodes = [$txn, $gw];
    foreach (['gateway_details', 'raw', 'response', 'processor_response'] as $key) {
        if (isset($gw[$key]) && is_array($gw[$key])) {
            $nodes[] = $gw[$key];
        }
    }
    $processor = pos_paypal_find_processor($gw);
    if ($processor !== []) {
        $nodes[] = $processor;
    }
    foreach ($nodes as $node) {
        foreach (['approval_code', 'auth_code', 'bank_approval_code', 'gateway_approval_code', 'authorization_code', 'authCode', 'approvalCode'] as $key) {
            $code = trim((string) ($node[$key] ?? ''));
            if ($code === '' || $code === '—') {
                continue;
            }
            if (preg_match('/^\d{12}$/', $code)) {
                continue;
            }
            if (preg_match('/[A-Za-z]/', $code) && strlen($code) > 8) {
                continue;
            }
            return $code;
        }
    }
    return '';
}

/**
 * @param array<string, mixed> $node
 * @return array<string, mixed>
 */
function pos_paypal_find_processor(array $node, int $depth = 0): array
{
    if ($depth > 5) {
        return [];
    }
    if (isset($node['processor_response']) && is_array($node['processor_response'])) {
        return $node['processor_response'];
    }
    foreach ($node as $child) {
        if (is_array($child)) {
            $found = pos_paypal_find_processor($child, $depth + 1);
            if ($found !== []) {
                return $found;
            }
        }
    }
    return [];
}

/**
 * @param array<string, mixed> $gw
 */
function pos_paypal_txn_id(array $gw, string $type, string $reference): string
{
    $keys = $type === 'auth'
        ? ['authorization_id', 'payment_id', 'transaction_id', 'order_id']
        : ['capture_id', 'authorization_id', 'payment_id', 'transaction_id', 'order_id'];
    foreach ($keys as $key) {
        $found = pos_paypal_find_key_deep($gw, $key, $reference);
        if ($found !== '') {
            return $found;
        }
    }
    $kind = $type === 'auth' ? 'authorizations' : 'captures';
    return pos_paypal_payment_bucket_id($gw, $kind, $reference);
}

/**
 * @param array<string, mixed> $node
 */
function pos_paypal_find_key_deep(array $node, string $key, string $reference, int $depth = 0): string
{
    if ($depth > 6) {
        return '';
    }
    if (isset($node[$key]) && is_scalar($node[$key])) {
        $id = pos_paypal_clean_txn_id((string) $node[$key], $reference);
        if ($id !== '') {
            return $id;
        }
    }
    foreach ($node as $child) {
        if (is_array($child)) {
            $found = pos_paypal_find_key_deep($child, $key, $reference, $depth + 1);
            if ($found !== '') {
                return $found;
            }
        }
    }
    return '';
}

/**
 * @param array<string, mixed> $node
 */
function pos_paypal_payment_bucket_id(array $node, string $kind, string $reference, int $depth = 0): string
{
    if ($depth > 6) {
        return '';
    }
    $payments = $node['purchase_units'][0]['payments'] ?? null;
    if (is_array($payments)) {
        $order = $kind === 'authorizations' ? ['authorizations', 'captures'] : ['captures', 'authorizations'];
        foreach ($order as $bucket) {
            $id = $payments[$bucket][0]['id'] ?? '';
            $clean = pos_paypal_clean_txn_id((string) $id, $reference);
            if ($clean !== '') {
                return $clean;
            }
        }
    }
    foreach ($node as $child) {
        if (is_array($child)) {
            $found = pos_paypal_payment_bucket_id($child, $kind, $reference, $depth + 1);
            if ($found !== '') {
                return $found;
            }
        }
    }
    return '';
}

function pos_paypal_clean_txn_id(string $value, string $reference): string
{
    $value = trim($value);
    if ($value === '' || $value === '—') {
        return '';
    }
    if ($reference !== '' && strcasecmp($value, $reference) === 0) {
        return '';
    }
    if (!preg_match('/^[A-Za-z0-9_-]{8,40}$/', $value)) {
        return '';
    }
    if (!preg_match('/[A-Za-z]/', $value)) {
        return '';
    }
    if (preg_match('/^\d{12}$/', $value)) {
        return '';
    }
    return $value;
}

/**
 * @param array<string, mixed> $gw
 */
function pos_paypal_authorized_amount(array $gw): ?float
{
    $nodes = [$gw];
    if (isset($gw['_meta']) && is_array($gw['_meta'])) {
        $nodes[] = $gw['_meta'];
    }
    foreach (['raw', 'response', 'gateway_details'] as $key) {
        if (!isset($gw[$key]) || !is_array($gw[$key])) {
            continue;
        }
        $nodes[] = $gw[$key];
        if (isset($gw[$key]['_meta']) && is_array($gw[$key]['_meta'])) {
            $nodes[] = $gw[$key]['_meta'];
        }
    }
    foreach ($nodes as $node) {
        foreach (['authorized_amount', 'auth_amount'] as $field) {
            if (isset($node[$field]) && is_numeric($node[$field]) && (float) $node[$field] > 0) {
                return (float) $node[$field];
            }
        }
    }
    return null;
}
