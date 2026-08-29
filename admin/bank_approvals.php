<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';

$db   = db();
$lang = isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'en' ? 'en' : 'ar';
$ar   = ($lang === 'ar');
$dir  = $ar ? 'rtl' : 'ltr';
$msg  = '';
$msgType = '';

// معالجة الموافقة / الرفض
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action']   ?? '';
    $id       = intval($_POST['id'] ?? 0);
    $note     = trim($_POST['admin_note'] ?? '');
    $adminId  = intval($_SESSION['user_id'] ?? 1);

    if ($id > 0 && in_array($action, ['approve','reject','complete'])) {
        $statusMap = ['approve' => 'approved', 'reject' => 'rejected', 'complete' => 'completed'];
        $newStatus = $statusMap[$action];
        try {
            $db->execute(
                "UPDATE dp_bank_transfers SET status=?, admin_note=?, approved_by=?, approved_at=NOW() WHERE id=?",
                [$newStatus, $note, $adminId, $id]
            );
            $msg = match($action) {
                'approve'  => $ar ? '✅ تمت الموافقة' : '✅ Approved',
                'reject'   => $ar ? '❌ تم الرفض' : '❌ Rejected',
                'complete' => $ar ? '✅ تم الإكمال' : '✅ Completed',
            };
            $msgType = $action === 'reject' ? 'error' : 'success';
        } catch (Exception $e) {
            $msg = 'Error: ' . $e->getMessage();
            $msgType = 'error';
        }
    }
}

// جلب الطلبات
$filter = $_GET['status'] ?? 'pending';
$valid  = ['pending','approved','rejected','completed','all'];
if (!in_array($filter, $valid)) $filter = 'pending';

$where = $filter === 'all' ? '' : "WHERE status = '$filter'";
$rows  = $db->query("SELECT * FROM dp_bank_transfers $where ORDER BY created_at DESC");

// إحصائيات
$stats = $db->query("SELECT status, COUNT(*) as cnt, SUM(amount) as total FROM dp_bank_transfers GROUP BY status");
$statMap = [];
foreach ($stats as $s) $statMap[$s['status']] = $s;
?><!DOCTYPE html>
<html lang="<?=$lang?>" dir="<?=$dir?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA | Bank Approvals</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--gold:#FFD700;--bg:#040810;--card:#080d1a;--border:rgba(255,215,0,.15);--text:#f0f0f0;--muted:#666;--green:#10B981;--red:#EF4444;--orange:#f0ad4e;--blue:#5bc0de}
body{font-family:'Cairo',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.top-bar{background:rgba(4,8,16,.97);border-bottom:1px solid var(--border);padding:0 24px;height:62px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:99}
.brand{color:var(--gold);font-weight:900}
.badge{background:rgba(219,0,17,.15);border:2px solid #DB0011;border-radius:10px;padding:5px 14px;color:#DB0011;font-weight:800;font-size:.82rem}
.top-nav a{color:var(--muted);font-size:.82rem;padding:6px 12px;border-radius:20px;text-decoration:none}
.top-nav a:hover{color:var(--gold)}
.wrap{max-width:1200px;margin:24px auto;padding:0 20px}
/* Stats */
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px}
.stat{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:16px;text-align:center}
.stat-val{font-size:1.6rem;font-weight:900;margin-bottom:4px}
.stat-lbl{font-size:.72rem;color:var(--muted);font-weight:600}
/* Filters */
.filters{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap}
.filter-btn{padding:7px 16px;border-radius:20px;border:1.5px solid rgba(255,215,0,.15);background:transparent;color:var(--muted);font-family:'Cairo',sans-serif;font-size:.8rem;font-weight:700;cursor:pointer;text-decoration:none;transition:.2s}
.filter-btn:hover{border-color:rgba(255,215,0,.3);color:var(--text)}
.filter-btn.active{border-color:var(--gold);color:var(--gold);background:rgba(255,215,0,.06)}
/* Table */
.tbl-wrap{background:var(--card);border:1px solid var(--border);border-radius:16px;overflow:hidden}
table{width:100%;border-collapse:collapse}
th{background:rgba(255,215,0,.06);padding:12px 14px;font-size:.76rem;color:var(--muted);font-weight:700;text-align:right;border-bottom:1px solid var(--border)}
td{padding:12px 14px;font-size:.82rem;border-bottom:1px solid rgba(255,255,255,.04);vertical-align:middle}
tr:last-child td{border:none}
tr:hover td{background:rgba(255,255,255,.02)}
/* Status badges */
.status{padding:3px 10px;border-radius:20px;font-size:.68rem;font-weight:800}
.status.pending{background:rgba(240,173,78,.15);color:var(--orange);border:1px solid rgba(240,173,78,.3)}
.status.approved{background:rgba(16,185,129,.15);color:var(--green);border:1px solid rgba(16,185,129,.3)}
.status.rejected{background:rgba(239,68,68,.15);color:var(--red);border:1px solid rgba(239,68,68,.3)}
.status.completed{background:rgba(91,192,222,.15);color:var(--blue);border:1px solid rgba(91,192,222,.3)}
/* Action buttons */
.action-btn{padding:5px 12px;border-radius:8px;border:none;cursor:pointer;font-family:'Cairo',sans-serif;font-size:.74rem;font-weight:700;transition:.2s}
.btn-approve{background:rgba(16,185,129,.15);color:var(--green);border:1px solid rgba(16,185,129,.3)}
.btn-approve:hover{background:rgba(16,185,129,.25)}
.btn-reject{background:rgba(239,68,68,.15);color:var(--red);border:1px solid rgba(239,68,68,.3)}
.btn-reject:hover{background:rgba(239,68,68,.25)}
.btn-complete{background:rgba(91,192,222,.15);color:var(--blue);border:1px solid rgba(91,192,222,.3)}
.btn-complete:hover{background:rgba(91,192,222,.25)}
/* Modal */
.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:999;align-items:center;justify-content:center}
.modal.open{display:flex}
.modal-box{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:24px;width:90%;max-width:480px}
.modal-title{font-size:.95rem;font-weight:800;margin-bottom:16px;display:flex;align-items:center;gap:8px}
.modal-note{width:100%;background:rgba(255,255,255,.04);border:1.5px solid var(--border);border-radius:10px;padding:10px 13px;color:var(--text);font-family:'Cairo',sans-serif;font-size:.86rem;resize:vertical;min-height:80px;margin-bottom:14px}
.modal-note:focus{outline:none;border-color:var(--gold)}
.modal-actions{display:flex;gap:8px;justify-content:flex-end}
.msg{padding:12px 16px;border-radius:10px;font-size:.84rem;font-weight:700;margin-bottom:14px}
.msg.success{background:rgba(16,185,129,.1);border:1px solid var(--green);color:var(--green)}
.msg.error{background:rgba(239,68,68,.1);border:1px solid var(--red);color:var(--red)}
.empty{text-align:center;padding:50px;color:var(--muted)}
.proof-link{color:var(--blue);font-size:.75rem;text-decoration:none}
.proof-link:hover{color:var(--gold)}
@media(max-width:768px){.stats{grid-template-columns:repeat(2,1fr)}table{font-size:.75rem}th,td{padding:8px 10px}}
</style>

<nav class="top-bar">
  <div class="brand"><i class="fas fa-coins"></i> DI PARMA</div>
  <div style="display:flex;align-items:center;gap:10px">
    <div class="badge"><i class="fas fa-university"></i> Bank Approvals</div>
    <div class="top-nav">
      <a href="../dashboard.php"><i class="fas fa-th-large"></i></a>
      <a href="../index.php"><i class="fas fa-home"></i></a>
    </div>
  </div>
</nav>

<div class="wrap">

<?php if ($msg): ?>
<div class="msg <?=$msgType?>"><?=htmlspecialchars($msg)?></div>
<?php endif; ?>

<!-- ══ Stats ══ -->
<div class="stats">
  <div class="stat">
    <div class="stat-val" style="color:var(--orange)"><?=intval($statMap['pending']['cnt'] ?? 0)?></div>
    <div class="stat-lbl"><?=$ar?'معلّقة':'Pending'?></div>
  </div>
  <div class="stat">
    <div class="stat-val" style="color:var(--green)"><?=intval($statMap['approved']['cnt'] ?? 0)?></div>
    <div class="stat-lbl"><?=$ar?'موافق عليها':'Approved'?></div>
  </div>
  <div class="stat">
    <div class="stat-val" style="color:var(--blue)"><?=intval($statMap['completed']['cnt'] ?? 0)?></div>
    <div class="stat-lbl"><?=$ar?'مكتملة':'Completed'?></div>
  </div>
  <div class="stat">
    <div class="stat-val" style="color:var(--red)"><?=intval($statMap['rejected']['cnt'] ?? 0)?></div>
    <div class="stat-lbl"><?=$ar?'مرفوضة':'Rejected'?></div>
  </div>
</div>

<!-- ══ Filters ══ -->
<div class="filters">
  <?php foreach (['pending','approved','completed','rejected','all'] as $f): ?>
  <a href="?status=<?=$f?>" class="filter-btn <?=$filter===$f?'active':''?>">
    <?=match($f){
      'pending'   => ($ar?'معلّقة':'Pending'),
      'approved'  => ($ar?'موافق عليها':'Approved'),
      'completed' => ($ar?'مكتملة':'Completed'),
      'rejected'  => ($ar?'مرفوضة':'Rejected'),
      'all'       => ($ar?'الكل':'All'),
    }?>
    <?php if($f !== 'all' && isset($statMap[$f])): ?>
    <span style="background:rgba(255,255,255,.1);border-radius:20px;padding:1px 7px;font-size:.65rem;margin-<?=$ar?'right':'left'?>:4px">
      <?=$statMap[$f]['cnt']?>
    </span>
    <?php endif; ?>
  </a>
  <?php endforeach; ?>
</div>

<!-- ══ Table ══ -->
<div class="tbl-wrap">
<?php if (empty($rows)): ?>
  <div class="empty"><i class="fas fa-inbox" style="font-size:2rem;margin-bottom:10px;display:block;color:rgba(255,215,0,.2)"></i><?=$ar?'لا توجد طلبات':'No requests found'?></div>
<?php else: ?>
<table>
  <thead>
    <tr>
      <th>#</th>
      <th><?=$ar?'المرجع':'Ref'?></th>
      <th><?=$ar?'العميل':'Customer'?></th>
      <th><?=$ar?'البنك':'Bank'?></th>
      <th><?=$ar?'المبلغ':'Amount'?></th>
      <th><?=$ar?'الحالة':'Status'?></th>
      <th><?=$ar?'الإثبات':'Proof'?></th>
      <th><?=$ar?'التاريخ':'Date'?></th>
      <th><?=$ar?'الإجراء':'Action'?></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($rows as $row): ?>
  <tr>
    <td style="color:var(--muted)"><?=$row['id']?></td>
    <td><code style="color:var(--gold);font-size:.75rem"><?=htmlspecialchars($row['ref'])?></code></td>
    <td>
      <div style="font-weight:700"><?=htmlspecialchars($row['customer_name'])?></div>
      <div style="font-size:.72rem;color:var(--muted)"><?=htmlspecialchars($row['customer_email'])?></div>
      <?php if($row['customer_phone']): ?><div style="font-size:.7rem;color:var(--muted)"><?=htmlspecialchars($row['customer_phone'])?></div><?php endif; ?>
    </td>
    <td>
      <div style="font-size:.78rem"><?=htmlspecialchars($row['bank_name'])?></div>
      <?php if($row['transfer_ref']): ?><div style="font-size:.7rem;color:var(--blue)">Ref: <?=htmlspecialchars($row['transfer_ref'])?></div><?php endif; ?>
    </td>
    <td style="font-weight:800;color:var(--gold)"><?=number_format($row['amount'],2)?> <?=htmlspecialchars($row['currency'])?></td>
    <td><span class="status <?=htmlspecialchars($row['status'])?>"><?=htmlspecialchars($row['status'])?></span></td>
    <td>
      <?php if($row['proof_file']): ?>
      <a href="../uploads/bank_proofs/<?=htmlspecialchars($row['proof_file'])?>" target="_blank" class="proof-link">
        <i class="fas fa-file-image"></i> <?=$ar?'عرض':'View'?>
      </a>
      <?php else: ?>
      <span style="color:var(--muted);font-size:.72rem">—</span>
      <?php endif; ?>
    </td>
    <td style="font-size:.74rem;color:var(--muted)"><?=date('d/m/Y H:i', strtotime($row['created_at']))?></td>
    <td>
      <div style="display:flex;gap:5px;flex-wrap:wrap">
        <?php if($row['status'] === 'pending'): ?>
        <button class="action-btn btn-approve" onclick="openModal(<?=$row['id']?>,'approve')">
          <i class="fas fa-check"></i> <?=$ar?'موافقة':'Approve'?>
        </button>
        <button class="action-btn btn-reject" onclick="openModal(<?=$row['id']?>,'reject')">
          <i class="fas fa-times"></i> <?=$ar?'رفض':'Reject'?>
        </button>
        <?php endif; ?>
        <?php if($row['status'] === 'approved'): ?>
        <button class="action-btn btn-complete" onclick="openModal(<?=$row['id']?>,'complete')">
          <i class="fas fa-check-double"></i> <?=$ar?'إكمال':'Complete'?>
        </button>
        <?php endif; ?>
        <?php if($row['admin_note']): ?>
        <span style="font-size:.7rem;color:var(--muted);max-width:120px;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?=htmlspecialchars($row['admin_note'])?>">
          📝 <?=htmlspecialchars($row['admin_note'])?>
        </span>
        <?php endif; ?>
      </div>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
</div>
</div>

<!-- ══ Modal ══ -->
<div class="modal" id="actionModal">
  <div class="modal-box">
    <div class="modal-title" id="modalTitle"><i class="fas fa-check-circle" style="color:var(--green)"></i> Action</div>
    <form method="POST" id="actionForm">
      <input type="hidden" name="id" id="modalId">
      <input type="hidden" name="action" id="modalAction">
      <textarea class="modal-note" name="admin_note" id="modalNote"
                placeholder="<?=$ar?'ملاحظة الإدارة (اختياري)':'Admin note (optional)'?>"></textarea>
      <div class="modal-actions">
        <button type="button" onclick="closeModal()"
                style="padding:8px 18px;border-radius:9px;border:1px solid rgba(255,255,255,.1);background:transparent;color:var(--muted);cursor:pointer;font-family:'Cairo',sans-serif">
          <?=$ar?'إلغاء':'Cancel'?>
        </button>
        <button type="submit" id="modalSubmitBtn"
                style="padding:8px 18px;border-radius:9px;border:none;cursor:pointer;font-family:'Cairo',sans-serif;font-weight:800;background:var(--green);color:#000">
          <?=$ar?'تأكيد':'Confirm'?>
        </button>
      </div>
    </form>
  </div>
</div>

<script>
var pendingCount = <?=intval($statMap['pending']['cnt'] ?? 0)?>;

function openModal(id, action) {
  var colors = { approve:'#10B981', reject:'#EF4444', complete:'#5bc0de' };
  var titles = {
    approve: '<?=$ar?'موافقة على الطلب':'Approve Request'?>',
    reject:  '<?=$ar?'رفض الطلب':'Reject Request'?>',
    complete:'<?=$ar?'إكمال الطلب':'Complete Request'?>'
  };
  var icons = { approve:'fas fa-check-circle', reject:'fas fa-times-circle', complete:'fas fa-check-double' };

  document.getElementById('modalId').value     = id;
  document.getElementById('modalAction').value = action;
  document.getElementById('modalNote').value   = '';
  document.getElementById('modalTitle').innerHTML =
    '<i class="' + icons[action] + '" style="color:' + colors[action] + '"></i> ' + titles[action];
  document.getElementById('modalSubmitBtn').style.background = colors[action];
  document.getElementById('actionModal').classList.add('open');
}

function closeModal() {
  document.getElementById('actionModal').classList.remove('open');
}

// إغلاق بالضغط خارج الـ modal
document.getElementById('actionModal').addEventListener('click', function(e) {
  if (e.target === this) closeModal();
});

// auto-refresh كل 30 ثانية إذا يوجد طلبات معلّقة
if (pendingCount > 0) {
  setTimeout(function(){ location.reload(); }, 30000);
}
</script>
</body></html>
