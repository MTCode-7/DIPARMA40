<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// إذا مسجل دخول → dashboard
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . SITE_URL . '/dashboard.php');
    exit;
}

$lang  = isset($_GET['lang']) && in_array($_GET['lang'], ['ar', 'en'], true)
  ? $_GET['lang']
  : (isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'en' ? 'en' : 'ar');
$ar    = $lang === 'ar';
$dir   = $ar ? 'rtl' : 'ltr';
$db    = db();
$error = '';
$success = '';
$approved_brands = ['Louis Vuitton', 'Armani', 'Gucci', 'Chanel', 'Dior', 'Prada', 'Hermes', 'Rolex', 'Nike', 'Apple', 'Microsoft', 'Amazon'];

// ── معالجة POST ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username   = trim($_POST['username']   ?? '');
    $email      = trim($_POST['email']      ?? '');
    $password   = trim($_POST['password']   ?? '');
    $confirm    = trim($_POST['confirm']    ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name']  ?? '');
    $phone      = trim($_POST['phone']      ?? '');
    $country    = trim($_POST['country']    ?? '');
    $subscription_plan = trim($_POST['subscription_plan'] ?? '');
    $allowed_plans = ['100000', '200000', '300000'];
    $account_type = trim($_POST['account_type'] ?? '');
    $allowed_account_types = ['personal', 'business'];
    $brand_name = trim($_POST['brand_name'] ?? '');
    $annual_revenue = trim($_POST['annual_revenue'] ?? '');
    $allowed_revenues = ['100000000', '200000000', '300000000', '500000000', '1000000000'];
    $brand_description = trim($_POST['brand_description'] ?? '');
    $brand_logo_path = null;
    $market_role = trim($_POST['market_role'] ?? '');
    $allowed_market_roles = ['seller', 'buyer'];

    // التحقق
    if (empty($username) || empty($email) || empty($password)
      || !in_array($subscription_plan, $allowed_plans, true)
      || !in_array($account_type, $allowed_account_types, true)
      || ($account_type === 'business' && ($brand_name === '' || !in_array($brand_name, $approved_brands, true) || !in_array($annual_revenue, $allowed_revenues, true) || $brand_description === '' || empty($_FILES['brand_logo']['name']) || !in_array($market_role, $allowed_market_roles, true)))) {
        $error = $ar ? 'جميع الحقول المطلوبة يجب تعبئتها' : 'All required fields must be filled';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = $ar ? 'البريد الإلكتروني غير صالح' : 'Invalid email address';
    } elseif (strlen($password) < 8) {
        $error = $ar ? 'كلمة المرور يجب أن تكون 8 أحرف على الأقل' : 'Password must be at least 8 characters';
    } elseif ($password !== $confirm) {
        $error = $ar ? 'كلمتا المرور غير متطابقتين' : 'Passwords do not match';
    } elseif (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username)) {
        $error = $ar ? 'اسم المستخدم يجب أن يحتوي على أحرف وأرقام فقط (3-50 حرف)' : 'Username must contain letters/numbers only (3-50 chars)';
    } else {
        // تحقق من التكرار
        $existEmail = $db->find('users', ['email' => $email]);
        $existUser  = $db->find('users', ['username' => $username]);

        if ($existEmail) {
            $error = $ar ? 'البريد الإلكتروني مسجل مسبقاً' : 'Email already registered';
        } elseif ($existUser) {
            $error = $ar ? 'اسم المستخدم محجوز' : 'Username already taken';
        } else {
            try {
            // دعم قواعد البيانات الموجودة قبل إضافة الاشتراكات.
            $columns = $db->query("SHOW COLUMNS FROM " . DB_PREFIX . "users LIKE 'subscription_plan'");
            if (empty($columns)) {
              $db->execute("ALTER TABLE " . DB_PREFIX . "users ADD COLUMN subscription_plan VARCHAR(20) DEFAULT NULL AFTER country");
            }
                $accountTypeColumns = $db->query("SHOW COLUMNS FROM " . DB_PREFIX . "users LIKE 'account_type'");
                if (empty($accountTypeColumns)) {
                    $db->execute("ALTER TABLE " . DB_PREFIX . "users ADD COLUMN account_type VARCHAR(20) DEFAULT NULL AFTER subscription_plan");
                }
                $brandNameColumns = $db->query("SHOW COLUMNS FROM " . DB_PREFIX . "users LIKE 'brand_name'");
                if (empty($brandNameColumns)) {
                  $db->execute("ALTER TABLE " . DB_PREFIX . "users ADD COLUMN brand_name VARCHAR(255) DEFAULT NULL AFTER account_type");
                }
                $brandDescriptionColumns = $db->query("SHOW COLUMNS FROM " . DB_PREFIX . "users LIKE 'brand_description'");
                if (empty($brandDescriptionColumns)) {
                  $db->execute("ALTER TABLE " . DB_PREFIX . "users ADD COLUMN brand_description TEXT DEFAULT NULL AFTER brand_name");
                }
                $brandLogoColumns = $db->query("SHOW COLUMNS FROM " . DB_PREFIX . "users LIKE 'brand_logo_path'");
                if (empty($brandLogoColumns)) {
                  $db->execute("ALTER TABLE " . DB_PREFIX . "users ADD COLUMN brand_logo_path VARCHAR(500) DEFAULT NULL AFTER brand_description");
                }
                $marketRoleColumns = $db->query("SHOW COLUMNS FROM " . DB_PREFIX . "users LIKE 'market_role'");
                if (empty($marketRoleColumns)) {
                    $db->execute("ALTER TABLE " . DB_PREFIX . "users ADD COLUMN market_role VARCHAR(20) DEFAULT NULL AFTER annual_revenue");
                }
                if ($account_type === 'business') {
                  $logo = $_FILES['brand_logo'];
                  if ($logo['error'] !== UPLOAD_ERR_OK || $logo['size'] > 5 * 1024 * 1024) {
                    throw new RuntimeException('Invalid brand logo.');
                  }
                  $logoMime = (new finfo(FILEINFO_MIME_TYPE))->file($logo['tmp_name']);
                  $logoExtensions = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
                  if (!isset($logoExtensions[$logoMime])) {
                    throw new RuntimeException('Brand logo must be JPG or PNG.');
                  }
                  $logoFolder = ROOT_PATH . '/private_uploads/brands';
                  if (!is_dir($logoFolder) && !mkdir($logoFolder, 0750, true)) {
                    throw new RuntimeException('Brand logo storage unavailable.');
                  }
                  $logoFilename = bin2hex(random_bytes(20)) . '.' . $logoExtensions[$logoMime];
                  if (!move_uploaded_file($logo['tmp_name'], $logoFolder . DIRECTORY_SEPARATOR . $logoFilename)) {
                    throw new RuntimeException('Brand logo upload failed.');
                  }
                  $brand_logo_path = 'private_uploads/brands/' . $logoFilename;
                }
                $db->insert('users', [
                    'username'      => $username,
                    'email'         => $email,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'first_name'    => $first_name,
                    'last_name'     => $last_name,
                    'phone'         => $phone,
                    'country'       => $country,
                    'subscription_plan' => $subscription_plan,
                    'account_type'   => $account_type,
                    'brand_name'     => $brand_name !== '' ? $brand_name : null,
                    'brand_description' => $brand_description !== '' ? $brand_description : null,
                    'brand_logo_path' => $brand_logo_path,
                    'annual_revenue' => $annual_revenue !== '' ? $annual_revenue : null,
                    'market_role' => $market_role !== '' ? $market_role : null,
                    'role'          => 'user',
                    'status'        => 'pending',
                    'created_at'    => date('Y-m-d H:i:s'),
                ]);
                // redirect لصفحة الانتظار
                header('Location: ' . SITE_URL . '/pending.php');
                exit;
            } catch (Exception $e) {
                $error = $ar ? 'خطأ في التسجيل — حاول مجدداً' : 'Registration error — please try again';
            }
        }
    }
}
?><!DOCTYPE html>
<html lang="<?=$lang?>" dir="<?=$dir?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA | <?=$ar?'إنشاء حساب':'Create Account'?></title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--gold:#FFD700;--gold2:#FFB700;--bg:#040810;--card:#080d1a;--border:rgba(255,215,0,.15);--text:#f0f0f0;--muted:#666;--green:#10B981;--red:#EF4444}
body{font-family:'Cairo',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;
  display:flex;align-items:center;justify-content:center;padding:20px}
.wrap{width:100%;max-width:520px}
.logo{text-align:center;margin-bottom:28px}
.logo-ico{width:64px;height:64px;background:linear-gradient(135deg,var(--gold),var(--gold2));
  border-radius:18px;display:inline-flex;align-items:center;justify-content:center;
  font-size:1.6rem;font-weight:900;color:#000;margin-bottom:12px}
.logo-title{font-size:1.4rem;font-weight:900;color:var(--gold)}
.logo-sub{font-size:.82rem;color:var(--muted);margin-top:4px}
.card{background:var(--card);border:1px solid var(--border);border-radius:20px;padding:32px}
.card-title{font-size:1.05rem;font-weight:800;margin-bottom:24px;text-align:center;
  display:flex;align-items:center;justify-content:center;gap:8px}
.fld{margin-bottom:16px}
.fld label{display:block;font-size:.78rem;color:var(--muted);margin-bottom:6px;font-weight:600}
.fld input,.fld select{width:100%;background:rgba(255,255,255,.04);border:1.5px solid var(--border);
  border-radius:12px;padding:12px 16px;color:var(--text);font-family:'Cairo',sans-serif;
  font-size:.9rem;transition:.2s}
.fld input:focus,.fld select:focus{outline:none;border-color:var(--gold)}
.fld-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.section-label{font-size:.72rem;font-weight:800;color:var(--muted);text-transform:uppercase;
  letter-spacing:1px;margin:20px 0 14px;padding-bottom:8px;border-bottom:1px solid var(--border)}
.error-msg{background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);
  border-radius:10px;padding:12px 16px;font-size:.82rem;color:#EF4444;margin-bottom:16px}
.submit-btn{width:100%;background:linear-gradient(135deg,var(--gold),var(--gold2));
  color:#000;padding:14px;border-radius:14px;border:none;font-family:'Cairo',sans-serif;
  font-size:.95rem;font-weight:800;cursor:pointer;transition:.3s;margin-top:8px}
.submit-btn:hover{transform:translateY(-1px);box-shadow:0 8px 24px rgba(255,215,0,.25)}
.login-link{text-align:center;margin-top:18px;font-size:.82rem;color:var(--muted)}
.login-link a{color:var(--gold);text-decoration:none;font-weight:700}
.req{color:var(--red);margin-<?=$ar?'right':'left'?>:2px}
.steps{display:flex;gap:0;margin-bottom:24px}
.step{flex:1;text-align:center;font-size:.72rem;color:var(--muted);padding-bottom:8px;
  border-bottom:2px solid rgba(255,255,255,.1)}
.step.active{color:var(--gold);border-bottom-color:var(--gold);font-weight:700}
@media(max-width:480px){.fld-row{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrap">
  <div class="logo">
    <div class="logo-ico">DP</div>
    <div class="logo-title">DI PARMA</div>
    <div class="logo-sub"><?=$ar?'منصة الدفع الشاملة':'Universal Payment Platform'?></div>
  </div>

  <div class="card">
    <div class="card-title">
      <i class="fas fa-user-plus" style="color:var(--gold)"></i>
      <?=$ar?'إنشاء حساب جديد':'Create New Account'?>
    </div>

    <div class="steps">
      <div class="step active">1. <?=$ar?'بيانات الحساب':'Account Info'?></div>
      <div class="step">2. <?=$ar?'مراجعة الإدارة':'Admin Review'?></div>
      <div class="step">3. <?=$ar?'تفعيل الحساب':'Activation'?></div>
    </div>

    <?php if ($error): ?>
    <div class="error-msg"><i class="fas fa-exclamation-circle"></i> <?=htmlspecialchars($error)?></div>
    <?php endif; ?>

    <form method="POST" autocomplete="off" enctype="multipart/form-data">

      <div class="section-label"><i class="fas fa-user"></i> <?=$ar?'معلومات الحساب':'Account Information'?></div>

      <div class="fld">
        <label><span class="req">*</span> <?=$ar?'اسم المستخدم':'Username'?></label>
        <input type="text" name="username" value="<?=htmlspecialchars($_POST['username']??'')?>"
          placeholder="<?=$ar?'أحرف وأرقام فقط':'Letters and numbers only'?>" required>
      </div>

      <div class="fld">
        <label><span class="req">*</span> <?=$ar?'البريد الإلكتروني':'Email Address'?></label>
        <input type="email" name="email" value="<?=htmlspecialchars($_POST['email']??'')?>"
          placeholder="example@email.com" required>
      </div>

      <div class="fld-row">
        <div class="fld">
          <label><span class="req">*</span> <?=$ar?'كلمة المرور':'Password'?></label>
          <input type="password" name="password" placeholder="8+ <?=$ar?'أحرف':'chars'?>" required>
        </div>
        <div class="fld">
          <label><span class="req">*</span> <?=$ar?'تأكيد كلمة المرور':'Confirm Password'?></label>
          <input type="password" name="confirm" placeholder="<?=$ar?'أعد الكتابة':'Re-enter'?>" required>
        </div>
      </div>

      <div class="section-label"><i class="fas fa-id-card"></i> <?=$ar?'المعلومات الشخصية':'Personal Information'?></div>

      <div class="fld-row">
        <div class="fld">
          <label><?=$ar?'الاسم الأول':'First Name'?></label>
          <input type="text" name="first_name" value="<?=htmlspecialchars($_POST['first_name']??'')?>" placeholder="<?=$ar?'الاسم الأول':'First name'?>">
        </div>
        <div class="fld">
          <label><?=$ar?'اسم العائلة':'Last Name'?></label>
          <input type="text" name="last_name" value="<?=htmlspecialchars($_POST['last_name']??'')?>" placeholder="<?=$ar?'اسم العائلة':'Last name'?>">
        </div>
      </div>

      <div class="fld-row">
        <div class="fld">
          <label><?=$ar?'رقم الهاتف':'Phone Number'?></label>
          <input type="tel" name="phone" value="<?=htmlspecialchars($_POST['phone']??'')?>" placeholder="+971 XX XXX XXXX">
        </div>
        <div class="fld">
          <label><?=$ar?'الدولة':'Country'?></label>
          <select name="country">
            <option value=""><?=$ar?'اختر الدولة':'Select Country'?></option>
            <option value="UAE" <?=($_POST['country']??'')==='UAE'?'selected':''?>>UAE — الإمارات</option>
            <option value="SAU" <?=($_POST['country']??'')==='SAU'?'selected':''?>>Saudi Arabia — السعودية</option>
            <option value="KWT" <?=($_POST['country']??'')==='KWT'?'selected':''?>>Kuwait — الكويت</option>
            <option value="QAT" <?=($_POST['country']??'')==='QAT'?'selected':''?>>Qatar — قطر</option>
            <option value="BHR" <?=($_POST['country']??'')==='BHR'?'selected':''?>>Bahrain — البحرين</option>
            <option value="OMN" <?=($_POST['country']??'')==='OMN'?'selected':''?>>Oman — عُمان</option>
            <option value="JOR" <?=($_POST['country']??'')==='JOR'?'selected':''?>>Jordan — الأردن</option>
            <option value="EGY" <?=($_POST['country']??'')==='EGY'?'selected':''?>>Egypt — مصر</option>
            <option value="OTHER" <?=($_POST['country']??'')==='OTHER'?'selected':''?>>Other — أخرى</option>
          </select>
        </div>
      </div>

      <div class="section-label"><i class="fas fa-layer-group"></i> <?=$ar?'نوع الاشتراك':'Subscription Plan'?></div>
      <div class="fld">
        <label><span class="req">*</span> <?=$ar?'اختر قيمة الاشتراك':'Choose Subscription Amount'?></label>
        <select name="subscription_plan" required>
          <option value=""><?=$ar?'اختر نوع الاشتراك':'Select a subscription plan'?></option>
          <?php foreach (['100000', '200000', '300000'] as $plan): ?>
            <option value="<?=$plan?>" <?=($_POST['subscription_plan']??'')===$plan?'selected':''?>>$<?=number_format((int)$plan)?> <?=$ar?'دولار':'USD'?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="fld" id="brandNameField" style="display:none">
        <label><span class="req">*</span> <?=$ar?'اسم الماركة للمتجر الإلكتروني':'Online Store Brand Name'?></label>
        <select name="brand_name">
          <option value=""><?=$ar?'اختر الماركة العالمية':'Select global brand'?></option>
          <?php foreach ($approved_brands as $brand): ?>
            <option value="<?=htmlspecialchars($brand)?>" <?=($_POST['brand_name']??'')===$brand?'selected':''?>><?=htmlspecialchars($brand)?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="fld" id="revenueField" style="display:none">
        <label><span class="req">*</span> <?=$ar?'الإيراد السنوي للماركة':'Brand Annual Revenue'?></label>
        <select name="annual_revenue">
          <option value=""><?=$ar?'اختر الإيراد السنوي (أكثر من 100 مليون دولار)':'Select annual revenue (over $100M)'?></option>
          <?php foreach (['100000000'=>'$100,000,000+', '200000000'=>'$200,000,000+', '300000000'=>'$300,000,000+', '500000000'=>'$500,000,000+', '1000000000'=>'$1,000,000,000+'] as $revenue => $label): ?>
            <option value="<?=$revenue?>" <?=($_POST['annual_revenue']??'')===$revenue?'selected':''?>><?=$label?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="fld" id="brandDescriptionField" style="display:none">
        <label><span class="req">*</span> <?=$ar?'معلومات الماركة ونشاط المتجر':'Brand and Store Information'?></label>
        <textarea name="brand_description" maxlength="3000" rows="4" placeholder="<?=$ar?'نبذة عن الماركة ونوع المنتجات':'Describe the brand and products'?>"><?=htmlspecialchars($_POST['brand_description']??'')?></textarea>
      </div>

      <div class="fld" id="brandLogoField" style="display:none">
        <label><span class="req">*</span> <?=$ar?'لوقو الماركة':'Brand Logo'?></label>
        <input type="file" name="brand_logo" accept=".jpg,.jpeg,.png" >
        <small style="display:block;color:var(--muted);font-size:.7rem;margin-top:5px"><?=$ar?'PNG أو JPG، حتى 5 ميجابايت':'PNG or JPG, up to 5 MB'?></small>
      </div>

      <div class="fld" id="marketRoleField" style="display:none">
        <label><span class="req">*</span> <?=$ar?'دور المتجر':'Store Role'?></label>
        <select name="market_role">
          <option value=""><?=$ar?'اختر الدور':'Select role'?></option>
          <option value="seller" <?=($_POST['market_role']??'')==='seller'?'selected':''?>><?=$ar?'بائع':'Seller'?></option>
          <option value="buyer" <?=($_POST['market_role']??'')==='buyer'?'selected':''?>><?=$ar?'مشتري':'Buyer'?></option>
        </select>
      </div>

      <div class="section-label"><i class="fas fa-user-tie"></i> <?=$ar?'نوع الحساب':'Account Type'?></div>
      <div class="fld">
        <label><span class="req">*</span> <?=$ar?'اختر نوع الحساب':'Choose Account Type'?></label>
        <select name="account_type" required>
          <option value=""><?=$ar?'اختر نوع الحساب':'Select account type'?></option>
          <option value="personal" <?=($_POST['account_type']??'')==='personal'?'selected':''?>><?=$ar?'حساب شخصي':'Personal Account'?></option>
          <option value="business" <?=($_POST['account_type']??'')==='business'?'selected':''?>><?=$ar?'حساب تجاري':'Business Account'?></option>
        </select>
      </div>

      <button type="submit" class="submit-btn">
        <i class="fas fa-user-plus"></i> <?=$ar?'إنشاء الحساب':'Create Account'?>
      </button>
    </form>

    <div class="login-link">
      <?=$ar?'لديك حساب؟':'Have an account?'?>
      <a href="/login.php"><?=$ar?'سجل الدخول':'Login'?></a>
    </div>
  </div>

  <div style="text-align:center;margin-top:16px;font-size:.72rem;color:var(--muted)">
    <i class="fas fa-shield-alt" style="color:var(--green)"></i>
    <?=$ar?'حسابك يحتاج موافقة الإدارة قبل التفعيل':'Account requires admin approval before activation'?>
  </div>
</div>
<script>
const accountType = document.querySelector('[name="account_type"]');
const brandNameField = document.getElementById('brandNameField');
const brandNameInput = document.querySelector('[name="brand_name"]');
const revenueField = document.getElementById('revenueField');
const revenueInput = document.querySelector('[name="annual_revenue"]');
const brandDescriptionField = document.getElementById('brandDescriptionField');
const brandDescriptionInput = document.querySelector('[name="brand_description"]');
const brandLogoField = document.getElementById('brandLogoField');
const brandLogoInput = document.querySelector('[name="brand_logo"]');
const marketRoleField = document.getElementById('marketRoleField');
const marketRoleInput = document.querySelector('[name="market_role"]');
function updateBrandField() {
  const isBusiness = accountType && accountType.value === 'business';
  brandNameField.style.display = isBusiness ? 'block' : 'none';
  brandNameInput.required = isBusiness;
  revenueField.style.display = isBusiness ? 'block' : 'none';
  revenueInput.required = isBusiness;
  brandDescriptionField.style.display = isBusiness ? 'block' : 'none';
  brandDescriptionInput.required = isBusiness;
  brandLogoField.style.display = isBusiness ? 'block' : 'none';
  brandLogoInput.required = isBusiness;
  marketRoleField.style.display = isBusiness ? 'block' : 'none';
  marketRoleInput.required = isBusiness;
}
accountType.addEventListener('change', updateBrandField);
updateBrandField();
</script>
</body>
</html>
