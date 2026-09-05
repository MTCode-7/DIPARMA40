<?php
/**
 * ============================================================
 * DI PARMA | إدارة بوابات الدفع - Gateway Manager
 * ============================================================
 */

// ============================================================
// [1] تضمين الملفات المطلوبة (المسارات الصحيحة)
// ============================================================

// تعيين المسار الأساسي
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__));
}

// تضمين الملفات من المجلد الصحيح
require_once ROOT_PATH . '/includes/config.php';
require_once ROOT_PATH . '/includes/database.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/includes/gateways.php';
require_once ROOT_PATH . '/includes/auth_check.php';

requireAdmin();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// ============================================================
// [2] مدير بوابات الدفع
// ============================================================

$db = db();
function gatewayFieldLabel($field) {
    $labels = [
        'api_key' => 'API Key',
        'access_token' => 'Access Token',
        'public_key' => 'Public Key',
        'secret' => 'Secret',
        'secret_key' => 'Secret Key',
        'client_id' => 'Client ID',
        'merchant_id' => 'Merchant ID',
        'merchant_account' => 'Merchant Account',
        'webhook' => 'Webhook URL',
        'success' => 'Success URL',
        'cancel' => 'Cancel URL',
        'webhook_url' => 'Webhook URL',
        'callback_url' => 'Callback URL',
        'profile_id' => 'Profile ID',
        'private_key' => 'Private Key',
        'environment' => 'Environment',
        'network' => 'Network',
        'receiver_address' => 'Receiver Address'
    ];
    return $labels[$field] ?? ucfirst(str_replace(['_', '-'], ' ', $field));
}

function gatewayCompletionBadge(array $gateway) {
    $config = getGatewayConfig($gateway['code']);
    $rowConfig = json_decode($gateway['config'] ?? '{}', true) ?: [];
    $credentials = json_decode($gateway['credentials'] ?? '{}', true) ?: [];

    $gw = [
        'status' => $gateway['status'] ?? 'inactive',
        'config' => array_replace_recursive($config ?? [], $rowConfig),
        'credentials' => $credentials,
        'connection_status' => $gateway['connection_status'] ?? '',
        'setup_complete' => !empty($gateway['setup_complete']) || !empty($rowConfig['setup_complete']) || !empty($config['setup_complete'])
    ];

    $ready = isGatewayReady($gw);
    return [
        'label' => $ready ? 'جاهز للعمل ✅' : 'غير جاهز ⚠️',
        'icon' => $ready ? 'check-circle' : 'exclamation-circle',
        'class' => $ready ? 'badge-complete' : 'badge-incomplete'
    ];
}

function getGatewayFormSchema($code) {
    $config = getGatewayConfig($code);
    if (!$config) {
        return [
            'credentials' => ['api_key', 'api_secret', 'api_url', 'merchant_id'],
            'settings' => ['webhook_url', 'callback_url']
        ];
    }

    $credentials = array_keys($config['credentials'] ?? []);
    if (empty($credentials)) {
        $credentials = ['api_key', 'api_secret'];
    }

    $settings = [];
    $urls = $config['urls'] ?? [];
    foreach (['webhook', 'success', 'cancel'] as $key) {
        if (isset($urls[$key])) {
            $settings[] = $key;
        }
    }
    if (isset($config['environment'])) {
        $settings[] = 'environment';
    }

    if (empty($settings)) {
        $settings = ['webhook_url', 'callback_url'];
    }

    return [
        'credentials' => $credentials,
        'settings' => $settings
    ];
}

function getGatewayFormValues($gateway, $schema) {
    $credentials = json_decode($gateway['credentials'] ?? '{}', true);
    $settings = json_decode($gateway['settings'] ?? '{}', true);

    $values = ['credentials' => [], 'settings' => []];
    foreach ($schema['credentials'] as $field) {
        $values['credentials'][$field] = $credentials[$field] ?? ($gateway[$field] ?? '');
    }
    foreach ($schema['settings'] as $field) {
        $values['settings'][$field] = $settings[$field] ?? ($gateway[$field] ?? '');
    }

    return $values;
}
// ============================================================
// [3] معالجة الطلبات
// ============================================================

$message = '';
$messageType = '';
$editGateway = null;
$showProfileForm = false;
$profileFormData = [
    'username' => $_SESSION['user_data']['username'] ?? '',
    'current_password' => '',
    'new_password' => '',
    'confirm_password' => ''
];

// معالجة تحديث بيانات آدمن الحساب
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_admin_credentials') {
    $showProfileForm = true;
    $csrfTokenValue = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($csrfTokenValue)) {
        $message = '❌ فشل التحقق الأمني. حاول مرة أخرى.';
        $messageType = 'error';
    } else {
        $userId = intval($_SESSION['user_id'] ?? 0);
        $profileFormData['username'] = trim($_POST['username'] ?? '');
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($userId <= 0 || empty($profileFormData['username']) || empty($currentPassword)) {
            $message = '❌ يرجى إدخال اسم المستخدم الحالي وكلمة المرور الحالية.';
            $messageType = 'error';
        } else {
            try {
                $user = $db->find('users', ['id' => $userId]);
                if (!$user) {
                    $message = '❌ لم يتم العثور على المستخدم.';
                    $messageType = 'error';
                } elseif (!password_verify($currentPassword, $user['password_hash'])) {
                    $message = '❌ كلمة المرور الحالية غير صحيحة.';
                    $messageType = 'error';
                } else {
                    $updateData = [];
                    if ($profileFormData['username'] !== $user['username']) {
                        $exists = $db->find('users', ['username' => $profileFormData['username']]);
                        if ($exists && intval($exists['id']) !== $userId) {
                            $message = '❌ اسم المستخدم غير متاح. يرجى اختيار اسم آخر.';
                            $messageType = 'error';
                        } else {
                            $updateData['username'] = $profileFormData['username'];
                        }
                    }

                    if ($newPassword !== '') {
                        if ($newPassword !== $confirmPassword) {
                            $message = '❌ كلمة المرور الجديدة وتأكيدها غير متطابقتين.';
                            $messageType = 'error';
                        } else {
                            $updateData['password_hash'] = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
                        }
                    }

                    if (empty($updateData) && $messageType !== 'error') {
                        $message = 'ℹ️ لم يتم إجراء تغييرات.';
                        $messageType = 'info';
                    }

                    if (!empty($updateData) && $messageType !== 'error') {
                        $db->update('users', $updateData, ['id' => $userId]);
                        if (isset($updateData['username'])) {
                            $_SESSION['user_data']['username'] = $updateData['username'];
                        }
                        $message = '✅ تم تحديث بيانات الدخول بنجاح.';
                        $messageType = 'success';
                        $profileFormData['new_password'] = '';
                        $profileFormData['confirm_password'] = '';
                    }
                }
            } catch (Exception $e) {
                $message = '❌ حدث خطأ أثناء تحديث بيانات الحساب.';
                $messageType = 'error';
            }
        }
    }
}

// ── اختبار الاتصال بالبوابة ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'test_connection') {
    header('Content-Type: application/json; charset=utf-8');
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'فشل التحقق الأمني'], JSON_UNESCAPED_UNICODE);
        exit();
    }
    $id = intval($_POST['gateway_id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'gateway_id مطلوب']);
        exit();
    }
    require_once ROOT_PATH . '/lib/GatewayConnectionTester.php';
    $tester = new GatewayConnectionTester();
    $result = $tester->test($id);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit();
}

// ── تعديل وحفظ بوابة موجودة ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_gateway') {
    $id = intval($_POST['gateway_id'] ?? $_POST['id'] ?? 0);
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = '❌ فشل التحقق الأمني. حاول مرة أخرى.';
        $messageType = 'error';
    } elseif ($id > 0) {
        try {
            $existing = $db->find('payment_gateways', ['id' => $id]);
            if ($existing) {
                $oldCreds = json_decode($existing['credentials'] ?? '{}', true) ?: [];
                $oldSettings = json_decode($existing['settings'] ?? '{}', true) ?: [];
                $schema = getGatewayFormSchema($existing['code']);
                $credentials = $oldCreds;
                foreach ($schema['credentials'] as $field) {
                    if (array_key_exists($field, $_POST) && trim((string)$_POST[$field]) !== '') {
                        $credentials[$field] = trim((string)$_POST[$field]);
                    }
                }
                $settings = $oldSettings;
                foreach ($schema['settings'] as $field) {
                    if (array_key_exists($field, $_POST)) {
                        $settings[$field] = trim((string)$_POST[$field]);
                    }
                }

                $db->update('payment_gateways', [
                    'name'             => trim($_POST['name'] ?? $existing['name']),
                    'type'             => trim($_POST['type'] ?? $existing['type']),
                    'status'           => trim($_POST['status'] ?? $existing['status']),
                    'api_endpoint'     => trim($_POST['api_endpoint']     ?? $existing['api_endpoint'] ?? ''),
                    'api_version'      => trim($_POST['api_version']      ?? $existing['api_version'] ?? ''),
                    'connection_type'  => trim($_POST['connection_type']  ?? $existing['connection_type'] ?? 'rest'),
                    'gateway_type'     => trim($_POST['gateway_type']     ?? $existing['gateway_type'] ?? 'card'),
                    'supports_2d'      => isset($_POST['supports_2d'])    ? 1 : 0,
                    'supports_3d'      => isset($_POST['supports_3d'])    ? 1 : 0,
                    'supports_hold'    => isset($_POST['supports_hold'])   ? 1 : 0,
                    'supports_capture' => isset($_POST['supports_capture'])? 1 : 0,
                    'sort_order'       => intval($_POST['sort_order']     ?? 0),
                    'credentials'      => json_encode($credentials, JSON_UNESCAPED_UNICODE),
                    'settings'         => json_encode($settings, JSON_UNESCAPED_UNICODE),
                    'updated_at'       => date('Y-m-d H:i:s'),
                ], ['id' => $id]);

                $message     = '✅ تم حفظ التعديلات بنجاح';
                $messageType = 'success';
            } else {
                $message     = '❌ البوابة غير موجودة';
                $messageType = 'error';
            }
        } catch (Exception $e) {
            $message     = '❌ خطأ: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// ── اختبار جميع البوابات دفعة واحدة ─────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'test_all_connections') {
    header('Content-Type: application/json; charset=utf-8');
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'فشل التحقق الأمني'], JSON_UNESCAPED_UNICODE);
        exit();
    }
    require_once ROOT_PATH . '/lib/GatewayConnectionTester.php';
    $tester = new GatewayConnectionTester();
    $result = $tester->testAll();
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit();
}

// إضافة بوابة جديدة
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_gateway') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = '❌ فشل التحقق الأمني. حاول مرة أخرى.';
        $messageType = 'error';
    } else {
    $data = [
        'code' => trim($_POST['code'] ?? ''),
        'name' => trim($_POST['name'] ?? ''),
        'type' => trim($_POST['type'] ?? 'electronic'),
        'status' => trim($_POST['status'] ?? 'inactive'),
        'api_key' => trim($_POST['api_key'] ?? ''),
        'api_secret' => trim($_POST['api_secret'] ?? ''),
        'api_url' => trim($_POST['api_url'] ?? ''),
        'merchant_id' => trim($_POST['merchant_id'] ?? ''),
        'webhook_url' => trim($_POST['webhook_url'] ?? ''),
        'callback_url' => trim($_POST['callback_url'] ?? ''),
    ];
    
    if (empty($data['code']) || empty($data['name'])) {
        $message = '❌ يرجى إدخال كود واسم البوابة';
        $messageType = 'error';
    } else {
        try {
            // التحقق من عدم وجود تكرار
            $exists = $db->find('payment_gateways', ['code' => $data['code']]);
            if ($exists) {
                $message = '❌ البوابة موجودة بالفعل';
                $messageType = 'error';
            } else {
                $config = json_encode([
                    'currencies' => ['USD', 'EUR', 'GBP', 'AED'],
                    'fees' => ['percentage' => 2.5, 'fixed' => 0.30],
                    'limits' => ['min' => 1, 'max_daily' => 50000, 'max_monthly' => 250000]
                ]);
                
                $credentials = json_encode([
                    'api_key' => $data['api_key'],
                    'api_secret' => $data['api_secret'],
                    'api_url' => $data['api_url'],
                    'merchant_id' => $data['merchant_id']
                ]);
                
                $settings = json_encode([
                    'webhook_url' => $data['webhook_url'],
                    'callback_url' => $data['callback_url'],
                    'timeout' => 30,
                    'retry_attempts' => 3
                ]);
                
                $db->insert('payment_gateways', [
                    'code'              => $data['code'],
                    'name'              => $data['name'],
                    'type'              => $data['type'],
                    'status'            => $data['status'],
                    'config'            => $config,
                    'credentials'       => $credentials,
                    'settings'          => $settings,
                    'connection_type'   => trim($_POST['connection_type']  ?? 'rest'),
                    'api_endpoint'      => trim($_POST['api_endpoint']     ?? $data['api_url']),
                    'api_version'       => trim($_POST['api_version']      ?? ''),
                    'gateway_type'      => trim($_POST['gateway_type']     ?? 'card'),
                    'supports_2d'       => isset($_POST['supports_2d'])    ? 1 : 0,
                    'supports_3d'       => isset($_POST['supports_3d'])    ? 1 : 0,
                    'supports_hold'     => isset($_POST['supports_hold'])   ? 1 : 0,
                    'supports_capture'  => isset($_POST['supports_capture'])? 1 : 0,
                    'connection_status' => 'untested',
                    'sort_order'        => intval($_POST['sort_order']     ?? 0),
                ]);
                
                $message = '✅ تم إضافة البوابة بنجاح';
                $messageType = 'success';
            }
        } catch (Exception $e) {
            $message = '❌ خطأ: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
    }
}

// حذف بوابة
if (isset($_GET['delete']) && isset($_GET['token'])) {
    $id = intval($_GET['delete']);
    $token = $_GET['token'];
    
    if (hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        try {
            $db->delete('payment_gateways', ['id' => $id]);
            $message = '✅ تم حذف البوابة بنجاح';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = '❌ خطأ في الحذف';
            $messageType = 'error';
        }
    }
}

// فتح نموذج تعديل البوابة
if (isset($_GET['edit'])) {
    $id = intval($_GET['edit']);
    if ($id > 0) {
        $editGateway = $db->find('payment_gateways', ['id' => $id]);
        if (!$editGateway) {
            $message = '❌ لم يتم العثور على البوابة المطلوبة.';
            $messageType = 'error';
        }
    }
}

// تحديث حالة البوابة
if (isset($_GET['toggle']) && isset($_GET['token'])) {
    $id = intval($_GET['toggle']);
    $token = $_GET['token'];
    
    if (hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        try {
            $gateway = $db->find('payment_gateways', ['id' => $id]);
            if ($gateway) {
                $newStatus = $gateway['status'] === 'active' ? 'inactive' : 'active';
                $db->update('payment_gateways', ['status' => $newStatus], ['id' => $id]);
                $message = '✅ تم تغيير الحالة بنجاح';
                $messageType = 'success';
            }
        } catch (Exception $e) {
            $message = '❌ خطأ في تغيير الحالة';
            $messageType = 'error';
        }
    }
}

// مزامنة البوابات المفقودة من التكوين
if (isset($_GET['sync']) && isset($_GET['token'])) {
    $token = $_GET['token'];
    if (hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        try {
            $existing = $db->query("SELECT code FROM " . DB_PREFIX . "payment_gateways");
            $existingCodes = [];
            foreach ($existing as $existingRow) {
                $existingCode = strtolower(trim((string)($existingRow['code'] ?? '')));
                if ($existingCode !== '') {
                    $existingCodes[$existingCode] = true;
                }
            }
            // استخدم التكوين الثابت هنا حتى لا تعيد سجلات قاعدة البيانات نفسها إلى قائمة المزامنة.
            $configured = $GLOBALS['PAYMENT_GATEWAYS_CONFIG'] ?? [];
            $added = 0;
            foreach ($configured as $code => $cfg) {
                $code = strtolower(trim((string)$code));
                if ($code === '' || isset($existingCodes[$code])) {
                    continue;
                }

                $type = 'electronic';
                if (($cfg['region'] ?? '') === 'Crypto') {
                    $type = 'crypto';
                } elseif (in_array($cfg['region'] ?? '', ['UK', 'Europe', 'USA'], true)) {
                    $type = 'bank';
                }

                $status = ($cfg['setup_complete'] ?? false) ? 'active' : 'inactive';
                $credentials = json_encode($cfg['credentials'] ?? []);
                $config = json_encode([
                    'currencies' => $cfg['currencies'] ?? ['USD'],
                    'fees' => $cfg['fees'] ?? ['percentage' => 2.5, 'fixed' => 0.30],
                    'limits' => $cfg['limits'] ?? ['min' => 1, 'max_daily' => 50000, 'max_monthly' => 250000],
                    'urls' => $cfg['urls'] ?? [],
                    'environment' => $cfg['environment'] ?? 'test',
                    'features' => $cfg['features'] ?? [],
                    'card_types' => $cfg['card_types'] ?? [],
                    'region' => $cfg['region'] ?? 'Global'
                ]);
                $settings = json_encode([
                    'webhook_url' => $cfg['urls']['webhook'] ?? '',
                    'success_url' => $cfg['urls']['success'] ?? '',
                    'cancel_url' => $cfg['urls']['cancel'] ?? '',
                    'environment' => $cfg['environment'] ?? 'test'
                ]);

                $db->insert('payment_gateways', [
                    'code' => $code,
                    'name' => $cfg['name'] ?? $code,
                    'type' => $type,
                    'status' => $status,
                    'config' => $config,
                    'credentials' => $credentials,
                    'settings' => $settings
                ]);
                $existingCodes[$code] = true;
                $added++;
            }

            if ($added > 0) {
                $message = "✅ تم إضافة {$added} بوابة مفقودة من التكوين";
                $messageType = 'success';
            } else {
                $message = 'ℹ️ لا توجد بوابات جديدة لإضافتها من التكوين';
                $messageType = 'info';
            }
        } catch (Exception $e) {
            $message = '❌ خطأ في مزامنة البوابات: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// ============================================================
// [4] جلب البوابات
// ============================================================

$existing = $db->query("SELECT code FROM " . DB_PREFIX . "payment_gateways");
$existingCodes = [];
foreach ($existing as $existingRow) {
    $existingCode = strtolower(trim((string)($existingRow['code'] ?? '')));
    if ($existingCode !== '') {
        $existingCodes[$existingCode] = true;
    }
}

// ابدأ من الكتالوج الثابت لضمان ظهور كل البوابات المعرفة، ومنها Nuvei.
$configuredGateways = $GLOBALS['PAYMENT_GATEWAYS_CONFIG'] ?? [];
$configuredFromDatabase = getGatewaysConfig();
foreach ($configuredFromDatabase as $code => $config) {
    $normalizedCode = strtolower(trim((string)$code));
    if ($normalizedCode !== '') {
        $configuredGateways[$normalizedCode] = array_replace_recursive(
            $configuredGateways[$normalizedCode] ?? [],
            $config
        );
    }
}

$missingGatewayCodes = [];
foreach ($configuredGateways as $code => $config) {
    $normalizedCode = strtolower(trim((string)$code));
    if ($normalizedCode !== '' && !isset($existingCodes[$normalizedCode])) {
        $missingGatewayCodes[] = $normalizedCode;
    }
}
$missingGatewayCount = count($missingGatewayCodes);

// جمع بوابات التكوين وسجلات قاعدة البيانات، بما فيها البوابات المضافة يدويًا.
$gatewayRows = $db->query("SELECT * FROM " . DB_PREFIX . "payment_gateways");
$rowsByCode = [];
foreach ($gatewayRows as $row) {
    $rowCode = strtolower(trim((string)($row['code'] ?? '')));
    if ($rowCode !== '') {
        $rowsByCode[$rowCode] = $row;
    }
}

$gateways = [];
$addGateway = static function ($code, array $config, ?array $row = null) {
    $code = strtolower(trim($code));
    $row = $row ?? [];
    $status = ($row['status'] ?? null) ?: (($config['setup_complete'] ?? false) ? 'active' : 'inactive');

    return array_merge($row, [
        'id' => $row['id'] ?? 0,
        'code' => $code,
        'name' => $row['name'] ?? ($config['name'] ?? ucfirst($code)),
        'type' => $row['type'] ?? (($config['region'] ?? 'Global') === 'Crypto' ? 'crypto' : 'electronic'),
        'status' => $status,
        'config' => $row['config'] ?? json_encode($config),
        'credentials' => $row['credentials'] ?? json_encode($config['credentials'] ?? []),
        'settings' => $row['settings'] ?? json_encode($config['urls'] ?? []),
        'created_at' => $row['created_at'] ?? date('Y-m-d H:i:s')
    ]);
};

foreach ($configuredGateways as $code => $config) {
    $normalizedCode = strtolower(trim($code));
    $gateways[] = $addGateway($normalizedCode, $config, $rowsByCode[$normalizedCode] ?? null);
    unset($rowsByCode[$normalizedCode]);
}

// لا تخفِ بوابة مخصصة أضافها المدير حتى لو لم تكن في ملف التكوين.
foreach ($rowsByCode as $code => $row) {
    $gateways[] = $addGateway($code, [], $row);
}

usort($gateways, function ($a, $b) {
    if ($a['code'] === 'nuvei' && $b['code'] !== 'nuvei') {
        return -1;
    }
    if ($b['code'] === 'nuvei' && $a['code'] !== 'nuvei') {
        return 1;
    }

    $order = ['active' => 0, 'inactive' => 1, 'pending' => 2, 'suspended' => 3];
    $aOrder = $order[strtolower((string)($a['status'] ?? 'inactive'))] ?? 99;
    $bOrder = $order[strtolower((string)($b['status'] ?? 'inactive'))] ?? 99;

    if ($aOrder !== $bOrder) {
        return $aOrder <=> $bOrder;
    }

    return strcmp($a['name'], $b['name']);
});

// ============================================================
// [5] CSRF Token
// ============================================================

$csrfToken = generateCsrfToken();

// ============================================================
// [6] عرض الصفحة
// ============================================================
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang ?? 'en') ?>" dir="<?= htmlspecialchars($pageDir ?? 'ltr') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DI PARMA | إدارة البوابات</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        :root {
            --gold: #FFD700;
            --gold-dark: #B58E15;
            --gold-light: #FFE066;
            --bg-dark: #0A0F1E;
            --bg-card: rgba(10,16,39,0.94);
            --text-gold: #FFDFA0;
            --text-light: #E8F0FF;
            --border-gold: rgba(255,215,0,0.25);
            --success: #4CAF50;
            --danger: #d9534f;
            --warning: #f0ad4e;
            --info: #5bc0de;
        }
        body {
            font-family: 'Cairo', sans-serif;
            background: radial-gradient(circle at top left, rgba(255,215,0,0.1), transparent 18%),
                        radial-gradient(circle at bottom right, rgba(255,215,0,0.08), transparent 16%),
                        linear-gradient(180deg, #020202 0%, #0b0b0b 35%, #090909 100%);
            color: var(--text-gold);
            min-height: 100vh;
        }
        .container { max-width: 1400px; margin: 0 auto; padding: 20px; }
        
        .header {
            background: rgba(0,0,0,0.85);
            border: 1px solid var(--border-gold);
            backdrop-filter: blur(20px);
            padding: 15px 30px;
            border-radius: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 15px;
        }
        .header h1 {
            font-size: 1.5rem;
            background: linear-gradient(135deg, var(--gold-light), var(--gold));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 10px;
            font-family: 'Cairo', sans-serif;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            font-size: 0.9rem;
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--gold-light), var(--gold));
            color: var(--bg-dark);
        }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(255,215,0,0.3); }
        .btn-success { background: var(--success); color: white; }
        .btn-danger { background: var(--danger); color: white; }
        .btn-warning { background: var(--warning); color: white; }
        .btn-info { background: var(--info); color: white; }
        .btn-outline { background: transparent; border: 1px solid var(--border-gold); color: var(--text-gold); }
        .btn-sm { padding: 5px 12px; font-size: 0.75rem; }
        
        .gateways-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
        }
        .gateway-card {
            background: var(--bg-card);
            border: 1px solid var(--border-gold);
            border-radius: 16px;
            padding: 20px;
            transition: all 0.3s ease;
        }
        .gateway-card:hover { transform: translateY(-5px); border-color: var(--gold); }
        .gateway-card .header-card {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 15px;
        }
        .gateway-card .info h3 { color: var(--text-light); font-size: 1.1rem; }
        .gateway-card .info .code { color: #888; font-size: 0.8rem; }
        .gateway-card .status {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        .status-active { background: rgba(76,175,80,0.2); color: var(--success); }
        .status-inactive { background: rgba(217,83,79,0.2); color: var(--danger); }
        .gateway-card .badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            margin-top: 8px;
            border: 1px solid rgba(255,255,255,0.08);
        }
        .badge-complete { background: rgba(76,175,80,0.12); color: var(--success); border-color: rgba(76,175,80,0.25); }
        .badge-incomplete { background: rgba(255,193,7,0.12); color: var(--warning); border-color: rgba(255,193,7,0.25); }
        
        .gateway-card .details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin: 15px 0;
            padding: 15px;
            background: rgba(0,0,0,0.3);
            border-radius: 10px;
            font-size: 0.8rem;
        }
        .gateway-card .details .label { color: #888; }
        .gateway-card .details .value { color: var(--text-gold); font-weight: 600; }
        .gateway-card .actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid rgba(255,255,255,0.05);
        }
        
        .alert {
            padding: 12px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            text-align: center;
            font-weight: 600;
        }
        .alert-success { background: rgba(76,175,80,0.12); border: 1px solid var(--success); color: var(--success); }
        .alert-error { background: rgba(217,83,79,0.12); border: 1px solid var(--danger); color: var(--danger); }
        
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; color: var(--text-gold); font-size: 0.8rem; font-weight: 600; margin-bottom: 5px; }
        .form-group input, .form-group select { width: 100%; padding: 10px 14px; background: rgba(0,0,0,0.8); border: 1px solid rgba(255,255,255,0.08); border-radius: 10px; color: var(--text-light); font-family: 'Cairo', sans-serif; font-size: 0.9rem; }
        .form-group input:focus, .form-group select:focus { outline: none; border-color: var(--gold); }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        
        .empty-state { text-align:center; padding:40px 20px; color:#666; }
        .empty-state i { font-size:4rem; display:block; margin-bottom:15px; color:var(--border-gold); }
        
        @media (max-width: 768px) {
            .gateways-grid { grid-template-columns:1fr; }
            .form-row { grid-template-columns:1fr; }
            .header { flex-direction:column; text-align:center; }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <h1><i class="fas fa-route"></i> إدارة بوابات الدفع</h1>
        <div style="color:#5bc0de;font-size:.9rem;font-weight:700;">
            إجمالي البوابات: <?= count($gateways) ?>
        </div>
        <div>
            <a href="../index.php" class="btn btn-outline"><i class="fas fa-home"></i> الرئيسية</a>
            <a href="bank_gateways.php" class="btn btn-info" style="background:linear-gradient(135deg,#1a6fb5,#1356a0);color:#fff;border:none"><i class="fas fa-university"></i> بوابات البنوك</a>
            <a href="performance_monitor.php" class="btn btn-outline" style="border-color:rgba(76,175,80,.5);color:#4CAF50"><i class="fas fa-tachometer-alt"></i> مراقبة الأداء</a>
            <a href="../dashboard.php" class="btn btn-outline"><i class="fas fa-chart-pie"></i> لوحة التحكم</a>
            <a href="?profile=true" class="btn btn-info"><i class="fas fa-user-cog"></i> تغيير اسم المستخدم / كلمة المرور</a>
            <a href="?sync=true&token=<?= $csrfToken ?>" class="btn btn-success" onclick="return confirm('هل تريد إضافة جميع بوابات الدفع المفقودة من التكوين؟')"><i class="fas fa-plus-circle"></i> إضافة جميع البوابات</a>
            <a href="?add=true" class="btn btn-primary" onclick="toggleAddForm()"><i class="fas fa-plus"></i> إضافة بوابة</a>
            <button type="button" id="btn-test-all" onclick="testAllConnections()"
                style="background:rgba(91,192,222,.15);border:1px solid #5bc0de;color:#5bc0de;
                       padding:10px 20px;border-radius:10px;cursor:pointer;font-family:Cairo,sans-serif;font-weight:600;font-size:.9rem">
                <i class="fas fa-plug"></i> اختبار الكل
            </button>
        </div>
    </div>
    
    <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?>"><?= $message ?></div>
    <?php endif; ?>

    <?php if ($missingGatewayCount > 0): ?>
        <div class="alert alert-info">
            هناك <?= $missingGatewayCount ?> بوابة دفع غير مضافة بعد: <?= htmlspecialchars(implode(', ', $missingGatewayCodes)) ?>
            <br>
            <a href="?sync=true&token=<?= $csrfToken ?>" class="btn btn-success btn-sm" style="margin-top:10px; display:inline-flex; align-items:center; gap:8px;"><i class="fas fa-plus-circle"></i> إضافة جميع البوابات المفقودة</a>
        </div>
    <?php endif; ?>

    <!-- ===== نموذج تغيير بيانات الحساب ===== -->
    <?php if (isset($_GET['profile']) || $showProfileForm): ?>
        <div style="background:var(--bg-card);border:1px solid var(--border-gold);border-radius:16px;padding:25px;margin-bottom:25px;">
            <h3 style="color:var(--text-light);margin-bottom:20px;"><i class="fas fa-user-cog"></i> تغيير اسم المستخدم وكلمة المرور</h3>
            <form method="POST">
                <input type="hidden" name="action" value="update_admin_credentials">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

                <div class="form-row">
                    <div class="form-group">
                        <label><i class="fas fa-user"></i> اسم المستخدم الجديد</label>
                        <input type="text" name="username" value="<?= htmlspecialchars($profileFormData['username']) ?>" required placeholder="أدخل اسم المستخدم الجديد">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label><i class="fas fa-lock"></i> كلمة المرور الحالية</label>
                        <input type="password" name="current_password" value="" required placeholder="أدخل كلمة المرور الحالية">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label><i class="fas fa-lock"></i> كلمة المرور الجديدة</label>
                        <input type="password" name="new_password" value="" placeholder="أدخل كلمة المرور الجديدة">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-lock"></i> تأكيد كلمة المرور</label>
                        <input type="password" name="confirm_password" value="" placeholder="أعد كتابة كلمة المرور الجديدة">
                    </div>
                </div>

                <div style="display:flex;gap:10px;margin-top:15px;flex-wrap:wrap;">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> حفظ التغييرات</button>
                    <a href="gateway_manager.php" class="btn btn-outline"><i class="fas fa-times"></i> إلغاء</a>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <!-- ===== نموذج إضافة بوابة ===== -->
    <?php if (isset($_GET['add']) || isset($_GET['edit'])): ?>
        <?php
            $isEditMode = isset($_GET['edit']) && $editGateway;
            $formData = [
                'code' => $isEditMode ? $editGateway['code'] : '',
                'name' => $isEditMode ? $editGateway['name'] : '',
                'type' => $isEditMode ? $editGateway['type'] : 'electronic',
                'status' => $isEditMode ? $editGateway['status'] : 'inactive',
                'api_key' => '',
                'api_secret' => '',
                'api_url' => '',
                'merchant_id' => '',
                'webhook_url' => '',
                'callback_url' => ''
            ];

            $schema = [
                'credentials' => ['api_key', 'api_secret', 'api_url', 'merchant_id'],
                'settings' => ['webhook_url', 'callback_url']
            ];
            $credentialValues = [
                'api_key' => '',
                'api_secret' => '',
                'api_url' => '',
                'merchant_id' => ''
            ];
            $settingValues = [
                'webhook_url' => '',
                'callback_url' => ''
            ];

            if ($isEditMode) {
                $schema = getGatewayFormSchema($editGateway['code']);
                $values = getGatewayFormValues($editGateway, $schema);
                $credentialValues = $values['credentials'];
                $settingValues = $values['settings'];
                $formData = array_merge($formData, [
                    'api_key' => $credentialValues['api_key'] ?? '',
                    'api_secret' => $credentialValues['secret'] ?? $credentialValues['secret_key'] ?? '',
                    'api_url' => $credentialValues['api_url'] ?? '',
                    'merchant_id' => $credentialValues['merchant_id'] ?? '',
                    'webhook_url' => $settingValues['webhook'] ?? $settingValues['webhook_url'] ?? '',
                    'callback_url' => $settingValues['callback_url'] ?? ''
                ]);
            }
        ?>
        <div style="background:var(--bg-card);border:1px solid var(--border-gold);border-radius:16px;padding:25px;margin-bottom:25px;">
            <h3 style="color:var(--text-light);margin-bottom:20px;"><i class="fas fa-plus-circle"></i> <?= $isEditMode ? 'تعديل بيانات البوابة' : 'إضافة بوابة جديدة' ?></h3>
            <form method="POST">
                <input type="hidden" name="action" value="<?= $isEditMode ? 'edit_gateway' : 'add_gateway' ?>">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <?php if ($isEditMode): ?>
                    <input type="hidden" name="id" value="<?= intval($editGateway['id']) ?>">
                <?php endif; ?>

                <div class="form-row">
                    <div class="form-group">
                        <label><i class="fas fa-code"></i> كود البوابة</label>
                        <input type="text" name="code" value="<?= htmlspecialchars($formData['code']) ?>" <?= $isEditMode ? 'readonly' : 'required' ?> <?= $isEditMode ? 'style="background:rgba(255,255,255,0.05)"' : '' ?> placeholder="مثال: stripe">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-tag"></i> اسم البوابة</label>
                        <input type="text" name="name" value="<?= htmlspecialchars($formData['name']) ?>" required placeholder="مثال: Stripe">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label><i class="fas fa-th-list"></i> النوع</label>
                        <select name="type">
                            <option value="electronic" <?= $formData['type'] === 'electronic' ? 'selected' : '' ?>>إلكترونية</option>
                            <option value="bank" <?= $formData['type'] === 'bank' ? 'selected' : '' ?>>بنكية</option>
                            <option value="crypto" <?= $formData['type'] === 'crypto' ? 'selected' : '' ?>>عملات رقمية</option>
                            <option value="game" <?= $formData['type'] === 'game' ? 'selected' : '' ?>>ألعاب</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-circle"></i> الحالة</label>
                        <select name="status">
                            <option value="inactive" <?= $formData['status'] === 'inactive' ? 'selected' : '' ?>>غير نشط</option>
                            <option value="active" <?= $formData['status'] === 'active' ? 'selected' : '' ?>>نشط</option>
                        </select>
                    </div>
                </div>
                
                <?php foreach ($schema['credentials'] as $field): ?>
                    <?php if ($field === 'secret' || $field === 'secret_key'): ?>
                        <div class="form-row">
                            <div class="form-group" style="width:100%;">
                                <label><i class="fas fa-lock"></i> <?= gatewayFieldLabel($field) ?></label>
                                <input type="password" name="<?= htmlspecialchars($field) ?>" placeholder="<?= gatewayFieldLabel($field) ?>" value="<?= htmlspecialchars($credentialValues[$field] ?? '') ?>">
                            </div>
                        </div>
                    <?php elseif ($field === 'access_token' || $field === 'private_key'): ?>
                        <div class="form-row">
                            <div class="form-group" style="width:100%;">
                                <label><i class="fas fa-file-alt"></i> <?= gatewayFieldLabel($field) ?></label>
                                <textarea name="<?= htmlspecialchars($field) ?>" placeholder="<?= gatewayFieldLabel($field) ?>" rows="4"><?= htmlspecialchars($credentialValues[$field] ?? '') ?></textarea>
                                <?php if ($field === 'access_token'): ?>
                                    <small style="color:#ccc;display:block;margin-top:6px;">يمكن أن يكون رمز Wise طويلًا (JWT/JWE)، لا تقتصر على طول 36 حرفًا.</small>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="form-row">
                            <div class="form-group">
                                <label><i class="fas fa-key"></i> <?= gatewayFieldLabel($field) ?></label>
                                <input type="text" name="<?= htmlspecialchars($field) ?>" placeholder="<?= gatewayFieldLabel($field) ?>" value="<?= htmlspecialchars($credentialValues[$field] ?? '') ?>">
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php foreach ($schema['settings'] as $field): ?>
                    <div class="form-row">
                        <div class="form-group" style="width:100%;">
                            <label><i class="fas fa-link"></i> <?= gatewayFieldLabel($field) ?></label>
                            <input type="text" name="<?= htmlspecialchars($field) ?>" placeholder="<?= gatewayFieldLabel($field) ?>" value="<?= htmlspecialchars($settingValues[$field] ?? '') ?>">
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (($editGateway['code'] ?? '') === 'paypal'): ?>
                    <div class="form-row">
                        <div class="form-group" style="width:100%;">
                            <label><i class="fas fa-fingerprint"></i> Webhook ID</label>
                            <input type="text" value="<?= htmlspecialchars((string)(getenv('PAYPAL_WEBHOOK_ID') ?: 'غير مضبوط')) ?>" readonly>
                            <small style="color:#888">يُقرأ من PAYPAL_WEBHOOK_ID في ملف .env ولا يُحفظ من هذه الصفحة.</small>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- ════ حقول Dynamic Gateway Registry ════ -->
                <hr style="border-color:rgba(255,215,0,.15);margin:20px 0">
                <p style="color:var(--gold);font-size:.9rem;font-weight:700;margin-bottom:14px">
                    <i class="fas fa-network-wired"></i> إعدادات الاتصال
                </p>

                <div class="form-row">
                    <div class="form-group">
                        <label><i class="fas fa-globe"></i> API Endpoint (Base URL)</label>
                        <input type="url" name="api_endpoint"
                            value="<?= htmlspecialchars($editGateway['api_endpoint'] ?? '') ?>"
                            placeholder="https://api.example.com">
                        <small style="color:#888">عنوان API الرئيسي للبوابة</small>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-code-branch"></i> API Version</label>
                        <input type="text" name="api_version"
                            value="<?= htmlspecialchars($editGateway['api_version'] ?? '') ?>"
                            placeholder="v1 أو v2 أو 2024-01">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label><i class="fas fa-plug"></i> نوع الاتصال</label>
                        <select name="connection_type">
                            <?php foreach (['rest'=>'REST API','soap'=>'SOAP/XML','web3'=>'Web3/Blockchain','manual'=>'يدوي'] as $v=>$l): ?>
                            <option value="<?= $v ?>" <?= ($editGateway['connection_type'] ?? 'rest') === $v ? 'selected' : '' ?>>
                                <?= $l ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-layer-group"></i> نوع البوابة</label>
                        <select name="gateway_type">
                            <?php foreach (['card'=>'بطاقة ائتمانية','wallet'=>'محفظة إلكترونية','crypto'=>'عملة رقمية','bank'=>'بنكي','otc'=>'OTC'] as $v=>$l): ?>
                            <option value="<?= $v ?>" <?= ($editGateway['gateway_type'] ?? 'card') === $v ? 'selected' : '' ?>>
                                <?= $l ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group" style="width:100%">
                        <label><i class="fas fa-check-square"></i> الميزات المدعومة</label>
                        <div style="display:flex;gap:20px;flex-wrap:wrap;margin-top:8px">
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;color:#ccc;font-size:.9rem">
                                <input type="checkbox" name="supports_2d" value="1"
                                    <?= ($editGateway['supports_2d'] ?? 1) ? 'checked' : '' ?>
                                    style="width:18px;height:18px;accent-color:#5bc0de">
                                <span style="color:#5bc0de">2D (بدون OTP)</span>
                            </label>
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;color:#ccc;font-size:.9rem">
                                <input type="checkbox" name="supports_3d" value="1"
                                    <?= ($editGateway['supports_3d'] ?? 1) ? 'checked' : '' ?>
                                    style="width:18px;height:18px;accent-color:var(--gold)">
                                <span style="color:var(--gold)">3D Secure</span>
                            </label>
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;color:#ccc;font-size:.9rem">
                                <input type="checkbox" name="supports_hold" value="1"
                                    <?= ($editGateway['supports_hold'] ?? 0) ? 'checked' : '' ?>
                                    style="width:18px;height:18px;accent-color:#9fe870">
                                <span style="color:#9fe870">HOLD (101.1)</span>
                            </label>
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;color:#ccc;font-size:.9rem">
                                <input type="checkbox" name="supports_capture" value="1"
                                    <?= ($editGateway['supports_capture'] ?? 0) ? 'checked' : '' ?>
                                    style="width:18px;height:18px;accent-color:#f0ad4e">
                                <span style="color:#f0ad4e">CAPTURE</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label><i class="fas fa-sort-numeric-up"></i> الترتيب في Checkout</label>
                        <input type="number" name="sort_order"
                            value="<?= intval($editGateway['sort_order'] ?? 0) ?>"
                            min="0" max="999" placeholder="0">
                        <small style="color:#888">رقم أصغر = يظهر أولاً</small>
                    </div>
                    <div class="form-group">
                        <label style="color:#888"><i class="fas fa-info-circle"></i> حالة الاتصال</label>
                        <?php $cs = $editGateway['connection_status'] ?? 'untested'; ?>
                        <div style="padding:10px 14px;border-radius:10px;font-size:.9rem;
                            border:1px solid <?= $cs==='verified'?'#4CAF50':($cs==='failed'?'#ef5350':'#f0ad4e') ?>40;
                            color:<?= $cs==='verified'?'#4CAF50':($cs==='failed'?'#ef5350':'#f0ad4e') ?>">
                            <?= $cs==='verified'?'✅ متصل':($cs==='failed'?'❌ فشل':'⏳ لم يُختبر بعد') ?>
                            <?php if (!empty($editGateway['test_message'])): ?>
                            <br><small style="opacity:.8"><?= htmlspecialchars($editGateway['test_message']) ?></small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <!-- ════════════════════════════════════════ -->

                <div style="display:flex;gap:10px;margin-top:15px;flex-wrap:wrap">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> حفظ البوابة</button>
                    <?php if ($isEditMode && !empty($editGateway['id'])): ?>
                    <button type="button"
                        onclick="testGatewayConnection(<?= $editGateway['id'] ?>, '<?= addslashes($editGateway['name']) ?>')"
                        style="background:rgba(91,192,222,.15);border:1px solid #5bc0de;color:#5bc0de;
                               padding:10px 20px;border-radius:10px;cursor:pointer;font-family:Cairo,sans-serif;font-weight:600">
                        <i class="fas fa-plug"></i> اختبار الاتصال الآن
                    </button>
                    <?php endif; ?>
                    <a href="gateway_manager.php" class="btn btn-outline"><i class="fas fa-times"></i> إلغاء</a>
                </div>
            </form>
        </div>
    <?php endif; ?>
    
    <!-- ===== قائمة البوابات ===== -->
    <div class="gateways-grid">
        <?php if (empty($gateways)): ?>
            <div class="empty-state" style="grid-column:1/-1;">
                <i class="fas fa-route"></i>
                <p>لا توجد بوابات دفع مضافة</p>
                <a href="?add=true" class="btn btn-primary" style="margin-top:15px;"><i class="fas fa-plus"></i> إضافة بوابة جديدة</a>
            </div>
        <?php else: ?>
            <?php foreach ($gateways as $gw): ?>
                <div class="gateway-card" id="gw-card-<?= $gw['id'] ?>">
                    <div class="header-card">
                        <div class="info">
                            <h3><?= htmlspecialchars($gw['name']) ?></h3>
                            <div class="code"><?= htmlspecialchars($gw['code']) ?></div>
                        </div>
                        <div style="text-align:right;">
                            <span class="status status-<?= $gw['status'] ?>">
                                <?= $gw['status'] === 'active' ? '✅ نشط' : '❌ غير نشط' ?>
                            </span>
                            <?php
                            // حالة الاتصال
                            $connStatus = $gw['connection_status'] ?? 'untested';
                            $connBadge  = match($connStatus) {
                                'verified'  => ['icon'=>'check-circle',     'color'=>'#4CAF50', 'label'=>'متصل ✅'],
                                'failed'    => ['icon'=>'times-circle',     'color'=>'#ef5350', 'label'=>'فشل ❌'],
                                'disabled'  => ['icon'=>'ban',              'color'=>'#888',    'label'=>'معطل'],
                                default     => ['icon'=>'question-circle',  'color'=>'#f0ad4e', 'label'=>'لم يُختبر'],
                            };
                            $lastTested = $gw['last_tested'] ?? null;
                            $respMs     = $gw['test_response_ms'] ?? null;
                            ?>
                            <div style="margin-top:6px;font-size:.78rem;color:<?= $connBadge['color'] ?>;
                                 background:rgba(0,0,0,.3);border-radius:8px;padding:3px 10px;
                                 border:1px solid <?= $connBadge['color'] ?>40"
                                 id="conn-badge-<?= $gw['id'] ?>">
                                <i class="fas fa-<?= $connBadge['icon'] ?>"></i>
                                <?= $connBadge['label'] ?>
                                <?php if ($respMs): ?><small style="opacity:.7">(<?= $respMs ?>ms)</small><?php endif; ?>
                            </div>
                            <?php if ($lastTested): ?>
                            <div style="font-size:.7rem;color:#666;margin-top:2px">
                                آخر اختبار: <?= date('Y-m-d H:i', strtotime($lastTested)) ?>
                            </div>
                            <?php endif; ?>
                            <?php $completion = gatewayCompletionBadge($gw); ?>
                            <div class="badge <?= $completion['class'] ?>">
                                <i class="fas fa-<?= $completion['icon'] ?>"></i>
                                <?= htmlspecialchars($completion['label']) ?>
                            </div>
                        </div>
                    </div>

                    <?php
                    $config      = json_decode($gw['config']      ?? '{}', true);
                    $credentials = json_decode($gw['credentials']  ?? '{}', true);
                    ?>
                    <div class="details">
                        <div><span class="label">النوع:</span> <span class="value"><?= htmlspecialchars($gw['type']) ?></span></div>
                        <div><span class="label">نوع الاتصال:</span> <span class="value"><?= strtoupper($gw['connection_type'] ?? 'REST') ?></span></div>
                        <div><span class="label">API Endpoint:</span>
                            <span class="value" style="word-break:break-all;font-size:.8rem">
                                <?= htmlspecialchars($gw['api_endpoint'] ?? $credentials['api_url'] ?? 'غير محدد') ?>
                            </span>
                        </div>
                        <div><span class="label">API Key:</span> <span class="value"><?= !empty($credentials['api_key']) ? '••••••••' : 'غير محدد' ?></span></div>
                        <div><span class="label">يدعم:</span>
                            <span class="value">
                                <?= ($gw['supports_2d'] ?? 1) ? '<span style="color:#5bc0de">2D</span> ' : '' ?>
                                <?= ($gw['supports_3d'] ?? 1) ? '<span style="color:var(--gold)">3D</span> ' : '' ?>
                                <?= ($gw['supports_hold'] ?? 0) ? '<span style="color:#9fe870">HOLD</span> ' : '' ?>
                                <?= ($gw['supports_capture'] ?? 0) ? '<span style="color:#f0ad4e">CAPTURE</span>' : '' ?>
                            </span>
                        </div>
                        <div><span class="label">العملات:</span> <span class="value"><?= implode(', ', array_slice($config['currencies'] ?? ['USD'], 0, 4)) ?></span></div>
                        <?php if (!empty($gw['test_message'])): ?>
                        <div style="margin-top:6px;font-size:.8rem;color:<?= $connBadge['color'] ?>">
                            <i class="fas fa-info-circle"></i> <?= htmlspecialchars($gw['test_message']) ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="actions">
                        <!-- زر اختبار الاتصال -->
                        <button type="button"
                            onclick="testGatewayConnection(<?= $gw['id'] ?>, '<?= addslashes($gw['name']) ?>')"
                            class="btn btn-sm"
                            id="test-btn-<?= $gw['id'] ?>"
                            style="background:rgba(91,192,222,.15);border:1px solid #5bc0de;color:#5bc0de">
                            <i class="fas fa-plug"></i> اختبار الاتصال
                        </button>
                        <a href="?edit=<?= $gw['id'] ?>" class="btn btn-info btn-sm">
                            <i class="fas fa-pen"></i> إضافة بيانات
                        </a>
                        <a href="?toggle=<?= $gw['id'] ?>&token=<?= $csrfToken ?>" class="btn btn-warning btn-sm" onclick="return confirm('هل تريد تغيير حالة البوابة؟')">
                            <i class="fas fa-<?= $gw['status'] === 'active' ? 'pause' : 'play' ?>"></i>
                            <?= $gw['status'] === 'active' ? 'تعطيل' : 'تفعيل' ?>
                        </a>
                        <a href="?delete=<?= $gw['id'] ?>&token=<?= $csrfToken ?>" class="btn btn-danger btn-sm" onclick="return confirm('⚠️ هل أنت متأكد من حذف هذه البوابة؟')">
                            <i class="fas fa-trash"></i> حذف
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
// ══════════════════════════════════════════════════════
// اختبار اتصال بوابة واحدة
// ══════════════════════════════════════════════════════
async function testGatewayConnection(gatewayId, gatewayName) {
    const btn    = document.getElementById('test-btn-' + gatewayId);
    const badge  = document.getElementById('conn-badge-' + gatewayId);

    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جارٍ الاختبار...';
    }
    if (badge) {
        badge.style.color   = '#f0ad4e';
        badge.innerHTML     = '<i class="fas fa-spinner fa-spin"></i> جارٍ الاختبار...';
    }

    try {
        const fd = new FormData();
        fd.append('action',     'test_connection');
        fd.append('gateway_id', gatewayId);
        fd.append('csrf_token', '<?= htmlspecialchars($csrfToken) ?>');

        const res  = await fetch('gateway_manager.php', { method: 'POST', body: fd });
        const body = await res.text();
        let data;
        try {
            data = body ? JSON.parse(body) : null;
        } catch (parseError) {
            throw new Error(`استجابة غير صالحة من الخادم (${res.status})`);
        }
        if (!res.ok || !data || typeof data !== 'object') {
            throw new Error(`استجابة غير متوقعة من الخادم (${res.status})`);
        }

        const ok    = data.success;
        const color = ok ? '#4CAF50' : '#ef5350';
        const icon  = ok ? 'check-circle' : 'times-circle';
        const ms    = data.response_ms ?? '';

        if (badge) {
            badge.style.color   = color;
            badge.style.border  = '1px solid ' + color + '40';
            badge.innerHTML     = `<i class="fas fa-${icon}"></i> ${data.message}` +
                                  (ms ? ` <small style="opacity:.7">(${ms}ms)</small>` : '');
        }

        // توست
        showToast(data.message, ok ? 'success' : 'error');

    } catch (e) {
        if (badge) {
            badge.style.color = '#ef5350';
            badge.innerHTML   = '<i class="fas fa-times-circle"></i> خطأ في الاتصال';
        }
        showToast('❌ خطأ: ' + e.message, 'error');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-plug"></i> اختبار الاتصال';
        }
    }
}

// ══════════════════════════════════════════════════════
// اختبار جميع البوابات
// ══════════════════════════════════════════════════════
async function testAllConnections() {
    const btn = document.getElementById('btn-test-all');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جارٍ الاختبار الشامل...';
    }

    try {
        const fd = new FormData();
        fd.append('action',     'test_all_connections');
        fd.append('csrf_token', '<?= htmlspecialchars($csrfToken) ?>');

        const res  = await fetch('gateway_manager.php', { method: 'POST', body: fd });
        const body = await res.text();
        let data;
        try {
            data = body ? JSON.parse(body) : null;
        } catch (parseError) {
            throw new Error(`استجابة غير صالحة من الخادم (${res.status})`);
        }
        if (!res.ok || !data || typeof data !== 'object') {
            throw new Error(`استجابة غير متوقعة من الخادم (${res.status})`);
        }

        showToast(
            `✅ ${data.verified} متصلة | ❌ ${data.failed} فشلت | إجمالي ${data.total}`,
            data.failed === 0 ? 'success' : 'warning'
        );

        // تحديث الصفحة لعرض النتائج
        setTimeout(() => location.reload(), 2000);

    } catch (e) {
        showToast('❌ خطأ: ' + e.message, 'error');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-plug"></i> اختبار الكل';
        }
    }
}

// ══════════════════════════════════════════════════════
// Toast إشعار
// ══════════════════════════════════════════════════════
function showToast(msg, type) {
    let t = document.getElementById('admin-toast');
    if (!t) {
        t = document.createElement('div');
        t.id = 'admin-toast';
        t.style.cssText = `position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(80px);
            background:rgba(20,20,20,.97);border:1.5px solid var(--gold,#ffd700);
            color:#fff;padding:12px 28px;border-radius:12px;font-size:.95rem;
            z-index:9999;transition:transform .3s;max-width:80vw;text-align:center;`;
        document.body.appendChild(t);
    }
    const colors = { success: '#4CAF50', error: '#ef5350', warning: '#f0ad4e', info: '#5bc0de' };
    t.style.borderColor = colors[type] || colors.info;
    t.textContent = msg;
    t.style.transform = 'translateX(-50%) translateY(0)';
    clearTimeout(t._timer);
    t._timer = setTimeout(() => { t.style.transform = 'translateX(-50%) translateY(80px)'; }, 4000);
}

function toggleAddForm() {
    const f = document.getElementById('add-gateway-form');
    if (f) f.style.display = f.style.display === 'none' ? '' : 'none';
}
</script>

</body>
</html>