<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';

$lang = isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'ar' ? 'ar' : 'en';
$ar   = ($lang === 'ar'); $dir = $ar ? 'rtl' : 'ltr';

// ─── جلب الأرصدة ────────────────────────────────────────────
function fetchBalance(string $gw): array {
    $result = ['gateway'=>$gw,'balance'=>null,'currency'=>'USD','status'=>'error','message'=>''];
    try {
        switch ($gw) {

            // ── Stripe ───────────────────────────────────────
            case 'stripe': {
                $sk = getenv('STRIPE_SECRET_KEY') ?: '';
                if (!$sk) { $result['message']='Missing key'; break; }
                $ch = curl_init('https://api.stripe.com/v1/balance');
                curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$sk],CURLOPT_TIMEOUT=>10]);
                $r = json_decode(curl_exec($ch),true) ?: [];
                curl_close($ch);
                if (!empty($r['available'])) {
                    $balances = [];
                    foreach ($r['available'] as $b) {
                        $balances[] = number_format($b['amount']/100,2).' '.strtoupper($b['currency']);
                    }
                    $result['balance']  = implode(' | ', $balances);
                    $result['currency'] = '';
                    $result['status']   = 'success';
                } else {
                    $result['message'] = $r['error']['message'] ?? 'Error';
                }
                break;
            }

            // ── PayPal ───────────────────────────────────────
            case 'paypal': {
                $cid = getenv('PAYPAL_CLIENT_ID') ?: '';
                $sec = getenv('PAYPAL_CLIENT_SECRET') ?: (getenv('PAYPAL_SECRET') ?: '');
                if (!$cid || !$sec) { $result['message']='Missing credentials'; break; }
                // Get token
                $ch = curl_init('https://api-m.paypal.com/v1/oauth2/token');
                curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,
                    CURLOPT_POSTFIELDS=>'grant_type=client_credentials',
                    CURLOPT_USERPWD=>$cid.':'.$sec,
                    CURLOPT_HTTPHEADER=>['Accept: application/json','Content-Type: application/x-www-form-urlencoded'],
                    CURLOPT_TIMEOUT=>10]);
                $td = json_decode(curl_exec($ch),true) ?: [];
                curl_close($ch);
                $token = $td['access_token'] ?? '';
                if (!$token) { $result['message']='Auth failed'; break; }
                // Get balance
                $ch = curl_init('https://api-m.paypal.com/v1/reporting/balances');
                curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,
                    CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Content-Type: application/json'],
                    CURLOPT_TIMEOUT=>10]);
                $bd = json_decode(curl_exec($ch),true) ?: [];
                curl_close($ch);
                if (!empty($bd['balances'])) {
                    $parts = [];
                    foreach ($bd['balances'] as $b) {
                        $parts[] = number_format((float)($b['total_balance']['value']??0),2).' '.($b['total_balance']['currency_code']??'');
                    }
                    $result['balance'] = implode(' | ', $parts) ?: '0.00 USD';
                    $result['currency'] = '';
                    $result['status'] = 'success';
                } else {
                    $result['balance'] = '0.00 USD';
                    $result['status']  = 'success';
                    $result['message'] = 'No balance data';
                }
                break;
            }

            // ── Nuvei ────────────────────────────────────────
            case 'nuvei': {
                $mId = getenv('NUVEI_MERCHANT_ID') ?: '2761828514943809999';
                $sId = getenv('NUVEI_SITE_ID')     ?: '5613117';
                $key = getenv('NUVEI_SECRET_KEY')  ?: '';
                if (!$key) { $result['message']='Missing key'; break; }
                $ts  = date('YmdHis');
                $ref = 'BAL'.time();
                $cs  = hash('sha256', $mId.$sId.$ref.$ts.$key);
                $body = json_encode(['merchantId'=>$mId,'merchantSiteId'=>$sId,
                    'clientRequestId'=>$ref,'timeStamp'=>$ts,'checksum'=>$cs]);
                $ch = curl_init('https://secure.nuvei.com/ppp/api/v1/getSessionToken.do');
                curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,
                    CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_TIMEOUT=>10]);
                $r = json_decode(curl_exec($ch),true) ?: [];
                curl_close($ch);
                if (($r['status']??'') === 'SUCCESS') {
                    $result['balance']  = 'Connected ✓';
                    $result['currency'] = '';
                    $result['status']   = 'success';
                    $result['message']  = 'Session: '.substr($r['sessionToken']??'',0,16).'...';
                } else {
                    $result['message'] = $r['reason'] ?? 'Error';
                }
                break;
            }

            // ── Wise ─────────────────────────────────────────
            case 'wise': {
                $key = getenv('WISE_API_KEY') ?: '';
                if (!$key) { $result['message']='Missing key'; break; }
                $ch = curl_init('https://api.transferwise.com/v1/profiles');
                curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,
                    CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key],CURLOPT_TIMEOUT=>10]);
                $pd = json_decode(curl_exec($ch),true) ?: [];
                curl_close($ch);
                $profileId = $pd[0]['id'] ?? '';
                if (!$profileId) { $result['message']='Profile not found'; break; }
                // Get balances
                $ch = curl_init("https://api.transferwise.com/v4/profiles/{$profileId}/balances?types=STANDARD");
                curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,
                    CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key],CURLOPT_TIMEOUT=>10]);
                $bd = json_decode(curl_exec($ch),true) ?: [];
                curl_close($ch);
                if (!empty($bd)) {
                    $parts = [];
                    foreach ((array)$bd as $b) {
                        if (isset($b['amount']['value'])) {
                            $parts[] = number_format($b['amount']['value'],2).' '.$b['amount']['currency'];
                        }
                    }
                    $result['balance']  = implode(' | ', $parts) ?: '0.00';
                    $result['currency'] = '';
                    $result['status']   = 'success';
                } else {
                    $result['balance'] = 'Connected ✓';
                    $result['status']  = 'success';
                }
                break;
            }

            // ── Binance ───────────────────────────────────────
            case 'binance': {
                $key = getenv('EXCHANGE_API_KEY')    ?: '';
                $sec = getenv('EXCHANGE_SECRET_KEY') ?: '';
                if (!$key || !$sec) { $result['message']='Missing credentials'; break; }
                $ts  = round(microtime(true)*1000);
                $qs  = 'timestamp='.$ts;
                $sig = hash_hmac('sha256', $qs, $sec);
                $ch  = curl_init('https://api.binance.com/api/v3/account?'.$qs.'&signature='.$sig);
                curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,
                    CURLOPT_HTTPHEADER=>['X-MBX-APIKEY: '.$key],CURLOPT_TIMEOUT=>10]);
                $r = json_decode(curl_exec($ch),true) ?: [];
                curl_close($ch);
                if (!empty($r['balances'])) {
                    $parts = [];
                    foreach ($r['balances'] as $b) {
                        $free = floatval($b['free']??0);
                        if ($free > 0.001) $parts[] = number_format($free,4).' '.$b['asset'];
                    }
                    $result['balance']  = implode(' | ', array_slice($parts,0,5)) ?: '0';
                    $result['currency'] = '';
                    $result['status']   = 'success';
                } else {
                    $result['message'] = $r['msg'] ?? 'Error';
                }
                break;
            }

            // ── Gate.io ───────────────────────────────────────
            case 'gate_io': {
                $key = getenv('GATE_IO_API_KEY')    ?: '';
                $sec = getenv('GATE_IO_SECRET_KEY') ?: '';
                if (!$key || !$sec) { $result['message']='Missing credentials'; break; }
                $ts    = (string)time();
                $body  = '';
                $sign  = hash_hmac('sha512',
                    "GET\n/api/v4/spot/accounts\n\n\n".$ts, $sec);
                $ch = curl_init('https://api.gateio.ws/api/v4/spot/accounts');
                curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,
                    CURLOPT_HTTPHEADER=>['KEY:'.$key,'TIMESTAMP:'.$ts,'SIGN:'.$sign,
                        'Content-Type: application/json'],CURLOPT_TIMEOUT=>10]);
                $r = json_decode(curl_exec($ch),true) ?: [];
                curl_close($ch);
                if (is_array($r) && isset($r[0]['currency'])) {
                    $parts = [];
                    foreach ($r as $b) {
                        $av = floatval($b['available']??0);
                        if ($av > 0.001) $parts[] = number_format($av,4).' '.$b['currency'];
                    }
                    $result['balance']  = implode(' | ', array_slice($parts,0,5)) ?: '0';
                    $result['currency'] = '';
                    $result['status']   = 'success';
                } else {
                    $result['message'] = is_array($r) ? ($r['message']??json_encode($r)) : 'Error';
                }
                break;
            }

            // ── MyFatoorah ────────────────────────────────────
            case 'myfatoorah': {
                $key = getenv('MYFAOORAH_API_KEY') ?: '';
                if (!$key) { $result['message']='Missing key'; break; }
                $ch = curl_init('https://api.myfatoorah.com/v2/GetAccountInfo');
                curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,
                    CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key,'Content-Type: application/json'],
                    CURLOPT_TIMEOUT=>10]);
                $r = json_decode(curl_exec($ch),true) ?: [];
                curl_close($ch);
                if (!empty($r['Data'])) {
                    $result['balance']  = 'Connected ✓';
                    $result['currency'] = '';
                    $result['status']   = 'success';
                    $result['message']  = $r['Data']['AccountName'] ?? '';
                } else {
                    $result['message'] = $r['Message'] ?? 'Error';
                }
                break;
            }

            // ── Whop ──────────────────────────────────────────
            case 'whop': {
                $key = getenv('WHOP_API_KEY') ?: '';
                if (!$key) { $result['message']='Missing key'; break; }
                $ch = curl_init('https://api.whop.com/api/v2/me');
                curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,
                    CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key,'Content-Type: application/json'],
                    CURLOPT_TIMEOUT=>10]);
                $r = json_decode(curl_exec($ch),true) ?: [];
                curl_close($ch);
                if (!empty($r['id'])) {
                    $result['balance']  = 'Connected ✓';
                    $result['currency'] = '';
                    $result['status']   = 'success';
                    $result['message']  = $r['username'] ?? $r['id'];
                } else {
                    $result['message'] = $r['message'] ?? 'Error';
                }
                break;
            }
        }
    } catch (Exception $e) {
        $result['message'] = $e->getMessage();
    }
    return $result;
}

// الحصول على الأرصدة لكل البوابات
$gateways = [
    'stripe'     => ['label'=>'Stripe',     'color'=>'#6772e5','icon'=>'fab fa-stripe-s'],
    'paypal'     => ['label'=>'PayPal',     'color'=>'#0070ba','icon'=>'fab fa-paypal'],
    'nuvei'      => ['label'=>'Nuvei',      'color'=>'#0A5EB0','icon'=>'fas fa-credit-card'],
    'wise'       => ['label'=>'Wise',       'color'=>'#00B9FF','icon'=>'fas fa-paper-plane'],
    'binance'    => ['label'=>'Binance',    'color'=>'#F0B90B','icon'=>'fas fa-coins'],
    'gate_io'    => ['label'=>'Gate.io',    'color'=>'#e8112d','icon'=>'fas fa-door-open'],
    'myfatoorah' => ['label'=>'MyFatoorah', 'color'=>'#00b09b','icon'=>'fas fa-money-bill-wave'],
    'whop'       => ['label'=>'Whop',       'color'=>'#4F46E5','icon'=>'fas fa-bolt'],
];

$balances = [];
foreach ($gateways as $code => $gw) {
    $b = fetchBalance($code);
    $balances[$code] = array_merge($gw, $b);
}
?><!DOCTYPE html>
<html lang="<?=$lang?>" dir="<?=$dir?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA | Gateway Balances</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--gold:#FFD700;--bg:#040810;--card:#080d1a;--border:rgba(255,215,0,.15);--text:#f0f0f0;--muted:#666;--green:#10B981;--red:#EF4444}
body{font-family:'Cairo',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.top-bar{background:rgba(4,8,16,.97);border-bottom:1px solid var(--border);padding:0 24px;height:62px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:99}
.brand{color:var(--gold);font-weight:900}
.badge{background:rgba(255,215,0,.1);border:1px solid rgba(255,215,0,.3);border-radius:10px;padding:5px 14px;color:var(--gold);font-weight:800;font-size:.82rem}
.top-nav a{color:var(--muted);font-size:.82rem;padding:6px 12px;border-radius:20px;text-decoration:none}
.top-nav a:hover{color:var(--gold)}
.wrap{max-width:1100px;margin:28px auto;padding:0 20px}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px}
.gw-card{background:var(--card);border:1.5px solid var(--border);border-radius:18px;padding:20px;transition:.25s;position:relative;overflow:hidden}
.gw-card:hover{transform:translateY(-3px);box-shadow:0 8px 32px rgba(0,0,0,.4)}
.gw-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--gw-color)}
.gw-header{display:flex;align-items:center;gap:12px;margin-bottom:14px}
.gw-icon{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0}
.gw-name{font-size:.92rem;font-weight:800}
.gw-status{font-size:.7rem;margin-top:2px}
.balance-val{font-size:1.4rem;font-weight:900;margin-bottom:6px;line-height:1.2;word-break:break-word}
.balance-msg{font-size:.74rem;color:var(--muted)}
.status-dot{width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:5px;flex-shrink:0}
.refresh-btn{position:absolute;top:14px;right:14px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:8px;padding:4px 10px;cursor:pointer;font-size:.72rem;color:var(--muted);transition:.2s}
.refresh-btn:hover{color:var(--gold);border-color:rgba(255,215,0,.3)}
.page-title{margin-bottom:24px}
.page-title h1{font-size:1.4rem;font-weight:900;color:var(--gold)}
.page-title p{color:var(--muted);font-size:.84rem;margin-top:4px}
.back-link{display:inline-flex;align-items:center;gap:6px;color:var(--muted);font-size:.8rem;text-decoration:none;margin-bottom:16px}
.back-link:hover{color:var(--gold)}
.summary-bar{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:16px 20px;margin-bottom:20px;display:flex;gap:24px;flex-wrap:wrap}
.sum-item{text-align:center}
.sum-val{font-size:1.3rem;font-weight:900}
.sum-lbl{font-size:.7rem;color:var(--muted);margin-top:2px}
@media(max-width:600px){.grid{grid-template-columns:1fr}.summary-bar{gap:14px}}
</style>

<nav class="top-bar">
  <div class="brand"><i class="fas fa-coins"></i> DI PARMA</div>
  <div style="display:flex;align-items:center;gap:10px">
    <div class="badge"><i class="fas fa-wallet"></i> Gateway Balances</div>
    <div class="top-nav">
      <a href="dashboard.php"><i class="fas fa-th-large"></i></a>
      <a href="index.php"><i class="fas fa-home"></i></a>
    </div>
  </div>
</nav>

<div class="wrap">
  <a href="index.php" class="back-link"><i class="fas fa-arrow-<?=$ar?'right':'left'?>"></i> <?=$ar?'رجوع':'Back'?></a>

  <div class="page-title">
    <h1><i class="fas fa-wallet"></i> <?=$ar?'أرصدة بوابات الدفع':'Gateway Balances'?></h1>
    <p><?=$ar?'آخر تحديث: '.date('H:i:s'):'Last updated: '.date('H:i:s')?></p>
  </div>

  <?php
  $connected = array_filter($balances, fn($b) => $b['status']==='success');
  $errors    = array_filter($balances, fn($b) => $b['status']!=='success');
  ?>

  <!-- Summary -->
  <div class="summary-bar">
    <div class="sum-item">
      <div class="sum-val" style="color:var(--green)"><?=count($connected)?></div>
      <div class="sum-lbl"><?=$ar?'متصلة':'Connected'?></div>
    </div>
    <div class="sum-item">
      <div class="sum-val" style="color:var(--red)"><?=count($errors)?></div>
      <div class="sum-lbl"><?=$ar?'خطأ':'Error'?></div>
    </div>
    <div class="sum-item">
      <div class="sum-val" style="color:var(--gold)"><?=count($balances)?></div>
      <div class="sum-lbl"><?=$ar?'إجمالي':'Total'?></div>
    </div>
    <div style="margin-<?=$ar?'right':'left'?>:auto;display:flex;align-items:center">
      <button onclick="location.reload()"
              style="background:rgba(255,215,0,.1);border:1px solid rgba(255,215,0,.2);border-radius:10px;padding:8px 18px;cursor:pointer;color:var(--gold);font-family:'Cairo',sans-serif;font-size:.82rem;font-weight:700">
        <i class="fas fa-sync-alt"></i> <?=$ar?'تحديث':'Refresh'?>
      </button>
    </div>
  </div>

  <!-- Cards -->
  <div class="grid">
    <?php foreach ($balances as $code => $gw): ?>
    <div class="gw-card" style="--gw-color:<?=$gw['color']?>">
      <div class="gw-header">
        <div class="gw-icon" style="background:color-mix(in srgb,<?=$gw['color']?> 15%,transparent)">
          <i class="<?=$gw['icon']?>" style="color:<?=$gw['color']?>"></i>
        </div>
        <div>
          <div class="gw-name"><?=$gw['label']?></div>
          <div class="gw-status">
            <span class="status-dot" style="background:<?=$gw['status']==='success'?'var(--green)':'var(--red)'?>"></span>
            <span style="color:<?=$gw['status']==='success'?'var(--green)':'var(--red)';?>; font-size:.7rem">
              <?=$gw['status']==='success'?($ar?'متصل':'Connected'):($ar?'خطأ':'Error')?>
            </span>
          </div>
        </div>
      </div>

      <?php if ($gw['status'] === 'success'): ?>
        <div class="balance-val" style="color:<?=$gw['color']?>">
          <?=htmlspecialchars($gw['balance'] ?? '—')?>
        </div>
        <?php if (!empty($gw['message'])): ?>
        <div class="balance-msg"><?=htmlspecialchars($gw['message'])?></div>
        <?php endif; ?>
      <?php else: ?>
        <div class="balance-val" style="color:var(--red);font-size:1rem">
          <i class="fas fa-exclamation-triangle"></i>
          <?=$ar?'غير متصل':'Not Connected'?>
        </div>
        <div class="balance-msg" style="color:var(--red);opacity:.8">
          <?=htmlspecialchars($gw['message'] ?? 'Unknown error')?>
        </div>
        <a href="checkout_<?=$code?>.php" style="display:inline-block;margin-top:10px;font-size:.72rem;color:<?=$gw['color']?>;text-decoration:none">
          <i class="fas fa-external-link-alt"></i> <?=$ar?'إعداد':'Setup'?>
        </a>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
</body></html>
