<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';

$db = db();
$search = trim((string)($_GET['search'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$types = ['purchase_2d','purchase_advice','purchase_offline','purchase_online','auth_capture','auth_complete','offline_purchase','online_purchase','withdrawal_manual','withdrawal_physical','cash_advance','purchase_moto'];
$marks = implode(',', array_fill(0, count($types), '?'));
$where = ["(transaction_type IN ($marks) OR transaction_type LIKE ? OR transaction_label LIKE ?)"];
$params = array_merge($types, ['%MOTO%', '%MOTO%']);
if ($search !== '') {
    $where[] = '(reference LIKE ? OR gateway LIKE ? OR transaction_type LIKE ? OR transaction_label LIKE ? OR cardholder_name LIKE ? OR rrn LIKE ? OR auth_code LIKE ? OR approval_code LIKE ?)';
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term, $term, $term, $term, $term, $term);
}
if ($status !== '') { $where[] = 'status = ?'; $params[] = $status; }
if ($dateFrom !== '') { $where[] = 'created_at >= ?'; $params[] = $dateFrom . ' 00:00:00'; }
if ($dateTo !== '') { $where[] = 'created_at <= ?'; $params[] = $dateTo . ' 23:59:59'; }
$whereSql = 'WHERE ' . implode(' AND ', $where);

try {
    $count = (int)(($db->query('SELECT COUNT(*) total FROM ' . DB_PREFIX . 'transactions ' . $whereSql, $params)[0]['total'] ?? 0));
    $rows = $db->query('SELECT * FROM ' . DB_PREFIX . 'transactions ' . $whereSql . ' ORDER BY created_at DESC LIMIT ? OFFSET ?', array_merge($params, [$perPage, $offset])) ?: [];
    $stats = $db->query("SELECT COUNT(*) total, COALESCE(SUM(amount),0) amount, SUM(status='completed') completed, SUM(status IN ('failed','declined','cancelled')) failed FROM " . DB_PREFIX . "transactions $whereSql", $params)[0] ?? [];
} catch (Throwable $e) {
    http_response_code(500);
    exit('Unable to load withdrawal database.');
}

function wdEscape($value): string { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8'); }
function wdLabel(string $key): string { return ucwords(str_replace(['_', '-'], ' ', $key)); }
function wdSafeValue(string $key, $value): string {
    if (is_array($value)) {
        $parts = [];
        foreach ($value as $childKey => $childValue) $parts[] = wdLabel((string)$childKey) . ': ' . wdSafeValue((string)$childKey, $childValue);
        return '{ ' . implode(' | ', $parts) . ' }';
    }
    if (is_object($value)) return wdSafeValue($key, (array)$value);
    $text = (string)($value ?? '');
    if (preg_match('/card_number|card_pan|cvv|cvc|password|secret|token|api_key|private_key/i', $key)) return '[NOT DISPLAYED]';
    if (preg_match('/card_last4/i', $key)) return '****' . substr(preg_replace('/\D/', '', $text), -4);
    return $text;
}
function wdQueryUrl(int $page): string {
    $query = $_GET;
    $query['page'] = $page;
    return 'withdrawal_database.php?' . http_build_query($query);
}
$totalPages = max(1, (int)ceil($count / $perPage));
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>قاعدة بيانات السحب | DI PARMA</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root{--bg:#071018;--panel:#0d1a25;--line:#233746;--text:#edf4f7;--muted:#91a5b4;--gold:#ffd34d;--green:#22c997;--red:#ff7070}*{box-sizing:border-box}body{margin:0;background:linear-gradient(145deg,#061018,#101e29);color:var(--text);font-family:Cairo,sans-serif}.wrap{max-width:1500px;margin:auto;padding:24px}.top{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px}.brand{color:var(--gold);font-size:1.2rem;font-weight:900}.brand small{display:block;color:var(--muted);font-size:.7rem}.back{color:var(--muted);text-decoration:none}.panel{background:rgba(13,26,37,.96);border:1px solid var(--line);border-radius:10px;padding:16px;margin-bottom:15px}.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:9px;margin-top:15px}.stat{background:#122533;border-radius:7px;padding:12px}.stat b{display:block;color:var(--gold);font-size:1.1rem}.stat span{color:var(--muted);font-size:.7rem}.filters{display:grid;grid-template-columns:2fr 1fr 1fr 1fr auto;gap:8px;align-items:end}.field label{display:block;color:var(--muted);font-size:.68rem;margin-bottom:4px}.field input,.field select{width:100%;padding:9px;border:1px solid var(--line);border-radius:6px;background:#08141d;color:var(--text);font:inherit;font-size:.76rem}.btn{border:0;border-radius:6px;background:var(--gold);color:#151515;padding:10px 15px;font:700 .76rem Cairo;cursor:pointer}.table-wrap{overflow:auto}.table{width:100%;min-width:1150px;border-collapse:collapse;font-size:.72rem}.table th{color:var(--muted);font-size:.64rem;text-align:right;border-bottom:1px solid var(--line);padding:10px;white-space:nowrap}.table td{border-bottom:1px solid rgba(35,55,70,.7);padding:10px;vertical-align:top}.table tr:hover{background:rgba(255,211,77,.04)}.mono{font-family:Consolas,monospace;direction:ltr;text-align:right}.status{padding:3px 8px;border-radius:14px;font-size:.62rem;font-weight:800}.ok{color:var(--green);background:rgba(34,201,151,.12)}.bad{color:var(--red);background:rgba(255,112,112,.12)}.wait{color:var(--gold);background:rgba(255,211,77,.12)}.muted{color:var(--muted);font-size:.66rem}.details{margin-top:7px;border:1px solid var(--line);border-radius:5px;padding:6px;color:var(--gold)}.details summary{cursor:pointer}.all-data{display:grid;grid-template-columns:repeat(3,minmax(180px,1fr));gap:6px;margin-top:8px;color:var(--text)}.all-data div{background:#08141d;border-radius:4px;padding:6px;word-break:break-word}.all-data b{display:block;color:var(--muted);font-size:.6rem}.all-data span{font-size:.68rem}.pager{text-align:center;margin-top:15px;color:var(--muted);font-size:.75rem}.pager a{color:var(--gold);margin:0 12px;text-decoration:none}@media(max-width:850px){.filters{grid-template-columns:1fr 1fr}.stats{grid-template-columns:1fr 1fr}}
</style>
</head>
<body><main class="wrap">
<header class="top"><div class="brand">قاعدة بيانات السحب<small>Withdrawal Operations Database</small></div><a class="back" href="dashboard.php">لوحة التحكم ←</a></header>
<section class="panel"><h1 style="margin:0;font-size:1.25rem">كل عمليات السحب</h1><div class="muted">كل أرقام العمليات والمعرّفات التشغيلية تظهر كاملة. بيانات البطاقة وCVV محمية.</div><div class="stats"><div class="stat"><b><?=number_format((int)($stats['total'] ?? 0))?></b><span>إجمالي العمليات</span></div><div class="stat"><b><?=number_format((float)($stats['amount'] ?? 0),2)?></b><span>إجمالي المبالغ</span></div><div class="stat"><b><?=number_format((int)($stats['completed'] ?? 0))?></b><span>ناجحة</span></div><div class="stat"><b><?=number_format((int)($stats['failed'] ?? 0))?></b><span>فاشلة أو ملغاة</span></div></div></section>
<section class="panel"><form class="filters" method="get"><div class="field"><label>بحث</label><input name="search" value="<?=wdEscape($search)?>" placeholder="مرجع، RRN، Approval، بوابة..."></div><div class="field"><label>الحالة</label><select name="status"><option value="">كل الحالات</option><?php foreach(['completed','pending','processing','failed','declined','cancelled','refunded'] as $option): ?><option value="<?=$option?>" <?=$status===$option?'selected':''?>><?=$option?></option><?php endforeach; ?></select></div><div class="field"><label>من تاريخ</label><input type="date" name="date_from" value="<?=wdEscape($dateFrom)?>"></div><div class="field"><label>إلى تاريخ</label><input type="date" name="date_to" value="<?=wdEscape($dateTo)?>"></div><button class="btn">بحث</button></form></section>
<section class="panel"><div class="table-wrap"><table class="table"><thead><tr><th>التاريخ</th><th>المرجع</th><th>نوع العملية</th><th>المبلغ</th><th>الحالة</th><th>البطاقة</th><th>RRN</th><th>Approval</th><th>البوابة</th><th>كل البيانات</th></tr></thead><tbody><?php if (!$rows): ?><tr><td colspan="10" style="text-align:center;padding:40px;color:var(--muted)">لا توجد عمليات سحب.</td></tr><?php endif; ?><?php foreach($rows as $row): $state=strtolower((string)($row['status']??'unknown')); $stateClass=in_array($state,['completed','approved','captured','settled'],true)?'ok':(in_array($state,['failed','declined','cancelled','refunded'],true)?'bad':'wait'); $json=[]; foreach(['gateway_response','transaction_data'] as $column){if(!empty($row[$column])){$decoded=json_decode((string)$row[$column],true);$json[$column]=is_array($decoded)?$decoded:$row[$column];}} ?><tr><td class="mono"><?=wdEscape($row['created_at']??'—')?></td><td class="mono"><?=wdEscape($row['reference']??'—')?></td><td><?=wdEscape($row['transaction_label']??$row['transaction_type']??'—')?><br><span class="muted"><?=wdEscape($row['transaction_type']??'')?></span></td><td class="mono"><?=number_format((float)($row['amount']??0),2)?> <?=wdEscape($row['currency']??'')?></td><td><span class="status <?=$stateClass?>"><?=wdEscape(strtoupper($state))?></span></td><td><?=wdEscape($row['card_brand']??'')?> ****<?=wdEscape(substr((string)($row['card_last4']??''),-4))?><br><span class="muted"><?=wdEscape($row['cardholder_name']??'')?></span></td><td class="mono"><?=wdEscape($row['rrn']??'—')?></td><td class="mono"><?=wdEscape($row['approval_code']??$row['auth_code']??'—')?></td><td><?=wdEscape($row['gateway']??'—')?><br><span class="muted"><?=wdEscape($row['gateway_type']??'')?></span></td><td><details class="details"><summary>عرض البيانات</summary><div class="all-data"><?php foreach($row as $key=>$value): if(in_array($key,['gateway_response','transaction_data'],true))continue; ?><div><b><?=wdEscape(wdLabel((string)$key))?></b><span><?=wdEscape(wdSafeValue((string)$key,$value))?></span></div><?php endforeach; ?><?php foreach($json as $key=>$value): ?><div style="grid-column:1/-1"><b><?=wdEscape(wdLabel($key))?></b><span><?=wdEscape(wdSafeValue($key,$value))?></span></div><?php endforeach; ?></div></details></td></tr><?php endforeach; ?></tbody></table></div><?php if($totalPages>1): ?><div class="pager"><?php if($page>1): ?><a href="<?=wdQueryUrl($page-1)?>">السابق</a><?php endif; ?>صفحة <?=$page?> من <?=$totalPages?><?php if($page<$totalPages): ?><a href="<?=wdQueryUrl($page+1)?>">التالي</a><?php endif; ?></div><?php endif; ?></section>
</main></body></html>
