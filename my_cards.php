<?php
/**
 * DI PARMA | my_cards.php — إدارة البطاقات المحفوظة
 */
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/lib/SavedPaymentService.php';

$userId    = intval($_SESSION['user_id'] ?? 0);
$csrfToken = generateCsrfToken();
$svc       = SavedPaymentService::getInstance();
$cards     = $svc->getUserCards($userId);

$currentLang = (isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'en') ? 'en' : 'ar';
$pageDir     = $currentLang === 'en' ? 'ltr' : 'rtl';

$brandIcon = function(string $brand): string {
    return match(strtolower($brand)) {
        'visa'       => 'fab fa-cc-visa',
        'mastercard' => 'fab fa-cc-mastercard',
        'amex'       => 'fab fa-cc-amex',
        default      => 'fas fa-credit-card',
    };
};
$brandColor = function(string $brand): string {
    return match(strtolower($brand)) {
        'visa'       => '#1a1f71',
        'mastercard' => '#eb001b',
        'amex'       => '#007bc1',
        default      => 'var(--gold)',
    };
};
?>
<!DOCTYPE html>
<html lang="<?= $currentLang ?>" dir="<?= $pageDir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA | <?= $currentLang==='en'?'Saved Cards':'بطاقاتي المحفوظة' ?></title>
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<?php if (!empty(getenv('STRIPE_PUBLIC_KEY'))): ?>
<script src="https://js.stripe.com/v3/"></script>
<?php endif; ?>
<style>
body { background:var(--bg-dark); color:var(--text-light); font-family:Cairo,sans-serif; }
.wrap { max-width:700px; margin:32px auto; padding:0 20px; }
.card-item { background:var(--bg-card); border:1.5px solid var(--border-gold);
    border-radius:16px; padding:20px 24px; margin-bottom:16px;
    display:flex; align-items:center; gap:16px; transition:border-color .2s; }
.card-item:hover { border-color:var(--gold); }
.card-item.is-default { border-color:var(--gold); background:rgba(255,215,0,.04); }
.card-brand-icon { font-size:2.2rem; width:48px; text-align:center; }
.card-info { flex:1; }
.card-number { font-size:1.1rem; font-weight:700; letter-spacing:2px; }
.card-meta { color:var(--text-muted); font-size:.82rem; margin-top:4px; }
.action-btn { padding:7px 16px; border-radius:10px; border:none; cursor:pointer;
    font-size:.82rem; font-weight:600; transition:all .2s; }
.btn-default { background:rgba(255,215,0,.12); color:var(--gold); border:1px solid var(--border-gold); }
.btn-delete  { background:rgba(239,83,80,.12);  color:#ef5350; border:1px solid rgba(239,83,80,.3); }
.btn-delete:hover { background:rgba(239,83,80,.25); }
.add-card-btn { width:100%; padding:16px; border-radius:14px; border:2px dashed rgba(255,215,0,.3);
    background:transparent; color:var(--text-muted); font-size:.95rem; cursor:pointer;
    transition:all .2s; display:flex; align-items:center; justify-content:center; gap:10px; }
.add-card-btn:hover { border-color:var(--gold); color:var(--gold); background:rgba(255,215,0,.05); }
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.7);
    z-index:9999; align-items:center; justify-content:center; }
.modal-box { background:var(--bg-card); border:1px solid var(--border-gold);
    border-radius:20px; padding:32px; width:440px; max-width:90vw; }
.stripe-element { padding:14px 16px; background:rgba(255,255,255,.04);
    border:1.5px solid var(--border-gold); border-radius:11px; }
</style>
</head>
<body>
<nav style="background:rgba(0,0,0,.85);border-bottom:1px solid var(--border-gold);
    padding:14px 28px;display:flex;align-items:center;justify-content:space-between">
    <span style="color:var(--gold);font-weight:800;font-size:1.1rem">
        <i class="fas fa-wallet" style="margin-left:8px"></i>
        <?= $currentLang==='en'?'Saved Cards':'بطاقاتي المحفوظة' ?>
    </span>
    <a href="checkout.php" style="color:var(--text-muted);font-size:.85rem;text-decoration:none">
        <i class="fas fa-arrow-right"></i> <?= $currentLang==='en'?'Back to Checkout':'العودة للدفع' ?>
    </a>
</nav>

<div class="wrap">

    <?php if (empty($cards)): ?>
    <div style="text-align:center;padding:48px 20px;background:var(--bg-card);border:1px dashed rgba(255,215,0,.2);border-radius:20px;margin-bottom:24px">
        <i class="fas fa-credit-card" style="font-size:3rem;color:rgba(255,215,0,.3);margin-bottom:16px;display:block"></i>
        <h3 style="color:var(--text-muted);margin:0 0 8px">
            <?= $currentLang==='en'?'No saved cards yet':'لا توجد بطاقات محفوظة بعد' ?>
        </h3>
        <p style="color:var(--text-muted);font-size:.85rem;margin:0">
            <?= $currentLang==='en'
                ?'Save your card during checkout to pay without OTP next time'
                :'احفظ بطاقتك أثناء الدفع لتدفع بدون OTP في المرة القادمة' ?>
        </p>
    </div>
    <?php else: ?>

    <p style="color:var(--text-muted);font-size:.85rem;margin:0 0 20px">
        <i class="fas fa-shield-halved" style="color:#4CAF50"></i>
        <?= $currentLang==='en'
            ?'Your saved cards allow you to pay without OTP verification'
            :'بطاقاتك المحفوظة تتيح لك الدفع بدون رمز OTP من البنك' ?>
    </p>

    <?php foreach ($cards as $card): ?>
    <div class="card-item <?= $card['is_default'] ? 'is-default' : '' ?>" id="cardItem_<?= $card['id'] ?>">
        <div class="card-brand-icon">
            <i class="<?= $brandIcon($card['card_brand']) ?>"
               style="color:<?= $brandColor($card['card_brand']) ?>"></i>
        </div>
        <div class="card-info">
            <div class="card-number">
                <?= strtoupper($card['card_brand']) ?> •••• •••• •••• <?= htmlspecialchars($card['card_last4']) ?>
            </div>
            <div class="card-meta">
                <?= htmlspecialchars($card['gateway']) ?>
                <?= $card['card_expiry'] ? ' · ' . htmlspecialchars($card['card_expiry']) : '' ?>
                <?php if ($card['is_default']): ?>
                <span style="background:rgba(255,215,0,.15);color:var(--gold);padding:2px 8px;border-radius:10px;font-size:.72rem;margin-right:6px">
                    <i class="fas fa-star"></i> <?= $currentLang==='en'?'Default':'افتراضي' ?>
                </span>
                <?php endif; ?>
                <span style="color:#4CAF50;font-size:.78rem">
                    <i class="fas fa-bolt"></i> <?= $currentLang==='en'?'No OTP':'بدون OTP' ?>
                </span>
            </div>
        </div>
        <div style="display:flex;gap:8px">
            <?php if (!$card['is_default']): ?>
            <button class="action-btn btn-default" onclick="setDefault(<?= $card['id'] ?>)">
                <i class="fas fa-star"></i> <?= $currentLang==='en'?'Set Default':'افتراضي' ?>
            </button>
            <?php endif; ?>
            <button class="action-btn btn-delete" onclick="deleteCard(<?= $card['id'] ?>)">
                <i class="fas fa-trash"></i>
            </button>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <!-- إضافة بطاقة جديدة عبر Stripe -->
    <?php if (!empty(getenv('STRIPE_PUBLIC_KEY'))): ?>
    <button class="add-card-btn" onclick="showAddCard()">
        <i class="fas fa-plus-circle" style="color:var(--gold)"></i>
        <?= $currentLang==='en'?'Add New Card (Stripe)':'إضافة بطاقة جديدة (Stripe)' ?>
    </button>
    <?php endif; ?>

</div><!-- wrap -->

<!-- Modal إضافة بطاقة -->
<div id="addCardModal" class="modal-overlay">
    <div class="modal-box">
        <h3 style="color:var(--gold);margin:0 0 20px">
            <i class="fas fa-plus-circle" style="margin-left:8px"></i>
            <?= $currentLang==='en'?'Add New Card':'إضافة بطاقة جديدة' ?>
        </h3>
        <div class="stripe-element" id="add-card-element"></div>
        <div id="add-card-error" style="color:#ef5350;font-size:.82rem;margin-top:6px"></div>
        <div style="display:flex;gap:12px;margin-top:24px">
            <button onclick="confirmAddCard()" style="flex:1;padding:13px;border-radius:12px;
                background:var(--gold-gradient);color:#000;border:none;font-weight:700;cursor:pointer">
                <i class="fas fa-save"></i> <?= $currentLang==='en'?'Save Card':'حفظ البطاقة' ?>
            </button>
            <button onclick="hideAddCard()" style="flex:1;padding:13px;border-radius:12px;
                background:rgba(255,255,255,.05);color:var(--text-muted);border:1px solid var(--border-light);cursor:pointer">
                <?= $currentLang==='en'?'Cancel':'إلغاء' ?>
            </button>
        </div>
    </div>
</div>

<div id="toast" style="position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(80px);
    background:rgba(10,16,39,.97);border:1px solid var(--border-gold);border-radius:12px;
    padding:12px 24px;color:var(--text-light);font-size:.9rem;z-index:10000;
    transition:transform .3s;white-space:nowrap"></div>

<script>
var CSRF_TOKEN = '<?= $csrfToken ?>';
var stripe = null, addCardElement = null;

<?php if (!empty(getenv('STRIPE_PUBLIC_KEY'))): ?>
stripe = Stripe('<?= addslashes(getenv('STRIPE_PUBLIC_KEY')) ?>');
<?php endif; ?>

function showAddCard() {
    if (!stripe) { showToast('Stripe غير متاح', 'error'); return; }
    document.getElementById('addCardModal').style.display = 'flex';
    if (!addCardElement) {
        var elements = stripe.elements();
        addCardElement = elements.create('card', {
            style: {
                base: {color:'#fff', fontFamily:'Cairo,sans-serif', fontSize:'15px',
                       '::placeholder':{color:'#888'}},
                invalid: {color:'#ef5350'}
            },
            hidePostalCode: true
        });
        addCardElement.mount('#add-card-element');
        addCardElement.on('change', function(e){
            document.getElementById('add-card-error').textContent = e.error ? e.error.message : '';
        });
    }
}

function hideAddCard() {
    document.getElementById('addCardModal').style.display = 'none';
}

async function confirmAddCard() {
    // جلب SetupIntent
    var email = '<?= addslashes($_SESSION['user_data']['email'] ?? '') ?>';
    var r1 = await fetch('api/saved_cards.php?action=setup_stripe', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({email: email, csrf_token: CSRF_TOKEN})
    });
    var d1 = await r1.json();
    if (!d1.success) { showToast(d1.message, 'error'); return; }

    // تأكيد SetupIntent
    var res = await stripe.confirmCardSetup(d1.client_secret, {
        payment_method: {card: addCardElement}
    });
    if (res.error) {
        document.getElementById('add-card-error').textContent = res.error.message;
        return;
    }

    // حفظ البطاقة
    var r2 = await fetch('api/saved_cards.php?action=save_stripe', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({
            payment_method_id: res.setupIntent.payment_method,
            customer_id: d1.customer_id,
            csrf_token: CSRF_TOKEN
        })
    });
    var d2 = await r2.json();
    if (d2.success) {
        showToast('تم حفظ البطاقة بنجاح ✓', 'success');
        setTimeout(function(){ window.location.reload(); }, 1500);
    } else {
        showToast(d2.message || 'فشل الحفظ', 'error');
    }
    hideAddCard();
}

async function setDefault(cardId) {
    var r = await fetch('api/saved_cards.php?action=set_default', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({card_id: cardId, csrf_token: CSRF_TOKEN})
    });
    var d = await r.json();
    if (d.success) { showToast('تم التعيين كافتراضي', 'success'); setTimeout(function(){ window.location.reload(); }, 1000); }
    else showToast(d.message, 'error');
}

async function deleteCard(cardId) {
    if (!confirm('<?= $currentLang==='en'?'Delete this card?':'حذف هذه البطاقة؟' ?>')) return;
    var r = await fetch('api/saved_cards.php?action=delete', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({card_id: cardId, csrf_token: CSRF_TOKEN})
    });
    var d = await r.json();
    if (d.success) {
        var el = document.getElementById('cardItem_' + cardId);
        if (el) { el.style.opacity='0'; el.style.transform='scale(.95)'; setTimeout(function(){ el.remove(); }, 300); }
        showToast('تم الحذف', 'success');
    } else {
        showToast(d.message, 'error');
    }
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
