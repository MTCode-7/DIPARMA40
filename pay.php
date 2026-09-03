<?php
/**
 * ============================================================
 * DI PARMA | معالجة روابط الدفع - Payment Links Processor
 * ============================================================
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/gateways.php';

$db = db();
$error = null;
$linkData = null;
$linkInput = trim($_POST['link_code'] ?? $_GET['link'] ?? $_GET['ref'] ?? '');
$token = trim($_GET['token'] ?? '');
$showLanding = true;

// 1. البحث عن الرابط عبر نموذج POST (رمز الرابط أو الـ slug)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['find_link'])) {
    if (empty($linkInput)) {
        $error = '❌ الرجاء إدخال رمز الرابط أو slug';
    } else {
        $linkData = $db->find('payment_links', ['link_id' => $linkInput]);
        if (!$linkData) {
            $linkData = $db->find('payment_links', ['slug' => $linkInput]);
        }

        if (!$linkData) {
            $error = '❌ الرابط غير موجود';
        } elseif (!isLinkValid($linkData)) {
            $error = '❌ الرابط منتهي الصلاحية أو غير نشط';
        } else {
            $showLanding = false;
        }
    }
}

// 2. معالجة الرابط القادم مباشرة عبر رابط URL (GET)
$getLinkParam = trim($_GET['link'] ?? '');
if ($showLanding && !empty($getLinkParam)) {
    $linkData = $db->find('payment_links', ['link_id' => $getLinkParam]);
    if (!$linkData) {
        $linkData = $db->find('payment_links', ['slug' => $getLinkParam]);
    }

    if (!$linkData) {
        $error = '❌ الرابط غير موجود';
    } elseif (!empty($token) && isset($linkData['token']) && $linkData['token'] !== $token) {
        $error = '❌ رمز أمان غير صحيح';
    } elseif (!isLinkValid($linkData)) {
        $error = '❌ الرابط منتهي الصلاحية أو غير نشط';
    } else {
        $showLanding = false;
        // زيادة عدد استخدامات الرابط بأمان
        $db->update('payment_links', ['uses_count' => ($linkData['uses_count'] ?? 0) + 1], ['id' => $linkData['id']]);
    }
}

// 3. معالجة إتمام الدفع النهائي عند الضغط على زر (دفع الآن)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pay_now'])) {
    $postedLinkId = trim($_POST['link_id'] ?? '');
    if (empty($postedLinkId)) {
        $error = '❌ لم يتم تحديد الرابط';
    } else {
        $linkData = $db->find('payment_links', ['link_id' => $postedLinkId]);
        if (!$linkData) {
            $linkData = $db->find('payment_links', ['slug' => $postedLinkId]);
        }

        if (!$linkData) {
            $error = '❌ الرابط غير موجود';
        } elseif (!isLinkValid($linkData)) {
            $error = '❌ الرابط منتهي الصلاحية أو غير نشط';
        }
    }

    if (!$error && $linkData) {
        // إنشاء سجل معاملة حقيقية
        $reference = generateReference('TXN');
        $amount = floatval($linkData['amount']);
        $transactionData = [
            'reference' => $reference,
            'gateway' => $linkData['gateway'] ?? 'default',
            'amount' => $amount,
            'currency' => $linkData['currency'] ?? 'USD',
            'customer_name' => $linkData['customer_name'] ?? 'Customer',
            'customer_email' => $linkData['customer_email'] ?? '',
            'customer_phone' => $linkData['customer_phone'] ?? '',
            'status' => 'pending',
            'payment_method' => 'link',
            'description' => $linkData['title'] ?? 'دفع عبر رابط',
            'user_id' => $linkData['user_id'] ?? ($_SESSION['user_id'] ?? 0),
            'fees' => $amount * 0.025,
            'net_amount' => $amount * 0.975,
            'transaction_data' => json_encode($linkData),
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        try {
            $db->insert('transactions', $transactionData);
            $db->update('payment_links', ['uses_count' => ($linkData['uses_count'] ?? 0) + 1], ['id' => $linkData['id']]);
            header('Location: receipt.php?ref=' . urlencode($reference));
            exit();
        } catch (Exception $e) {
            $error = '❌ فشل في تسجيل المعاملة: ' . $e->getMessage();
        }
    }
    $showLanding = false;
}

$availableGateways = function_exists('getConfiguredGateways') ? getConfiguredGateways() : [];
if (empty($availableGateways) && function_exists('getGatewaysConfig')) {
    $availableGateways = getGatewaysConfig();
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang ?? 'en') ?>" dir="<?= htmlspecialchars($pageDir ?? 'ltr') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DI PARMA | الدفع</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: 'Cairo', sans-serif;
            background: radial-gradient(circle at top left, rgba(255,215,0,0.1), transparent 18%),
                        radial-gradient(circle at bottom right, rgba(255,215,0,0.08), transparent 16%),
                        linear-gradient(180deg, #020202 0%, #0b0b0b 35%, #090909 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            color: #FFDFA0;
        }
        .container {
            width: 100%;
            max-width: 500px;
            background: rgba(10,16,39,0.94);
            border: 1px solid rgba(255,215,0,0.25);
            border-radius: 24px;
            padding: 35px;
            backdrop-filter: blur(18px);
            box-shadow: 0 15px 35px rgba(0,0,0,0.5);
        }
        .logo { text-align:center; margin-bottom:25px; }
        .logo .icon {
            display:inline-block; width:60px; height:60px;
            background:linear-gradient(135deg,#FFD700,#B58E15);
            border-radius:16px; font-size:2rem; font-weight:900;
            color:#0A0F1E; line-height:60px; box-shadow:0 0 40px rgba(255,215,0,0.2);
        }
        .logo h1 {
            font-size:1.5rem; font-weight:800;
            background:linear-gradient(135deg,#FFE066,#FFD700);
            -webkit-background-clip:text; -webkit-text-fill-color:transparent;
            margin-top:8px;
        }
        .amount-box {
            background:rgba(255,215,0,0.05); border:1px solid rgba(255,215,0,0.15);
            border-radius:16px; padding:20px; text-align:center; margin:20px 0;
        }
        .amount-box .amount { font-size:2.5rem; font-weight:800; color:#FFD700; }
        .amount-box .currency { font-size:1.2rem; color:#888; }
        .btn {
            width:100%; padding:14px; border:none; border-radius:12px;
            font-family:'Cairo',sans-serif; font-weight:700; font-size:1.1rem;
            cursor:pointer; transition:all 0.3s ease; text-align: center; text-decoration: none; display: block;
        }
        .btn-primary { background:linear-gradient(135deg,#FFE066,#FFD700); color:#0A0F1E; }
        .btn-primary:hover { transform:translateY(-2px); box-shadow:0 8px 30px rgba(255,215,0,0.3); }
        .error {
            background:rgba(217,83,79,0.12); border:1px solid #d9534f; color:#d9534f;
            padding:12px; border-radius:12px; text-align:center; margin-bottom: 15px; font-size: 0.9rem;
        }
        .gateway-list { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin-top: 20px; }
        .gateway-card {
            background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.08);
            border-radius: 16px; padding: 16px; text-align: center; color: #F7F1CD;
        }
        .gateway-card .icon {
            display: inline-flex; align-items: center; justify-content: center;
            width: 44px; height: 44px; border-radius: 50%; background: rgba(255,215,0,0.18);
            color: #FFD700; margin-bottom: 10px; font-size: 1.1rem;
        }
        .gateway-card h3 { font-size: 0.95rem; margin-bottom: 6px; }
        .gateway-card p { font-size: 0.8rem; color: #AAA; line-height: 1.4; }
        .search-box margin: 20px 0 10px; }
        .search-box input {
            width: 100%; border: 1px solid rgba(255,255,255,0.12); border-radius: 12px;
            padding: 14px 16px; background: rgba(255,255,255,0.04); color: #fff; font-size: 1rem; margin-bottom: 12px;
        }
        .search-box input::placeholder { color: rgba(255,255,255,0.6); }
        .info-text { color: #CCC; font-size: 0.9rem; margin-top: 12px; line-height: 1.6; text-align: center; }
        @media (max-width: 600px) { .gateway-list { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">
            <div class="icon">DP</div>
            <h1>DI PARMA</h1>
        </div>
        
        <?php if ($showLanding): ?>
            <div style="text-align:center;margin-bottom:15px;color:#DDD;font-size:0.95rem;">
                <i class="fas fa-credit-card"></i> صفحة الدفع العامة. أدخل رمز الرابط أو slug لبدء الدفع.
            </div>
            
            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            
            <div class="search-box">
                <form method="POST">
                    <input type="text" name="link_code" value="<?= htmlspecialchars($linkInput) ?>" placeholder="ضع رمز الرابط أو slug هنا">
                    <button type="submit" name="find_link" class="btn btn-primary" style="margin-top: 5px;">تحقق من الرابط</button>
                </form>
            </div>

            <div class="info-text">
                يمكنك إدخال رمز الرابط المرسَل إليك، أو استخدام رابط الدفع الخاص بك إذا كان لديك واحد.
            </div>

            <?php if (!empty($availableGateways)): ?>
                <div class="gateway-list">
                    <?php foreach ($availableGateways as $code => $gateway): ?>
                        <div class="gateway-card">
                            <div class="icon"><i class="<?= htmlspecialchars($gateway['icon'] ?? 'fas fa-credit-card') ?>"></i></div>
                            <h3><?= htmlspecialchars($gateway['name'] ?? $code) ?></h3>
                            <p><?= htmlspecialchars($gateway['region'] ?? 'عالمي') ?> · <?= htmlspecialchars(implode(', ', array_slice($gateway['currencies'] ?? ['USD'], 0, 3))) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        <?php elseif ($linkData): ?>
            <div style="text-align:center;margin-bottom:15px;color:#888;font-size:0.9rem;">
                <i class="fas fa-file-invoice"></i> <?= htmlspecialchars($linkData['title'] ?? 'فاتورة دفع') ?>
            </div>
            
            <div class="amount-box">
                <div class="amount"><?= number_format($linkData['amount'], 2) ?></div>
                <div class="currency"><?= htmlspecialchars($linkData['currency'] ?? 'USD') ?></div>
            </div>
            
            <form method="POST">
                <input type="hidden" name="link_id" value="<?= htmlspecialchars($linkData['link_id'] ?? $linkData['slug']) ?>">
                <button type="submit" name="pay_now" class="btn btn-primary">
                    <i class="fas fa-check-circle"></i> دفع الآن
                </button>
            </form>
            
            <div style="margin-top:15px;font-size:0.75rem;color:#777;text-align:center;">
                <i class="fas fa-shield-alt"></i> مدعوم من DI PARMA Gateway
            </div>
        <?php else: ?>
            <div class="error"><?= htmlspecialchars($error ?? '❌ الرابط غير صالح أو منتهي الصلاحية') ?></div>
            <div class="info-text" style="margin-top:18px;">
                استخدم صفحة الدفع العامة لإدخال رمز الرابط أو افتح الرابط مباشرة من الرسالة.
            </div>
        <?php endif; ?>
    </div>
</body>
</html>