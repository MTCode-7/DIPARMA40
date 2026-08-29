<?php
/**
 * ============================================================
 * DI PARMA | Gateway Dashboard
 * Card-to-Crypto Transaction Life Cycle Monitor
 * ============================================================
 */
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/FXConversionEngine.php';
require_once __DIR__ . '/SettlementEngine.php';

$lang = isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang']==='en' ? 'en' : 'ar';
$ar   = ($lang === 'ar');
$dir  = $ar ? 'rtl' : 'ltr';
$db   = db();

// ── Data ────────────────────────────────────────────────────
$fx         = new FXConversionEngine();
$settlement = new SettlementEngine();
$trxBal     = $fx->getWalletTRX();
$usdtBal    = $fx->getWalletUSDT();
$dailyReport= $settlement->generateDailyReport();
$pending    = [];

try {
    $pending = $db->query(
        "SELECT * FROM dp_ledger_transfer_queue WHERE status IN ('queued','processing') ORDER BY created_at DESC LIMIT 20"
    ) ?: [];
    $recentTxns = $db->query(
        "SELECT * FROM dp_transactions ORDER BY created_at DESC LIMIT 15"
    ) ?: [];
    $auditTrail = [];
    try {
        $auditTrail = $db->query(
            "SELECT * FROM dp_audit_trail ORDER BY created_at DESC LIMIT 10"
        ) ?: [];
    } catch (Exception $e) {}
} catch (Exception $e) {
    $recentTxns = [];
}

$todayStats = $db->query(
    "SELECT COUNT(*) cnt, COALESCE(SUM(amount),0) vol, COALESCE(SUM(crypto_amount),0) crypto_vol
     FROM dp_transactions WHERE DATE(created_at)=? AND status='completed'",
    [date('Y-m-d')]
)[0] ?? ['cnt'=>0,'vol'=>0,'crypto_vol'=>0];

$rateResult = $fx->getRate('USD', 'USDT');
$currentRate = $rateResult['success'] ? $rateResult['rate'] : 1.0;
?><!DOCTYPE html>
<html lang="<?=$lang?>" dir="<?=$dir?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="refresh" content="30"><!-- Auto-refresh every 30s -->
<title>DI PARMA | Gateway Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&family=Share+Tech+Mono&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--gold:#FFD700;--gold2:#FFB700;--bg:#020508;--bg2:#060c14;--card:#090f1e;--card2:#0b1224;--border:rgba(255,215,0,.12);--border2:rgba(255,215,0,.28);--text:#edf0f7;--muted:#4a5568;--muted2:#718096;--green:#10B981;--red:#EF4444;--blue:#3B82F6;--orange:#F97316;--purple:#8B5CF6}
body{font-family:'Cairo',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.topbar{background:rgba(2,5,8,.97);border-bottom:1px solid var(--border);height:62px;display:flex;align-items:center;justify-content:space-between;padding:0 28px;position:sticky;top:0;z-index:100}
.tb-brand{color:var(--gold);font-weight:900;font-size:1.05rem;display:flex;align-items:center;gap:10px}
.tb-badge{background:rgba(16,185,129,.1);border:1.5px solid rgba(16,185,129,.25);border-radius:10px;padding:5px 14px;font-size:.78rem;font-weight:800;color:var(--green);display:flex;align-items:center;gap:7px}
.live-dot{width:8px;height:8px;border-radius:50%;background:var(--green);animation:pulse 1.5s infinite;flex-shrink:0}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}
.wrap{max-width:1400px;margin:0 auto;padding:24px}

/* Pipeline Visual */
.pipeline{display:flex;align-items:center;gap:0;overflow-x:auto;padding:20px 0;margin-bottom:24px;background:var(--card);border:1px solid var(--border);border-radius:18px;padding:20px 24px}
.stage{display:flex;flex-direction:column;align-items:center;gap:6px;min-width:90px;text-align:center}
.stage-icon{width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;transition:.3s}
.stage-num{font-size:.6rem;color:var(--muted);font-weight:700}
.stage-name{font-size:.62rem;font-weight:800;color:var(--muted2);line-height:1.3}
.stage-arrow{color:rgba(255,255,255,.15);font-size:1rem;margin:0 4px;flex-shrink:0;align-self:center}
.stage.active .stage-icon{box-shadow:0 0 16px rgba(255,215,0,.3)}
.stage.done .stage-name{color:var(--green)}
.stage.error .stage-name{color:var(--red)}

/* Stats */
.stats-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:14px;margin-bottom:24px}
.stat{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:16px;text-align:center}
.stat-val{font-size:1.2rem;font-weight:900;margin-bottom:4px}
.stat-lbl{font-size:.65rem;color:var(--muted2)}
.stat-sub{font-size:.7rem;color:var(--muted);margin-top:2px}

/* 2-col grid */
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:18px}
.grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:18px;margin-bottom:18px}

/* Cards */
.card{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:20px}
.card-title{font-size:.85rem;font-weight:800;color:var(--gold);display:flex;align-items:center;gap:8px;margin-bottom:14px;justify-content:space-between}

/* Tables */
.tbl{width:100%;border-collapse:collapse;font-size:.74rem}
.tbl th{padding:8px 10px;color:var(--muted2);font-weight:700;text-align:<?=$ar?'right':'left'?>;border-bottom:1px solid var(--border)}
.tbl td{padding:8px 10px;border-bottom:1px solid rgba(255,255,255,.03)}
.tbl tr:hover td{background:rgba(255,215,0,.02)}
.mono{font-family:'Share Tech Mono',monospace}

/* Badges */
.badge{padding:2px 8px;border-radius:7px;font-size:.63rem;font-weight:700}
.badge-completed,.badge-confirmed{background:rgba(16,185,129,.1);color:var(--green)}
.badge-pending,.badge-queued,.badge-broadcasting,.badge-confirming{background:rgba(251,191,36,.1);color:#FBBF24}
.badge-failed,.badge-error{background:rgba(239,68,68,.1);color:var(--red)}
.badge-processing{background:rgba(59,130,246,.1);color:var(--blue)}

/* Ledger box */
.ledger-box{background:linear-gradient(135deg,rgba(16,185,129,.08),rgba(16,185,129,.02));border:1.5px solid rgba(16,185,129,.2);border-radius:16px;padding:20px}
.ledger-title{font-size:.82rem;font-weight:800;color:var(--green);margin-bottom:14px;display:flex;align-items:center;gap:8px}
.ledger-addr{font-family:'Share Tech Mono',monospace;font-size:.68rem;color:var(--muted2);word-break:break-all;margin-bottom:10px;cursor:pointer}
.ledger-addr:hover{color:var(--green)}
.ledger-bal{font-size:1.4rem;font-weight:900;color:var(--green)}
.ledger-sub{font-size:.75rem;color:var(--muted2)}

/* Rate */
.rate-box{background:var(--card2);border:1px solid var(--border);border-radius:12px;padding:14px;text-align:center}

/* Refresh bar */
.refresh-bar{height:2px;background:rgba(255,215,0,.1);position:fixed;top:62px;left:0;right:0;z-index:99}
.refresh-progress{height:100%;background:var(--gold);animation:refresh 30s linear infinite}
@keyframes refresh{0%{width:100%}100%{width:0%}}

/* Buttons */
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:10px;border:none;font-family:'Cairo',sans-serif;font-size:.78rem;font-weight:700;cursor:pointer;text-decoration:none;transition:.2s}
.btn-gold{background:linear-gradient(135deg,var(--gold),var(--gold2));color:#000}
.btn-dark{background:rgba(255,255,255,.06);color:var(--text);border:1.5px solid var(--border)}
.btn-green{background:rgba(16,185,129,.12);color:var(--green);border:1px solid rgba(16,185,129,.2)}

#toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(100px);background:var(--card);border:1px solid var(--border2);border-radius:14px;padding:12px 28px;font-size:.84rem;font-weight:700;z-index:9999;transition:.35s;color:var(--text)}
@media(max-width:1200px){.stats-grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:900px){.grid-2,.grid-3{grid-template-columns:1fr}.stats-grid{grid-template-columns:repeat(2,1fr)}}
</style>
</head>
<body>
<div class="refresh-bar"><div class="refresh-progress"></div></div>

<header class="topbar">
  <div class="tb-brand">
    <i class="fas fa-coins"></i> DI PARMA
    <span style="color:var(--muted)">|</span>
    <div class="tb-badge">
      <div class="live-dot"></div>
      Card-to-Crypto Gateway
    </div>
  </div>
  <div style="display:flex;align-items:center;gap:10px">
    <span style="font-size:.7rem;color:var(--muted2)">Auto-refresh 30s</span>
    <a href="https://tronscan.org/#/address/TEwLFWlwK55b7PuFfzgH1H2f3xs3pLgLn2" target="_blank" class="btn btn-green" style="font-size:.72rem">
      <i class="fas fa-external-link-alt"></i> TronScan
    </a>
    <a href="../dashboard.php" class="btn btn-dark" style="font-size:.72rem">
      <i class="fas fa-th-large"></i> <?=$ar?'لوحة التحكم':'Dashboard'?>
    </a>
  </div>
</header>

<div class="wrap">

  <!-- ══ PIPELINE VISUAL ══ -->
  <div class="pipeline">
    <?php
    $stages = [
      [1,'fas fa-user','Cardholder','#3B82F6'],
      [2,'fas fa-sim-card','POS Terminal','#F97316'],
      [3,'fas fa-server','POS Backend','#8B5CF6'],
      [4,'fas fa-shield-alt','Pre-checks','#F59E0B'],
      [5,'fas fa-university','Card Auth','#10B981'],
      [6,'fas fa-check-circle','Auth Decision','#10B981'],
      [7,'fas fa-hand-holding-usd','Funds Capture','#14B8A6'],
      [8,'fas fa-exchange-alt','FX Convert','#3B82F6'],
      [9,'fas fa-tint','Liquidity','#6366F1'],
      [10,'fas fa-wallet','Blockchain Exec','#10B981'],
      [11,'fas fa-eye','On-Chain Monitor','#F59E0B'],
      [12,'fas fa-check-double','Settlement','#10B981'],
      [13,'fas fa-book','Reconciliation','#FFD700'],
    ];
    foreach($stages as $i => [$num,$icon,$name,$color]):
    ?>
    <div class="stage done">
      <div class="stage-icon" style="background:<?=$color?>22;color:<?=$color?>">
        <i class="<?=$icon?>"></i>
      </div>
      <div class="stage-num"><?=$num?></div>
      <div class="stage-name"><?=$name?></div>
    </div>
    <?php if($num < 13): ?><div class="stage-arrow"><i class="fas fa-chevron-right"></i></div><?php endif; ?>
    <?php endforeach; ?>
  </div>

  <!-- ══ STATS ══ -->
  <div class="stats-grid">
    <div class="stat">
      <div class="stat-val" style="color:var(--gold)"><?=number_format($todayStats['cnt'])?></div>
      <div class="stat-lbl"><?=$ar?'معاملات اليوم':'Today TXNs'?></div>
    </div>
    <div class="stat">
      <div class="stat-val" style="color:var(--green)">$<?=number_format(floatval($todayStats['vol']),0)?></div>
      <div class="stat-lbl"><?=$ar?'حجم اليوم':'Today Volume'?></div>
    </div>
    <div class="stat">
      <div class="stat-val" style="color:var(--blue)"><?=number_format(floatval($todayStats['crypto_vol']),2)?></div>
      <div class="stat-lbl"><?=$ar?'USDT محوّل':'USDT Converted'?></div>
    </div>
    <div class="stat">
      <div class="stat-val" style="color:var(--orange)"><?=count($pending)?></div>
      <div class="stat-lbl"><?=$ar?'تحويلات معلقة':'Pending Transfers'?></div>
    </div>
    <div class="stat">
      <div class="stat-val" style="color:var(--green)"><?=number_format($usdtBal,2)?></div>
      <div class="stat-lbl"><?=$ar?'رصيد USDT':'USDT Balance'?></div>
    </div>
    <div class="stat">
      <div class="stat-val" style="color:var(--muted2)"><?=number_format($trxBal,2)?></div>
      <div class="stat-lbl"><?=$ar?'رصيد TRX':'TRX Balance'?></div>
      <div class="stat-sub"><?=$trxBal < 50 ? '⚠️ Low Gas!' : '✓ Gas OK'?></div>
    </div>
  </div>

  <!-- ══ ROW 1 ══ -->
  <div class="grid-3">

    <!-- Ledger Status -->
    <div class="ledger-box">
      <div class="ledger-title"><i class="fas fa-wallet"></i> Ledger TRX Wallet</div>
      <div class="ledger-addr" onclick="copyText('TEwLFWlwK55b7PuFfzgH1H2f3xs3pLgLn2')" title="Click to copy">
        TEwLFWlwK55b7PuFfzgH1H2f3xs3pLgLn2
      </div>
      <div class="ledger-bal"><?=number_format($usdtBal,2)?> USDT</div>
      <div class="ledger-sub"><?=number_format($trxBal,4)?> TRX (gas)</div>
      <div style="margin-top:12px;display:flex;gap:8px">
        <a href="https://tronscan.org/#/address/TEwLFWlwK55b7PuFfzgH1H2f3xs3pLgLn2" target="_blank" class="btn btn-green btn-sm" style="font-size:.7rem">
          <i class="fas fa-external-link-alt"></i> TronScan
        </a>
      </div>
      <?php if($trxBal < 50): ?>
      <div style="margin-top:10px;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.2);border-radius:8px;padding:8px;font-size:.72rem;color:var(--red)">
        ⚠️ <?=$ar?'رصيد TRX منخفض — قد يتوقف التحويل':'Low TRX balance — transfers may fail'?>
      </div>
      <?php endif; ?>
    </div>

    <!-- FX Rate -->
    <div class="card">
      <div class="card-title"><i class="fas fa-exchange-alt"></i> FX Rates</div>
      <?php
      $fxRates = [
        'USD'=>['rate'=>1.0,'color'=>'#3B82F6'],
        'AED'=>['rate'=>0.2723,'color'=>'#10B981'],
        'EUR'=>['rate'=>1.082,'color'=>'#6366F1'],
        'SAR'=>['rate'=>0.2667,'color'=>'#F59E0B'],
        'GBP'=>['rate'=>1.271,'color'=>'#8B5CF6'],
      ];
      foreach($fxRates as $cur=>$data): ?>
      <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid rgba(255,255,255,.04);font-size:.78rem">
        <span style="font-weight:700;color:<?=$data['color']?>"><?=$cur?></span>
        <span style="font-family:'Share Tech Mono',monospace"><?=number_format(1/$data['rate'],6)?> USDT</span>
        <span style="color:var(--muted2);font-size:.65rem">(1 <?=$cur?> = X USDT)</span>
      </div>
      <?php endforeach; ?>
      <div style="margin-top:10px;font-size:.68rem;color:var(--muted2)">
        <i class="fas fa-info-circle"></i>
        <?=$ar?'سعر الصرف + spread 0.5%':'Rate includes 0.5% spread'?>
      </div>
    </div>

    <!-- Daily Report -->
    <div class="card">
      <div class="card-title">
        <span><i class="fas fa-chart-bar"></i> <?=$ar?'تقرير اليوم':'Daily Report'?></span>
        <span style="font-size:.7rem;color:var(--muted2)"><?=date('d/m/Y')?></span>
      </div>
      <?php if(!empty($dailyReport['transactions'])): ?>
      <?php foreach($dailyReport['transactions'] as $row): ?>
      <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid rgba(255,255,255,.04);font-size:.76rem">
        <span class="badge badge-<?=htmlspecialchars($row['status'])?>"><?=htmlspecialchars($row['status'])?></span>
        <span style="color:var(--muted2)"><?=htmlspecialchars($row['gateway']??'')?></span>
        <span style="font-weight:700">$<?=number_format(floatval($row['vol']??0),0)?></span>
        <span style="color:var(--muted2)"><?=$row['cnt']?> txns</span>
      </div>
      <?php endforeach; ?>
      <?php else: ?>
      <div style="text-align:center;padding:20px;color:var(--muted2);font-size:.78rem"><?=$ar?'لا توجد معاملات اليوم':'No transactions today'?></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ══ ROW 2 ══ -->
  <div class="grid-2">

    <!-- Recent Transactions -->
    <div class="card">
      <div class="card-title">
        <span><i class="fas fa-list"></i> <?=$ar?'آخر المعاملات':'Recent Transactions'?></span>
        <a href="../transactions.php" class="btn btn-dark" style="font-size:.7rem"><i class="fas fa-external-link-alt"></i></a>
      </div>
      <div style="overflow-x:auto">
        <table class="tbl">
          <thead><tr>
            <th>Ref</th>
            <th><?=$ar?'المبلغ':'Amount'?></th>
            <th>USDT</th>
            <th>Status</th>
            <th>Ledger</th>
            <th><?=$ar?'التاريخ':'Date'?></th>
          </tr></thead>
          <tbody>
          <?php foreach(($recentTxns ?? []) as $t):
            $gr = json_decode($t['gateway_response']??'{}',true)?:[];
            $ledgerTxid = $gr['ledger_txid'] ?? $t['ledger_txid'] ?? null;
          ?>
          <tr>
            <td class="mono" style="font-size:.65rem"><?=htmlspecialchars(substr($t['reference']??'',0,14))?></td>
            <td style="font-weight:700"><?=number_format(floatval($t['amount']??0),2)?> <?=htmlspecialchars($t['currency']??'')?></td>
            <td style="color:var(--green);font-size:.72rem"><?=number_format(floatval($gr['usdt_amount']??$t['crypto_amount']??0),4)?></td>
            <td><span class="badge badge-<?=htmlspecialchars($t['status']??'pending')?>"><?=htmlspecialchars($t['status']??'—')?></span></td>
            <td>
              <?php if($ledgerTxid && !str_starts_with($ledgerTxid,'QUEUED_')): ?>
              <a href="https://tronscan.org/#/transaction/<?=htmlspecialchars($ledgerTxid)?>" target="_blank"
                 class="mono" style="font-size:.62rem;color:var(--green)">
                <?=substr($ledgerTxid,0,10)?>...
              </a>
              <?php elseif($ledgerTxid): ?>
              <span style="font-size:.65rem;color:var(--orange)">⏳ <?=$ar?'في الانتظار':'Queued'?></span>
              <?php else: ?>
              <span style="color:var(--muted);font-size:.65rem">—</span>
              <?php endif; ?>
            </td>
            <td style="font-size:.65rem;color:var(--muted2)"><?=date('d/m H:i',strtotime($t['created_at']??'now'))?></td>
          </tr>
          <?php endforeach; ?>
          <?php if(empty($recentTxns)): ?>
          <tr><td colspan="6" style="text-align:center;padding:24px;color:var(--muted2)"><?=$ar?'لا توجد معاملات':'No transactions'?></td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Pending Ledger Transfers -->
    <div class="card">
      <div class="card-title">
        <span><i class="fas fa-hourglass-half" style="color:var(--orange)"></i> <?=$ar?'تحويلات Ledger المعلقة':'Pending Ledger Transfers'?></span>
        <span style="font-size:.7rem;color:var(--orange)"><?=count($pending)?> <?=$ar?'معلق':'pending'?></span>
      </div>
      <?php if(!empty($pending)): ?>
      <div style="overflow-x:auto">
        <table class="tbl">
          <thead><tr>
            <th>Ref</th>
            <th>USDT</th>
            <th>Address</th>
            <th>Status</th>
            <th><?=$ar?'محاولات':'Attempts'?></th>
          </tr></thead>
          <tbody>
          <?php foreach($pending as $p): ?>
          <tr>
            <td class="mono" style="font-size:.65rem"><?=htmlspecialchars(substr($p['reference']??'',0,14))?></td>
            <td style="font-weight:700;color:var(--green)"><?=number_format(floatval($p['usdt_amount']??0),4)?></td>
            <td class="mono" style="font-size:.62rem;color:var(--muted2)"><?=htmlspecialchars(substr($p['ledger_address']??'',0,14))?>...</td>
            <td><span class="badge badge-<?=htmlspecialchars($p['status']??'queued')?>"><?=htmlspecialchars($p['status']??'—')?></span></td>
            <td style="text-align:center;color:var(--muted2)"><?=$p['attempts']??0?>/5</td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
      <div style="text-align:center;padding:24px;color:var(--green);font-size:.82rem">
        <i class="fas fa-check-circle" style="font-size:1.5rem;display:block;margin-bottom:8px"></i>
        <?=$ar?'لا توجد تحويلات معلقة':'No pending transfers'?>
      </div>
      <?php endif; ?>
      <div style="margin-top:12px">
        <a href="?process_queue=1" class="btn btn-green" style="font-size:.74rem;width:100%;justify-content:center">
          <i class="fas fa-play"></i> <?=$ar?'معالجة الطابور':'Process Queue'?>
        </a>
      </div>
    </div>
  </div>

  <!-- ══ Audit Trail ══ -->
  <?php if(!empty($auditTrail)): ?>
  <div class="card">
    <div class="card-title">
      <span><i class="fas fa-book" style="color:var(--gold)"></i> <?=$ar?'سجل التدقيق':'Audit Trail'?></span>
    </div>
    <div style="overflow-x:auto">
      <table class="tbl">
        <thead><tr>
          <th>Audit ID</th>
          <th>Reference</th>
          <th><?=$ar?'المبلغ':'Amount'?></th>
          <th>USDT</th>
          <th>TXID</th>
          <th><?=$ar?'مطابق':'Matched'?></th>
          <th><?=$ar?'الرسوم':'Fees'?></th>
          <th><?=$ar?'التاريخ':'Date'?></th>
        </tr></thead>
        <tbody>
        <?php foreach($auditTrail as $a):
          $fees = json_decode($a['fees_json']??'{}',true)?:[];
        ?>
        <tr>
          <td class="mono" style="font-size:.62rem;color:var(--purple)"><?=htmlspecialchars($a['audit_id']??'')?></td>
          <td class="mono" style="font-size:.65rem"><?=htmlspecialchars(substr($a['reference']??'',0,14))?></td>
          <td style="font-weight:700"><?=number_format(floatval($a['fiat_amount']??0),2)?> <?=htmlspecialchars($a['fiat_currency']??'')?></td>
          <td style="color:var(--green)"><?=number_format(floatval($a['crypto_amount']??0),4)?></td>
          <td>
            <?php $txid=$a['txid']??''; if($txid && !str_starts_with($txid,'QUEUED_')): ?>
            <a href="https://tronscan.org/#/transaction/<?=htmlspecialchars($txid)?>" target="_blank" class="mono" style="font-size:.62rem;color:var(--green)"><?=substr($txid,0,12)?>...</a>
            <?php else: ?><span style="color:var(--muted);font-size:.65rem">—</span><?php endif; ?>
          </td>
          <td>
            <span class="badge <?=($a['reconciled']??0)?'badge-completed':'badge-pending'?>">
              <?=($a['reconciled']??0)?'✓ YES':'⚠ NO'?>
            </span>
          </td>
          <td style="font-size:.7rem;color:var(--red)">$<?=number_format(floatval($fees['total']??0),4)?></td>
          <td style="font-size:.65rem;color:var(--muted2)"><?=date('d/m H:i',strtotime($a['created_at']??'now'))?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /wrap -->

<?php
// Process queue if requested
if(isset($_GET['process_queue'])){
    require_once __DIR__ . '/OnChainMonitor.php';
    $result = (new OnChainMonitor())->processPendingQueue();
    echo "<script>alert('Processed: " . ($result['processed']??0) . " transfers'); window.location.href=window.location.pathname;</script>";
}
?>

<div id="toast"></div>
<script>
function copyText(txt){navigator.clipboard?.writeText(txt).then(()=>{const t=document.getElementById('toast');t.style.color='var(--green)';t.style.borderColor='var(--green)';t.textContent='✅ Copied';t.style.transform='translateX(-50%) translateY(0)';setTimeout(()=>{t.style.transform='translateX(-50%) translateY(100px)';},2000);});}
</script>
</body>
</html>
