<?php
/**
 * DI PARMA | KYC — التحقق من الهوية
 */
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/crypto_schema.php';
require_once __DIR__ . '/lib/KYCService.php';

dp_create_crypto_tables();

$userId    = intval($_SESSION['user_id'] ?? 0);
$csrfToken = generateCsrfToken();
$uploadError = '';
$successMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_kyc'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $uploadError = 'رمز CSRF غير صالح.';
    } else {
        try {
            $db = db();
            $user = $db->find('users', ['id' => $userId]);
            if (!$user) {
                throw new RuntimeException('المستخدم غير موجود.');
            }

            $phone = trim((string)($_POST['phone'] ?? ''));
            $country = trim((string)($_POST['country'] ?? ''));
            $address = trim((string)($_POST['address'] ?? ''));
            $documentType = trim((string)($_POST['document_type'] ?? ''));
            $level = max(1, min(3, intval($_POST['level'] ?? 1)));

            if ($phone === '' || $country === '' || $address === '' || $documentType === '') {
                throw new RuntimeException('الرجاء ملء جميع الحقول الإجبارية.');
            }
            if (empty($_FILES['document_file']) || empty($_FILES['selfie_file'])) {
                throw new RuntimeException('يرجى رفع صورة الهوية والصورة الشخصية.');
            }

            $uploadFolder = ROOT_PATH . '/uploads/kyc/' . $userId;
            $documentPath = storeUploadedDocument($_FILES['document_file'], $uploadFolder);
            $selfiePath = storeUploadedDocument($_FILES['selfie_file'], $uploadFolder);

            $db->update('users', [
                'phone' => $phone,
                'country' => $country,
                'address' => $address,
            ], ['id' => $userId]);

            $existingKyc = $db->find('kyc_verifications', ['user_id' => $userId]);
            $limits = KYCService::getLevelLimits();
            $kycData = [
                'provider'      => 'manual',
                'level'         => $level,
                'status'        => 'pending',
                'daily_limit'   => $limits[$level]['daily'] ?? PHP_INT_MAX,
                'monthly_limit' => $limits[$level]['monthly'] ?? PHP_INT_MAX,
                'country'       => $country,
                'address'       => $address,
                'phone'         => $phone,
                'document_type' => $documentType,
                'document_file' => $documentPath,
                'selfie_file'   => $selfiePath,
                'updated_at'    => date('Y-m-d H:i:s'),
                'created_at'    => date('Y-m-d H:i:s'),
            ];

            if ($existingKyc) {
                $db->update('kyc_verifications', $kycData, ['user_id' => $userId]);
            } else {
                $db->insert('kyc_verifications', array_merge(['user_id' => $userId], $kycData));
            }

            $successMessage = 'تم إرسال بيانات التحقق بنجاح. سيتم مراجعتها خلال 24 ساعة.';
        } catch (Exception $e) {
            $uploadError = $e->getMessage();
        }
    }
}

$kyc       = KYCService::getInstance()->getStatus($userId);
$pageDir   = 'rtl';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA | التحقق من الهوية</title>
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
.kyc-wrap { max-width:680px; margin:40px auto; padding:0 20px; }
.kyc-card { background:var(--bg-card); border:1px solid var(--border-gold);
    border-radius:20px; padding:32px; }
.level-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:14px; margin:24px 0; }
.level-box { border:2px solid var(--border-gold); border-radius:14px; padding:18px;
    text-align:center; cursor:pointer; transition:all .2s; }
.level-box:hover, .level-box.selected {
    border-color:var(--gold); background:rgba(255,215,0,.06); }
.level-box.current { border-color:#4CAF50; background:rgba(76,175,80,.06); }
.level-num { font-size:1.8rem; font-weight:800; color:var(--gold); margin-bottom:6px; }
.level-title { font-size:.85rem; font-weight:700; color:var(--text-light); margin-bottom:8px; }
.level-limit { font-size:.78rem; color:var(--text-muted); }
.status-banner { border-radius:12px; padding:16px 20px; margin-bottom:24px;
    display:flex; align-items:center; gap:12px; }
.start-btn { width:100%; padding:15px; border-radius:14px; border:none; cursor:pointer;
    font-size:1rem; font-weight:700; background:var(--gold-gradient); color:#000;
    box-shadow:var(--shadow-gold); transition:all .3s; margin-top:24px; }
.start-btn:disabled { opacity:.5; cursor:not-allowed; }
</style>
</head>
<body>
<nav style="background:rgba(0,0,0,.85);border-bottom:1px solid var(--border-gold);
    padding:14px 28px;display:flex;align-items:center;justify-content:space-between;margin-bottom:0">
    <span style="color:var(--gold);font-weight:800;font-size:1.1rem">
        <i class="fas fa-id-card" style="margin-left:8px"></i><?= __('kyc_verification') ?>
    </span>
    <div style="display:flex;gap:12px;align-items:center">
        <?= langSwitcher() ?>
        <a href="crypto.php" style="color:var(--text-muted);font-size:.85rem;text-decoration:none">
            <i class="fas fa-arrow-right"></i> <?= __('back') ?>
        </a>
    </div>
</nav>

<div class="kyc-wrap">
<div class="kyc-card">

    <!-- حالة KYC الحالية -->
    <?php
    $bannerColor = match($kyc['status']) {
        'approved' => ['#4CAF50','rgba(76,175,80,.1)','fa-check-circle'],
        'pending'  => ['#f0ad4e','rgba(240,173,78,.1)','fa-clock'],
        'rejected' => ['#ef5350','rgba(239,83,80,.1)','fa-times-circle'],
        default    => ['#888','rgba(255,255,255,.05)','fa-info-circle'],
    };
    ?>
    <div class="status-banner" style="background:<?= $bannerColor[1] ?>;border:1px solid <?= $bannerColor[0] ?>44">
        <i class="fas <?= $bannerColor[2] ?>" style="color:<?= $bannerColor[0] ?>;font-size:1.4rem"></i>
        <div>
            <div style="color:<?= $bannerColor[0] ?>;font-weight:700;font-size:.95rem">
                <?= match($kyc['status']) {
                    'approved'    => 'تم التحقق — Level ' . $kyc['level'],
                    'pending'     => 'قيد المراجعة',
                    'rejected'    => 'تم الرفض — تواصل مع الدعم',
                    'not_started' => 'لم تبدأ التحقق بعد',
                    default       => $kyc['status']
                } ?>
            </div>
            <div style="color:var(--text-muted);font-size:.82rem">
                الحد اليومي: <?= $kyc['daily_limit'] >= 999999999 ? '∞ بلا حدود' : '$' . number_format($kyc['daily_limit']) . ' USD' ?> |
                الحد الشهري: <?= $kyc['monthly_limit'] >= 999999999 ? '∞ بلا حدود' : '$' . number_format($kyc['monthly_limit']) . ' USD' ?>
            </div>
        </div>
    </div>

    <h3 style="color:var(--text-light);margin:0 0 8px">اختر مستوى التحقق</h3>
    <p style="color:var(--text-muted);font-size:.85rem;margin:0 0 8px">
        كل مستوى أعلى يتيح لك حدوداً أكبر للتداول
    </p>

    <!-- مستويات KYC -->
    <div class="level-grid">
        <?php foreach ([
            [1, 'أساسي',   'بريد + هاتف',       '1,000', '5,000'],
            [2, 'متوسط',   'هوية + صورة شخصية', '5,000', '50,000'],
            [3, 'مؤسسي',   'مستندات كاملة',     '50,000','500,000'],
        ] as [$lvl,$name,$docs,$daily,$monthly]): ?>
        <?php $isCurrent = $kyc['level'] >= $lvl && $kyc['status'] === 'approved'; ?>
        <div class="level-box <?= $isCurrent ? 'current' : ($lvl==1?'selected':'') ?>"
             id="level<?= $lvl ?>" onclick="selectLevel(<?= $lvl ?>)">
            <?php if ($isCurrent): ?>
            <div style="color:#4CAF50;font-size:.75rem;margin-bottom:4px">
                <i class="fas fa-check"></i> محقّق
            </div>
            <?php endif; ?>
            <div class="level-num"><?= $lvl ?></div>
            <div class="level-title"><?= $name ?></div>
            <div style="color:var(--text-muted);font-size:.75rem;margin-bottom:8px"><?= $docs ?></div>
            <div class="level-limit">يومي: ∞ بلا حدود</div>
            <div class="level-limit">شهري: ∞ بلا حدود</div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ($uploadError): ?>
        <div style="margin-bottom:18px;padding:14px;border-radius:14px;background:rgba(239,83,80,.1);border:1px solid rgba(239,83,80,.35);color:#ef5350;">
            <?= htmlspecialchars($uploadError) ?>
        </div>
    <?php endif; ?>
    <?php if ($successMessage): ?>
        <div style="margin-bottom:18px;padding:14px;border-radius:14px;background:rgba(76,175,80,.1);border:1px solid rgba(76,175,80,.35);color:#4CAF50;">
            <?= htmlspecialchars($successMessage) ?>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" style="margin-top:22px">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="submit_kyc" value="1">
        <input type="hidden" id="selectedLevel" name="level" value="1">

        <div style="display:grid;gap:18px;margin-bottom:24px">
            <div style="display:grid;gap:8px">
                <label style="color:var(--text-light);font-weight:700">البريد الإلكتروني</label>
                <input type="email" value="<?= htmlspecialchars($_SESSION['user_data']['email'] ?? '') ?>" readonly style="padding:12px;border-radius:12px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.7);color:#fff;">
            </div>
            <div style="display:grid;gap:8px">
                <label style="color:var(--text-light);font-weight:700">الهاتف</label>
                <input type="text" name="phone" value="<?= htmlspecialchars($kyc['phone'] ?? '') ?>" required style="padding:12px;border-radius:12px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.7);color:#fff;">
            </div>
            <div style="display:grid;gap:8px">
                <label style="color:var(--text-light);font-weight:700">الدولة</label>
                <input type="text" name="country" value="<?= htmlspecialchars($kyc['country'] ?? '') ?>" required style="padding:12px;border-radius:12px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.7);color:#fff;">
            </div>
            <div style="display:grid;gap:8px">
                <label style="color:var(--text-light);font-weight:700">العنوان</label>
                <textarea name="address" rows="3" required style="padding:12px;border-radius:12px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.7);color:#fff;resize:vertical;"><?= htmlspecialchars($kyc['address'] ?? '') ?></textarea>
            </div>
            <div style="display:grid;gap:8px">
                <label style="color:var(--text-light);font-weight:700">نوع المستند</label>
                <select name="document_type" required style="padding:12px;border-radius:12px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.7);color:#fff;">
                    <?php foreach (['Passport' => 'جواز سفر', 'National ID' => 'هوية وطنية', 'Driver License' => 'رخصة قيادة'] as $value => $label): ?>
                        <option value="<?= htmlspecialchars($value) ?>" <?= ($kyc['document_type'] ?? '') === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:grid;gap:8px">
                <label style="color:var(--text-light);font-weight:700">صورة المستند</label>
                <input type="file" name="document_file" accept="image/jpeg,image/png,application/pdf" required style="padding:12px;border-radius:12px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.7);color:#fff;">
            </div>
            <div style="display:grid;gap:8px">
                <label style="color:var(--text-light);font-weight:700">الصورة الشخصية</label>
                <input type="file" name="selfie_file" accept="image/jpeg,image/png" required style="padding:12px;border-radius:12px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.7);color:#fff;">
            </div>
        </div>

        <?php if ($kyc['status'] === 'pending'): ?>
            <div style="text-align:center;padding:16px;color:#f0ad4e;font-size:.9rem;background:rgba(240,173,78,.08);border:1px solid rgba(240,173,78,.25);border-radius:14px;">
                <i class="fas fa-clock"></i> طلبك قيد المراجعة — سيتم إشعارك عند الانتهاء
            </div>
        <?php elseif ($kyc['status'] === 'approved'): ?>
            <div style="text-align:center;padding:16px;color:#4CAF50;font-size:.9rem;background:rgba(76,175,80,.08);border:1px solid rgba(76,175,80,.25);border-radius:14px;">
                <i class="fas fa-check-circle"></i> تم التحقق بنجاح — يمكنك متابعة الاستخدام
            </div>
        <?php else: ?>
            <button class="start-btn" type="submit">
                <i class="fas fa-id-card"></i> إرسال طلب التحقق
            </button>
        <?php endif; ?>
    </form>

    <!-- شرح العملية -->
    <div style="margin-top:28px;border-top:1px solid var(--border-light);padding-top:20px">
        <h4 style="color:var(--text-light);margin:0 0 14px;font-size:.9rem">كيف تعمل العملية؟</h4>
        <?php foreach ([
            ['fa-upload',    'رفع المستندات',    'هوية وطنية أو جواز سفر + صورة شخصية'],
            ['fa-search',    'المراجعة',          'يتم التحقق خلال 1-24 ساعة'],
            ['fa-check',     'التفعيل',           'ترتفع حدودك تلقائياً'],
        ] as [$ic,$title,$desc]): ?>
        <div style="display:flex;gap:12px;margin-bottom:14px">
            <div style="width:32px;height:32px;border-radius:50%;background:rgba(255,215,0,.1);
                        border:1px solid var(--border-gold);display:flex;align-items:center;
                        justify-content:center;flex-shrink:0">
                <i class="fas <?= $ic ?>" style="color:var(--gold);font-size:.8rem"></i>
            </div>
            <div>
                <div style="color:var(--text-light);font-size:.88rem;font-weight:600"><?= $title ?></div>
                <div style="color:var(--text-muted);font-size:.8rem"><?= $desc ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

</div>
</div>

<div id="toast" style="position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(80px);
    background:rgba(10,16,39,.97);border:1px solid var(--border-gold);border-radius:12px;
    padding:12px 24px;color:var(--text-light);font-size:.9rem;z-index:9999;
    transition:transform .3s"></div>

<script>
function selectLevel(n) {
    document.getElementById('selectedLevel').value = n;
    document.querySelectorAll('.level-box').forEach(b => b.classList.remove('selected'));
    document.getElementById('level' + n).classList.add('selected');
}

async function startKyc() {
    const level = parseInt(document.getElementById('selectedLevel').value);
    const btn   = document.getElementById('startKycBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري...';

    const r = await fetch('api/kyc.php?action=initiate', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({
            level,
            csrf_token: '<?= $csrfToken ?>'
        })
    });
    const d = await r.json();

    if (d.success) {
        if (d.sdk_token) {
            // Sumsub SDK — في الإنتاج يفتح SDK مدمج
            showToast('سيتم فتح واجهة التحقق...', 'success');
            setTimeout(() => window.location.reload(), 2000);
        } else {
            showToast('تم إرسال طلب التحقق — سيتم مراجعته قريباً', 'success');
            setTimeout(() => window.location.reload(), 2500);
        }
    } else {
        showToast(d.message || 'فشل', 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-id-card"></i> ابدأ التحقق';
    }
}

function showToast(msg, type='info') {
    const t = document.getElementById('toast');
    const c = {success:'#4CAF50',error:'#ef5350',info:'var(--gold)'};
    t.style.borderColor = c[type]||c.info;
    t.textContent = msg;
    t.style.transform = 'translateX(-50%) translateY(0)';
    setTimeout(() => t.style.transform = 'translateX(-50%) translateY(80px)', 3500);
}
</script>
</body>
</html>
