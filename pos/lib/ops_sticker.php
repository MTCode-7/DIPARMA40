<?php
/**
 * POS operator sticker + decline playbook.
 */

function pos_ops_sticker_legend(bool $ar): string
{
    return $ar
        ? 'نعم = مطلوب للتمرير. لا = لا يُدخل. حسب الوضع = يتبع نوع العملية. Ledger بعد Approved فقط، وليس بعد الحجز.'
        : 'Yes = required. No = do not enter. By mode = depends on the operation. Ledger only after Approved — never after hold.';
}

function pos_render_ops_legend(bool $ar): void
{
    ?>
    <section id="opsLegend" class="ops-legend-box" aria-label="أسطورة" style="display:block!important;visibility:visible!important">
      <div class="ops-legend-title">أسطورة <span id="opsLegendOpName">· Legend</span></div>
      <div class="ops-legend-chips" id="opsLegendKey">
        <span class="ops-chip yes"><?=$ar ? 'نعم = مطلوب' : 'Yes = required'?></span>
        <span class="ops-chip no"><?=$ar ? 'لا = لا يُدخل' : 'No = skip'?></span>
        <span class="ops-chip mode"><?=$ar ? 'حسب الوضع' : 'By mode'?></span>
      </div>
      <div class="ops-legend-chips" id="opsLegendLiveChips"></div>
      <p class="ops-legend-note"><?= htmlspecialchars(pos_ops_sticker_legend($ar)) ?></p>
    </section>
    <?php
}

/**
 * @return list<array<string,mixed>>
 */
function pos_ops_sticker_rows(): array
{
    return [
        ['op' => 'purchase_3d', 'group' => 'sale', 'card' => true, 'exp' => true, 'cvv' => true, 'otp' => true, 'rrn' => false, 'appr' => false, 'pid' => false, 'amt' => 'fixed', 'ledger' => 'after_sale'],
        ['op' => 'purchase_2d', 'group' => 'sale', 'card' => true, 'exp' => true, 'cvv' => true, 'otp' => false, 'rrn' => false, 'appr' => false, 'pid' => false, 'amt' => 'fixed', 'ledger' => 'after_sale'],
        ['op' => 'auth', 'group' => 'hold', 'card' => true, 'exp' => true, 'cvv' => false, 'otp' => false, 'rrn' => 'mode', 'appr' => 'mode', 'pid' => false, 'amt' => 'hold', 'ledger' => 'never_hold'],
        ['op' => 'capture', 'group' => 'hold', 'card' => true, 'exp' => true, 'cvv' => false, 'otp' => false, 'rrn' => true, 'appr' => true, 'pid' => true, 'amt' => 'flex_hold', 'ledger' => 'after_capture'],
        ['op' => 'purchase_advice', 'group' => 'advice', 'card' => true, 'exp' => true, 'cvv' => false, 'otp' => false, 'rrn' => true, 'appr' => true, 'pid' => true, 'amt' => 'cap5m', 'ledger' => 'after_sale'],
        ['op' => 'online_sale_moto', 'group' => 'advice', 'card' => true, 'exp' => true, 'cvv' => false, 'otp' => false, 'rrn' => true, 'appr' => true, 'pid' => true, 'amt' => 'fixed', 'ledger' => 'after_sale'],
        ['op' => 'offline_sale_moto', 'group' => 'advice', 'card' => true, 'exp' => true, 'cvv' => false, 'otp' => false, 'rrn' => true, 'appr' => true, 'pid' => true, 'amt' => 'cap2m', 'ledger' => 'after_sale'],
        ['op' => 'refund', 'group' => 'reverse', 'card' => false, 'exp' => false, 'cvv' => false, 'otp' => false, 'rrn' => true, 'appr' => false, 'pid' => false, 'amt' => 'orig', 'ledger' => 'no_new'],
        ['op' => 'avoid', 'group' => 'reverse', 'card' => false, 'exp' => false, 'cvv' => false, 'otp' => false, 'rrn' => true, 'appr' => false, 'pid' => false, 'amt' => 'none', 'ledger' => 'no_new'],
        ['op' => 'recurring', 'group' => 'sale', 'card' => true, 'exp' => true, 'cvv' => true, 'otp' => false, 'rrn' => false, 'appr' => false, 'pid' => false, 'amt' => 'fixed', 'ledger' => 'after_sale'],
        ['op' => 'installment', 'group' => 'sale', 'card' => true, 'exp' => true, 'cvv' => true, 'otp' => false, 'rrn' => false, 'appr' => false, 'pid' => false, 'amt' => 'fixed', 'ledger' => 'after_sale'],
        ['op' => 'withdrawal_pos', 'group' => 'wd', 'card' => true, 'exp' => true, 'cvv' => true, 'otp' => false, 'rrn' => 'mode', 'appr' => 'mode', 'pid' => 'mode', 'amt' => 'mode', 'ledger' => 'mode'],
        ['op' => 'withdrawal_nfc', 'group' => 'wd', 'card' => true, 'exp' => true, 'cvv' => false, 'otp' => false, 'rrn' => 'mode', 'appr' => 'mode', 'pid' => 'mode', 'amt' => 'mode', 'ledger' => 'mode'],
        ['op' => 'wire_transfer', 'group' => 'other', 'card' => false, 'exp' => false, 'cvv' => false, 'otp' => false, 'rrn' => false, 'appr' => false, 'pid' => false, 'amt' => 'fixed', 'ledger' => 'after_accept'],
    ];
}

function pos_sticker_cell($v, bool $ar): string
{
    if ($v === true) {
        return $ar ? 'نعم' : 'Yes';
    }
    if ($v === false) {
        return $ar ? 'لا' : 'No';
    }
    if ($v === 'mode') {
        return $ar ? 'حسب الوضع' : 'By mode';
    }
    return htmlspecialchars((string) $v);
}

function pos_sticker_amt($v, bool $ar): string
{
    $map = [
        'fixed' => $ar ? 'ثابت' : 'Fixed',
        'hold' => $ar ? 'مبلغ الحجز' : 'Hold amount',
        'flex_hold' => $ar ? 'أقل / مساوٍ / أكثر من الحجز' : 'Less / same / more than hold',
        'cap5m' => $ar ? 'حد 5,000,000' : 'Cap 5,000,000',
        'cap2m' => $ar ? 'حد 2,000,000' : 'Cap 2,000,000',
        'orig' => $ar ? 'من الأصل' : 'From original',
        'none' => $ar ? 'لا خصم' : 'No charge',
        'mode' => $ar ? 'حسب الوضع' : 'By mode',
    ];
    return $map[$v] ?? (string) $v;
}

function pos_sticker_ledger($v, bool $ar): string
{
    $map = [
        'after_sale' => $ar ? 'بعد Approved من المضيف' : 'After host Approved',
        'never_hold' => $ar ? 'لا — حجز فقط' : 'No — hold only',
        'after_capture' => $ar ? 'بعد قبض ناجح فقط' : 'After successful capture only',
        'no_new' => $ar ? 'لا USDT جديد' : 'No new USDT',
        'after_accept' => $ar ? 'بعد قبول الحوالة' : 'After transfer accept',
        'mode' => $ar ? 'قبض فقط لا الحجز' : 'Capture only, not hold',
    ];
    return $map[$v] ?? (string) $v;
}

function pos_render_ops_sticker(bool $ar, array $txnTypes): void
{
    ?>
    <div class="ops-sticker" id="opsSticker">
      <div class="ops-sticker-head"><?=$ar ? 'ملصق التشغيل — الحقول لكل نوع' : 'Operator sticker — fields by type'?></div>
      <p class="ops-sticker-wait"><?=$ar
        ? 'زر الدفع: انتظر رد المضيف (Approved / Declined). لا تُغلق الشاشة أثناء المعالجة.'
        : 'Pay button: wait for the host (Approved / Declined). Do not close the screen while processing.'?></p>
      <div class="ops-sticker-wrap">
        <table>
          <thead>
            <tr>
              <th><?=$ar?'العملية':'Operation'?></th>
              <th><?=$ar?'بطاقة':'PAN'?></th>
              <th><?=$ar?'انتهاء':'Exp'?></th>
              <th>CVV</th>
              <th>OTP</th>
              <th>RRN 12</th>
              <th>Approval</th>
              <th>Payment ID</th>
              <th><?=$ar?'المبلغ':'Amount'?></th>
              <th>Ledger</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach (pos_ops_sticker_rows() as $row):
                $meta = $txnTypes[$row['op']] ?? null;
                $label = $meta ? ($ar ? ($meta['ar'] ?? $row['op']) : ($meta['en'] ?? $row['op'])) : $row['op'];
                $yesClass = static function ($v) {
                    return $v === true ? ' is-yes' : ($v === false ? ' is-no' : ' is-mode');
                };
            ?>
            <tr data-sticker-op="<?= htmlspecialchars($row['op']) ?>" style="cursor:pointer" onclick="if(window.selectTxnType)selectTxnType('<?= htmlspecialchars($row['op']) ?>')">
              <td><?= htmlspecialchars($label) ?></td>
              <td class="<?= $yesClass($row['card']) ?>"><?= pos_sticker_cell($row['card'], $ar) ?></td>
              <td class="<?= $yesClass($row['exp']) ?>"><?= pos_sticker_cell($row['exp'], $ar) ?></td>
              <td class="<?= $yesClass($row['cvv']) ?>"><?= pos_sticker_cell($row['cvv'], $ar) ?></td>
              <td class="<?= $yesClass($row['otp']) ?>"><?= pos_sticker_cell($row['otp'], $ar) ?></td>
              <td class="<?= $yesClass($row['rrn']) ?>"><?= pos_sticker_cell($row['rrn'], $ar) ?></td>
              <td class="<?= $yesClass($row['appr']) ?>"><?= pos_sticker_cell($row['appr'], $ar) ?></td>
              <td class="<?= $yesClass($row['pid']) ?>"><?= pos_sticker_cell($row['pid'], $ar) ?></td>
              <td><?= htmlspecialchars(pos_sticker_amt($row['amt'], $ar)) ?></td>
              <td><?= htmlspecialchars(pos_sticker_ledger($row['ledger'], $ar)) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php
}

/**
 * @return list<array{id:string,match:string,title_ar:string,title_en:string,reason_ar:string,reason_en:string,fix_ar:string,fix_en:string,method_ar:string,method_en:string}>
 */
function pos_error_playbook(): array
{
    return [
        ['id' => 'wait_host', 'match' => 'PROCESSING|host', 'title_ar' => 'انتظار رد المضيف', 'title_en' => 'Waiting for host',
            'reason_ar' => 'الطلب أُرسل للبوابة ولم يعد الرد بعد.',
            'reason_en' => 'The request was sent to the gateway and the host has not answered yet.',
            'fix_ar' => 'انتظر على الشاشة. لا تضغط الدفع مرتين. إن طال الانتظار راجع الاتصال والمفاتيح.',
            'fix_en' => 'Stay on the screen. Do not tap Pay twice. If it hangs, check network and keys.',
            'method_ar' => 'زر الدفع → ChargeHub → HTTPS للمزود → Approved أو Declined.',
            'method_en' => 'Pay → ChargeHub → HTTPS to provider → Approved or Declined.'],
        ['id' => '1507', 'match' => '1507', 'title_ar' => 'رفض المصدر (1507)', 'title_en' => 'Issuer decline (1507)',
            'reason_ar' => 'بنك حامل البطاقة رفض 2D/MOTO غالباً بدون OTP.',
            'reason_en' => 'The card issuer refused 2D/MOTO, often without OTP.',
            'fix_ar' => 'استخدم شراء 3D بمبلغ صغير حتى يصل OTP.',
            'fix_en' => 'Use Purchase 3D with a small amount so the bank can send OTP.',
            'method_ar' => 'purchase_3d + بطاقة + CVV + إكمال صفحة البنك.',
            'method_en' => 'purchase_3d + PAN + CVV + complete bank page.'],
        ['id' => '1011', 'match' => '1011|Invalid card', 'title_ar' => 'رقم بطاقة غير صالح', 'title_en' => 'Invalid card number',
            'reason_ar' => 'PAN ناقص أو خاطئ أو بطاقة اختبار مرفوضة.',
            'reason_en' => 'PAN is short, wrong, or a rejected test card.',
            'fix_ar' => 'أدخل رقماً حياً 13–19 خانة. بطاقات 4111/4242 مرفوضة.',
            'fix_en' => 'Enter a live 13–19 digit PAN. 4111/4242 test cards are rejected.',
            'method_ar' => 'حقل رقم البطاقة في الوضع مانول أو تمرير فيزيكل.',
            'method_en' => 'Card number field in Manual, or physical swipe.'],
        ['id' => '1007', 'match' => '1007|Expired', 'title_ar' => 'بطاقة منتهية', 'title_en' => 'Expired card',
            'reason_ar' => 'تاريخ الانتهاء لا يطابق البطاقة أو منتهٍ.',
            'reason_en' => 'Expiry does not match the card or is past.',
            'fix_ar' => 'أدخل MM/YY كما على البطاقة.',
            'fix_en' => 'Enter MM/YY exactly as on the card.',
            'method_ar' => 'حقل الانتهاء مطلوب في كل بيع ببطاقة.',
            'method_en' => 'Expiry is required on every card sale.'],
        ['id' => '1106', 'match' => '1106|Insufficient', 'title_ar' => 'رصيد غير كافٍ', 'title_en' => 'Insufficient funds',
            'reason_ar' => 'حساب البطاقة لا يغطي المبلغ.',
            'reason_en' => 'The card account cannot cover the amount.',
            'fix_ar' => 'خفّض المبلغ أو استخدم بطاقة أخرى. ليس عطل POS.',
            'fix_en' => 'Lower the amount or use another card. Not a POS fault.',
            'method_ar' => 'المضيف Declined — لا Ledger.',
            'method_en' => 'Host Declined — no Ledger.'],
        ['id' => 'gateway_pick', 'match' => 'Pick an enabled|اختر بوابة', 'title_ar' => 'لا بوابة مفعّلة', 'title_en' => 'No live gateway',
            'reason_ar' => 'لم تُختر بوابة ظاهرة ومتصلة، أو اختيرت وجهة Ledger كمعالج.',
            'reason_en' => 'No visible connected gateway, or Ledger was picked as a charger.',
            'fix_ar' => 'من محور POS اختر بوابة متصلة (Nuvei/Stripe/…). Ledger للاستلام فقط.',
            'fix_en' => 'On the POS hub pick a connected gateway. Ledger is receive-only.',
            'method_ar' => 'قائمة البوابات الحية → فتح POS.',
            'method_en' => 'Live gateway list → Open POS.'],
        ['id' => 'csrf', 'match' => 'CSRF|رمز الأمان', 'title_ar' => 'رمز الأمان', 'title_en' => 'Security token',
            'reason_ar' => 'انتهت الجلسة أو النافذة قديمة.',
            'reason_en' => 'Session expired or the page is stale.',
            'fix_ar' => 'حدّث الصفحة وسجّل الدخول ثم أعد العملية.',
            'fix_en' => 'Refresh, sign in, and retry.',
            'method_ar' => 'csrf_token في طلب /pos/api/transaction.php',
            'method_en' => 'csrf_token on /pos/api/transaction.php'],
        ['id' => 'rrn', 'match' => 'RRN', 'title_ar' => 'RRN غير مكتمل', 'title_en' => 'RRN incomplete',
            'reason_ar' => 'Capture/Advice/Refund تحتاج RRN من 12 رقماً.',
            'reason_en' => 'Capture/Advice/Refund need a 12-digit RRN.',
            'fix_ar' => 'انسخ RRN من إيصال الحجز أو لوحة البنك/البوابة.',
            'fix_en' => 'Copy RRN from the AUTH receipt or bank/gateway panel.',
            'method_ar' => 'حقل RRN في ملصق التشغيل = نعم.',
            'method_en' => 'RRN field on the sticker = Yes.'],
        ['id' => 'capture_amt', 'match' => 'withdraw amount|مبلغ السحب', 'title_ar' => 'مبلغ إتمام الحجز', 'title_en' => 'Capture amount missing',
            'reason_ar' => 'Capture يحتاج مبلغ قبض: أقل أو مساوٍ أو أكثر من الحجز.',
            'reason_en' => 'Capture needs a capture amount: less, same, or more than the hold.',
            'fix_ar' => 'اختر الحجز ثم أدخل مبلغ الإتمام في الحقل الجديد.',
            'fix_en' => 'Pick the hold then enter the completion amount in the new field.',
            'method_ar' => 'AUTH أولاً → Capture بمبلغ الإتمام → Ledger بعد Approved.',
            'method_en' => 'AUTH first → Capture with completion amount → Ledger after Approved.'],
        ['id' => '3ds', 'match' => '3DS|redirect', 'title_ar' => '3DS مطلوب', 'title_en' => '3DS required',
            'reason_ar' => 'المضيف لم يقبض بعد؛ ينتظر OTP البنك.',
            'reason_en' => 'The host has not captured yet; bank OTP is pending.',
            'fix_ar' => 'أكمل الصفحة. لا Ledger قبل العودة Approved.',
            'fix_en' => 'Finish the bank page. No Ledger before Approved return.',
            'method_ar' => 'شراء 3D فقط.',
            'method_en' => 'Purchase 3D only.'],
        ['id' => 'ledger_hold', 'match' => 'hold only|حجز فقط', 'title_ar' => 'لا Ledger بعد الحجز', 'title_en' => 'No Ledger after hold',
            'reason_ar' => 'AUTH يحجز ولا يخصم. التحويل على السلسلة بعد Capture ناجح.',
            'reason_en' => 'AUTH holds funds. On-chain transfer runs after a successful Capture.',
            'fix_ar' => 'نفّذ AUTH Capture بمبلغ الإتمام ثم راقب مسار لايف.',
            'fix_en' => 'Run AUTH Capture with the completion amount, then watch the live trail.',
            'method_ar' => 'ledger_status يبقى فارغاً حتى القبض.',
            'method_en' => 'ledger_status stays empty until capture.'],
        ['id' => 'ledger_queue', 'match' => 'queued|الانتظار', 'title_ar' => 'USDT في الطابور', 'title_en' => 'USDT queued',
            'reason_ar' => 'البطاقة وافقت لكن المحفظة الساخنة لم ترسل السلسلة بعد.',
            'reason_en' => 'The card approved but the hot wallet has not sent on-chain yet.',
            'fix_ar' => 'تحقق من رصيد TRX/USDT ومفتاح المحفظة الساخنة ثم أعد التحويل من الإيصال.',
            'fix_en' => 'Check hot-wallet TRX/USDT and key, then retry transfer from the receipt.',
            'method_ar' => 'HotWalletService → dp_ledger_transfer_queue',
            'method_en' => 'HotWalletService → dp_ledger_transfer_queue'],
        ['id' => 'cvv', 'match' => 'Enter CVV|أدخل CVV', 'title_ar' => 'CVV مطلوب', 'title_en' => 'CVV required',
            'reason_ar' => 'هذا النوع في الملصق: CVV = نعم (شراء 2D/3D).',
            'reason_en' => 'This type on the sticker: CVV = Yes (2D/3D purchase).',
            'fix_ar' => 'أدخل 3 أو 4 أرقام. Capture وAdvice بدون CVV.',
            'fix_en' => 'Enter 3 or 4 digits. Capture and Advice have no CVV.',
            'method_ar' => 'حقل CVV قبل زر الدفع.',
            'method_en' => 'CVV field before Pay.'],
        ['id' => 'host_down', 'match' => 'Connection error|الاتصال بالسيرفر|Charge hub', 'title_ar' => 'لا رد من السيرفر/البوابة', 'title_en' => 'No host/server reply',
            'reason_ar' => 'انقطع HTTPS أو المفاتيح أو ChargeHub.',
            'reason_en' => 'HTTPS, keys, or ChargeHub failed.',
            'fix_ar' => 'تحقق من الشبكة وSITE_URL والمفاتيح الحية.',
            'fix_en' => 'Check network, SITE_URL, and live keys.',
            'method_ar' => 'زر الدفع ينتظر Approved/Declined من المضيف فقط.',
            'method_en' => 'Pay waits only for host Approved/Declined.'],
    ];
}

function pos_match_error_playbook(string $text): ?array
{
    foreach (pos_error_playbook() as $row) {
        if (@preg_match('/' . $row['match'] . '/iu', $text)) {
            return $row;
        }
    }
    return null;
}
