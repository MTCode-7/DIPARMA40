<?php
/**
 * DI PARMA | holds.php — إدارة عمليات الحجز
 */
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/lib/HoldCaptureService.php';

$userId    = intval($_SESSION['user_id'] ?? 0);
$csrfToken = generateCsrfToken();
$svc       = HoldCaptureService::getInstance();
$db        = db();

$currentLang = (isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'ar') ? 'ar' : 'en';
$pageDir     = $currentLang === 'en' ? 'ltr' : 'rtl';

// نتيجة حجز جديد
$newRef = $_GET['ref'] ?? '';
$newPI  = $_GET['pi']  ?? '';
$newStatus = $_GET['status'] ?? '';

$holds = $svc->getUserHolds($userId);
$paypalHolds = $db->select('transactions', ['reference', 'amount', 'currency', 'status', 'gateway_response', 'created_at'], [
    'user_id' => $userId,
    'gateway' => 'paypal',
    'status' => 'authorized',
], ['created_at' => 'DESC']);

$statusConfig = [
    'pending'    => ['color'=>'#f0ad4e', 'label_ar'=>'قيد الانتظار',   'label_en'=>'Pending'],
    'authorized' => ['color'=>'#5bc0de', 'label_ar'=>'محجوز ✓',        'label_en'=>'Authorized ✓'],
    'captured'   => ['color'=>'#4CAF50', 'label_ar'=>'تم التحصيل ✓',   'label_en'=>'Captured ✓'],
    'cancelled'  => ['color'=>'#ef5350', 'label_ar'=>'ملغي',            'label_en'=>'Cancelled'],
    'expired'    => ['color'=>'#888',    'label_ar'=>'منتهي الصلاحية',  'label_en'=>'Expired'],
];
?>
<!DOCTYPE html>
<html lang="<?= $currentLang ?>" dir="<?= $pageDir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA | <?= $currentLang==='en'?'My Holds':'حجوزاتي' ?></title>
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
body{background:var(--bg-dark);color:var(--text-light);font-family:Cairo,sans-serif;}
.wrap{max-width:900px;margin:32px auto;padding:0 20px;}
.hold-card{background:var(--bg-card);border:1.5px solid var(--border-gold);border-radius:16px;padding:20px 24px;margin-bottom:16px;transition:border-color .2s;}
.hold-card.authorized{border-color:#5bc0de;}
.hold-card.captured{border-color:#4CAF50;}
.hold-card.cancelled{border-color:#ef5350;opacity:.7;}
.badge{padding:4px 12px;border-radius:20px;font-size:.75rem;font-weight:700;}
.action-btn{padding:9px 18px;border-radius:10px;border:none;cursor:pointer;font-size:.85rem;font-weight:700;transition:all .2s;}
.btn-capture{background:rgba(76,175,80,.2);color:#4CAF50;border:1px solid rgba(76,175,80,.3);}
.btn-capture:hover{background:rgba(76,175,80,.35);}
.btn-cancel{background:rgba(239,83,80,.15);color:#ef5350;border:1px solid rgba(239,83,80,.3);}
.btn-cancel:hover{background:rgba(239,83,80,.3);}
.pi-code{font-family:monospace;font-size:.78rem;background:rgba(255,255,255,.05);padding:4px 8px;border-radius:6px;word-break:break-all;}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:9999;align-items:center;justify-content:center;}
.modal-box{background:var(--bg-card);border:1px solid var(--border-gold);border-radius:20px;padding:28px;width:420px;max-width:90vw;}
</style>
</head>
<body>
<nav style="background:rgba(0,0,0,.85);border-bottom:1px solid var(--border-gold);padding:14px 28px;display:flex;align-items:center;justify-content:space-between">
    <span style="color:var(--gold);font-weight:800;font-size:1.1rem">
        <i class="fas fa-hand-holding-usd" style="margin-left:8px"></i>
        <?= $currentLang==='en'?'My Holds (HOLD/CAPTURE)':'حجوزاتي (HOLD/CAPTURE)' ?>
    </span>
    <div style="display:flex;gap:10px">
        <a href="checkout_router.php" style="color:var(--text-muted);font-size:.85rem;text-decoration:none;padding:7px 14px;border:1px solid var(--border-light);border-radius:8px">
            <i class="fas fa-plus"></i> <?= $currentLang==='en'?'New':'جديد' ?>
        </a>
        <a href="dashboard.php" style="color:var(--text-muted);font-size:.85rem;text-decoration:none">
            <i class="fas fa-arrow-right"></i>
        </a>
    </div>
</nav>

<div class="wrap">

<?php if (!empty($paypalHolds)): ?>
<div style="background:rgba(0,48,135,.08);border:1.5px solid rgba(77,166,255,.35);border-radius:16px;padding:20px 24px;margin-bottom:24px">
    <h3 style="color:#4da6ff;margin:0 0 14px"><i class="fab fa-paypal"></i> PayPal Authorization Holds</h3>
    <?php foreach ($paypalHolds as $paypalHold):
        $paypalMeta = json_decode($paypalHold['gateway_response'] ?? '{}', true) ?: [];
        $authorizationId = $paypalMeta['authorization_id'] ?? '';
    ?>
    <div style="background:rgba(255,255,255,.03);border:1px solid rgba(77,166,255,.2);border-radius:12px;padding:14px;margin-top:10px">
        <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center">
            <div>
                <strong style="color:var(--gold)"><?= htmlspecialchars($paypalHold['reference']) ?></strong>
                <span style="color:var(--text-muted);margin-right:10px"><?= number_format((float)$paypalHold['amount'], 2) ?> <?= htmlspecialchars($paypalHold['currency']) ?></span>
                <div style="color:var(--text-muted);font-size:.75rem;margin-top:6px">Authorization ID: <span class="pi-code"><?= htmlspecialchars($authorizationId) ?></span></div>
            </div>
            <?php if ($authorizationId): ?>
            <button class="action-btn btn-capture" onclick="capturePayPalHold('<?= addslashes($authorizationId) ?>','<?= addslashes($paypalHold['reference']) ?>')">
                <i class="fas fa-check-double"></i> Capture PayPal
            </button>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- رسالة نجاح الحجز الجديد -->
<?php if ($newStatus === 'authorized' && $newPI): ?>
<div style="background:rgba(91,192,222,.1);border:1.5px solid #5bc0de;border-radius:16px;padding:20px 24px;margin-bottom:24px">
    <h3 style="color:#5bc0de;margin:0 0 8px">
        <i class="fas fa-check-circle"></i>
        <?= $currentLang==='en'?'Hold Authorized Successfully!':'تم تأكيد الحجز بنجاح!' ?>
    </h3>
    <p style="color:var(--text-muted);margin:0 0 12px;font-size:.9rem">
        <?= $currentLang==='en'
            ?'The amount is reserved but NOT charged yet. You can capture or cancel it.'
            :'المبلغ محجوز ولم يُخصم بعد. يمكنك التحصيل أو الإلغاء.' ?>
    </p>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button onclick="captureHold('<?= addslashes($newPI) ?>')"
            class="action-btn btn-capture">
            <i class="fas fa-check-double"></i>
            <?= $currentLang==='en'?'Capture Now (Send USDT)':'تحصيل الآن (إرسال USDT)' ?>
        </button>
        <button onclick="cancelHold('<?= addslashes($newPI) ?>')"
            class="action-btn btn-cancel">
            <i class="fas fa-times"></i>
            <?= $currentLang==='en'?'Cancel Hold':'إلغاء الحجز' ?>
        </button>
    </div>
    <div class="pi-code" style="margin-top:12px">PI: <?= htmlspecialchars($newPI) ?></div>
</div>
<?php endif; ?>

<!-- شرح الخدمة -->
<div style="background:rgba(255,215,0,.05);border:1px solid rgba(255,215,0,.15);border-radius:14px;padding:16px 20px;margin-bottom:24px">
    <h4 style="color:var(--gold);margin:0 0 10px;font-size:.9rem">
        <i class="fas fa-info-circle"></i>
        <?= $currentLang==='en'?'How Hold/Capture Works:':'كيف يعمل الحجز والتحصيل:' ?>
    </h4>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px">
        <?php foreach ([
            ['fa-lock',         '#5bc0de', $currentLang==='en'?'1. HOLD':'1. الحجز',       $currentLang==='en'?'Reserve amount without charging':'حجز المبلغ بدون خصم'],
            ['fa-check-double', '#4CAF50', $currentLang==='en'?'2. CAPTURE':'2. التحصيل', $currentLang==='en'?'Charge & send USDT':'الخصم وإرسال USDT'],
            ['fa-times',        '#ef5350', $currentLang==='en'?'3. CANCEL':'3. الإلغاء',  $currentLang==='en'?'Release without charge':'تحرير بدون خصم'],
        ] as [$ic,$c,$t,$d]): ?>
        <div style="background:rgba(255,255,255,.03);border-radius:10px;padding:12px">
            <div style="color:<?= $c ?>;font-weight:700;margin-bottom:4px">
                <i class="fas <?= $ic ?>"></i> <?= $t ?>
            </div>
            <div style="color:var(--text-muted);font-size:.8rem"><?= $d ?></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php if (empty($holds)): ?>
<div style="text-align:center;padding:48px;background:var(--bg-card);border:1px dashed rgba(255,215,0,.2);border-radius:20px">
    <i class="fas fa-hand-holding-usd" style="font-size:3rem;color:rgba(255,215,0,.2);display:block;margin-bottom:12px"></i>
    <p style="color:var(--text-muted)">
        <?= $currentLang==='en'?'No holds yet — use HOLD in checkout':'لا توجد حجوزات بعد — استخدم خيار الحجز في Checkout' ?>
    </p>
    <a href="checkout_router.php" style="display:inline-block;margin-top:12px;padding:10px 24px;background:var(--gold-gradient);color:#000;border-radius:10px;text-decoration:none;font-weight:700">
        <i class="fas fa-plus"></i> <?= $currentLang==='en'?'New Hold':'حجز جديد' ?>
    </a>
</div>
<?php else: ?>
<?php foreach ($holds as $hold):
    $sc = $statusConfig[$hold['status']] ?? $statusConfig['pending'];
    $label = $currentLang==='en' ? $sc['label_en'] : $sc['label_ar'];
    $meta  = json_decode($hold['meta'] ?? '{}', true);
    $canCapture = $hold['status'] === 'authorized';
    $canCancel  = in_array($hold['status'], ['authorized', 'pending']);
?>
<div class="hold-card <?= $hold['status'] ?>">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px">
        <div style="flex:1">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
                <span style="font-size:1.1rem;font-weight:700;color:var(--gold)"><?= htmlspecialchars($hold['reference']) ?></span>
                <span class="badge" style="background:<?= $sc['color'] ?>22;color:<?= $sc['color'] ?>;border:1px solid <?= $sc['color'] ?>44">
                    <?= $label ?>
                </span>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;margin-bottom:10px">
                <div><span style="color:var(--text-muted);font-size:.78rem">المبلغ</span><br>
                    <span style="color:var(--gold);font-weight:700"><?= number_format((float)$hold['amount'],2) ?> <?= $hold['currency'] ?></span></div>
                <?php if ($hold['captured_amount']): ?>
                <div><span style="color:var(--text-muted);font-size:.78rem">المُحصَّل</span><br>
                    <span style="color:#4CAF50;font-weight:700"><?= number_format((float)$hold['captured_amount'],2) ?> <?= $hold['currency'] ?></span></div>
                <?php endif; ?>
                <?php if (!empty($meta['crypto'])): ?>
                <div><span style="color:var(--text-muted);font-size:.78rem">Crypto</span><br>
                    <span><?= htmlspecialchars($meta['crypto']) ?>/<?= htmlspecialchars($meta['network']??'TRC20') ?></span></div>
                <?php endif; ?>
                <div><span style="color:var(--text-muted);font-size:.78rem">التاريخ</span><br>
                    <span style="font-size:.8rem"><?= $hold['created_at'] ?></span></div>
                <?php if ($hold['expires_at'] && $hold['status']==='authorized'): ?>
                <div><span style="color:var(--text-muted);font-size:.78rem">ينتهي</span><br>
                    <span style="color:#f0ad4e;font-size:.8rem"><?= $hold['expires_at'] ?></span></div>
                <?php endif; ?>
            </div>
            <div class="pi-code">PI: <?= htmlspecialchars($hold['payment_intent_id']) ?></div>
        </div>
        <?php if ($canCapture || $canCancel): ?>
        <div style="display:flex;flex-direction:column;gap:8px;min-width:140px">
            <?php if ($canCapture): ?>
            <button onclick="captureHold('<?= addslashes($hold['payment_intent_id']) ?>')"
                    class="action-btn btn-capture">
                <i class="fas fa-check-double"></i>
                <?= $currentLang==='en'?'Capture':'تحصيل' ?>
            </button>
            <button onclick="showPartialCapture('<?= addslashes($hold['payment_intent_id']) ?>',<?= $hold['amount'] ?>)"
                    style="padding:7px 14px;border-radius:10px;border:1px solid rgba(76,175,80,.3);background:transparent;color:#9fe870;cursor:pointer;font-size:.82rem">
                <i class="fas fa-scissors"></i>
                <?= $currentLang==='en'?'Partial':'جزئي' ?>
            </button>
            <?php endif; ?>
            <?php if ($canCancel): ?>
            <button onclick="cancelHold('<?= addslashes($hold['payment_intent_id']) ?>')"
                    class="action-btn btn-cancel">
                <i class="fas fa-times"></i>
                <?= $currentLang==='en'?'Cancel':'إلغاء' ?>
            </button>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

</div><!-- wrap -->

<!-- Modal Partial Capture -->
<div id="partialModal" class="modal-overlay">
    <div class="modal-box">
        <h3 style="color:var(--gold);margin:0 0 16px">تحصيل جزئي</h3>
        <input type="hidden" id="partialPI">
        <label style="color:var(--text-muted);font-size:.85rem;display:block;margin-bottom:6px">المبلغ الجزئي</label>
        <input type="number" id="partialAmount" step="0.01" placeholder="0.00"
               style="width:100%;padding:12px;background:rgba(255,255,255,.05);border:1px solid var(--border-gold);border-radius:10px;color:var(--text-light);font-size:1rem;margin-bottom:6px">
        <small id="partialMax" style="color:var(--text-muted);font-size:.78rem"></small>
        <div style="display:flex;gap:10px;margin-top:16px">
            <button onclick="confirmPartial()" class="action-btn btn-capture" style="flex:1;padding:11px">
                <i class="fas fa-check-double"></i> تأكيد التحصيل
            </button>
            <button onclick="document.getElementById('partialModal').style.display='none'"
                    style="flex:1;padding:11px;border-radius:10px;background:rgba(255,255,255,.05);color:var(--text-muted);border:1px solid var(--border-light);cursor:pointer">
                إلغاء
            </button>
        </div>
    </div>
</div>

<div id="toast" style="position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(80px);background:rgba(10,16,39,.97);border:1px solid var(--border-gold);border-radius:12px;padding:12px 24px;color:var(--text-light);font-size:.9rem;z-index:10000;transition:transform .3s;white-space:nowrap"></div>

<script>
var CSRF = '<?= $csrfToken ?>';

async function captureHold(pi, partial) {
    if (!confirm('تأكيد التحصيل وإرسال USDT؟')) return;
    var body = {payment_intent_id: pi, csrf_token: CSRF};
    if (partial) body.partial_amount = partial;
    var r = await fetch('api/hold_capture.php?action=capture', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify(body)
    });
    var d = await r.json();
    if (d.success) {
        showToast('تم التحصيل بنجاح ✓' + (d.crypto_sent ? ' + USDT أُرسل' : ''), 'success');
        setTimeout(function(){ location.reload(); }, 2000);
    } else {
        showToast(d.message || 'فشل التحصيل', 'error');
    }
}

async function capturePayPalHold(authorizationId, reference) {
    if (!confirm('تأكيد تحصيل حجز PayPal؟')) return;
    var r = await fetch('api/paypal.php?action=capture_authorization', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({authorization_id: authorizationId, reference: reference, csrf_token: CSRF})
    });
    var d = await r.json();
    if (d.success) {
        showToast('تم تحصيل PayPal بنجاح ✓', 'success');
        setTimeout(function(){ location.reload(); }, 1500);
    } else {
        showToast(d.message || 'فشل تحصيل PayPal', 'error');
    }
}

async function cancelHold(pi) {
    if (!confirm('إلغاء الحجز؟ سيُحرَّر المبلغ للعميل.')) return;
    var r = await fetch('api/hold_capture.php?action=cancel', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({payment_intent_id: pi, csrf_token: CSRF})
    });
    var d = await r.json();
    if (d.success) {
        showToast('تم إلغاء الحجز', 'success');
        setTimeout(function(){ location.reload(); }, 1500);
    } else {
        showToast(d.message || 'فشل الإلغاء', 'error');
    }
}

function showPartialCapture(pi, maxAmount) {
    document.getElementById('partialPI').value = pi;
    document.getElementById('partialAmount').value = '';
    document.getElementById('partialMax').textContent = 'الحد الأقصى: ' + maxAmount;
    document.getElementById('partialAmount').max = maxAmount;
    document.getElementById('partialModal').style.display = 'flex';
}

function confirmPartial() {
    var pi = document.getElementById('partialPI').value;
    var amt = parseFloat(document.getElementById('partialAmount').value);
    if (!amt || amt <= 0) { showToast('أدخل مبلغاً صحيحاً', 'warning'); return; }
    document.getElementById('partialModal').style.display = 'none';
    captureHold(pi, amt);
}

function showToast(msg, type) {
    var t = document.getElementById('toast');
    var c = {success:'#4CAF50',error:'#ef5350',warning:'#ff9800',info:'var(--gold)'};
    t.style.borderColor = c[type]||'var(--gold)';
    t.textContent = msg;
    t.style.transform = 'translateX(-50%) translateY(0)';
    setTimeout(function(){ t.style.transform='translateX(-50%) translateY(80px)'; }, 3500);
}
</script>
</body>
</html>
