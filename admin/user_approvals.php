<?php
/**
 * ============================================================
 * DI PARMA | Admin — موافقة على المستخدمين الجدد
 * ============================================================
 */
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/database.php';

requireAdmin();

$db = db();

try {
    $accountTypeColumn = $db->query("SHOW COLUMNS FROM " . DB_PREFIX . "users LIKE 'account_type'");
    if (empty($accountTypeColumn)) {
        $db->execute("ALTER TABLE " . DB_PREFIX . "users ADD COLUMN account_type VARCHAR(20) DEFAULT NULL AFTER subscription_plan");
    }
    $brandNameColumn = $db->query("SHOW COLUMNS FROM " . DB_PREFIX . "users LIKE 'brand_name'");
    if (empty($brandNameColumn)) {
        $db->execute("ALTER TABLE " . DB_PREFIX . "users ADD COLUMN brand_name VARCHAR(255) DEFAULT NULL AFTER account_type");
    }
    $revenueColumn = $db->query("SHOW COLUMNS FROM " . DB_PREFIX . "users LIKE 'annual_revenue'");
    if (empty($revenueColumn)) {
        $db->execute("ALTER TABLE " . DB_PREFIX . "users ADD COLUMN annual_revenue DECIMAL(18,2) DEFAULT NULL AFTER brand_name");
    }
    $brandDescriptionColumn = $db->query("SHOW COLUMNS FROM " . DB_PREFIX . "users LIKE 'brand_description'");
    if (empty($brandDescriptionColumn)) {
        $db->execute("ALTER TABLE " . DB_PREFIX . "users ADD COLUMN brand_description TEXT DEFAULT NULL AFTER brand_name");
    }
    $brandLogoColumn = $db->query("SHOW COLUMNS FROM " . DB_PREFIX . "users LIKE 'brand_logo_path'");
    if (empty($brandLogoColumn)) {
        $db->execute("ALTER TABLE " . DB_PREFIX . "users ADD COLUMN brand_logo_path VARCHAR(500) DEFAULT NULL AFTER brand_description");
    }
    $marketRoleColumn = $db->query("SHOW COLUMNS FROM " . DB_PREFIX . "users LIKE 'market_role'");
    if (empty($marketRoleColumn)) {
        $db->execute("ALTER TABLE " . DB_PREFIX . "users ADD COLUMN market_role VARCHAR(20) DEFAULT NULL AFTER annual_revenue");
    }
} catch (Exception $e) {
    // Continue so the approval page remains available on older schemas.
}

// ── معالجة الإجراءات ──────────────────────────────────────
$msg = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $msg = 'CSRF غير صالح'; $msgType = 'error';
    } else {
        $action = $_POST['action'];

        // ── إنشاء مستخدم جديد ────────────────────────────
        if ($action === 'create_user') {
            $newUsername = trim($_POST['new_username'] ?? '');
            $newEmail    = trim($_POST['new_email']    ?? '');
            $newPassword = $_POST['new_password']      ?? '';
            $newRole     = in_array($_POST['new_role'] ?? 'user', ['user','admin']) ? $_POST['new_role'] : 'user';

            if (!$newUsername || !$newEmail || !$newPassword) {
                $msg = '⚠️ أدخل اسم المستخدم والبريد وكلمة المرور'; $msgType = 'warning';
            } elseif (strlen($newPassword) < 6) {
                $msg = '⚠️ كلمة المرور يجب أن تكون 6 أحرف على الأقل'; $msgType = 'warning';
            } elseif ($db->find('users', ['username' => $newUsername])) {
                $msg = '⚠️ اسم المستخدم موجود مسبقاً'; $msgType = 'warning';
            } elseif ($db->find('users', ['email' => $newEmail])) {
                $msg = '⚠️ البريد الإلكتروني موجود مسبقاً'; $msgType = 'warning';
            } else {
                $db->insert('users', [
                    'username'      => $newUsername,
                    'email'         => $newEmail,
                    'password_hash' => password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]),
                    'role'          => $newRole,
                    'status'        => 'active',
                    'created_at'    => date('Y-m-d H:i:s'),
                ]);
                $msg = '✅ تم إنشاء الحساب بنجاح: ' . htmlspecialchars($newUsername);
                $msgType = 'success';
            }

        // ── إجراءات على مستخدم موجود ─────────────────────
        } elseif (isset($_POST['user_id'])) {
            $uid = intval($_POST['user_id']);

            if ($action === 'approve') {
                $approvalUser = $db->find('users', ['id' => $uid]);
                $isMarketplaceApplication = ($approvalUser['account_type'] ?? '') === 'business'
                    || !empty($approvalUser['market_role']);
                $isQualifiedBusiness = $approvalUser
                    && ($approvalUser['account_type'] ?? '') === 'business'
                    && !empty($approvalUser['brand_name'])
                    && !empty($approvalUser['brand_description'])
                    && !empty($approvalUser['brand_logo_path'])
                    && (float)($approvalUser['annual_revenue'] ?? 0) > 100000000
                    && in_array($approvalUser['market_role'] ?? '', ['seller', 'buyer'], true);
                if ($isMarketplaceApplication && !$isQualifiedBusiness) {
                    $msg = '⚠️ لا يمكن قبول المتجر: يجب استكمال بيانات الماركة والإيراد واللوقو وتحديد بائع أو مشتري';
                    $msgType = 'warning';
                } else {
                    $db->update('users', ['status' => 'active'], ['id' => $uid]);
                    $msg = '✅ تم تفعيل الحساب بنجاح'; $msgType = 'success';
                }
            } elseif ($action === 'reject') {
                $db->update('users', ['status' => 'inactive'], ['id' => $uid]);
                $msg = '🚫 تم رفض الحساب'; $msgType = 'warning';
            } elseif ($action === 'delete') {
                $db->execute("DELETE FROM " . DB_PREFIX . "users WHERE id = ? AND role != 'admin'", [$uid]);
                $msg = '🗑️ تم حذف الحساب'; $msgType = 'error';
            }
        }
    }
}

// ── جلب المستخدمين pending ────────────────────────────────
$pendingUsers = $db->query(
    "SELECT id, username, email, account_type, brand_name, brand_description, brand_logo_path, annual_revenue, market_role, created_at FROM " . DB_PREFIX . "users
     WHERE status = 'pending' ORDER BY created_at DESC"
);

// ── جلب جميع المستخدمين ──────────────────────────────────
$allUsers = $db->query(
    "SELECT id, username, email, account_type, brand_name, brand_description, brand_logo_path, annual_revenue, market_role, role, status, created_at, last_login
     FROM " . DB_PREFIX . "users
     ORDER BY created_at DESC LIMIT 100"
);

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>DI PARMA | موافقة المستخدمين</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
* { margin:0; padding:0; box-sizing:border-box; }
:root { --gold:#FFD700; --bg:#0b0f17; --card:#0e1420; --border:rgba(255,215,0,.15); --text:#f0f0f0; --muted:#888; }
body { font-family:'Cairo',sans-serif; background:var(--bg); color:var(--text); min-height:100vh; padding:0; }

/* Nav */
.topbar { background:rgba(0,0,0,.9); border-bottom:1px solid var(--border); padding:12px 24px;
          display:flex; align-items:center; justify-content:space-between; position:sticky; top:0; z-index:100; }
.topbar-brand { color:var(--gold); font-weight:800; font-size:1rem; text-decoration:none; }
.topbar-links { display:flex; gap:12px; }
.topbar-links a { color:var(--muted); font-size:.82rem; text-decoration:none; padding:5px 10px;
                  border-radius:8px; transition:.2s; }
.topbar-links a:hover { background:rgba(255,215,0,.07); color:var(--gold); }

.container { max-width:1100px; margin:0 auto; padding:28px 20px; }
h1 { color:var(--gold); font-size:1.3rem; margin-bottom:6px; }
.subtitle { color:var(--muted); font-size:.85rem; margin-bottom:28px; }

/* Tabs */
.tabs { display:flex; gap:8px; margin-bottom:24px; }
.tab-btn { padding:8px 20px; border-radius:20px; border:1.5px solid var(--border);
           background:transparent; color:var(--muted); cursor:pointer; font-family:'Cairo',sans-serif;
           font-size:.85rem; transition:.2s; }
.tab-btn.active { background:rgba(255,215,0,.1); border-color:var(--gold); color:var(--gold); }

/* Alert */
.alert { padding:12px 16px; border-radius:10px; margin-bottom:20px; font-size:.88rem; }
.alert.success { background:rgba(76,175,80,.12); border:1px solid #4CAF5040; color:#4CAF50; }
.alert.error   { background:rgba(239,83,80,.12);  border:1px solid #ef535040; color:#ef5350; }
.alert.warning { background:rgba(255,152,0,.12);  border:1px solid #ff980040; color:#ff9800; }

/* Badge */
.badge { display:inline-block; padding:3px 10px; border-radius:20px; font-size:.75rem; font-weight:700; }
.badge-pending  { background:rgba(255,152,0,.15); color:#ff9800; border:1px solid #ff980040; }
.badge-active   { background:rgba(76,175,80,.15); color:#4CAF50; border:1px solid #4CAF5040; }
.badge-inactive { background:rgba(239,83,80,.12); color:#ef5350; border:1px solid #ef535040; }
.badge-admin    { background:rgba(255,215,0,.12); color:var(--gold); border:1px solid #ffd70040; }

/* Table */
.table-wrap { overflow-x:auto; }
table { width:100%; border-collapse:collapse; font-size:.85rem; }
th { background:rgba(255,215,0,.06); color:var(--gold); padding:11px 14px; text-align:right;
     border-bottom:1px solid var(--border); font-weight:600; white-space:nowrap; }
td { padding:11px 14px; border-bottom:1px solid rgba(255,255,255,.04); vertical-align:middle; }
tr:hover td { background:rgba(255,255,255,.02); }

/* Action buttons */
.btn { display:inline-flex; align-items:center; gap:5px; padding:6px 14px; border-radius:8px;
       border:none; cursor:pointer; font-family:'Cairo',sans-serif; font-size:.8rem; font-weight:700;
       text-decoration:none; transition:.2s; }
.btn-approve { background:rgba(76,175,80,.15); color:#4CAF50; border:1px solid #4CAF5040; }
.btn-approve:hover { background:rgba(76,175,80,.3); }
.btn-reject  { background:rgba(255,152,0,.12); color:#ff9800; border:1px solid #ff980040; }
.btn-reject:hover { background:rgba(255,152,0,.25); }
.btn-delete  { background:rgba(239,83,80,.1); color:#ef5350; border:1px solid #ef535040; }
.btn-delete:hover { background:rgba(239,83,80,.2); }

/* Empty state */
.empty { text-align:center; padding:48px 20px; color:var(--muted); }
.empty i { font-size:2.5rem; margin-bottom:12px; display:block; opacity:.4; }

/* Counter badge */
.count-badge { background:#ef5350; color:#fff; border-radius:50%; width:20px; height:20px;
               display:inline-flex; align-items:center; justify-content:center;
               font-size:.7rem; font-weight:700; margin-right:6px; }
</style>
</head>
<body>

<div class="topbar">
    <a href="../index.php" class="topbar-brand"><i class="fas fa-coins"></i> DI PARMA</a>
    <div class="topbar-links">
        <a href="../index.php"><i class="fas fa-home"></i> الرئيسية</a>
        <a href="gateway_manager.php"><i class="fas fa-cog"></i> البوابات</a>
        <a href="connection_manager.php"><i class="fas fa-network-wired"></i> الاتصال</a>
        <a href="../admin/users.php"><i class="fas fa-users"></i> المستخدمون</a>
        <a href="../logout.php" style="color:#ef5350"><i class="fas fa-sign-out-alt"></i> خروج</a>
    </div>
</div>

<div class="container">
    <h1><i class="fas fa-user-check" style="margin-left:8px"></i> موافقة المستخدمين</h1>
    <p class="subtitle">إدارة طلبات التسجيل الجديدة والمستخدمين</p>

    <?php if ($msg): ?>
        <div class="alert <?= $msgType ?>"><?= $msg ?></div>
    <?php endif; ?>

    <!-- Tabs -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px">
        <div class="tabs" style="margin-bottom:0">
            <button class="tab-btn active" onclick="showTab('pending',this)">
                <i class="fas fa-clock"></i> بانتظار الموافقة
                <?php if (count($pendingUsers) > 0): ?>
                    <span class="count-badge"><?= count($pendingUsers) ?></span>
                <?php endif; ?>
            </button>
            <button class="tab-btn" onclick="showTab('all',this)">
                <i class="fas fa-users"></i> جميع المستخدمين
            </button>
        </div>
        <!-- زر إنشاء مستخدم جديد -->
        <button onclick="document.getElementById('createUserModal').style.display='flex'"
                style="background:linear-gradient(135deg,#ffd700,#ffb700);color:#000;
                       border:none;padding:10px 20px;border-radius:10px;cursor:pointer;
                       font-family:Cairo,sans-serif;font-weight:700;font-size:.88rem;
                       display:inline-flex;align-items:center;gap:7px">
            <i class="fas fa-user-plus"></i> إنشاء حساب جديد
        </button>
    </div>

    <!-- ══ Pending Users ════════════════════════════════════ -->
    <div id="tab-pending">
        <?php if (empty($pendingUsers)): ?>
            <div class="empty">
                <i class="fas fa-check-circle" style="color:#4CAF50;opacity:.6"></i>
                لا توجد طلبات تسجيل بانتظار الموافقة
            </div>
        <?php else: ?>
        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>اسم المستخدم</th>
                    <th>البريد الإلكتروني</th>
                    <th>نوع الحساب</th>
                    <th>الدور</th>
                    <th>اسم الماركة</th>
                    <th>معلومات الماركة</th>
                    <th>الإيراد السنوي</th>
                    <th>اللوقو</th>
                    <th>تاريخ التسجيل</th>
                    <th>الإجراء</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($pendingUsers as $u): ?>
                <tr>
                    <td style="color:var(--muted)"><?= intval($u['id']) ?></td>
                    <td>
                        <i class="fas fa-user-circle" style="color:var(--gold);margin-left:6px"></i>
                        <strong><?= htmlspecialchars($u['username']) ?></strong>
                    </td>
                    <td>
                        <?php if (($u['account_type'] ?? '') === 'business'): ?>
                            <span class="badge badge-pending"><i class="fas fa-building"></i> تجاري</span>
                        <?php else: ?>
                            <span class="badge"><i class="fas fa-user"></i> شخصي</span>
                        <?php endif; ?>
                    </td>
                    <td style="color:var(--muted)"><?= ($u['market_role'] ?? '') === 'seller' ? 'بائع' : (($u['market_role'] ?? '') === 'buyer' ? 'مشتري' : '—') ?></td>
                    <td style="color:var(--muted)"><?= htmlspecialchars($u['brand_name'] ?? '—') ?></td>
                    <td style="color:var(--muted);max-width:220px"><?= htmlspecialchars($u['brand_description'] ?? '—') ?></td>
                    <td style="color:var(--muted)"><?= !empty($u['annual_revenue']) ? '$' . number_format((float)$u['annual_revenue']) : '—' ?></td>
                    <td>
                        <?php if (!empty($u['brand_logo_path'])): ?>
                            <a href="document_download.php?type=brand&id=<?= intval($u['id']) ?>" target="_blank" class="btn btn-approve" style="padding:4px 9px;font-size:.72rem">
                                <i class="fas fa-image"></i> عرض اللوقو
                            </a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td style="color:var(--muted);font-size:.78rem">
                        <?= htmlspecialchars(date('Y/m/d H:i', strtotime($u['created_at']))) ?>
                    </td>
                    <td>
                        <div style="display:flex;gap:6px;flex-wrap:wrap">
                            <!-- موافقة -->
                            <form method="POST" style="display:inline" onsubmit="return confirm('تفعيل حساب: <?= addslashes($u['username']) ?>?')">
                                <input type="hidden" name="action"     value="approve">
                                <input type="hidden" name="user_id"   value="<?= intval($u['id']) ?>">
                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                <button type="submit" class="btn btn-approve">
                                    <i class="fas fa-check"></i> موافقة
                                </button>
                            </form>
                            <!-- رفض -->
                            <form method="POST" style="display:inline" onsubmit="return confirm('رفض حساب: <?= addslashes($u['username']) ?>?')">
                                <input type="hidden" name="action"     value="reject">
                                <input type="hidden" name="user_id"   value="<?= intval($u['id']) ?>">
                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                <button type="submit" class="btn btn-reject">
                                    <i class="fas fa-times"></i> رفض
                                </button>
                            </form>
                            <!-- حذف -->
                            <form method="POST" style="display:inline" onsubmit="return confirm('حذف حساب نهائياً؟')">
                                <input type="hidden" name="action"     value="delete">
                                <input type="hidden" name="user_id"   value="<?= intval($u['id']) ?>">
                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                <button type="submit" class="btn btn-delete">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- ══ All Users ═════════════════════════════════════════ -->
    <div id="tab-all" style="display:none">
        <!-- بحث سريع -->
        <div style="margin-bottom:14px">
            <input type="text" id="userSearch" placeholder="بحث بالاسم أو البريد..."
                   oninput="filterUsers(this.value)"
                   style="width:100%;max-width:320px;padding:9px 14px;
                          background:rgba(255,255,255,.05);border:1.5px solid var(--border);
                          border-radius:20px;color:#fff;font-family:Cairo,sans-serif;outline:none">
        </div>
        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>المستخدم</th>
                    <th>البريد</th>
                    <th>الدور</th>
                    <th>الحالة</th>
                    <th>آخر دخول</th>
                    <th>إجراء</th>
                </tr>
            </thead>
            <tbody id="usersTableBody">
            <?php foreach ($allUsers as $u): ?>
                <?php
                $status = $u['status'] ?? 'active';
                $role   = $u['role']   ?? 'user';
                $badgeStatus = match($status) {
                    'active'   => '<span class="badge badge-active">نشط</span>',
                    'pending'  => '<span class="badge badge-pending">معلّق</span>',
                    'inactive' => '<span class="badge badge-inactive">معطّل</span>',
                    default    => '<span class="badge">'.$status.'</span>',
                };
                $badgeRole = $role === 'admin'
                    ? '<span class="badge badge-admin"><i class="fas fa-crown"></i> أدمن</span>'
                    : '<span style="color:var(--muted);font-size:.8rem">مستخدم</span>';
                ?>
                <tr class="user-row"
                    data-name="<?= htmlspecialchars(strtolower($u['username'])) ?>"
                    data-email="<?= htmlspecialchars(strtolower($u['email'])) ?>">
                    <td style="color:var(--muted)"><?= intval($u['id']) ?></td>
                    <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
                    <td style="color:var(--muted);font-size:.8rem"><?= htmlspecialchars($u['email']) ?></td>
                    <td><?= $badgeRole ?></td>
                    <td><?= $badgeStatus ?></td>
                    <td style="color:var(--muted);font-size:.78rem">
                        <?= $u['last_login'] ? htmlspecialchars(date('Y/m/d', strtotime($u['last_login']))) : '—' ?>
                    </td>
                    <td>
                        <?php if ($role !== 'admin'): ?>
                        <div style="display:flex;gap:5px;flex-wrap:wrap">
                            <?php if ($status !== 'active'): ?>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="action"     value="approve">
                                <input type="hidden" name="user_id"   value="<?= intval($u['id']) ?>">
                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                <button type="submit" class="btn btn-approve" style="padding:4px 10px;font-size:.75rem">
                                    <i class="fas fa-check"></i> تفعيل
                                </button>
                            </form>
                            <?php endif; ?>
                            <?php if ($status === 'active'): ?>
                            <form method="POST" style="display:inline" onsubmit="return confirm('تعطيل الحساب؟')">
                                <input type="hidden" name="action"     value="reject">
                                <input type="hidden" name="user_id"   value="<?= intval($u['id']) ?>">
                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                <button type="submit" class="btn btn-reject" style="padding:4px 10px;font-size:.75rem">
                                    <i class="fas fa-ban"></i> تعطيل
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                        <?php else: ?>
                            <span style="color:var(--muted);font-size:.78rem">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<script>
function showTab(tab, btn) {
    document.getElementById('tab-pending').style.display = tab === 'pending' ? '' : 'none';
    document.getElementById('tab-all').style.display     = tab === 'all'     ? '' : 'none';
    document.querySelectorAll('.tab-btn').forEach(function(b){ b.classList.remove('active'); });
    btn.classList.add('active');
}

// إعادة فتح modal إنشاء المستخدم إذا كانت هناك رسالة بعد POST
<?php if ($msgType === 'success' && strpos($msg, 'إنشاء') !== false): ?>
// تم إنشاء مستخدم — لا نعيد فتح الـ modal
<?php elseif (isset($_POST['action']) && $_POST['action'] === 'create_user' && $msgType !== 'success'): ?>
document.addEventListener('DOMContentLoaded', function(){
    document.getElementById('createUserModal').style.display = 'flex';
});
<?php endif; ?>

function filterUsers(q) {
    q = q.toLowerCase();
    document.querySelectorAll('.user-row').forEach(function(row) {
        var name  = row.dataset.name  || '';
        var email = row.dataset.email || '';
        row.style.display = (!q || name.includes(q) || email.includes(q)) ? '' : 'none';
    });
}
</script>

<!-- ══ Modal: إنشاء مستخدم جديد ══════════════════════════ -->
<div id="createUserModal"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.85);
            z-index:9999;align-items:center;justify-content:center"
     onclick="if(event.target===this)this.style.display='none'">
  <div style="background:#0e0e0e;border:1.5px solid rgba(255,215,0,.3);
              border-radius:20px;padding:30px;width:100%;max-width:440px;color:#ddd;
              max-height:90vh;overflow-y:auto">

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:22px">
      <h3 style="color:#ffd700;margin:0;font-size:1.05rem">
          <i class="fas fa-user-plus" style="margin-left:8px"></i>
          إنشاء حساب جديد
      </h3>
      <button onclick="document.getElementById('createUserModal').style.display='none'"
              style="background:none;border:none;color:#aaa;font-size:1.6rem;cursor:pointer">×</button>
    </div>

    <p style="color:#888;font-size:.82rem;margin-bottom:20px;
              background:rgba(255,215,0,.05);border:1px solid rgba(255,215,0,.15);
              border-radius:8px;padding:10px 12px">
        <i class="fas fa-info-circle" style="color:#ffd700;margin-left:6px"></i>
        التسجيل مغلق للعموم — أنت فقط من يُنشئ الحسابات.
    </p>

    <form method="POST">
      <input type="hidden" name="action"     value="create_user">
      <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

      <div style="margin-bottom:14px">
        <label style="font-size:.78rem;color:#888;display:block;margin-bottom:5px">
            <i class="fas fa-user" style="color:#ffd700;margin-left:5px"></i>
            اسم المستخدم <span style="color:#ef5350">*</span>
        </label>
        <input type="text" name="new_username" required placeholder="username"
               style="width:100%;padding:11px 14px;background:rgba(255,255,255,.05);
                      border:1.5px solid rgba(255,215,0,.2);border-radius:10px;
                      color:#fff;font-family:Cairo,sans-serif;outline:none;box-sizing:border-box">
      </div>

      <div style="margin-bottom:14px">
        <label style="font-size:.78rem;color:#888;display:block;margin-bottom:5px">
            <i class="fas fa-envelope" style="color:#ffd700;margin-left:5px"></i>
            البريد الإلكتروني <span style="color:#ef5350">*</span>
        </label>
        <input type="email" name="new_email" required placeholder="user@example.com"
               style="width:100%;padding:11px 14px;background:rgba(255,255,255,.05);
                      border:1.5px solid rgba(255,215,0,.2);border-radius:10px;
                      color:#fff;font-family:Cairo,sans-serif;outline:none;box-sizing:border-box">
      </div>

      <div style="margin-bottom:14px">
        <label style="font-size:.78rem;color:#888;display:block;margin-bottom:5px">
            <i class="fas fa-lock" style="color:#ffd700;margin-left:5px"></i>
            كلمة المرور <span style="color:#ef5350">*</span>
            <span style="color:#555;font-weight:400"> (6 أحرف على الأقل)</span>
        </label>
        <input type="password" name="new_password" required placeholder="••••••••" minlength="6"
               style="width:100%;padding:11px 14px;background:rgba(255,255,255,.05);
                      border:1.5px solid rgba(255,215,0,.2);border-radius:10px;
                      color:#fff;font-family:Cairo,sans-serif;outline:none;box-sizing:border-box">
      </div>

      <div style="margin-bottom:22px">
        <label style="font-size:.78rem;color:#888;display:block;margin-bottom:5px">
            <i class="fas fa-shield-alt" style="color:#ffd700;margin-left:5px"></i>
            الصلاحية
        </label>
        <select name="new_role"
                style="width:100%;padding:11px 14px;background:rgba(255,255,255,.05);
                       border:1.5px solid rgba(255,215,0,.2);border-radius:10px;
                       color:#fff;font-family:Cairo,sans-serif;outline:none">
            <option value="user">مستخدم عادي</option>
            <option value="admin">أدمن</option>
        </select>
      </div>

      <button type="submit"
              style="width:100%;padding:12px;background:linear-gradient(135deg,#ffd700,#ffb700);
                     color:#000;border:none;border-radius:10px;cursor:pointer;
                     font-family:Cairo,sans-serif;font-weight:700;font-size:.95rem">
          <i class="fas fa-user-plus"></i> إنشاء الحساب
      </button>
    </form>
  </div>
</div>

</body>
</html>
