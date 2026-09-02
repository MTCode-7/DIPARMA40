<?php
/**
 * ============================================================
 * DI PARMA | نظام الترجمة المركزي
 * ============================================================
 * الاستخدام في أي صفحة:
 *   require_once __DIR__ . '/includes/lang.php';
 *   echo __('buy_now');           // شراء الآن / Buy Now
 *   echo __('amount');            // المبلغ / Amount
 * ============================================================
 */

// قراءة اللغة الحالية
if (!isset($currentLang)) {
    $currentLang = (isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'ar') ? 'ar' : 'en';
}
if (!isset($pageDir)) {
    $pageDir = $currentLang === 'en' ? 'ltr' : 'rtl';
}

// ── مصفوفة الترجمات ────────────────────────────────────
$TRANSLATIONS = [

    // ── عام ──────────────────────────────────────────────
    'app_name'          => ['ar' => 'DI PARMA',                     'en' => 'DI PARMA'],
    'dashboard'         => ['ar' => 'لوحة التحكم',                   'en' => 'Dashboard'],
    'transactions'      => ['ar' => 'المعاملات',                     'en' => 'Transactions'],
    'history'           => ['ar' => 'سجل العمليات',                  'en' => 'History'],
    'logout'            => ['ar' => 'تسجيل الخروج',                  'en' => 'Logout'],
    'login'             => ['ar' => 'تسجيل الدخول',                  'en' => 'Login'],
    'language'          => ['ar' => 'اللغة',                         'en' => 'Language'],
    'save'              => ['ar' => 'حفظ',                           'en' => 'Save'],
    'cancel'            => ['ar' => 'إلغاء',                         'en' => 'Cancel'],
    'confirm'           => ['ar' => 'تأكيد',                         'en' => 'Confirm'],
    'search'            => ['ar' => 'بحث',                           'en' => 'Search'],
    'filter'            => ['ar' => 'فلتر',                          'en' => 'Filter'],
    'export'            => ['ar' => 'تصدير',                         'en' => 'Export'],
    'print'             => ['ar' => 'طباعة',                         'en' => 'Print'],
    'loading'           => ['ar' => 'جاري التحميل...',               'en' => 'Loading...'],
    'error'             => ['ar' => 'خطأ',                           'en' => 'Error'],
    'success'           => ['ar' => 'نجح',                           'en' => 'Success'],
    'warning'           => ['ar' => 'تحذير',                         'en' => 'Warning'],
    'back'              => ['ar' => 'رجوع',                          'en' => 'Back'],
    'next'              => ['ar' => 'التالي',                        'en' => 'Next'],
    'previous'          => ['ar' => 'السابق',                        'en' => 'Previous'],
    'all'               => ['ar' => 'الكل',                          'en' => 'All'],
    'date'              => ['ar' => 'التاريخ',                       'en' => 'Date'],
    'status'            => ['ar' => 'الحالة',                        'en' => 'Status'],
    'total'             => ['ar' => 'الإجمالي',                      'en' => 'Total'],

    // ── Checkout ──────────────────────────────────────────
    'checkout'          => ['ar' => 'الدفع',                         'en' => 'Checkout'],
    'secure_payment'    => ['ar' => 'دفع آمن',                       'en' => 'Secure Payment'],
    'amount'            => ['ar' => 'المبلغ',                        'en' => 'Amount'],
    'currency'          => ['ar' => 'العملة',                        'en' => 'Currency'],
    'crypto'            => ['ar' => 'العملة الرقمية',                'en' => 'Cryptocurrency'],
    'network'           => ['ar' => 'الشبكة',                        'en' => 'Network'],
    'wallet_address'    => ['ar' => 'عنوان المحفظة',                 'en' => 'Wallet Address'],
    'payment_gateway'   => ['ar' => 'بوابة الدفع',                   'en' => 'Payment Gateway'],
    'full_name'         => ['ar' => 'الاسم الكامل',                  'en' => 'Full Name'],
    'email'             => ['ar' => 'البريد الإلكتروني',             'en' => 'Email Address'],
    'card_number'       => ['ar' => 'رقم البطاقة',                   'en' => 'Card Number'],
    'expiry'            => ['ar' => 'تاريخ الانتهاء',                'en' => 'Expiry Date'],
    'cvv'               => ['ar' => 'CVV',                           'en' => 'CVV'],
    'buy_now'           => ['ar' => 'شراء الآن',                     'en' => 'Buy Now'],
    'pay_now'           => ['ar' => 'ادفع الآن',                     'en' => 'Pay Now'],
    'order_summary'     => ['ar' => 'ملخص الطلب',                   'en' => 'Order Summary'],
    'you_receive'       => ['ar' => 'ستستقبل',                       'en' => 'You Receive'],
    'platform_fee'      => ['ar' => 'رسوم المنصة',                   'en' => 'Platform Fee'],
    'rate'              => ['ar' => 'السعر',                         'en' => 'Rate'],
    'purchase_type'     => ['ar' => 'نوع الشراء',                    'en' => 'Purchase Type'],
    'security_level'    => ['ar' => 'مستوى الأمان',                  'en' => 'Security Level'],
    'direct_purchase'   => ['ar' => 'شراء مباشر',                    'en' => 'Direct Purchase'],
    'hold_authorize'    => ['ar' => 'حجز / تفويض',                   'en' => 'Hold / Authorize'],
    'settlement'        => ['ar' => 'تسوية / كابتشر',                'en' => 'Settlement / Capture'],
    'offline_billing'   => ['ar' => 'أوف لاين',                      'en' => 'Offline Billing'],
    'protected_by'      => ['ar' => 'محمي بـ',                       'en' => 'Protected by'],
    'no_limits'         => ['ar' => '∞ بلا حدود',                   'en' => '∞ No Limits'],
    'verified'          => ['ar' => 'موثّق ✓',                       'en' => 'Verified ✓'],
    'not_verified'      => ['ar' => 'غير مكتمل',                     'en' => 'Not Verified'],

    // ── Crypto ────────────────────────────────────────────
    'crypto_exchange'   => ['ar' => 'Crypto Exchange',               'en' => 'Crypto Exchange'],
    'buy_usdt'          => ['ar' => 'شراء USDT',                     'en' => 'Buy USDT'],
    'sell_usdt'         => ['ar' => 'بيع USDT',                      'en' => 'Sell USDT'],
    'buy'               => ['ar' => 'شراء',                          'en' => 'Buy'],
    'sell'              => ['ar' => 'بيع',                           'en' => 'Sell'],
    'live_rates'        => ['ar' => 'الأسعار الحية',                 'en' => 'Live Rates'],
    'my_wallet'         => ['ar' => 'محفظتي',                        'en' => 'My Wallet'],
    'create_wallet'     => ['ar' => 'إنشاء محفظة',                   'en' => 'Create Wallet'],
    'copy_address'      => ['ar' => 'نسخ العنوان',                   'en' => 'Copy Address'],
    'includes_margin'   => ['ar' => 'يشمل هامش منصة',               'en' => 'Includes platform margin'],
    'updates_every'     => ['ar' => 'يتحدث كل 30 ثانية',            'en' => 'Updates every 30 seconds'],
    'new_transaction'   => ['ar' => 'عملية جديدة',                   'en' => 'New Transaction'],

    // ── KYC ──────────────────────────────────────────────
    'kyc_verification'  => ['ar' => 'التحقق من الهوية',              'en' => 'KYC Verification'],
    'kyc_level'         => ['ar' => 'مستوى KYC',                     'en' => 'KYC Level'],
    'daily_limit'       => ['ar' => 'الحد اليومي',                   'en' => 'Daily Limit'],
    'monthly_limit'     => ['ar' => 'الحد الشهري',                   'en' => 'Monthly Limit'],
    'start_kyc'         => ['ar' => 'ابدأ التحقق',                   'en' => 'Start Verification'],
    'pending_review'    => ['ar' => 'قيد المراجعة',                  'en' => 'Pending Review'],
    'approved'          => ['ar' => 'موثّق',                         'en' => 'Approved'],
    'rejected'          => ['ar' => 'مرفوض',                         'en' => 'Rejected'],
    'kyc_required'      => ['ar' => 'التحقق مطلوب',                  'en' => 'Verification Required'],
    'complete_kyc'      => ['ar' => 'أكمل التحقق',                   'en' => 'Complete Verification'],

    // ── Admin ─────────────────────────────────────────────
    'admin_dashboard'   => ['ar' => 'لوحة الأدمن',                  'en' => 'Admin Dashboard'],
    'gateway_manager'   => ['ar' => 'إدارة البوابات',                'en' => 'Gateway Manager'],
    'audit_dashboard'   => ['ar' => 'سجل التدقيق',                   'en' => 'Audit Dashboard'],
    'aml_report'        => ['ar' => 'تقرير AML',                     'en' => 'AML Report'],
    'compliance_report' => ['ar' => 'تقرير الامتثال',                'en' => 'Compliance Report'],
    'crypto_admin'      => ['ar' => 'Crypto Admin',                  'en' => 'Crypto Admin'],
    'users'             => ['ar' => 'المستخدمون',                    'en' => 'Users'],
    'active'            => ['ar' => 'نشط',                           'en' => 'Active'],
    'inactive'          => ['ar' => 'غير نشط',                       'en' => 'Inactive'],
    'approve'           => ['ar' => 'قبول',                          'en' => 'Approve'],
    'reject'            => ['ar' => 'رفض',                           'en' => 'Reject'],

    // ── حالات المعاملات ───────────────────────────────────
    'completed'         => ['ar' => 'مكتمل',                         'en' => 'Completed'],
    'pending'           => ['ar' => 'قيد الانتظار',                  'en' => 'Pending'],
    'failed'            => ['ar' => 'فشل',                           'en' => 'Failed'],
    'processing'        => ['ar' => 'جاري المعالجة',                 'en' => 'Processing'],
    'refunded'          => ['ar' => 'مسترد',                         'en' => 'Refunded'],
    'authorized'        => ['ar' => 'مُفوَّض',                       'en' => 'Authorized'],

    // ── Audit / VARA ──────────────────────────────────────
    'vara_compliant'    => ['ar' => 'متوافق مع VARA',                'en' => 'VARA Compliant'],
    'audit_trail'       => ['ar' => 'سجل التدقيق',                   'en' => 'Audit Trail'],
    'from_date'         => ['ar' => 'من تاريخ',                      'en' => 'From Date'],
    'to_date'           => ['ar' => 'إلى تاريخ',                     'en' => 'To Date'],
    'export_csv'        => ['ar' => 'تصدير CSV',                     'en' => 'Export CSV'],
    'total_transactions'=> ['ar' => 'إجمالي العمليات',               'en' => 'Total Transactions'],
    'total_volume'      => ['ar' => 'حجم التداول',                   'en' => 'Trading Volume'],
    'high_risk'         => ['ar' => 'عالي المخاطر',                  'en' => 'High Risk'],
    'sar_candidates'    => ['ar' => 'مرشحة لـ SAR',                  'en' => 'SAR Candidates'],
    'last_updated'      => ['ar' => 'آخر تحديث',                     'en' => 'Last Updated'],
    'reference'         => ['ar' => 'المرجع',                        'en' => 'Reference'],
    'gateway'           => ['ar' => 'البوابة',                       'en' => 'Gateway'],
    'protocol'          => ['ar' => 'البروتوكول',                    'en' => 'Protocol'],
    'user'              => ['ar' => 'المستخدم',                      'en' => 'User'],
    'customer'          => ['ar' => 'العميل',                        'en' => 'Customer'],
    'fees'              => ['ar' => 'الرسوم',                        'en' => 'Fees'],
    'net_amount'        => ['ar' => 'الصافي',                        'en' => 'Net Amount'],
    'page'              => ['ar' => 'صفحة',                          'en' => 'Page'],
    'of'                => ['ar' => 'من',                            'en' => 'of'],
];

/**
 * دالة الترجمة الرئيسية
 * echo __('buy_now'); → شراء الآن / Buy Now
 */
function __(string $key, string $default = ''): string {
    global $TRANSLATIONS, $currentLang;
    $lang = $currentLang ?? 'ar';
    return $TRANSLATIONS[$key][$lang]
        ?? $TRANSLATIONS[$key]['ar']
        ?? ($default ?: $key);
}

/**
 * ترجمة مع بيانات ديناميكية
 * echo __t('welcome', ['name' => 'Ahmed']); → مرحباً Ahmed
 */
function __t(string $key, array $data = []): string {
  $text = __($key);
    foreach ($data as $k => $v) {
        $text = str_replace('{' . $k . '}', $v, $text);
    }
    return $text;
}

/**
 * زر تبديل اللغة
 */
function langSwitcher(bool $showLabel = true): string {
    global $currentLang;
    $targetLang  = $currentLang === 'ar' ? 'en' : 'ar';
    $targetLabel = $currentLang === 'ar' ? 'English' : 'العربية';
    $icon        = $currentLang === 'ar' ? '🇬🇧' : '🇸🇦';
    $label       = $showLabel ? " $targetLabel" : '';
    $query = $_GET;
    $query['lang'] = $targetLang;
    $url = htmlspecialchars((string)($_SERVER['PHP_SELF'] ?? '') . '?' . http_build_query($query), ENT_QUOTES, 'UTF-8');
    return "<a href='$url' style='text-decoration:none;padding:6px 14px;border-radius:20px;border:1px solid rgba(255,215,0,.3);color:var(--text-gold);font-size:.82rem;display:inline-flex;align-items:center;gap:5px'>$icon$label</a>";
}

// ══════════════════════════════════════════════════════════
// Global Navigation Bar — يشمل بحث + تغيير بيانات الدخول
// ══════════════════════════════════════════════════════════
function globalNav(string $activePage = ''): string {
    global $currentLang;
    $username = htmlspecialchars($_SESSION['user_data']['username'] ?? $_SESSION['username'] ?? 'User');
    $isAr     = ($currentLang ?? 'ar') !== 'en';
    $langSw   = langSwitcher(false);

    $pages = [
        'index'      => ['icon'=>'fas fa-home',          'url'=>'index.php',                     'label_ar'=>'الرئيسية',      'label_en'=>'Home'],
        'checkout'   => ['icon'=>'fas fa-shopping-cart', 'url'=>'checkout_router.php',           'label_ar'=>'الدفع',         'label_en'=>'Checkout'],
        'wallets'    => ['icon'=>'fas fa-wallet',        'url'=>'wallets.php',                   'label_ar'=>'المحافظ',       'label_en'=>'Wallets'],
        'history'    => ['icon'=>'fas fa-history',       'url'=>'history.php',                   'label_ar'=>'السجل',         'label_en'=>'History'],
        'reports'    => ['icon'=>'fas fa-chart-bar',     'url'=>'reports.php',                   'label_ar'=>'التقارير',      'label_en'=>'Reports'],
        'connection' => ['icon'=>'fas fa-network-wired', 'url'=>'admin/connection_manager.php',  'label_ar'=>'إدارة الاتصال','label_en'=>'Connections'],
    ];

    $navLinks = '';
    foreach ($pages as $key => $p) {
        $isActive = $activePage === $key;
        $label    = $isAr ? $p['label_ar'] : $p['label_en'];
        $style    = $isActive
            ? 'color:var(--gold);border-bottom:2px solid var(--gold);'
            : 'color:var(--text-muted);';
        $navLinks .= "<a href='{$p['url']}' style='text-decoration:none;padding:6px 4px;font-size:.82rem;{$style}display:inline-flex;align-items:center;gap:5px'>
                        <i class='{$p['icon']}'></i> <span style='display:none' class='nav-label'>$label</span>
                      </a> ";
    }

    $modalId = 'globalAccountModal';
    return <<<HTML
<!-- ═══ Global Nav ═══════════════════════════════════════ -->
<style>
#globalNav{background:rgba(0,0,0,.92);border-bottom:1px solid rgba(255,215,0,.2);padding:10px 20px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;position:sticky;top:0;z-index:900;}
#globalNav .nav-links{display:flex;gap:14px;align-items:center;}
#globalNav .search-wrap{flex:1;min-width:160px;max-width:280px;position:relative;}
#globalNav .search-wrap input{width:100%;padding:7px 14px 7px 34px;background:rgba(255,255,255,.06);border:1.5px solid rgba(255,215,0,.2);border-radius:20px;color:#fff;font-family:Cairo,sans-serif;font-size:.82rem;outline:none;}
#globalNav .search-wrap input:focus{border-color:var(--gold,#ffd700);}
#globalNav .search-wrap .search-icon{position:absolute;right:10px;top:50%;transform:translateY(-50%);color:#888;pointer-events:none;}
#globalSearchResults{position:absolute;top:calc(100% + 6px);right:0;left:0;background:#0e0e0e;border:1.5px solid rgba(255,215,0,.25);border-radius:12px;max-height:240px;overflow-y:auto;z-index:9999;display:none;}
#globalSearchResults a{display:flex;align-items:center;gap:10px;padding:10px 14px;color:#ddd;text-decoration:none;font-size:.82rem;border-bottom:1px solid rgba(255,255,255,.05);}
#globalSearchResults a:hover{background:rgba(255,215,0,.06);color:var(--gold,#ffd700);}
#{$modalId}{display:none;position:fixed;inset:0;background:rgba(0,0,0,.8);z-index:9999;align-items:center;justify-content:center;}
#{$modalId}.show{display:flex;}
.account-box{background:#0e0e0e;border:1.5px solid rgba(255,215,0,.25);border-radius:20px;padding:28px;width:100%;max-width:420px;color:#ddd;}
.account-box h3{color:var(--gold,#ffd700);margin:0 0 20px;font-size:1.1rem;}
.account-box label{font-size:.78rem;color:#888;display:block;margin-bottom:4px;}
.account-box input{width:100%;padding:10px 14px;background:rgba(255,255,255,.06);border:1.5px solid rgba(255,215,0,.2);border-radius:10px;color:#fff;font-family:Cairo,sans-serif;margin-bottom:14px;outline:none;}
.account-box input:focus{border-color:var(--gold,#ffd700);}
.account-box .btns{display:flex;gap:10px;margin-top:6px;}
.account-box .btn-save{background:linear-gradient(135deg,#ffd700,#ffb700);color:#000;padding:10px 20px;border-radius:10px;border:none;cursor:pointer;font-family:Cairo,sans-serif;font-weight:700;}
.account-box .btn-close{background:transparent;border:1.5px solid rgba(255,255,255,.15);color:#aaa;padding:10px 20px;border-radius:10px;cursor:pointer;font-family:Cairo,sans-serif;}
.account-box .msg{padding:8px 12px;border-radius:8px;font-size:.82rem;margin-bottom:12px;display:none;}
.account-box .msg.ok{background:rgba(76,175,80,.15);color:#4CAF50;border:1px solid #4CAF5040;}
.account-box .msg.err{background:rgba(239,83,80,.12);color:#ef5350;border:1px solid #ef535040;}
@media(max-width:600px){#globalNav .nav-links a span.nav-label{display:none!important;}}
</style>

<nav id="globalNav">
  <!-- Logo -->
  <a href="index.php" style="color:var(--gold,#ffd700);font-weight:800;font-size:1rem;text-decoration:none;white-space:nowrap">
    <i class="fas fa-coins"></i> DI PARMA
  </a>

  <!-- Nav Links -->
  <div class="nav-links">$navLinks</div>

  <!-- Search -->
  <div class="search-wrap">
    <i class="fas fa-search search-icon"></i>
    <input type="text" id="globalSearch"
           placeholder="بحث — صفحة، معاملة، بوابة..."
           onkeyup="globalSearchFn(this.value)"
           onfocus="globalSearchFn(this.value)"
           onblur="setTimeout(function(){document.getElementById('globalSearchResults').style.display='none'},200)">
    <div id="globalSearchResults"></div>
  </div>

  <!-- Lang + Account -->
  <div style="display:flex;gap:8px;align-items:center;margin-right:auto">
    {$langSw}
    <button onclick="document.getElementById('{$modalId}').classList.toggle('show')"
            style="background:rgba(255,215,0,.1);border:1px solid rgba(255,215,0,.3);color:var(--gold,#ffd700);padding:6px 12px;border-radius:20px;cursor:pointer;font-family:Cairo,sans-serif;font-size:.82rem;display:inline-flex;align-items:center;gap:6px">
      <i class="fas fa-user-circle"></i> $username
    </button>
  </div>
</nav>

<!-- Account Modal -->
<div id="{$modalId}" onclick="if(event.target.id==='{$modalId}')this.classList.remove('show')">
  <div class="account-box">
    <h3><i class="fas fa-user-cog" style="margin-left:8px"></i> تغيير بيانات الدخول</h3>
    <div id="acctMsg" class="msg"></div>
    <form id="acctForm" onsubmit="updateAccount(event)">
      <label>اسم المستخدم الجديد</label>
      <input type="text" id="newUsername" value="$username" placeholder="اسم المستخدم">
      <label>كلمة المرور الحالية <span style="color:#ef5350">*</span></label>
      <input type="password" id="currentPwd" placeholder="••••••••" required>
      <label>كلمة المرور الجديدة <span style="color:#888">(اختياري)</span></label>
      <input type="password" id="newPwd" placeholder="اتركها فارغة للإبقاء">
      <label>تأكيد كلمة المرور الجديدة</label>
      <input type="password" id="confirmPwd" placeholder="••••••••">
      <div class="btns">
        <button type="submit" class="btn-save"><i class="fas fa-save"></i> حفظ</button>
        <button type="button" class="btn-close" onclick="document.getElementById('{$modalId}').classList.remove('show')">
          <i class="fas fa-times"></i> إغلاق
        </button>
      </div>
    </form>
  </div>
</div>

<script>
// ── Global Search ─────────────────────────────────────────
var _searchPages = [
  {url:'index.php',       icon:'fas fa-home',          label:'الصفحة الرئيسية'},
  {url:'checkout_router.php', icon:'fas fa-credit-card',    label:'Checkout — الدفع'},
  {url:'wallets.php',     icon:'fas fa-wallet',        label:'المحافظ المالية'},
  {url:'history.php',     icon:'fas fa-history',       label:'سجل المعاملات'},
  {url:'reports.php',     icon:'fas fa-chart-bar',     label:'التقارير المالية'},
  {url:'transactions.php',icon:'fas fa-exchange-alt',  label:'المعاملات'},
  {url:'approvals.php',   icon:'fas fa-check-circle',  label:'الموافقات'},
  {url:'kyc.php',         icon:'fas fa-id-card',       label:'KYC — التحقق من الهوية'},
  {url:'admin/connection_manager.php', icon:'fas fa-network-wired', label:'إدارة الاتصال'},
  {url:'admin/gateway_manager.php',    icon:'fas fa-cog',           label:'إعدادات البوابات'},
  {url:'dashboard.php',   icon:'fas fa-tachometer-alt',label:'لوحة التحكم'},
  {url:'change_password.php',icon:'fas fa-lock',       label:'تغيير كلمة المرور'},
  {url:'holds.php',       icon:'fas fa-hand-holding-usd',label:'الحجوزات HOLD'},
  {url:'links.php',       icon:'fas fa-link',          label:'روابط الدفع'},
  {url:'pay.php',         icon:'fas fa-dollar-sign',   label:'صفحة الدفع'},
  {url:'user_profile.php',icon:'fas fa-user',          label:'الملف الشخصي'},
];
function globalSearchFn(q) {
  var box = document.getElementById('globalSearchResults');
  if (!q || q.length < 1) { box.style.display = 'none'; return; }
  q = q.trim().toLowerCase();
  var matches = _searchPages.filter(function(p){ return p.label.toLowerCase().includes(q) || p.url.toLowerCase().includes(q); });
  if (!matches.length) { box.style.display = 'none'; return; }
  box.innerHTML = matches.slice(0,8).map(function(p){
    return '<a href="'+p.url+'"><i class="'+p.icon+'" style="width:18px;color:var(--gold,#ffd700)"></i> '+p.label+'</a>';
  }).join('');
  box.style.display = 'block';
}

// ── Update Account ────────────────────────────────────────
async function updateAccount(e) {
  e.preventDefault();
  var msg = document.getElementById('acctMsg');
  msg.className = 'msg'; msg.style.display = 'none';
  var data = {
    action:       'update_admin_credentials',
    username:     document.getElementById('newUsername').value,
    current_password: document.getElementById('currentPwd').value,
    new_password: document.getElementById('newPwd').value,
    confirm_password: document.getElementById('confirmPwd').value,
    csrf_token:   typeof CSRF_TOKEN !== 'undefined' ? CSRF_TOKEN : ''
  };
  try {
    var fd = new FormData();
    Object.keys(data).forEach(function(k){ fd.append(k, data[k]); });
    // اكتشف الـ endpoint تلقائياً
    var endpoint = window.location.pathname.includes('admin/') ? 'gateway_manager.php' : '../admin/gateway_manager.php';
    var r = await fetch(endpoint, {method:'POST', body:fd});
    var text = await r.text();
    if (text.includes('✅') || text.includes('success')) {
      msg.textContent = '✅ تم التحديث بنجاح'; msg.className = 'msg ok'; msg.style.display = 'block';
      document.getElementById('currentPwd').value = '';
      document.getElementById('newPwd').value     = '';
      document.getElementById('confirmPwd').value = '';
    } else {
      msg.textContent = '❌ فشل التحديث — تحقق من كلمة المرور الحالية'; msg.className = 'msg err'; msg.style.display = 'block';
    }
  } catch(err) {
    msg.textContent = '❌ خطأ في الاتصال'; msg.className = 'msg err'; msg.style.display = 'block';
  }
}
</script>
<!-- ═══ End Global Nav ═══════════════════════════════════ -->
HTML;
}

// ── ترجمات إضافية ─────────────────────────────────────
$TRANSLATIONS = array_merge($TRANSLATIONS, [

    // crypto_confirm
    'buy_usdt_title'    => ['ar'=>'شراء USDT',               'en'=>'Buy USDT'],
    'sell_usdt_title'   => ['ar'=>'بيع USDT',                'en'=>'Sell USDT'],
    'confirm_op'        => ['ar'=>'تأكيد العملية',           'en'=>'Transaction Confirmation'],
    'new_operation'     => ['ar'=>'عملية جديدة',             'en'=>'New Transaction'],
    'complete_payment'  => ['ar'=>'إتمام الدفع',             'en'=>'Complete Payment'],
    'tx_reference'      => ['ar'=>'المرجع',                  'en'=>'Reference'],
    'tx_amount'         => ['ar'=>'المبلغ',                  'en'=>'Amount'],
    'tx_network'        => ['ar'=>'الشبكة',                  'en'=>'Network'],
    'tx_gateway'        => ['ar'=>'البوابة',                 'en'=>'Gateway'],
    'tx_date'           => ['ar'=>'التاريخ',                 'en'=>'Date'],
    'auto_refresh'      => ['ar'=>'يتحدث تلقائياً',         'en'=>'Auto refreshing'],
    'blockchain_confirm'=> ['ar'=>'تأكيد البلوكشين',        'en'=>'Blockchain Confirmation'],
    'fiat_payment'      => ['ar'=>'دفع الفيات',              'en'=>'Fiat Payment'],
    'send_usdt'         => ['ar'=>'إرسال USDT',              'en'=>'Send USDT'],
    'op_confirm_order'  => ['ar'=>'تأكيد الطلب',            'en'=>'Order Confirmed'],
    'op_completed'      => ['ar'=>'اكتملت العملية بنجاح',   'en'=>'Transaction completed successfully'],
    'op_failed'         => ['ar'=>'فشلت العملية',           'en'=>'Transaction failed'],

    // dashboard
    'total_transactions'=> ['ar'=>'إجمالي العمليات',         'en'=>'Total Transactions'],
    'success_rate'      => ['ar'=>'نسبة النجاح',             'en'=>'Success Rate'],
    'active_gateways'   => ['ar'=>'البوابات النشطة',         'en'=>'Active Gateways'],
    'total_users'       => ['ar'=>'المستخدمون',              'en'=>'Users'],
    'last_transactions' => ['ar'=>'آخر المعاملات',           'en'=>'Recent Transactions'],
    'view_all'          => ['ar'=>'عرض الكل',                'en'=>'View All'],
    'daily_volume'      => ['ar'=>'الحجم اليومي',            'en'=>'Daily Volume'],
    'weekly_chart'      => ['ar'=>'مخطط الأسبوع',           'en'=>'Weekly Chart'],
    'protocol_stats'    => ['ar'=>'إحصائيات البروتوكولات',  'en'=>'Protocol Statistics'],
    'gateway_stats'     => ['ar'=>'إحصائيات البوابات',      'en'=>'Gateway Statistics'],
    'no_transactions'   => ['ar'=>'لا توجد معاملات',        'en'=>'No transactions found'],

    // admin
    'treasury'          => ['ar'=>'Treasury',                'en'=>'Treasury'],
    'hot_wallet'        => ['ar'=>'Hot Wallet',              'en'=>'Hot Wallet'],
    'cold_wallet'       => ['ar'=>'Cold Wallet',             'en'=>'Cold Wallet'],
    'refill_wallet'     => ['ar'=>'تعبئة Hot Wallet',        'en'=>'Refill Hot Wallet'],
    'kyc_pending'       => ['ar'=>'KYC معلق',               'en'=>'Pending KYC'],
    'risk_alerts'       => ['ar'=>'تنبيهات مخاطر',          'en'=>'Risk Alerts'],
    'event_log'         => ['ar'=>'سجل الأحداث',            'en'=>'Event Log'],
    'processed'         => ['ar'=>'معالج',                   'en'=>'Processed'],
    'available'         => ['ar'=>'متاح',                    'en'=>'Available'],
    'reserved'          => ['ar'=>'محجوز',                   'en'=>'Reserved'],
    'needs_refill'      => ['ar'=>'يحتاج تعبئة',           'en'=>'Needs Refill'],
    'good'              => ['ar'=>'جيد',                     'en'=>'Good'],
    'critical'          => ['ar'=>'حرج',                     'en'=>'Critical'],

    // Audit / AML
    'high_risk_ops'     => ['ar'=>'عمليات عالية المخاطر',   'en'=>'High Risk Operations'],
    'sar_section'       => ['ar'=>'SAR Candidates — عمليات مشبوهة', 'en'=>'SAR Candidates — Suspicious Transactions'],
    'velocity_check'    => ['ar'=>'فحص Velocity',           'en'=>'Velocity Check'],
    'gateway_volume'    => ['ar'=>'توزيع حجم التداول بالبوابات', 'en'=>'Trading Volume by Gateway'],
    'compliance_score'  => ['ar'=>'Compliance Score',        'en'=>'Compliance Score'],
    'company_info'      => ['ar'=>'معلومات الشركة',          'en'=>'Company Information'],
    'system_stats'      => ['ar'=>'إحصائيات النظام',         'en'=>'System Statistics'],
    'compliance_checks' => ['ar'=>'فحوصات الامتثال التقني', 'en'=>'Technical Compliance Checks'],
    'applied_policies'  => ['ar'=>'السياسات المطبّقة',      'en'=>'Applied Policies'],
    'no_suspicious'     => ['ar'=>'لا توجد عمليات مشبوهة — النظام نظيف ✓', 'en'=>'No suspicious transactions — System is clean ✓'],
    'no_high_risk'      => ['ar'=>'لا توجد عمليات عالية المخاطر في هذه الفترة ✓', 'en'=>'No high risk operations in this period ✓'],
    'no_velocity'       => ['ar'=>'لا توجد أنماط غير طبيعية ✓', 'en'=>'No abnormal patterns ✓'],
    'aml_footer'        => ['ar'=>'هذا التقرير مولَّد تلقائياً وفق متطلبات VARA', 'en'=>'This report is auto-generated per VARA AML requirements'],
    'vara_footer'       => ['ar'=>'جميع السجلات محفوظة وفق متطلبات VARA — مدة الحفظ: 7 سنوات', 'en'=>'All records retained per VARA requirements — Retention: 7 years'],
    'report_date'       => ['ar'=>'تاريخ التقرير',           'en'=>'Report Date'],
    'from_date'         => ['ar'=>'من تاريخ',                'en'=>'From Date'],
    'to_date'           => ['ar'=>'إلى تاريخ',               'en'=>'To Date'],
    'apply'             => ['ar'=>'تطبيق',                   'en'=>'Apply'],
    'operations'        => ['ar'=>'عمليات',                  'en'=>'operations'],
    'last_update'       => ['ar'=>'آخر تحديث',               'en'=>'Last update'],
]);
