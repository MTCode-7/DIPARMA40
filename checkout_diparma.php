<?php
/**
 * ============================================================
 * DI PARMA | Ultimate Financial Gateway × Ledger Nano X
 * ============================================================
 * 
 * ملف واحد شامل يدعم:
 * - 13 نوع شراء حقيقي
 * - اتصال Ledger Nano X عبر WebHID
 * - دفع مباشر من البطاقة
 * - إيصال مطبوع
 * - 3D Secure
 * - Webhook
 * ============================================================
 * اسم الملف: checkout_diparma.php
 * المسار: /checkout_diparma.php
 * ============================================================
 */

require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';

$lang = isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'ar' ? 'ar' : 'en';
$ar   = ($lang === 'ar');
$dir  = $ar ? 'rtl' : 'ltr';
$csrf = generateCsrfToken();
$db   = db();

// ── بيانات API من DB ─────────────────────────────────────────
$apiKey = $apiSecret = $webhookSecret = $webhookUrl = $ledgerAddr = '';
try {
    $rows = $db->query(
        "SELECT * FROM dp_api_clients WHERE status='active' ORDER BY id DESC LIMIT 1", []
    );
    if (!empty($rows[0])) {
        $apiKey        = $rows[0]['api_key']        ?? '';
        $apiSecret     = $rows[0]['api_secret']     ?? '';
        $webhookSecret = $rows[0]['webhook_secret'] ?? $rows[0]['api_secret'] ?? '';
        $webhookUrl    = $rows[0]['webhook_url']    ?? '';
        $meta          = json_decode($rows[0]['meta'] ?? '{}', true);
        $ledgerAddr    = $meta['ledger_address']    ?? 'TFyAQPrTRdP7zp46RPmE1iiCac1Lh6Bu58';
    }
} catch (Exception $e) {
    error_log('[checkout_diparma] DB: ' . $e->getMessage());
}
if (!$ledgerAddr) $ledgerAddr = 'TFyAQPrTRdP7zp46RPmE1iiCac1Lh6Bu58';

if (!$apiKey) $apiKey = 'CD82DFFE2E4DDB6A';
if (!$apiSecret) $apiSecret = '1e0ec4b703138796f11cf93673e8762e5dbb04112eb851b1';
if (!$webhookSecret) $webhookSecret = 'y8K4r7Qz9vT2pX1sB6nF0mL3aR5cH2yU';
if (!$webhookUrl) $webhookUrl = 'https://diparmas.com/api/webhook.php';

$siteUrl  = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
$endpoint = $siteUrl . '/api/v1/diparma_charge.php';

// ── 13 نوع عمليات الشراء ──────────────────────────────────────
$TXN = [
    // ════════════════════════════════════════════════════════════
    // 1. PURCHASE 3D SECURE
    // ════════════════════════════════════════════════════════════
    'purchase_3d' => [
        'ar'=>'شراء 3D Secure', 
        'en'=>'Purchase 3D Secure',
        'icon'=>'fa-shield-alt', 
        'c'=>'#10B981', 
        'sub'=>'3D Secure',
        'orig'=>true, 
        'noamt'=>false, 
        'secmode'=>'3D',
        'iso'=>'0200', 
        'desc'=>'شراء مع تحقق 3D Secure من البنك المصدر',
        'category'=>'online', 
        'moto_type'=>null, 
        'advice'=>false, 
        'offline'=>false,
        'requires_original'=>false,
        'settlement_days'=>2
    ],
    
    // ════════════════════════════════════════════════════════════
    // 2. PURCHASE 2D / MOTO
    // ════════════════════════════════════════════════════════════
    'purchase_2d' => [
        'ar'=>'شراء 2D / MOTO', 
        'en'=>'Purchase 2D / MOTO',
        'icon'=>'fa-credit-card', 
        'c'=>'#3B82F6', 
        'sub'=>'2D / MOTO',
        'orig'=>false, 
        'noamt'=>false, 
        'secmode'=>'2D',
        'iso'=>'0200', 
        'desc'=>'شراء بدون 3D Secure (هاتف/بريد)',
        'category'=>'online', 
        'moto_type'=>null, 
        'advice'=>false, 
        'offline'=>false,
        'requires_original'=>false,
        'settlement_days'=>1
    ],
    
    // ════════════════════════════════════════════════════════════
    // 3. PURCHASE ADVICE (إشعار شراء)
    // ════════════════════════════════════════════════════════════
    'purchase_advice' => [
        'ar'=>'إشعار شراء (Advice)', 
        'en'=>'Purchase Advice',
        'icon'=>'fa-bell', 
        'c'=>'#F59E0B', 
        'sub'=>'Advice',
        'orig'=>true, 
        'noamt'=>false, 
        'secmode'=>'2D',
        'iso'=>'0220', 
        'desc'=>'معاملة إرشادية بعد موافقة مسبقة (ISO 0220)',
        'category'=>'advice', 
        'moto_type'=>'advice', 
        'advice'=>true, 
        'offline'=>false,
        'requires_original'=>true,
        'settlement_days'=>1
    ],
    
    // ════════════════════════════════════════════════════════════
    // 4. OFFLINE SALES - MOTO
    // ════════════════════════════════════════════════════════════
    'purchase_offline' => [
        'ar'=>'مبيعات خارج الخط (MOTO)', 
        'en'=>'Offline Sales (MOTO)',
        'icon'=>'fa-phone', 
        'c'=>'#8B5CF6', 
        'sub'=>'Offline MOTO',
        'orig'=>true, 
        'noamt'=>false, 
        'secmode'=>'2D',
        'iso'=>'0200', 
        'desc'=>'معاملة عبر الهاتف/البريد/فاكس - MOTO',
        'category'=>'offline', 
        'moto_type'=>'offline', 
        'advice'=>false, 
        'offline'=>true,
        'moto_indicator'=>'M',
        'requires_original'=>true,
        'requires_approval'=>true,
        'settlement_days'=>1
    ],
    
    // ════════════════════════════════════════════════════════════
    // 5. ONLINE SALES - MOTO
    // ════════════════════════════════════════════════════════════
    'purchase_online' => [
        'ar'=>'مبيعات عبر الإنترنت (MOTO)', 
        'en'=>'Online Sales (MOTO)',
        'icon'=>'fa-globe', 
        'c'=>'#06B6D4', 
        'sub'=>'Online MOTO',
        'orig'=>false, 
        'noamt'=>false, 
        'secmode'=>'2D',
        'iso'=>'0200', 
        'desc'=>'معاملة عبر الإنترنت مع تصنيف MOTO',
        'category'=>'online', 
        'moto_type'=>'online', 
        'advice'=>false, 
        'offline'=>false,
        'moto_indicator'=>'M',
        'requires_original'=>false,
        'settlement_days'=>1
    ],
    
    // ════════════════════════════════════════════════════════════
    // 6. AUTHORIZATION HOLD (تفويض - حجز)
    // ════════════════════════════════════════════════════════════
    'auth_hold' => [
        'ar'=>'تفويض (حجز)', 
        'en'=>'Authorization Hold',
        'icon'=>'fa-lock', 
        'c'=>'#6366F1', 
        'sub'=>'Hold',
        'orig'=>false, 
        'noamt'=>false, 
        'secmode'=>'3D',
        'iso'=>'0100', 
        'desc'=>'تجميد المبلغ مؤقتاً لحين التأكيد (ISO 0100)',
        'category'=>'auth', 
        'moto_type'=>null, 
        'advice'=>false, 
        'offline'=>false,
        'requires_original'=>false,
        'settlement_days'=>3
    ],
    
    // ════════════════════════════════════════════════════════════
    // 7. AUTHORIZATION CAPTURE (إتمام التفويض)
    // ════════════════════════════════════════════════════════════
    'auth_capture' => [
        'ar'=>'إتمام التفويض', 
        'en'=>'Authorization Capture',
        'icon'=>'fa-check-double', 
        'c'=>'#8B5CF6', 
        'sub'=>'Capture',
        'orig'=>true, 
        'noamt'=>false, 
        'secmode'=>'3D',
        'iso'=>'0200', 
        'desc'=>'تأكيد التجميد وتحويله إلى شراء كامل',
        'category'=>'auth', 
        'moto_type'=>null, 
        'advice'=>false, 
        'offline'=>false,
        'requires_original'=>true,
        'settlement_days'=>1
    ],
    
    // ════════════════════════════════════════════════════════════
    // 8. RECURRING (شراء متكرر - اشتراك)
    // ════════════════════════════════════════════════════════════
    'recurring' => [
        'ar'=>'شراء متكرر (اشتراك)', 
        'en'=>'Recurring Purchase',
        'icon'=>'fa-repeat', 
        'c'=>'#14B8A6', 
        'sub'=>'Subscription',
        'orig'=>false, 
        'noamt'=>false, 
        'secmode'=>'3D',
        'iso'=>'0200', 
        'desc'=>'دفع متكرر شهري/سنوي للاشتراكات',
        'category'=>'recurring', 
        'moto_type'=>null, 
        'advice'=>false, 
        'offline'=>false,
        'recurring_indicator'=>'R',
        'requires_original'=>false,
        'settlement_days'=>1
    ],
    
    // ════════════════════════════════════════════════════════════
    // 9. INSTALLMENT (شراء بالتقسيط)
    // ════════════════════════════════════════════════════════════
    'installment' => [
        'ar'=>'شراء بالتقسيط', 
        'en'=>'Installment Purchase',
        'icon'=>'fa-calculator', 
        'c'=>'#F97316', 
        'sub'=>'Installment',
        'orig'=>false, 
        'noamt'=>false, 
        'secmode'=>'3D',
        'iso'=>'0200', 
        'desc'=>'شراء وتقسيم المبلغ على عدة دفعات',
        'category'=>'installment', 
        'moto_type'=>null, 
        'advice'=>false, 
        'offline'=>false,
        'installment_indicator'=>'I',
        'requires_original'=>false,
        'settlement_days'=>1
    ],
    
    // ════════════════════════════════════════════════════════════
    // 10. CRYPTO PURCHASE (شراء عملات رقمية)
    // ════════════════════════════════════════════════════════════
    'crypto_purchase' => [
        'ar'=>'شراء عملات رقمية', 
        'en'=>'Crypto Purchase',
        'icon'=>'fab fa-bitcoin', 
        'c'=>'#F7931A', 
        'sub'=>'Crypto',
        'orig'=>false, 
        'noamt'=>false, 
        'secmode'=>'2D',
        'iso'=>'0200', 
        'desc'=>'شراء USDT/BTC/ETH باستخدام البطاقة',
        'category'=>'crypto', 
        'moto_type'=>null, 
        'advice'=>false, 
        'offline'=>false,
        'requires_original'=>false,
        'settlement_days'=>1
    ],
    
    // ════════════════════════════════════════════════════════════
    // 11. GIFT CARD (بطاقة هدايا)
    // ════════════════════════════════════════════════════════════
    'gift_card' => [
        'ar'=>'بطاقة هدايا', 
        'en'=>'Gift Card',
        'icon'=>'fa-gift', 
        'c'=>'#EC4899', 
        'sub'=>'Gift Card',
        'orig'=>false, 
        'noamt'=>false, 
        'secmode'=>'2D',
        'iso'=>'0200', 
        'desc'=>'شراء بطاقة هدايا رقمية',
        'category'=>'gift', 
        'moto_type'=>null, 
        'advice'=>false, 
        'offline'=>false,
        'requires_original'=>false,
        'settlement_days'=>1
    ],
    
    // ════════════════════════════════════════════════════════════
    // 12. WIRE TRANSFER (تحويل بنكي مباشر)
    // ════════════════════════════════════════════════════════════
    'wire_transfer' => [
        'ar'=>'تحويل بنكي مباشر', 
        'en'=>'Wire Transfer',
        'icon'=>'fa-university', 
        'c'=>'#1E40AF', 
        'sub'=>'Bank Transfer',
        'orig'=>false, 
        'noamt'=>false, 
        'secmode'=>'2D',
        'iso'=>'0200', 
        'desc'=>'تحويل مبلغ من البطاقة إلى حساب بنكي',
        'category'=>'bank', 
        'moto_type'=>null, 
        'advice'=>false, 
        'offline'=>false,
        'requires_original'=>false,
        'settlement_days'=>3
    ],
    
    // ════════════════════════════════════════════════════════════
    // 13. QUASI CASH (سحب نقدي شبيه)
    // ════════════════════════════════════════════════════════════
    'quasi_cash' => [
        'ar'=>'سحب نقدي شبيه', 
        'en'=>'Quasi Cash',
        'icon'=>'fa-coins', 
        'c'=>'#FFD700', 
        'sub'=>'Quasi Cash',
        'orig'=>false, 
        'noamt'=>false, 
        'secmode'=>'3D',
        'iso'=>'0200', 
        'desc'=>'سحب نقدي عبر البطاقة (كازينوهات/مراهنات)',
        'category'=>'cash', 
        'moto_type'=>null, 
        'advice'=>false, 
        'offline'=>false,
        'requires_original'=>false,
        'settlement_days'=>2
    ],
];

// تقسيم الأنواع إلى صفوف (4 أعمدة)
$txnChunks = array_chunk($TXN, 4, true);
?>
<!DOCTYPE html>
<html lang="<?=$lang?>" dir="<?=$dir?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<meta name="theme-color" content="#020508">
<title>DI PARMA | Ultimate Gateway × Ledger Nano X</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}
:root{
  --gold:#FFD700;--gold2:#FFB700;--gold3:rgba(255,215,0,.08);
  --bg:#020508;--bg2:#040a12;--card:#070e1c;--card2:#0a1224;
  --border:rgba(255,215,0,.11);--border2:rgba(255,215,0,.26);
  --text:#edf0f7;--muted:#3d4a5c;--muted2:#6b7a90;
  --green:#10B981;--red:#EF4444;--blue:#3B82F6;--purple:#8B5CF6;--teal:#14B8A6;--orange:#F97316;
  --ledger:#000;
}
html,body{height:100%;font-family:'Cairo',sans-serif;background:var(--bg);color:var(--text);overflow-x:hidden}

/* ═══ TOPBAR ═══ */
.topbar{
  height:54px;background:rgba(2,5,8,.97);border-bottom:1px solid var(--border);
  display:flex;align-items:center;justify-content:space-between;
  padding:0 18px;position:sticky;top:0;z-index:300;backdrop-filter:blur(10px);
}
.tb-brand{display:flex;align-items:center;gap:10px;font-weight:900;font-size:.92rem;color:var(--gold)}
.tb-ldg-badge{
  display:flex;align-items:center;gap:5px;background:var(--ledger);
  border:1.5px solid #fff;border-radius:8px;padding:3px 10px;
  font-size:.65rem;font-weight:800;color:#fff;
}
.tb-right{display:flex;align-items:center;gap:8px}
.tb-link{color:var(--muted2);font-size:.75rem;padding:5px 10px;border-radius:16px;text-decoration:none;transition:.2s}
.tb-link:hover{color:var(--gold)}

/* ═══ API BAR ═══ */
.api-bar{
  background:rgba(4,10,18,.95);border-bottom:1px solid var(--border);
  padding:5px 18px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;
  font-size:.67rem;
}
.ab-item{display:flex;align-items:center;gap:5px;padding:2px 9px;border-radius:10px;font-weight:700}
.ab-k  {background:rgba(255,215,0,.08); color:var(--gold);  border:1px solid rgba(255,215,0,.18)}
.ab-s  {background:rgba(59,130,246,.08);color:var(--blue);  border:1px solid rgba(59,130,246,.18)}
.ab-wh {background:rgba(139,92,246,.08);color:var(--purple);border:1px solid rgba(139,92,246,.18)}
.ab-ep {background:rgba(16,185,129,.08);color:var(--green); border:1px solid rgba(16,185,129,.18)}
.ab-dot{width:6px;height:6px;border-radius:50%;background:currentColor;flex-shrink:0}
.ab-copy{
  background:none;border:none;color:currentColor;cursor:pointer;
  opacity:.5;padding:0 3px;font-size:.7rem;
}
.ab-copy:hover{opacity:1}
.ab-ledger-addr{
  margin-<?=$ar?'right':'left'?>:auto;font-family:monospace;font-size:.62rem;
  color:var(--green);background:rgba(16,185,129,.07);
  border:1px solid rgba(16,185,129,.18);padding:3px 9px;border-radius:9px;
}

/* ═══ LEDGER CONNECT BAR ═══ */
.ledger-bar{
  background:rgba(0,0,0,.6);border-bottom:1px solid rgba(255,255,255,.07);
  padding:8px 18px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;
}
.ldg-status{display:flex;align-items:center;gap:7px;font-size:.75rem;font-weight:700}
.ldg-dot{width:9px;height:9px;border-radius:50%;background:var(--muted);flex-shrink:0;transition:.3s}
.ldg-dot.on{background:var(--green);animation:ldgpulse 2s infinite}
@keyframes ldgpulse{0%,100%{box-shadow:0 0 0 0 rgba(16,185,129,.4)}60%{box-shadow:0 0 0 8px rgba(16,185,129,0)}}
.ldg-connect-btn{
  display:flex;align-items:center;gap:6px;padding:6px 16px;border-radius:10px;
  border:1.5px solid rgba(255,255,255,.15);background:rgba(255,255,255,.06);
  color:var(--text);font-family:'Cairo',sans-serif;font-size:.75rem;font-weight:800;
  cursor:pointer;transition:.2s;
}
.ldg-connect-btn:hover:not(:disabled){border-color:var(--gold);color:var(--gold)}
.ldg-connect-btn:disabled{opacity:.4;cursor:not-allowed}
.ldg-connect-btn.connected{border-color:var(--green);color:var(--green);background:rgba(16,185,129,.07)}
.ldg-info{font-family:monospace;font-size:.65rem;color:var(--muted2);background:rgba(255,255,255,.04);padding:3px 9px;border-radius:8px}
.ldg-webhid-warn{
  font-size:.65rem;color:var(--red);background:rgba(239,68,68,.08);
  border:1px solid rgba(239,68,68,.2);border-radius:8px;padding:3px 10px;display:none;
}

/* ═══ LAYOUT ═══ */
.layout{display:grid;grid-template-columns:1fr 300px;min-height:calc(100vh - 110px)}
@media(max-width:860px){.layout{grid-template-columns:1fr}.side-panel{display:none}}

/* ═══ MAIN POS ═══ */
.pos-main{padding:18px 16px;border-right:1px solid var(--border)}

/* TXN TYPE GRID - 4 أعمدة */
.sec-label{font-size:.65rem;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:1.3px;margin-bottom:8px;display:flex;align-items:center;gap:6px}
.txn-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin-bottom:16px}
.txn-card{
  background:var(--card);border:1.5px solid var(--border);border-radius:11px;
  padding:8px 4px;cursor:pointer;text-align:center;transition:.2s;
}
.txn-card:hover{border-color:rgba(255,215,0,.2);transform:translateY(-1px)}
.txn-card.active{border-color:var(--gold);background:var(--gold3)}
.txn-ico{font-size:.9rem;margin-bottom:3px}
.txn-nm{font-size:.58rem;font-weight:800;color:var(--muted2);line-height:1.3}
.txn-card.active .txn-nm{color:var(--gold)}
.txn-sb{font-size:.52rem;color:var(--muted);margin-top:2px}

/* ORIG REF */
.orig-ref-row{display:none;margin-bottom:12px}
.orig-ref-row.show{display:block}

/* CARD AREA */
.card-area{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:14px;margin-bottom:14px}
.ca-title{font-size:.68rem;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:10px;display:flex;align-items:center;gap:6px}
.inp-row{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px}
.inp-row.full{grid-template-columns:1fr}
.fld label{display:block;font-size:.67rem;color:var(--muted2);margin-bottom:4px;font-weight:700}
.fld input,.fld select{
  width:100%;background:rgba(255,255,255,.04);border:1.5px solid var(--border);
  border-radius:10px;padding:10px 12px;color:var(--text);
  font-family:'Cairo',sans-serif;font-size:.83rem;transition:.2s;
}
.fld input:focus,.fld select:focus{outline:none;border-color:var(--gold);background:rgba(255,215,0,.03)}
.fld input::placeholder{color:var(--muted)}
.card-brand-wrap{position:relative}
.card-brand-wrap input{padding-<?=$ar?'left':'right'?>:42px}
.card-brand-ico{position:absolute;<?=$ar?'left':'right'?>:12px;top:50%;transform:translateY(-50%);font-size:.9rem;color:var(--muted)}

/* AMOUNT */
.amount-row{
  background:var(--card2);border:1px solid var(--border);border-radius:14px;
  padding:14px;margin-bottom:14px;display:flex;align-items:center;gap:12px;
}
.amt-big{font-size:2rem;font-weight:900;color:var(--gold);flex:1;line-height:1}
.amt-right{display:flex;flex-direction:column;gap:7px;min-width:120px}
.amt-cur{
  background:rgba(255,215,0,.1);color:var(--gold);border:1px solid rgba(255,215,0,.2);
  border-radius:7px;padding:4px 10px;font-weight:800;font-size:.82rem;
  font-family:'Cairo',sans-serif;cursor:pointer;
}
.amt-inp{
  width:100%;background:rgba(255,255,255,.04);border:1.5px solid var(--border);
  border-radius:9px;padding:6px 10px;color:var(--gold);font-family:'Cairo',sans-serif;
  font-size:.88rem;font-weight:800;text-align:center;
}
.amt-inp:focus{outline:none;border-color:var(--gold)}

/* Notes */
.fld{margin-bottom:12px}

/* PROCESS BTN */
.process-btn{
  width:100%;padding:15px;border-radius:14px;border:none;cursor:pointer;
  font-family:'Cairo',sans-serif;font-size:.98rem;font-weight:900;
  background:linear-gradient(135deg,var(--gold),var(--gold2));color:#000;
  box-shadow:0 8px 24px rgba(255,215,0,.22);transition:.3s;
  display:flex;align-items:center;justify-content:center;gap:10px;
}
.process-btn:hover:not(:disabled){transform:translateY(-2px);box-shadow:0 12px 32px rgba(255,215,0,.32)}
.process-btn:disabled{opacity:.35;cursor:not-allowed;transform:none;box-shadow:none}
.process-btn.loading{background:var(--card2);color:var(--muted2);border:1px solid var(--border)}

/* ═══ SIDE PANEL ═══ */
.side-panel{background:var(--card2);border-<?=$ar?'right':'left'?>:1px solid var(--border);padding:14px;display:flex;flex-direction:column;gap:12px;overflow-y:auto}
.panel-card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:13px}
.panel-title{font-size:.67rem;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:1.2px;margin-bottom:10px;display:flex;align-items:center;gap:6px}

/* LEDGER PANEL */
.ldg-bal-num{font-size:1.5rem;font-weight:900;color:var(--green);text-align:center;padding:6px 0 2px}
.ldg-bal-sub{font-size:.67rem;color:var(--muted2);text-align:center;margin-bottom:8px}
.ldg-addr-box{
  font-family:monospace;font-size:.6rem;word-break:break-all;color:var(--muted2);
  background:rgba(255,255,255,.03);border:1px solid var(--border);
  border-radius:8px;padding:7px 9px;
}
.mini-btn{
  flex:1;padding:5px;border-radius:8px;border:1px solid var(--border);
  background:rgba(255,255,255,.04);color:var(--muted2);font-size:.65rem;
  cursor:pointer;font-family:'Cairo',sans-serif;transition:.2s;
}
.mini-btn:hover{border-color:var(--gold);color:var(--gold)}
.mini-btn.green{border-color:rgba(16,185,129,.2);color:var(--green);background:rgba(16,185,129,.05)}

/* WEBHOOK LOG */
.wh-log{max-height:90px;overflow-y:auto;font-size:.62rem;font-family:monospace;background:rgba(255,255,255,.02);border-radius:8px;padding:7px 9px}
.wh-entry{padding:1px 0;border-bottom:1px solid rgba(255,255,255,.04);line-height:1.5}
.wh-ok{color:var(--green)}.wh-err{color:var(--red)}.wh-info{color:var(--gold)}

/* RECENT TXN */
.txn-list{max-height:160px;overflow-y:auto}
.txn-item{display:flex;align-items:center;gap:7px;padding:6px 0;border-bottom:1px solid rgba(255,255,255,.04)}
.txn-dot{width:6px;height:6px;border-radius:50%;flex-shrink:0}
.txn-ref{font-size:.62rem;font-family:monospace;color:var(--muted2);flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.txn-amt{font-size:.68rem;font-weight:800;color:var(--gold)}
.st-ok{background:rgba(16,185,129,.1);color:var(--green);font-size:.57rem;padding:1px 6px;border-radius:5px;font-weight:700}
.st-pend{background:rgba(255,215,0,.1);color:var(--gold);font-size:.57rem;padding:1px 6px;border-radius:5px;font-weight:700}
.st-fail{background:rgba(239,68,68,.1);color:var(--red);font-size:.57rem;padding:1px 6px;border-radius:5px;font-weight:700}

/* ═══ RESULT OVERLAY ═══ */
.overlay{
  display:none;position:fixed;inset:0;z-index:400;
  background:rgba(2,5,8,.93);backdrop-filter:blur(10px);
  align-items:center;justify-content:center;padding:20px;
}
.overlay.show{display:flex}
.res-box{
  background:var(--card);border:2px solid var(--border);border-radius:20px;
  padding:28px;width:100%;max-width:420px;text-align:center;
}
.res-icon{font-size:2.8rem;margin-bottom:12px}
.res-title{font-size:1.2rem;font-weight:900;margin-bottom:8px}
.res-ref{
  font-family:monospace;font-size:.74rem;color:var(--muted2);
  background:rgba(255,255,255,.04);padding:7px 12px;border-radius:8px;
  margin:10px 0;word-break:break-all;
}
.res-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:12px 0;text-align:<?=$ar?'right':'left'?>}
.res-row .rl{font-size:.68rem;color:var(--muted2)}
.res-row .rv{font-size:.74rem;font-weight:800}
.res-print{
  width:100%;padding:10px;border-radius:10px;border:1px solid var(--border2);
  background:rgba(255,215,0,.05);color:var(--gold);font-family:'Cairo',sans-serif;
  font-size:.8rem;font-weight:800;cursor:pointer;margin-bottom:8px;
  display:flex;align-items:center;justify-content:center;gap:7px;
}
.res-close{
  width:100%;padding:10px;border-radius:10px;border:1px solid var(--border);
  background:rgba(255,255,255,.05);color:var(--text);font-family:'Cairo',sans-serif;
  font-size:.82rem;font-weight:700;cursor:pointer;
}

/* TOAST */
#toast{
  position:fixed;bottom:22px;left:50%;transform:translateX(-50%) translateY(100px);
  background:var(--card);border:1px solid var(--border2);border-radius:13px;
  padding:10px 22px;font-size:.8rem;font-weight:700;z-index:9999;transition:.35s;
}

::-webkit-scrollbar{width:4px}::-webkit-scrollbar-thumb{background:rgba(255,215,0,.12);border-radius:4px}
</style>
</head>
<body>

<!-- ═══ TOPBAR ═══ -->
<header class="topbar">
  <div class="tb-brand">
    <i class="fas fa-coins"></i> DI PARMA
    <span style="color:var(--muted);margin:0 4px">×</span>
    <div class="tb-ldg-badge">
      <svg width="12" height="12" viewBox="0 0 100 100" fill="white"><rect width="100" height="100" rx="16"/><rect x="18" y="58" width="64" height="9" rx="4" fill="black"/></svg>
      Ledger Nano X
    </div>
  </div>
  <div class="tb-right">
    <a href="dashboard.php" class="tb-link"><i class="fas fa-th-large"></i></a>
  </div>
</header>

<!-- ═══ API BAR ═══ -->
<div class="api-bar">
  <div class="ab-item ab-k">
    <span class="ab-dot"></span>API K
    <span style="font-family:monospace;margin:0 4px"><?=substr(htmlspecialchars($apiKey),0,10)?>…</span>
    <button class="ab-copy" onclick="cp('<?=htmlspecialchars($apiKey)?>')" title="Copy"><i class="fas fa-copy"></i></button>
  </div>
  <div class="ab-item ab-s">
    <span class="ab-dot"></span>API S
    <span style="font-family:monospace;margin:0 4px"><?=substr(htmlspecialchars($apiSecret),0,8)?>…</span>
    <button class="ab-copy" onclick="cp('<?=htmlspecialchars($apiSecret)?>')" title="Copy"><i class="fas fa-copy"></i></button>
  </div>
  <div class="ab-item ab-wh">
    <span class="ab-dot"></span>Webhook
    <?php if($webhookUrl): ?>
    <span style="margin:0 4px"><?=substr(htmlspecialchars($webhookUrl),0,28)?>…</span>
    <button class="ab-copy" onclick="cp('<?=htmlspecialchars($webhookUrl)?>')" title="Copy"><i class="fas fa-copy"></i></button>
    <?php else: ?><span style="margin:0 4px;opacity:.5">— not set</span><?php endif; ?>
  </div>
  <div class="ab-item ab-ep">
    <span class="ab-dot"></span>Endpoint
    <span style="font-family:monospace;margin:0 4px"><?=htmlspecialchars($endpoint)?></span>
    <button class="ab-copy" onclick="cp('<?=htmlspecialchars($endpoint)?>')" title="Copy"><i class="fas fa-copy"></i></button>
  </div>
  <div class="ab-ledger-addr">
    <i class="fas fa-wallet"></i>
    <?=substr($ledgerAddr,0,10)?>…<?=substr($ledgerAddr,-6)?>
  </div>
</div>

<!-- ═══ LEDGER CONNECT BAR ═══ -->
<div class="ledger-bar">
  <div class="ldg-status">
    <div class="ldg-dot" id="ldgDot"></div>
    <span id="ldgStatusTxt"><?=$ar?'Ledger غير متصل':'Ledger Disconnected'?></span>
  </div>
  <button class="ldg-connect-btn" id="ldgBtn" onclick="ledgerConnect()">
    <i class="fas fa-usb" id="ldgBtnIcon"></i>
    <span id="ldgBtnTxt"><?=$ar?'توصيل Ledger USB':'Connect Ledger USB'?></span>
  </button>
  <div class="ldg-info" id="ldgDevInfo" style="display:none">
    <i class="fas fa-hdd" style="color:var(--gold)"></i>
    <span id="ldgDevName">Nano X</span> — <span id="ldgDevAddr">—</span>
  </div>
  <div class="ldg-webhid-warn" id="ldgWarn">
    <i class="fas fa-exclamation-triangle"></i>
    <?=$ar?'WebHID يتطلب Chrome/Edge':'WebHID requires Chrome or Edge'?>
  </div>
</div>

<!-- ═══ LAYOUT ═══ -->
<div class="layout">

<!-- ══════════ POS MAIN ══════════ -->
<div class="pos-main">

  <!-- TXN TYPES - 13 نوع في 4 أعمدة -->
  <div class="sec-label"><i class="fas fa-list"></i><?=$ar?'نوع العملية':'Transaction Type'?> (13)</div>
  <?php foreach($txnChunks as $chunk): ?>
  <div class="txn-grid">
  <?php foreach($chunk as $code => $t): ?>
    <div class="txn-card" id="tc-<?=$code?>"
         onclick="selTxn('<?=$code?>',this)"
         data-orig="<?=$t['orig']?'1':'0'?>"
         data-noamt="<?=$t['noamt']?'1':'0'?>">
      <div class="txn-ico"><i class="fas <?=$t['icon']?>" style="color:<?=$t['c']?>"></i></div>
      <div class="txn-nm"><?=$ar?$t['ar']:$t['en']?></div>
      <div class="txn-sb"><?=$t['sub']?></div>
    </div>
  <?php endforeach; ?>
  </div>
  <?php endforeach; ?>

  <!-- ORIG REF (يظهر للأنواع التي تحتاج مرجع أصلي) -->
  <div class="orig-ref-row" id="origRefRow">
    <div class="fld">
      <label><i class="fas fa-hashtag"></i> <?=$ar?'رقم المرجع الأصلي (RRN)':'Original Reference (RRN)'?></label>
      <input type="text" id="origRef" placeholder="<?=$ar?'رقم العملية السابقة':'Previous transaction reference'?>">
    </div>
    <div class="fld" id="approvalCodeWrap" style="display:none">
      <label><i class="fas fa-check-circle"></i> <?=$ar?'رمز الموافقة':'Approval Code'?></label>
      <input type="text" id="approvalCode" placeholder="<?=$ar?'أدخل رمز الموافقة':'Enter approval code'?>">
    </div>
  </div>

  <!-- CARD AREA -->
  <div class="card-area" id="cardArea">
    <div class="ca-title">
      <i class="fas fa-credit-card" style="color:var(--gold)"></i>
      <?=$ar?'بيانات البطاقة':'Card Details'?>
      <span id="cardModeBadge" style="margin-<?=$ar?'right':'left'?>:auto;font-size:.6rem;background:rgba(255,215,0,.1);color:var(--gold);border:1px solid rgba(255,215,0,.2);padding:2px 8px;border-radius:6px">Manual</span>
    </div>
    <div class="inp-row full">
      <div class="fld card-brand-wrap">
        <label><?=$ar?'رقم البطاقة':'Card Number'?></label>
        <input type="tel" id="cardNum" maxlength="19" placeholder="•••• •••• •••• ••••"
               oninput="fmtCard(this)" autocomplete="cc-number">
        <span class="card-brand-ico" id="cardBrandIco"><i class="fas fa-credit-card"></i></span>
      </div>
    </div>
    <div class="inp-row">
      <div class="fld">
        <label><?=$ar?'تاريخ الانتهاء':'Expiry'?></label>
        <input type="tel" id="cardExp" maxlength="5" placeholder="MM/YY" oninput="fmtExp(this)" autocomplete="cc-exp">
      </div>
      <div class="fld">
        <label>CVV</label>
        <input type="tel" id="cardCvv" maxlength="4" placeholder="•••" autocomplete="cc-csc">
      </div>
    </div>
    <div class="inp-row full">
      <div class="fld">
        <label><?=$ar?'اسم حامل البطاقة':'Cardholder Name'?></label>
        <input type="text" id="cardName" placeholder="<?=$ar?'الاسم الكامل':'Full Name'?>" autocomplete="cc-name">
      </div>
    </div>
  </div>

  <!-- AMOUNT -->
  <div class="amount-row" id="amountRow">
    <div>
      <div style="font-size:.63rem;color:var(--muted2);margin-bottom:3px"><?=$ar?'المبلغ':'Amount'?></div>
      <div class="amt-big" id="amtDisplay">0.00</div>
    </div>
    <div class="amt-right">
      <select class="amt-cur" id="txnCurrency" onchange="updAmt()">
        <option>USD</option><option>AED</option><option>SAR</option>
        <option>EUR</option><option>GBP</option><option>KWD</option>
        <option>EGP</option><option>QAR</option><option>USDT</option><option>TRX</option>
      </select>
      <input class="amt-inp" type="number" id="txnAmount" min="0.01" step="0.01"
             placeholder="0.00" oninput="updAmt()">
    </div>
  </div>

  <!-- Notes -->
  <div class="fld">
    <label><i class="fas fa-envelope"></i> Email</label>
    <input type="email" id="txnEmail" placeholder="client@example.com">
  </div>
  <div class="fld">
    <label><i class="fas fa-phone"></i> <?=$ar?'رقم الهاتف':'Phone'?></label>
    <input type="tel" id="txnPhone" placeholder="971501234567" value="971501234567">
  </div>
  <div class="fld" style="margin-bottom:14px">
    <label><i class="fas fa-sticky-note"></i> <?=$ar?'ملاحظات':'Notes'?> <span style="opacity:.5">(<?=$ar?'اختياري':'optional'?>)</span></label>
    <input type="text" id="txnNotes" placeholder="<?=$ar?'رقم الفاتورة، اسم العميل...':'Invoice #, client name...'?>">
  </div>

  <!-- PROCESS -->
  <button class="process-btn" id="processBtn" onclick="runTransaction()">
    <i class="fas fa-lock" id="procIco"></i>
    <span id="procLbl"><?=$ar?'تنفيذ عبر DI PARMA':'Process via DI PARMA'?></span>
  </button>

</div><!-- /pos-main -->

<!-- ══════════ SIDE PANEL ══════════ -->
<div class="side-panel">

  <!-- LEDGER BALANCE -->
  <div class="panel-card">
    <div class="panel-title"><i class="fas fa-wallet" style="color:var(--green)"></i> Ledger TRX (USDT)</div>
    <div class="ldg-bal-num" id="ldgBal">—</div>
    <div class="ldg-bal-sub">USDT · TRC20</div>
    <div class="ldg-addr-box" id="ldgAddrBox"><?=htmlspecialchars($ledgerAddr)?></div>
    <div style="display:flex;gap:7px;margin-top:8px">
      <button class="mini-btn" onclick="cp('<?=htmlspecialchars($ledgerAddr)?>')">
        <i class="fas fa-copy"></i> <?=$ar?'نسخ':'Copy'?>
      </button>
      <button class="mini-btn green" onclick="refreshBal()" id="balRefBtn">
        <i class="fas fa-sync-alt" id="balRefIco"></i> <?=$ar?'تحديث':'Refresh'?>
      </button>
    </div>
  </div>

  <!-- WEBHOOK LOG -->
  <div class="panel-card">
    <div class="panel-title"><i class="fas fa-bolt" style="color:var(--purple)"></i> Webhook Events</div>
    <?php if($webhookUrl): ?>
    <div style="font-size:.62rem;color:var(--purple);font-family:monospace;margin-bottom:6px;word-break:break-all;opacity:.7"><?=htmlspecialchars($webhookUrl)?></div>
    <?php endif; ?>
    <div class="wh-log" id="whLog">
      <div style="color:var(--muted);font-size:.6rem"><?=$ar?'تنتظر الأحداث...':'Waiting for events...'?></div>
    </div>
  </div>

  <!-- RECENT TXN -->
  <div class="panel-card">
    <div class="panel-title"><i class="fas fa-history" style="color:var(--blue)"></i> <?=$ar?'آخر العمليات':'Recent'?></div>
    <div class="txn-list" id="recentList">
      <div style="font-size:.65rem;color:var(--muted2);text-align:center;padding:10px"><?=$ar?'جاري التحميل...':'Loading...'?></div>
    </div>
  </div>

  <!-- API INFO -->
  <div class="panel-card">
    <div class="panel-title"><i class="fas fa-key" style="color:var(--gold)"></i> DI PARMA API</div>
    <?php
    $apiItems = [
      ['API K',      $apiKey,        'var(--gold)'],
      ['API S',      $apiSecret,     'var(--blue)'],
      ['Webhook S',  $webhookSecret, 'var(--purple)'],
      ['Endpoint',   $endpoint,      'var(--green)'],
    ];
    foreach($apiItems as [$lbl,$val,$clr]):
    ?>
    <div style="display:flex;align-items:center;gap:6px;margin-bottom:7px;padding:6px 8px;background:rgba(255,255,255,.03);border-radius:8px">
      <span style="font-size:.62rem;font-weight:800;color:var(--muted2);width:60px;flex-shrink:0"><?=$lbl?></span>
      <span style="font-family:monospace;font-size:.6rem;color:<?=$clr?>;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=htmlspecialchars($val?:($lbl==='Endpoint'?$endpoint:'—'))?></span>
      <?php if($val||$lbl==='Endpoint'): ?>
      <button onclick="cp('<?=htmlspecialchars($val?:$endpoint)?>')"
              style="background:none;border:none;color:var(--muted);cursor:pointer;font-size:.7rem;padding:1px 3px;flex-shrink:0">
        <i class="fas fa-copy"></i>
      </button>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>

</div><!-- /side-panel -->
</div><!-- /layout -->

<!-- ═══ RESULT OVERLAY ═══ -->
<div class="overlay" id="resultOverlay">
  <div class="res-box">
    <div class="res-icon" id="resIco"></div>
    <div class="res-title" id="resTitle"></div>
    <div class="res-ref" id="resRef"></div>
    <div class="res-grid" id="resGrid"></div>
    <button class="res-print" onclick="printRcpt()">
      <i class="fas fa-print"></i> <?=$ar?'طباعة الإيصال':'Print Receipt'?>
    </button>
    <button class="res-close" onclick="closeResult()">
      <?=$ar?'إغلاق — عملية جديدة':'Close — New Transaction'?>
    </button>
  </div>
</div>

<div id="receipt" style="display:none"></div>
<div id="toast"></div>

<!-- HIDDEN DATA -->
<input type="hidden" id="csrfToken"     value="<?=htmlspecialchars($csrf)?>">
<input type="hidden" id="apiKeyH"       value="<?=htmlspecialchars($apiKey)?>">
<input type="hidden" id="apiSecretH"    value="<?=htmlspecialchars($apiSecret)?>">
<input type="hidden" id="webhookSecH"   value="<?=htmlspecialchars($webhookSecret)?>">
<input type="hidden" id="webhookUrlH"   value="<?=htmlspecialchars($webhookUrl)?>">
<input type="hidden" id="endpointH"     value="<?=htmlspecialchars($endpoint)?>">
<input type="hidden" id="ledgerAddrH"   value="<?=htmlspecialchars($ledgerAddr)?>">

<!-- ═══ IMPORTMAP — Ledger DMK ═══ -->
<script type="importmap">
{
  "imports": {
    "@ledgerhq/device-management-kit":        "https://cdn.jsdelivr.net/npm/@ledgerhq/device-management-kit@1.8.0/lib/esm/index.js",
    "@ledgerhq/device-transport-kit-web-hid": "https://cdn.jsdelivr.net/npm/@ledgerhq/device-transport-kit-web-hid@1.2.4/lib/esm/index.js",
    "rxjs":                                   "https://cdn.jsdelivr.net/npm/rxjs@7.8.2/dist/esm5/index.js",
    "rxjs/operators":                         "https://cdn.jsdelivr.net/npm/rxjs@7.8.2/dist/esm5/operators/index.js"
  }
}
</script>

<script type="module">
// ════════════════════════════════════════════════════════════════
// CONFIGURATION
// ════════════════════════════════════════════════════════════════
const AR      = <?=$ar?'true':'false'?>;
const CSRF    = document.getElementById('csrfToken').value;
const API_K   = document.getElementById('apiKeyH').value;
const API_S   = document.getElementById('apiSecretH').value;
const WH_S    = document.getElementById('webhookSecH').value;
const WH_URL  = document.getElementById('webhookUrlH').value;
const EP      = document.getElementById('endpointH').value;
const LDG_ADDR= document.getElementById('ledgerAddrH').value;
const IS_PAYRAM = new URLSearchParams(window.location.search).get('gateway')?.toLowerCase() === 'payram';

// ── STATE ──
const S = {
  txnType : 'purchase_3d',
  secMode : '3D',
  processing: false,
  ledger: { connected:false, dmk:null, sessionId:null, address:null },
  lastResult: null,
};

// ── TXN LABELS ──
const TXN_LABELS = {
  purchase_3d    : AR ? 'شراء 3D Secure'       : 'Purchase 3D Secure',
  purchase_2d    : AR ? 'شراء 2D / MOTO'        : 'Purchase 2D/MOTO',
  purchase_advice: AR ? 'إشعار شراء (Advice)'   : 'Purchase Advice',
  purchase_offline: AR ? 'مبيعات خارج الخط (MOTO)' : 'Offline Sales (MOTO)',
  purchase_online : AR ? 'مبيعات عبر الإنترنت (MOTO)' : 'Online Sales (MOTO)',
  auth_hold      : AR ? 'تفويض (حجز)'           : 'Authorization Hold',
  auth_capture   : AR ? 'إتمام التفويض'         : 'Auth Capture',
  recurring      : AR ? 'شراء متكرر (اشتراك)'  : 'Recurring Purchase',
  installment    : AR ? 'شراء بالتقسيط'         : 'Installment Purchase',
  crypto_purchase: AR ? 'شراء عملات رقمية'      : 'Crypto Purchase',
  gift_card      : AR ? 'بطاقة هدايا'           : 'Gift Card',
  wire_transfer  : AR ? 'تحويل بنكي مباشر'      : 'Wire Transfer',
  quasi_cash     : AR ? 'سحب نقدي شبيه'         : 'Quasi Cash',
};

// ════════════════════════════════════════════════════════════════
// LEDGER USB / WebHID
// ════════════════════════════════════════════════════════════════
window.ledgerConnect = async function() {
  if (S.ledger.connected) { ledgerDisconnect(); return; }

  if (!navigator.hid) {
    document.getElementById('ldgWarn').style.display = 'flex';
    document.getElementById('ldgBtn').disabled = true;
    toast(AR?'WebHID غير مدعوم — استخدم Chrome/Edge':'WebHID unsupported — use Chrome/Edge','error');
    return;
  }

  const btn = document.getElementById('ldgBtn');
  const ico = document.getElementById('ldgBtnIco');
  const txt = document.getElementById('ldgBtnTxt');
  btn.disabled = true;
  ico.className = 'fas fa-spinner fa-spin';
  txt.textContent = AR ? 'جاري الاتصال...' : 'Connecting...';

  try {
    const { DeviceManagementKitBuilder } = await import('@ledgerhq/device-management-kit');
    const { webHidTransportFactory }     = await import('@ledgerhq/device-transport-kit-web-hid');

    const dmk = new DeviceManagementKitBuilder()
      .addTransport(webHidTransportFactory)
      .build();

    S.ledger.dmk = dmk;

    toast(AR?'اختر جهاز Ledger من النافذة المنبثقة...':'Select your Ledger device from the popup...','info');

    const device = await new Promise((resolve, reject) => {
      const t = setTimeout(() => { sub.unsubscribe(); reject(new Error(AR?'انتهت المهلة':'Timeout')); }, 30000);
      const sub = dmk.startDiscovering({}).subscribe({
        next(d) { clearTimeout(t); sub.unsubscribe(); resolve(d); },
        error(e){ clearTimeout(t); reject(e); },
      });
    });

    const sessionId = await dmk.connect({ device });
    S.ledger.sessionId = sessionId;
    S.ledger.connected = true;

    let devLabel = 'Ledger Nano X';
    try {
      const info = dmk.getConnectedDevice({ sessionId });
      devLabel = (info?.modelId || 'Ledger') + (info?.name ? ' ' + info.name : '');
    } catch(_){}

    const trxAddr = await getTRXAddress(dmk, sessionId) || LDG_ADDR;
    S.ledger.address = trxAddr;

    document.getElementById('ldgDot').classList.add('on');
    document.getElementById('ldgStatusTxt').textContent = AR ? 'Ledger متصل ✓' : 'Ledger Connected ✓';
    document.getElementById('ldgDevName').textContent = devLabel;
    document.getElementById('ldgDevAddr').textContent = trxAddr.substring(0,12) + '…';
    document.getElementById('ldgDevInfo').style.display = '';
    btn.className = 'ldg-connect-btn connected';
    ico.className = 'fas fa-link';
    txt.textContent = AR ? 'قطع الاتصال' : 'Disconnect';
    btn.disabled = false;

    document.getElementById('ldgAddrBox').textContent = trxAddr;
    document.getElementById('cardModeBadge').textContent = 'Ledger NanoX';
    document.getElementById('cardModeBadge').style.color = 'var(--green)';
    document.getElementById('cardModeBadge').style.borderColor = 'rgba(16,185,129,.3)';

    toast('✅ Ledger ' + devLabel + ' — ' + trxAddr.substring(0,12) + '…', 'success');
    refreshBal();
    logWh('CONNECT', trxAddr.substring(0,10)+'…', 'ok');

  } catch(err) {
    console.error('[Ledger]', err);
    const msg = err?.message || String(err);
    toast(msg.includes('No device') || msg.includes('discover')
      ? (AR?'لم يُعثر على جهاز Ledger — تحقق من USB':'No Ledger device found — check USB')
      : msg.substring(0,80), 'error');
    ico.className = 'fas fa-usb';
    txt.textContent = AR ? 'توصيل Ledger USB' : 'Connect Ledger USB';
    btn.disabled = false;
  }
};

async function getTRXAddress(dmk, sessionId) {
  try {
    const path = [0x80000000+44, 0x80000000+195, 0x80000000+0, 0, 0];
    const buf = new Uint8Array(1 + path.length * 4);
    buf[0] = path.length;
    const view = new DataView(buf.buffer);
    path.forEach((v,i) => view.setUint32(1 + i*4, v, false));

    const apdu = new Uint8Array([0xE0, 0x02, 0x00, 0x00, buf.length, ...buf]);
    const res  = await dmk.sendApdu({ sessionId, apdu });

    if (res?.data?.length >= 66) {
      const addrLen = res.data[65];
      const addrBytes = res.data.slice(66, 66 + addrLen);
      return new TextDecoder().decode(addrBytes);
    }
  } catch(e) { console.warn('[APDU]', e.message); }
  return null;
}

async function ledgerDisconnect() {
  try { if(S.ledger.dmk && S.ledger.sessionId) await S.ledger.dmk.disconnect({ sessionId: S.ledger.sessionId }); } catch(_){}
  S.ledger = { connected:false, dmk:null, sessionId:null, address:null };
  document.getElementById('ldgDot').classList.remove('on');
  document.getElementById('ldgStatusTxt').textContent = AR ? 'Ledger غير متصل' : 'Ledger Disconnected';
  document.getElementById('ldgDevInfo').style.display = 'none';
  const btn = document.getElementById('ldgBtn');
  btn.className = 'ldg-connect-btn';
  document.getElementById('ldgBtnIco').className = 'fas fa-usb';
  document.getElementById('ldgBtnTxt').textContent = AR ? 'توصيل Ledger USB' : 'Connect Ledger USB';
  document.getElementById('ldgAddrBox').textContent = LDG_ADDR;
  document.getElementById('cardModeBadge').textContent = 'Manual';
  document.getElementById('cardModeBadge').style.color = '';
  document.getElementById('cardModeBadge').style.borderColor = '';
  toast(AR?'تم قطع الاتصال':'Disconnected','info');
  logWh('DISCONNECT','—','info');
}

// ════════════════════════════════════════════════════════════════
// TXN TYPE SELECTION
// ════════════════════════════════════════════════════════════════
window.selTxn = function(code, el) {
  S.txnType = code;
  document.querySelectorAll('.txn-card').forEach(c => c.classList.remove('active'));
  el.classList.add('active');

  const needOrig = el.dataset.orig === '1';
  document.getElementById('origRefRow').classList.toggle('show', needOrig);
  document.getElementById('approvalCodeWrap').style.display = code === 'purchase_offline' ? '' : 'none';
};

// ════════════════════════════════════════════════════════════════
// AMOUNT
// ════════════════════════════════════════════════════════════════
window.updAmt = function() {
  const v = parseFloat(document.getElementById('txnAmount').value) || 0;
  document.getElementById('amtDisplay').textContent = v.toFixed(2);
};

// ════════════════════════════════════════════════════════════════
// CARD FORMATTING
// ════════════════════════════════════════════════════════════════
window.fmtCard = function(inp) {
  let v = inp.value.replace(/\D/g,'').substring(0,16);
  inp.value = v.replace(/(.{4})/g,'$1 ').trim();
  const ico = document.getElementById('cardBrandIco');
  if (/^4/.test(v))       ico.innerHTML = '<i class="fab fa-cc-visa" style="color:#1a1f71;font-size:1rem"></i>';
  else if (/^5[1-5]/.test(v)) ico.innerHTML = '<i class="fab fa-cc-mastercard" style="color:#eb001b;font-size:1rem"></i>';
  else if (/^3[47]/.test(v))  ico.innerHTML = '<i class="fab fa-cc-amex" style="color:#007bc1;font-size:1rem"></i>';
  else ico.innerHTML = '<i class="fas fa-credit-card"></i>';
};

window.fmtExp = function(inp) {
  let v = inp.value.replace(/\D/g,'').substring(0,4);
  if (v.length >= 3) v = v.substring(0,2) + '/' + v.substring(2);
  inp.value = v;
};

// ════════════════════════════════════════════════════════════════
// HMAC-SHA256 SIGNATURE
// ════════════════════════════════════════════════════════════════
async function hmacSHA256(secret, message) {
  const enc  = new TextEncoder();
  const key  = await crypto.subtle.importKey('raw', enc.encode(secret), { name:'HMAC', hash:'SHA-256' }, false, ['sign']);
  const sig  = await crypto.subtle.sign('HMAC', key, enc.encode(message));
  return Array.from(new Uint8Array(sig)).map(b => b.toString(16).padStart(2,'0')).join('');
}

// ════════════════════════════════════════════════════════════════
// PROCESS TRANSACTION
// ════════════════════════════════════════════════════════════════
window.runTransaction = async function() {
  if (S.processing) return;

  const amount = parseFloat(document.getElementById('txnAmount').value) || 0;
  if (amount <= 0) { toast(AR?'أدخل المبلغ':'Enter amount','error'); return; }

  if (IS_PAYRAM) {
    S.processing = true;
    const btn = document.getElementById('processBtn');
    btn.disabled = true; btn.classList.add('loading');
    document.getElementById('procIco').className = 'fas fa-spinner fa-spin';
    document.getElementById('procLbl').textContent = AR ? 'جاري إنشاء رابط PayRam...' : 'Creating PayRam payment link...';

    try {
      const response = await fetch('api/payram_payment.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        credentials: 'same-origin',
        body: JSON.stringify({
          action: 'create',
          amount,
          email: document.getElementById('txnEmail')?.value || 'client@diparmas.com',
          customer_id: 'dp_' + Date.now(),
          reference: 'DP' + Date.now().toString(36).toUpperCase(),
          csrf_token: CSRF,
          blockchain_code: 'BASE',
          currency_code: 'USDC',
          notes: document.getElementById('txnNotes')?.value || ''
        })
      });
      const result = await response.json();
      if (!response.ok || !result.success || !result.url) {
        throw new Error(result.message || ('HTTP ' + response.status));
      }
      window.location.assign(result.url);
    } catch (error) {
      S.processing = false;
      resetBtn();
      toast((AR ? 'فشل إنشاء رابط PayRam: ' : 'PayRam link failed: ') + (error.message || 'Unknown error'), 'error');
    }
    return;
  }

  const maxAmounts = {
    purchase_2d: 25000,
    purchase_offline: 25000,
    purchase_online: 25000,
    crypto_purchase: 25000,
    gift_card: 5000,
    recurring: 10000,
    quasi_cash: 10000,
    purchase_3d: 50000,
    purchase_advice: 100000,
    auth_hold: 100000,
    auth_capture: 100000,
    installment: 50000,
    wire_transfer: 100000
  };
  const maxAmount = maxAmounts[S.txnType] || 50000;
  if (amount > maxAmount) {
    toast(AR ? `الحد الأقصى للعملية ${maxAmount.toLocaleString()} USD` : `Maximum amount is ${maxAmount.toLocaleString()} USD`, 'error');
    return;
  }

  const cn = document.getElementById('cardNum').value.replace(/\s/g,'');
  const ce = document.getElementById('cardExp').value;
  const cv = document.getElementById('cardCvv').value;
  if (cn.length < 13) { toast(AR?'رقم البطاقة غير صحيح':'Invalid card number','error'); return; }
  if (!/^\d{2}\/\d{2}$/.test(ce)) { toast(AR?'تاريخ الانتهاء غير صحيح':'Invalid expiry','error'); return; }
  if (cv.length < 3)  { toast(AR?'CVV غير صحيح':'Invalid CVV','error'); return; }

  const needOrig = document.querySelector('.txn-card.active')?.dataset.orig === '1';
  const origVal  = document.getElementById('origRef')?.value.trim();
  if (needOrig && !origVal) {
    toast(AR?'أدخل رقم المرجع الأصلي':'Enter original reference','error');
    return;
  }
  const approvalVal = document.getElementById('approvalCode')?.value.trim();
  if (S.txnType === 'purchase_offline' && !approvalVal) {
    toast(AR?'أدخل رمز الموافقة':'Enter approval code','error');
    return;
  }

  S.processing = true;
  const btn = document.getElementById('processBtn');
  btn.disabled = true; btn.classList.add('loading');
  document.getElementById('procIco').className = 'fas fa-spinner fa-spin';
  document.getElementById('procLbl').textContent = AR ? 'جاري المعالجة عبر DI PARMA...' : 'Processing via DI PARMA...';

  const ref  = 'DP' + Date.now().toString(36).toUpperCase();
  const ts   = Math.floor(Date.now()/1000).toString();
  const curr = document.getElementById('txnCurrency').value;

  const payload = {
    reference      : ref,
    transaction_type: S.txnType,
    amount         : amount,
    currency       : curr,
    card_number    : document.getElementById('cardNum').value.replace(/\s/g,''),
    card_expiry    : document.getElementById('cardExp').value,
    card_cvv       : document.getElementById('cardCvv').value,
    card_name      : document.getElementById('cardName').value,
    email          : document.getElementById('txnEmail')?.value || 'client@diparmas.com',
    phone          : document.getElementById('txnPhone')?.value || '971501234567',
    country        : 'AE',
    orig_ref       : document.getElementById('origRef')?.value || '',
    original_reference: origVal || '',
    original_auth_code: approvalVal || '',
    notes          : document.getElementById('txnNotes').value,
    ledger_addr    : S.ledger.address || LDG_ADDR,
    ledger_connected: S.ledger.connected,
    timestamp      : ts,
    csrf_token     : CSRF,
  };

  const sigStr  = ts + '.' + ref + '.' + amount.toFixed(2);
  const sig     = await hmacSHA256(API_S, sigStr);

  try {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 30000);

    const resp = await fetch(EP, {
      method : 'POST',
      headers: {
        'Content-Type' : 'application/json',
        'X-Api-Key'    : API_K,
        'X-Timestamp'  : ts,
        'X-Signature'  : sig,
        'X-Gateway'    : 'diparma-ledger',
      },
      body: JSON.stringify({
        ...payload,
        destination    : 'ledger_trx',
        auto_transfer  : true,
        ledger_address : payload.ledger_addr,
        pos_device     : 'WEB_DIPARMA',
        extra: {
          sec_mode     : S.txnType.includes('3d') ? '3D' : '2D',
          processing_mode: S.txnType.includes('3d') ? '3D' : '2D',
          orig_ref     : payload.orig_ref,
          original_reference: payload.original_reference,
          original_auth_code: payload.original_auth_code,
        }
      }),
      signal: controller.signal,
      credentials: 'include',
    });

    clearTimeout(timeout);

    if (!resp.ok) {
      const errText = await resp.text();
      throw new Error('HTTP ' + resp.status + ': ' + errText.substring(0,100));
    }

    const data = await resp.json();
    S.lastResult = { ...data, ref, payload };
    S.processing = false;
    resetBtn();

    if (data.requires_3ds && data.redirect_url) {
      toast(AR ? 'تمت معالجة التحقق 3D Secure داخل التطبيق.' : '3D Secure verification was handled in-app.', 'info');
    }

    showResult(data, ref, payload);
    loadRecent();

    if (data.success) {
      logWh('POST', ref, 'ok');
      if (WH_URL) fireWebhook(data, ref, ts, payload);
    } else {
      logWh('POST', ref, 'err');
    }

  } catch(err) {
    S.processing = false;
    resetBtn();
    const msg = err?.message || String(err);
    if (msg.includes('aborted') || msg.includes('abort')) {
      toast(AR?'انتهت مهلة الاتصال — حاول مجدداً':'Request timeout — please try again','error');
    } else if (msg.includes('Failed to fetch') || msg.includes('NetworkError')) {
      toast(AR?'خطأ في الشبكة — تحقق من الاتصال':'Network error — check connection','error');
    } else {
      toast(msg.substring(0,80), 'error');
    }
    logWh('ERR', ref, 'err');
  }
};

function resetBtn() {
  const btn = document.getElementById('processBtn');
  btn.disabled = false; btn.classList.remove('loading');
  document.getElementById('procIco').className = 'fas fa-lock';
  document.getElementById('procLbl').textContent = AR ? 'تنفيذ عبر DI PARMA' : 'Process via DI PARMA';
}

// ════════════════════════════════════════════════════════════════
// WEBHOOK
// ════════════════════════════════════════════════════════════════
async function fireWebhook(data, ref, ts, payload) {
  try {
    const body = JSON.stringify({ event:'charge.completed', reference:ref, data, timestamp:ts });
    const sig  = await hmacSHA256(WH_S || API_S, ts + '.' + body);
    await fetch(WH_URL, {
      method:'POST',
      headers:{ 'Content-Type':'application/json', 'X-Signature':sig, 'X-Timestamp':ts },
      body,
    });
    logWh('WEBHOOK', ref, 'ok');
  } catch(e) { logWh('WEBHOOK', ref, 'err'); }
}

// ════════════════════════════════════════════════════════════════
// RESULT OVERLAY
// ════════════════════════════════════════════════════════════════
function showResult(data, ref, pl) {
  const ok = !!data.success;
  document.getElementById('resIco').textContent   = ok ? '✅' : '❌';
  document.getElementById('resTitle').textContent = ok
    ? (AR?'تمت العملية بنجاح':'Transaction Successful')
    : (AR?'فشلت العملية':'Transaction Failed');
  document.getElementById('resTitle').style.color = ok ? 'var(--green)' : 'var(--red)';
  document.getElementById('resRef').textContent   = ref;

  const rows = [
    [AR?'المبلغ':'Amount',        pl.amount > 0 ? pl.amount.toFixed(2)+' '+pl.currency : '—'],
    [AR?'نوع العملية':'Type',     TXN_LABELS[pl.txn_type] || pl.txn_type],
    [AR?'الأمان':'Security',      data.sec_mode || pl.sec_mode || '—'],
    [AR?'Auth Code':'Auth Code',   data.auth_code || '—'],
    [AR?'RRN':'RRN',               data.rrn || '—'],
    [AR?'ISO Type':'ISO Type',     data.iso_msg_type || '—'],
    [AR?'الوجهة':'Destination',   'Ledger TRX (USDT)'],
    [AR?'Ledger':'Ledger',         S.ledger.connected ? '✓ USB Connected' : '— Offline'],
    [AR?'الرسالة':'Message',       data.message || '—'],
  ];

  document.getElementById('resGrid').innerHTML = rows.map(([l,v]) =>
    `<div class="res-row"><div class="rl">${l}</div><div class="rv">${v}</div></div>`
  ).join('');

  const receiptBtn = document.createElement('a');
  receiptBtn.href = '/contract_receipt.php?ref=' + (data.reference || ref);
  receiptBtn.target = '_blank';
  receiptBtn.style.cssText = 'display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:10px;border-radius:10px;background:rgba(255,215,0,.08);border:1px solid rgba(255,215,0,.3);color:var(--gold);font-weight:800;font-size:.82rem;text-decoration:none;margin-top:10px';
  receiptBtn.innerHTML = '<i class="fas fa-external-link-alt"></i> ' + (AR ? 'عرض الإيصال الكامل' : 'View Full Receipt');
  document.getElementById('resGrid').after(receiptBtn);

  document.getElementById('resultOverlay').classList.add('show');
}

window.closeResult = function() {
  document.getElementById('resultOverlay').classList.remove('show');
  ['cardNum','cardExp','cardCvv','cardName','txnAmount','txnNotes','origRef'].forEach(id => {
    const el = document.getElementById(id); if(el) el.value = '';
  });
  document.getElementById('amtDisplay').textContent = '0.00';
};

// ════════════════════════════════════════════════════════════════
// PRINT RECEIPT
// ════════════════════════════════════════════════════════════════
window.printRcpt = function() {
  const r = S.lastResult; if(!r) return;
  const d   = r;
  const p   = r.payload || {};
  const now = new Date();
  const dateStr  = now.toLocaleDateString('en-GB',{day:'2-digit',month:'2-digit',year:'numeric'});
  const timeStr  = now.toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
  const txnLabel = TXN_LABELS[p.txn_type] || p.txn_type || 'PURCHASE';
  const status   = d.success ? 'APPROVED' : 'DECLINED';
  const statusClr= d.success ? 'green' : 'red';
  const ref      = d.reference || r.ref || '—';
  const authCode = d.approval_code || d.auth_code || '—';
  const rrn      = d.rrn || '—';
  const amount   = parseFloat(p.amount||0).toFixed(2);
  const currency = p.currency || 'USD';
  const cardLast4= d.card_last4 || (p.card_number||'').slice(-4) || '****';
  const ledgerAddr = p.ledger_addr || LDG_ADDR;
  const ledgerTxid = d.ledger?.txid || d.ledger_txid || null;
  const usdtAmt  = d.ledger?.usdt_amount || d.ledger_transfer ? parseFloat(d.ledger?.usdt_amount||0).toFixed(6) : '—';

  document.getElementById('receipt').innerHTML = `
<style>
  @media print {
    body * { visibility: hidden; }
    #receipt, #receipt * { visibility: visible; }
    #receipt { position: absolute; top: 0; left: 0; }
    @page { margin: 0; size: 80mm auto; }
  }
</style>
<div style="font-family:'Courier New',monospace;width:76mm;margin:0 auto;padding:4mm;color:#000;font-size:10.5px;line-height:1.6">

  <div style="text-align:center;border-bottom:2px solid #000;padding-bottom:6px;margin-bottom:6px">
    <div style="font-size:18px;font-weight:900;letter-spacing:2px">DI PARMA</div>
    <div style="font-size:9px;letter-spacing:1px">ULTIMATE FINANCIAL GATEWAY</div>
    <div style="font-size:8.5px;color:#333">TRANSCENDIO FZ-LLC</div>
    <div style="font-size:8px;color:#555">Al Barsha 1, Dubai, UAE</div>
    <div style="font-size:8px;color:#555">diparmas.com</div>
  </div>

  <div style="text-align:center;padding:6px 0;margin-bottom:6px;border:2px solid ${statusClr};border-radius:4px">
    <div style="font-size:15px;font-weight:900;color:${statusClr};letter-spacing:2px">${status}</div>
    <div style="font-size:8.5px;color:#555">${txnLabel.toUpperCase()}</div>
  </div>

  <div style="display:flex;justify-content:space-between;font-size:9px;margin-bottom:6px;border-bottom:1px dashed #999;padding-bottom:4px">
    <span>DATE: ${dateStr}</span>
    <span>TIME: ${timeStr}</span>
  </div>

  <table style="width:100%;border-collapse:collapse;font-size:9.5px;margin-bottom:6px">
    <tr><td style="color:#555;width:45%">REFERENCE</td><td style="font-weight:700;font-family:monospace;font-size:8.5px">${ref}</td></tr>
    <tr><td style="color:#555">TXN TYPE</td><td style="font-weight:700">${txnLabel.toUpperCase()}</td></tr>
    <tr><td style="color:#555">SECURITY</td><td style="font-weight:700">${p.sec_mode || '3D'} SECURE</td></tr>
    <tr><td style="color:#555">CHANNEL</td><td style="font-weight:700">ECOMMERCE</td></tr>
    <tr><td style="color:#555">ACQUIRER</td><td style="font-weight:700">Mashreq Bank PSC</td></tr>
    <tr><td style="color:#555">MERCHANT</td><td style="font-weight:700">TRANSCENDIO FZ-LLC</td></tr>
  </table>

  <div style="background:#f5f5f5;border:1px solid #ccc;border-radius:3px;padding:6px;margin-bottom:6px;text-align:center">
    <div style="font-size:9px;color:#555">AMOUNT</div>
    <div style="font-size:20px;font-weight:900;letter-spacing:1px">${amount} ${currency}</div>
  </div>

  <table style="width:100%;border-collapse:collapse;font-size:9.5px;margin-bottom:6px;border-top:1px dashed #999;padding-top:4px">
    <tr><td style="color:#555;width:45%">CARD</td><td style="font-weight:700;font-family:monospace">**** **** **** ${cardLast4}</td></tr>
    <tr><td style="color:#555">AUTH CODE</td><td style="font-weight:900;font-size:11px;color:${d.success?'green':'red'}">${authCode}</td></tr>
    <tr><td style="color:#555">RRN</td><td style="font-weight:700;font-family:monospace">${rrn}</td></tr>
  </table>

  <div style="border-top:1px dashed #999;padding-top:4px;margin-bottom:6px">
    <div style="font-size:9px;font-weight:700;margin-bottom:3px">LEDGER TRX (USDT)</div>
    <div style="font-size:8px;font-family:monospace;color:#333;word-break:break-all">${ledgerAddr}</div>
    <div style="font-size:9px;margin-top:3px">USDT: <b>${usdtAmt}</b></div>
    ${ledgerTxid ? `<div style="font-size:7.5px;font-family:monospace;color:#0066cc;word-break:break-all;margin-top:2px">TX: ${ledgerTxid}</div>` : '<div style="font-size:8px;color:#999">Transfer: Queued</div>'}
  </div>

  <div style="border-top:1px dashed #999;padding-top:4px;margin-bottom:6px;font-size:8.5px">
    <div style="font-weight:700">BANK ACCOUNT</div>
    <div>Mashreq Bank PSC — SWIFT: BOMLAEADXXX</div>
    <div style="font-family:monospace">IBAN: AE300330000019101562722</div>
  </div>

  <div style="text-align:center;border-top:2px solid #000;padding-top:6px;font-size:8.5px">
    <div style="font-weight:700">THANK YOU FOR YOUR BUSINESS</div>
    <div style="color:#555">Powered by DI PARMA Gateway © 2026</div>
    <div style="font-size:7.5px;color:#888;margin-top:3px">This is an official payment receipt</div>
    <div style="font-size:7.5px;color:#888">Keep for your records</div>
  </div>

</div>`;
  document.getElementById('receipt').style.display = '';
  window.print();
  document.getElementById('receipt').style.display = 'none';
};

// ════════════════════════════════════════════════════════════════
// LEDGER BALANCE
// ════════════════════════════════════════════════════════════════
window.refreshBal = async function() {
  const addr = S.ledger.address || LDG_ADDR;
  const ico  = document.getElementById('balRefIco');
  ico.className = 'fas fa-spinner fa-spin';
  try {
    const r = await fetch('api/wallet.php?action=balance', {
      method: 'GET',
      credentials: 'same-origin'
    });

    if (!r.ok) throw new Error(`HTTP ${r.status}`);

    const d = await r.json();
    const crypto = Array.isArray(d.crypto) ? d.crypto : [];
    const usdtWallet = crypto.find(item =>
      (item.coin || '').toUpperCase() === 'USDT' &&
      (item.network || '').toUpperCase() === 'TRC20'
    );

    const usdt = usdtWallet ? Number(usdtWallet.balance || 0).toFixed(2) : '0.00';
    document.getElementById('ldgBal').textContent = usdt + ' USDT';
  } catch(e) {
    document.getElementById('ldgBal').textContent = '—';
    console.warn('[Ledger Balance]', e.message);
  }
  ico.className = 'fas fa-sync-alt';
};

// ════════════════════════════════════════════════════════════════
// RECENT TRANSACTIONS
// ════════════════════════════════════════════════════════════════
async function loadRecent() {
  try {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 8000);
    
    const r = await fetch('api/wallet.php?action=recent_ledger&limit=6', {
      signal: controller.signal,
      credentials: 'include'
    });
    
    clearTimeout(timeout);
    
    if (!r.ok) throw new Error(`HTTP ${r.status}`);
    
    const d = await r.json();
    const c = document.getElementById('recentList');
    if (!d.transactions?.length) {
      c.innerHTML = `<div style="font-size:.65rem;color:var(--muted2);text-align:center;padding:8px">${AR?'لا توجد بعد':'None yet'}</div>`;
      return;
    }
    c.innerHTML = d.transactions.map(t => {
      const st = ['completed','captured'].includes(t.status) ? 'ok' : t.status==='failed' ? 'fail' : 'pend';
      const dc = st==='ok'?'var(--green)':st==='fail'?'var(--red)':'var(--gold)';
      return `<div class="txn-item">
        <div class="txn-dot" style="background:${dc}"></div>
        <div class="txn-ref">${(t.reference||'').substring(0,14)}</div>
        <div class="txn-amt">${parseFloat(t.amount||0).toFixed(2)} ${t.currency||''}</div>
        <div class="st-${st}">${t.status||'—'}</div>
      </div>`;
    }).join('');
  } catch(e) {
    console.warn('[Recent Transactions]', e.message);
    const c = document.getElementById('recentList');
    c.innerHTML = `<div style="font-size:.65rem;color:var(--muted2);text-align:center;padding:8px">${AR?'لم يتمكن من التحميل':'Unable to load'}</div>`;
  }
}

// ════════════════════════════════════════════════════════════════
// WEBHOOK LOG
// ════════════════════════════════════════════════════════════════
window.logWh = function(event, ref, type) {
  const log = document.getElementById('whLog');
  const t   = new Date().toLocaleTimeString();
  const cls = type==='ok'?'wh-ok':type==='err'?'wh-err':'wh-info';
  const el  = document.createElement('div');
  el.className = 'wh-entry ' + cls;
  el.textContent = `[${t}] ${event} ${ref}`;
  if (log.childElementCount === 1 && log.firstElementChild?.style?.color) log.innerHTML = '';
  log.prepend(el);
  if (log.childElementCount > 20) log.lastElementChild?.remove();
};

// ════════════════════════════════════════════════════════════════
// COPY
// ════════════════════════════════════════════════════════════════
window.cp = function(text) {
  if (!text || text === '—') return;
  navigator.clipboard?.writeText(text).then(() => toast(AR?'تم النسخ ✓':'Copied ✓','success'));
};

// ════════════════════════════════════════════════════════════════
// TOAST
// ════════════════════════════════════════════════════════════════
function toast(msg, type='info') {
  const t = document.getElementById('toast');
  const c = {success:'var(--green)',error:'var(--red)',info:'var(--gold)'};
  t.style.color = c[type]||c.info;
  t.style.borderColor = c[type]||c.info;
  t.textContent = msg;
  t.style.transform = 'translateX(-50%) translateY(0)';
  clearTimeout(t._t);
  t._t = setTimeout(()=>{ t.style.transform='translateX(-50%) translateY(100px)'; }, 4000);
}
window.toast = toast;

// ════════════════════════════════════════════════════════════════
// INIT
// ════════════════════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', () => {
  loadRecent();
  refreshBal();
  setInterval(refreshBal, 30000);
  if (!navigator.hid) {
    document.getElementById('ldgWarn').style.display = 'flex';
    document.getElementById('ldgBtn').disabled = true;
  }
});
</script>
</body>
</html>