<?php
/**
 * DI PARMA | Crypto Exchange — شراء وبيع USDT
 */
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/gateways.php';
require_once __DIR__ . '/lib/ExchangeRateService.php';
require_once __DIR__ . '/lib/WalletService.php';
require_once __DIR__ . '/lib/HotWalletService.php';
require_once __DIR__ . '/lib/CryptoGateway.php';
require_once __DIR__ . '/includes/crypto_schema.php';

dp_create_crypto_tables();

$currentLang = (isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'en') ? 'en' : 'ar';
$pageDir     = $currentLang === 'en' ? 'ltr' : 'rtl';
$userId      = intval($_SESSION['user_id'] ?? 0);
$db          = db();

// ── جلب البوابات النشطة ──────────────────────────────────
$activeGateways = $db->query(
    "SELECT code, name FROM dp_payment_gateways
     WHERE status = 'active' AND code NOT IN ('integrated','crypto_deposit')
     ORDER BY name ASC"
);

// ── جلب سعر حي مبدئي ────────────────────────────────────
$initialRate = null;
try {
    $fxService   = ExchangeRateService::getInstance();
    $initialRate = $fxService->getRate('USDT', 'AED');
} catch (Exception $e) {}

$userWallet = null;
$userWallets = [];
$financialWallets = [];
if ($userId > 0) {
    $walletService = WalletService::getInstance();
    try {
        $userWallet = $walletService->getOrCreate($userId, 'TRC20', 'USDT');
    } catch (Exception $e) {
        $userWallet = $db->find('user_wallets', ['user_id' => $userId, 'network' => 'TRC20', 'coin' => 'USDT']);
    }

    // إنشاء المحفظة المالية إذا لم تكن موجودة
    try {
        $walletService->getOrCreateFinancialWallet($userId, 'AED');
    } catch (Exception $e) {
        // تجاهل خطأ الإنشاء المؤقت
    }

    $userWallets = $db->query(
        "SELECT network, coin, address, status, created_at FROM " . DB_PREFIX . "user_wallets WHERE user_id = ? ORDER BY network ASC",
        [$userId]
    ) ?: [];

    $financialWallets = $db->query(
        "SELECT currency, balance, status, created_at FROM " . DB_PREFIX . "wallets WHERE user_id = ? ORDER BY currency ASC",
        [$userId]
    ) ?: [];
}

$csrfToken  = generateCsrfToken();
$hotAddress = getenv('HOT_WALLET_TRC20_ADDRESS') ?: '';
?>
<!DOCTYPE html>
<html lang="<?= $currentLang ?>" dir="<?= $pageDir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>DI PARMA | <?= __('crypto_exchange') ?></title>
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ── Crypto Page Styles ── */
.crypto-hero {
    background: linear-gradient(135deg, #0A0F1E 0%, #0d1a2e 50%, #0A0F1E 100%);
    border-bottom: 1px solid var(--border-gold);
    padding: 28px 0 0;
}
.tab-switcher { display:flex; gap:8px; background:rgba(255,255,255,.04);
    border:1px solid var(--border-gold); border-radius:14px; padding:5px; width:fit-content; }
.tab-btn { padding:10px 32px; border-radius:10px; border:none; cursor:pointer;
    font-size:.95rem; font-weight:600; transition:all .25s; color:var(--text-muted); background:transparent; }
.tab-btn.active { background:var(--gold-gradient); color:#000; box-shadow:var(--shadow-gold); }
.rate-ticker { display:flex; align-items:center; gap:18px; flex-wrap:wrap; }
.rate-chip { display:flex; align-items:center; gap:8px; background:rgba(255,215,0,.07);
    border:1px solid var(--border-gold); border-radius:10px; padding:8px 16px; }
.rate-chip .coin-icon { width:28px; height:28px; border-radius:50%; display:flex;
    align-items:center; justify-content:center; font-size:.75rem; font-weight:700; }
.rate-chip .rate-val { font-size:1.05rem; font-weight:700; color:var(--gold); }
.rate-chip .rate-lbl { font-size:.75rem; color:var(--text-muted); }
.rate-chip .rate-chg.up { color:#4CAF50; } .rate-chip .rate-chg.dn { color:#ef5350; }

.calc-card { background:var(--bg-card); border:1px solid var(--border-gold);
    border-radius:var(--border-radius-xl); padding:32px; box-shadow:var(--shadow-gold); }
.amount-input-wrap { position:relative; }
.amount-input-wrap input { width:100%; padding:16px 20px 16px 70px; font-size:1.4rem;
    font-weight:700; background:rgba(255,255,255,.04); border:1.5px solid var(--border-gold);
    border-radius:12px; color:var(--text-light); outline:none; transition:border-color .2s; }
.amount-input-wrap input:focus { border-color:var(--gold); }
.amount-input-wrap .currency-badge { position:absolute; left:16px; top:50%; transform:translateY(-50%);
    font-size:.8rem; font-weight:700; color:var(--gold); background:rgba(255,215,0,.12);
    padding:4px 8px; border-radius:6px; }
.calc-arrow { text-align:center; color:var(--gold); font-size:1.4rem; margin:12px 0; }
.result-box { background:rgba(255,215,0,.06); border:1px solid rgba(255,215,0,.2);
    border-radius:12px; padding:18px 22px; }
.result-amount { font-size:2rem; font-weight:800; color:var(--gold); letter-spacing:.5px; }
.result-meta { font-size:.8rem; color:var(--text-muted); margin-top:4px; }
.network-pills { display:flex; gap:8px; flex-wrap:wrap; }
.net-pill { padding:7px 18px; border-radius:20px; border:1.5px solid var(--border-gold);
    background:transparent; color:var(--text-muted); cursor:pointer; font-size:.85rem;
    font-weight:600; transition:all .2s; }
.net-pill.active, .net-pill:hover { border-color:var(--gold); color:var(--gold);
    background:rgba(255,215,0,.08); }
.addr-display { font-family:monospace; font-size:.82rem; word-break:break-all;
    background:rgba(255,255,255,.04); border:1px solid var(--border-light);
    border-radius:10px; padding:12px 16px; color:var(--text-light); }
.copy-btn { cursor:pointer; color:var(--gold); background:none; border:none;
    font-size:.9rem; padding:4px 8px; transition:opacity .2s; }
.copy-btn:hover { opacity:.7; }
.qr-placeholder { width:120px; height:120px; background:white; border-radius:10px;
    display:flex; align-items:center; justify-content:center; margin:0 auto; }
.step-badge { width:28px; height:28px; border-radius:50%;
    background:var(--gold-gradient); color:#000; font-weight:800;
    display:flex; align-items:center; justify-content:center; font-size:.85rem; flex-shrink:0; }
.gateway-select { width:100%; padding:13px 18px; background:rgba(255,255,255,.04);
    border:1.5px solid var(--border-gold); border-radius:12px; color:var(--text-light);
    font-size:.95rem; outline:none; cursor:pointer; }
.gateway-select:focus { border-color:var(--gold); }
.gateway-select option { background:#0A0F1E; }
.submit-btn { width:100%; padding:16px; border-radius:14px; border:none; cursor:pointer;
    font-size:1.05rem; font-weight:700; background:var(--gold-gradient); color:#000;
    box-shadow:var(--shadow-gold); transition:all .3s; letter-spacing:.3px; }
.submit-btn:hover { box-shadow:var(--shadow-gold-hover); transform:translateY(-1px); }
.submit-btn:disabled { opacity:.5; cursor:not-allowed; transform:none; }
.fee-row { display:flex; justify-content:space-between; padding:6px 0;
    border-bottom:1px solid var(--border-light); font-size:.88rem; }
.fee-row:last-child { border-bottom:none; font-weight:700; color:var(--gold); }
</style>
</head>
<body>
<?php if (function_exists('renderNavbar')) renderNavbar($currentLang); ?>
<!-- روابط إضافية للتنقل -->
<div style="background:rgba(0,0,0,.6);padding:8px 28px;display:flex;align-items:center;gap:16px;border-bottom:1px solid rgba(255,215,0,.1)">
    <a href="index.php" style="color:var(--text-muted);font-size:.82rem;text-decoration:none;display:flex;align-items:center;gap:5px">
        <i class="fas fa-home" style="color:var(--gold)"></i>
        <?= $currentLang==='en'?'Home':'الرئيسية' ?>
    </a>
    <span style="color:rgba(255,215,0,.2)">|</span>
    <a href="dashboard.php" style="color:var(--text-muted);font-size:.82rem;text-decoration:none;display:flex;align-items:center;gap:5px">
        <i class="fas fa-chart-pie" style="color:var(--gold)"></i>
        <?= $currentLang==='en'?'Dashboard':'لوحة التحكم' ?>
    </a>
    <span style="color:rgba(255,215,0,.2)">|</span>
    <a href="checkout.php" style="color:var(--text-muted);font-size:.82rem;text-decoration:none;display:flex;align-items:center;gap:5px">
        <i class="fas fa-credit-card" style="color:var(--gold)"></i>
        Checkout
    </a>
    <span style="color:rgba(255,215,0,.2)">|</span>
    <a href="my_cards.php" style="color:var(--text-muted);font-size:.82rem;text-decoration:none;display:flex;align-items:center;gap:5px">
        <i class="fas fa-wallet" style="color:var(--gold)"></i>
        <?= $currentLang==='en'?'My Cards':'بطاقاتي' ?>
    </a>
    <div style="margin-<?= $pageDir==='rtl'?'right':'left' ?>:auto">
        <?= langSwitcher(true) ?>
    </div>
</div>
<div class="crypto-hero">
<div class="container" style="max-width:1100px;margin:0 auto;padding:0 20px">

<!-- ── Header ── -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;padding-bottom:20px">
  <div>
    <h1 style="font-size:1.6rem;font-weight:800;color:var(--gold);margin:0">
      <i class="fas fa-coins" style="margin-<?= $pageDir==='rtl'?'left':'right' ?>:10px"></i>
      Crypto Exchange
    </h1>
    <p style="color:var(--text-muted);margin:4px 0 0;font-size:.9rem">شراء وبيع USDT بالعملات المحلية</p>
  </div>
  <div class="tab-switcher">
    <button class="tab-btn active" id="btnBuy" onclick="switchTab('buy')">
      <i class="fas fa-arrow-down-to-line"></i> شراء
    </button>
    <button class="tab-btn" id="btnSell" onclick="switchTab('sell')">
      <i class="fas fa-arrow-up-from-line"></i> بيع
    </button>
  </div>
</div>

<!-- ── Live Rate Ticker ── -->
<div class="rate-ticker" id="rateTicker" style="padding-bottom:22px">
  <div class="rate-chip">
    <div class="coin-icon" style="background:#26a17b;color:white">₮</div>
    <div>
      <div class="rate-val" id="tickerUSDT">
        <?= $initialRate ? number_format($initialRate['final_rate'],4) : '---' ?> AED
      </div>
      <div class="rate-lbl">USDT/AED <span class="rate-chg" id="tickerUSDTChg"></span></div>
    </div>
  </div>
  <div class="rate-chip">
    <div class="coin-icon" style="background:#f7931a;color:white">₿</div>
    <div>
      <div class="rate-val" id="tickerBTC">--- AED</div>
      <div class="rate-lbl">BTC/AED <span class="rate-chg" id="tickerBTCChg"></span></div>
    </div>
  </div>
  <div class="rate-chip">
    <div class="coin-icon" style="background:#627eea;color:white">Ξ</div>
    <div>
      <div class="rate-val" id="tickerETH">--- AED</div>
      <div class="rate-lbl">ETH/AED <span class="rate-chg" id="tickerETHChg"></span></div>
    </div>
  </div>
  <div style="margin-<?= $pageDir==='rtl'?'right':'left' ?>:auto;display:flex;align-items:center;gap:8px">
    <span style="width:8px;height:8px;border-radius:50%;background:#4CAF50;display:inline-block;animation:pulse 2s infinite"></span>
    <span style="color:var(--text-muted);font-size:.8rem" id="lastUpdate">يتحدث كل 30 ثانية</span>
  </div>
</div>
</div><!-- end crypto-hero -->
</div><!-- end container -->

<div style="max-width:1100px;margin:32px auto;padding:0 20px">
<div style="display:grid;grid-template-columns:1fr 340px;gap:28px;align-items:start">

<!-- ══ MAIN COLUMN ══ -->
<div>

<!-- ── BUY FORM ── -->
<div id="panelBuy" class="calc-card">
  <h3 style="color:var(--gold);margin:0 0 24px;font-size:1.15rem">
    <i class="fas fa-arrow-down-to-line" style="margin-<?= $pageDir==='rtl'?'left':'right' ?>:8px"></i>
    شراء USDT
  </h3>
  <form id="formBuy" onsubmit="submitBuy(event)">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
    <input type="hidden" name="crypto" value="USDT">
    <input type="hidden" id="buyNetwork" name="network" value="TRC20">

    <!-- المبلغ بالفيات -->
    <div style="margin-bottom:20px">
      <label style="color:var(--text-muted);font-size:.85rem;margin-bottom:8px;display:block">المبلغ الذي تريد دفعه</label>
      <div class="amount-input-wrap">
        <span class="currency-badge">AED</span>
        <input type="number" id="buyAmount" name="amount" placeholder="0.00"
               min="10" step="0.01" oninput="calcBuy()" required>
      </div>
      <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">
        <?php foreach ([100,250,500,1000,2500] as $q): ?>
        <button type="button" onclick="setBuyAmount(<?= $q ?>)"
          style="padding:5px 14px;border-radius:8px;border:1px solid var(--border-gold);
                 background:rgba(255,215,0,.06);color:var(--text-gold);font-size:.8rem;cursor:pointer">
          <?= number_format($q) ?> AED
        </button>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="calc-arrow"><i class="fas fa-arrow-down"></i></div>

    <!-- النتيجة -->
    <div class="result-box" style="margin-bottom:20px">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px">
        <div class="coin-icon" style="background:#26a17b;color:white;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800">₮</div>
        <div class="result-amount" id="buyResult">0.000000 USDT</div>
      </div>
      <div class="result-meta" id="buyMeta">أدخل المبلغ لرؤية التفاصيل</div>
    </div>

    <!-- الشبكة -->
    <div style="margin-bottom:20px">
      <label style="color:var(--text-muted);font-size:.85rem;margin-bottom:10px;display:block">الشبكة</label>
      <div class="network-pills">
        <button type="button" class="net-pill active" onclick="setNetwork('buy','TRC20',this)">TRC20 (Tron)</button>
        <button type="button" class="net-pill" onclick="setNetwork('buy','ERC20',this)">ERC20 (Ethereum)</button>
        <button type="button" class="net-pill" onclick="setNetwork('buy','BEP20',this)">BEP20 (BSC)</button>
      </div>
    </div>

    <!-- عنوان المحفظة -->
    <div style="margin-bottom:20px">
      <label style="color:var(--text-muted);font-size:.85rem;margin-bottom:8px;display:block">
        عنوان محفظتك <span id="networkLabel" style="color:var(--gold)">(TRC20)</span>
      </label>
      <input type="text" id="walletAddress" name="wallet_address"
             placeholder="T... (عنوان Tron TRC20)"
             style="width:100%;padding:13px 18px;background:rgba(255,255,255,.04);
                    border:1.5px solid var(--border-gold);border-radius:12px;
                    color:var(--text-light);font-family:monospace;font-size:.9rem;outline:none"
             onfocus="this.style.borderColor='var(--gold)'"
             onblur="this.style.borderColor='var(--border-gold)'"
             required>
      <?php if ($userWallet): ?>
      <button type="button" onclick="useMyWallet()"
        style="margin-top:8px;padding:5px 14px;border-radius:8px;border:1px solid var(--border-gold);
               background:rgba(255,215,0,.06);color:var(--text-gold);font-size:.8rem;cursor:pointer">
        <i class="fas fa-wallet"></i> استخدم محفظتي المحفوظة
      </button>
      <?php endif; ?>
    </div>

    <!-- البوابة -->
    <div style="margin-bottom:24px">
      <label style="color:var(--text-muted);font-size:.85rem;margin-bottom:8px;display:block">طريقة الدفع</label>
      <select class="gateway-select" name="payment_gateway" id="buyGateway">
        <?php foreach ($activeGateways as $gw): ?>
        <option value="<?= htmlspecialchars($gw['code']) ?>"><?= htmlspecialchars($gw['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- ملخص الرسوم -->
    <div id="feeBreakdown" style="display:none;margin-bottom:20px;background:rgba(255,255,255,.03);border-radius:10px;padding:14px 18px">
      <div class="fee-row"><span style="color:var(--text-muted)">المبلغ</span><span id="feeAmount">—</span></div>
      <div class="fee-row"><span style="color:var(--text-muted)">رسوم المنصة (1.5%)</span><span id="feePlatform">—</span></div>
      <div class="fee-row"><span>ستستقبل</span><span id="feeReceive">—</span></div>
    </div>

    <button type="submit" class="submit-btn" id="btnSubmitBuy" disabled>
      <i class="fas fa-bolt"></i> شراء الآن
    </button>
  </form>
</div>

<!-- ── SELL FORM ── -->
<div id="panelSell" class="calc-card" style="display:none">
  <h3 style="color:var(--gold);margin:0 0 24px;font-size:1.15rem">
    <i class="fas fa-arrow-up-from-line" style="margin-<?= $pageDir==='rtl'?'left':'right' ?>:8px"></i>
    بيع USDT
  </h3>
  <form id="formSell" onsubmit="submitSell(event)">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
    <input type="hidden" name="crypto" value="USDT">
    <input type="hidden" id="sellNetwork" name="network" value="TRC20">

    <!-- مبلغ USDT -->
    <div style="margin-bottom:20px">
      <label style="color:var(--text-muted);font-size:.85rem;margin-bottom:8px;display:block">كم USDT تريد بيعه؟</label>
      <div class="amount-input-wrap">
        <span class="currency-badge" style="font-size:.7rem">USDT</span>
        <input type="number" id="sellAmount" name="crypto_amount" placeholder="0.00"
               min="10" step="0.000001" oninput="calcSell()" required>
      </div>
    </div>

    <div class="calc-arrow"><i class="fas fa-arrow-down"></i></div>

    <!-- ستستقبل -->
    <div class="result-box" style="margin-bottom:20px">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px">
        <div style="font-size:1.4rem">🇦🇪</div>
        <div class="result-amount" id="sellResult">0.00 AED</div>
      </div>
      <div class="result-meta" id="sellMeta">أدخل المبلغ لرؤية التفاصيل</div>
    </div>

    <!-- الشبكة -->
    <div style="margin-bottom:20px">
      <label style="color:var(--text-muted);font-size:.85rem;margin-bottom:10px;display:block">أرسل من شبكة</label>
      <div class="network-pills">
        <button type="button" class="net-pill active" onclick="setNetwork('sell','TRC20',this)">TRC20 (Tron)</button>
        <button type="button" class="net-pill" onclick="setNetwork('sell','ERC20',this)">ERC20 (Ethereum)</button>
        <button type="button" class="net-pill" onclick="setNetwork('sell','BEP20',this)">BEP20 (BSC)</button>
      </div>
    </div>

    <!-- بيانات الاستقبال -->
    <div style="margin-bottom:20px;background:rgba(255,215,0,.05);border:1px dashed rgba(255,215,0,.3);border-radius:12px;padding:16px">
      <p style="color:var(--text-muted);font-size:.85rem;margin:0 0 10px">
        <i class="fas fa-info-circle" style="color:var(--gold)"></i>
        أرسل USDT للعنوان أدناه وسنحوّل AED لحسابك
      </p>
      <?php if ($hotAddress): ?>
      <div style="display:flex;align-items:center;gap:8px">
        <div class="addr-display" style="flex:1"><?= htmlspecialchars($hotAddress) ?></div>
        <button type="button" class="copy-btn" onclick="copyAddr('<?= htmlspecialchars($hotAddress) ?>')">
          <i class="fas fa-copy"></i>
        </button>
      </div>
      <?php else: ?>
      <div style="color:var(--warning);font-size:.85rem">⚠ Hot Wallet غير مضبوط بعد</div>
      <?php endif; ?>
    </div>

    <button type="submit" class="submit-btn" id="btnSubmitSell" disabled>
      <i class="fas fa-paper-plane"></i> تأكيد البيع
    </button>
  </form>
</div>

</div><!-- end main column -->

<!-- ══ SIDEBAR ══ -->
<div style="display:flex;flex-direction:column;gap:20px">

  <!-- المحفظة المالية الداخلية -->
  <div class="calc-card" style="padding:22px">
    <h4 style="color:var(--gold);margin:0 0 16px;font-size:.95rem">
      <i class="fas fa-piggy-bank" style="margin-<?= $pageDir==='rtl'?'left':'right' ?>:7px"></i>
      المحفظة المالية الداخلية
    </h4>
    <?php if (!empty($financialWallets)): ?>
      <?php foreach ($financialWallets as $wallet): ?>
        <div style="margin-bottom:14px;padding:14px 16px;background:rgba(255,255,255,.04);
                    border:1px solid rgba(255,215,0,.12);border-radius:12px">
          <div style="color:var(--text-muted);font-size:.82rem;margin-bottom:6px">
            <?= htmlspecialchars($wallet['currency']) ?>
          </div>
          <div style="font-size:1.25rem;font-weight:700;color:var(--gold)">
            <?= number_format((float)$wallet['balance'], 2) ?> <?= htmlspecialchars($wallet['currency']) ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <p style="color:var(--text-muted);font-size:.85rem;margin:0 0 12px">لا توجد محفظة مالية داخلية بعد.</p>
      <p style="color:var(--text-muted);font-size:.82rem;line-height:1.6">تظهر هنا أرصدةك المحلية بعد إتمام الدفعات أو استلام المستحقات.</p>
    <?php endif; ?>
  </div>

  <!-- المحفظة الرقمية -->
  <div class="calc-card" style="padding:22px">
    <h4 style="color:var(--gold);margin:0 0 16px;font-size:.95rem">
      <i class="fas fa-wallet" style="margin-<?= $pageDir==='rtl'?'left':'right' ?>:7px"></i>
      المحفظة الرقمية
    </h4>
    <?php if (!empty($userWallets)): ?>
      <div style="display:flex;flex-direction:column;gap:12px">
        <?php foreach ($userWallets as $wallet): ?>
          <div style="background:rgba(255,255,255,.04);border:1px solid rgba(255,215,0,.12);
                      border-radius:12px;padding:14px;">
            <div style="font-size:.82rem;color:var(--text-muted);margin-bottom:6px">
              <?= htmlspecialchars($wallet['network'] . ' ' . $wallet['coin']) ?>
            </div>
            <div class="addr-display" style="margin-bottom:10px;">
              <?= htmlspecialchars($wallet['address']) ?>
            </div>
            <button type="button" onclick="copyAddr('<?= htmlspecialchars($wallet['address']) ?>')"
              style="width:100%;padding:8px;border-radius:8px;border:1px solid var(--border-gold);
                     background:rgba(255,215,0,.06);color:var(--text-gold);font-size:.82rem;cursor:pointer">
              <i class="fas fa-copy"></i> نسخ العنوان
            </button>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p style="color:var(--text-muted);font-size:.85rem;margin:0 0 12px">لا توجد محفظة رقمية بعد.</p>
      <button onclick="createWallet()"
        style="width:100%;padding:10px;border-radius:10px;border:1px solid var(--gold);
               background:rgba(255,215,0,.08);color:var(--gold);font-size:.88rem;cursor:pointer;font-weight:600">
        <i class="fas fa-plus-circle"></i> إنشاء محفظة TRC20
      </button>
      <div id="walletResult" style="margin-top:10px;display:none"></div>
    <?php endif; ?>
  </div>

  <!-- الأسعار الحية -->
  <div class="calc-card" style="padding:22px">
    <h4 style="color:var(--gold);margin:0 0 16px;font-size:.95rem">
      <i class="fas fa-chart-line" style="margin-<?= $pageDir==='rtl'?'left':'right' ?>:7px"></i>
      أسعار الصرف الحية
    </h4>
    <div id="allRates" style="display:flex;flex-direction:column;gap:10px">
      <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border-light)">
        <span style="color:var(--text-muted);font-size:.85rem">USDT/AED</span>
        <span style="color:var(--gold);font-weight:700" id="sideUSDT">
          <?= $initialRate ? number_format($initialRate['final_rate'],4) : '—' ?>
        </span>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border-light)">
        <span style="color:var(--text-muted);font-size:.85rem">BTC/AED</span>
        <span style="color:var(--gold);font-weight:700" id="sideBTC">—</span>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0">
        <span style="color:var(--text-muted);font-size:.85rem">ETH/AED</span>
        <span style="color:var(--gold);font-weight:700" id="sideETH">—</span>
      </div>
    </div>
    <p style="color:var(--text-muted);font-size:.75rem;margin:12px 0 0;text-align:center">
      يشمل هامش منصة 1.5%
    </p>
  </div>

  <!-- تنبيه أمني -->
  <div style="background:rgba(91,192,222,.07);border:1px solid rgba(91,192,222,.2);
              border-radius:12px;padding:16px">
    <p style="color:var(--info);font-size:.82rem;margin:0;line-height:1.7">
      <i class="fas fa-shield-halved" style="margin-<?= $pageDir==='rtl'?'left':'right' ?>:6px"></i>
      تأكد دائماً من صحة عنوان المحفظة قبل الإرسال.<br>
      العمليات على البلوكشين <strong>لا يمكن التراجع عنها</strong>.
    </p>
  </div>

</div><!-- end sidebar -->
</div><!-- end grid -->
</div><!-- end container -->

<div id="toast" style="position:fixed;bottom:28px;left:50%;transform:translateX(-50%) translateY(80px);
     background:rgba(10,16,39,.97);border:1px solid var(--border-gold);border-radius:12px;
     padding:13px 24px;color:var(--text-light);font-size:.9rem;z-index:9999;
     transition:transform .3s;white-space:nowrap;box-shadow:var(--shadow-md)"></div>

<style>
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.4} }
@media(max-width:768px){
  div[style*="grid-template-columns:1fr 340px"]{grid-template-columns:1fr!important}
}
</style>

<script>
// ── State ─────────────────────────────────────────────────
let currentTab = 'buy';
let rates = { USDT: <?= $initialRate ? $initialRate['final_rate'] : 3.72 ?>, BTC: 0, ETH: 0 };
let prevRates = {...rates};
const MY_WALLET = '<?= htmlspecialchars($userWallet['address'] ?? '') ?>';

// ── Tab Switch ────────────────────────────────────────────
function switchTab(tab) {
    currentTab = tab;
    document.getElementById('panelBuy').style.display  = tab === 'buy'  ? '' : 'none';
    document.getElementById('panelSell').style.display = tab === 'sell' ? '' : 'none';
    document.getElementById('btnBuy').classList.toggle('active',  tab === 'buy');
    document.getElementById('btnSell').classList.toggle('active', tab === 'sell');
}

// ── Network ───────────────────────────────────────────────
function setNetwork(form, net, el) {
    document.getElementById(form + 'Network').value = net;
    document.querySelectorAll('#panel' + form.charAt(0).toUpperCase() + form.slice(1) + ' .net-pill')
            .forEach(p => p.classList.remove('active'));
    el.classList.add('active');
    if (form === 'buy') {
        document.getElementById('networkLabel').textContent = '(' + net + ')';
        document.getElementById('walletAddress').placeholder =
            net === 'TRC20' ? 'T... (عنوان Tron TRC20)' :
            net === 'ERC20' ? '0x... (عنوان Ethereum)' : '0x... (عنوان BSC)';
    }
}

// ── Calculate Buy ─────────────────────────────────────────
function calcBuy() {
    const amt = parseFloat(document.getElementById('buyAmount').value) || 0;
    if (amt <= 0) {
        document.getElementById('buyResult').textContent = '0.000000 USDT';
        document.getElementById('buyMeta').textContent = 'أدخل المبلغ لرؤية التفاصيل';
        document.getElementById('feeBreakdown').style.display = 'none';
        document.getElementById('btnSubmitBuy').disabled = true;
        return;
    }
    const rate  = rates.USDT || 3.72;
    const usdt  = (amt / rate).toFixed(6);
    const fee   = (amt * 0.015).toFixed(2);
    const net   = (amt - fee).toFixed(2);
    const netU  = (parseFloat(net) / rate).toFixed(6);

    document.getElementById('buyResult').textContent = usdt + ' USDT';
    document.getElementById('buyMeta').textContent   = 'السعر: ' + rate.toFixed(4) + ' AED/USDT';
    document.getElementById('feeBreakdown').style.display = '';
    document.getElementById('feeAmount').textContent    = amt.toFixed(2) + ' AED';
    document.getElementById('feePlatform').textContent  = fee + ' AED';
    document.getElementById('feeReceive').textContent   = netU + ' USDT';
    document.getElementById('btnSubmitBuy').disabled = false;
}

// ── Calculate Sell ────────────────────────────────────────
function calcSell() {
    const usdt = parseFloat(document.getElementById('sellAmount').value) || 0;
    if (usdt <= 0) {
        document.getElementById('sellResult').textContent = '0.00 AED';
        document.getElementById('sellMeta').textContent = 'أدخل المبلغ لرؤية التفاصيل';
        document.getElementById('btnSubmitSell').disabled = true;
        return;
    }
    const rate   = rates.USDT || 3.72;
    const gross  = (usdt * rate).toFixed(2);
    const fee    = (gross * 0.015).toFixed(2);
    const net    = (gross - fee).toFixed(2);
    document.getElementById('sellResult').textContent = net + ' AED';
    document.getElementById('sellMeta').textContent   = 'السعر: ' + rate.toFixed(4) + ' | رسوم: ' + fee + ' AED';
    document.getElementById('btnSubmitSell').disabled = false;
}

function setBuyAmount(v) {
    document.getElementById('buyAmount').value = v;
    calcBuy();
}

function useMyWallet() {
    if (MY_WALLET) document.getElementById('walletAddress').value = MY_WALLET;
}

// ── Live Rates ────────────────────────────────────────────
async function fetchRates() {
    try {
        const r = await fetch('api/crypto.php?action=rates&fiat=AED');
        if (!r.ok) return;
        const d = await r.json();
        if (!d.rates) return;
        prevRates = {...rates};
        if (d.rates.USDT?.final_rate) rates.USDT = parseFloat(d.rates.USDT.final_rate);
        if (d.rates.BTC?.final_rate)  rates.BTC  = parseFloat(d.rates.BTC.final_rate);
        if (d.rates.ETH?.final_rate)  rates.ETH  = parseFloat(d.rates.ETH.final_rate);
        updateTicker();
        if (currentTab === 'buy')  calcBuy();
        if (currentTab === 'sell') calcSell();
        document.getElementById('lastUpdate').textContent =
            'آخر تحديث: ' + new Date().toLocaleTimeString('ar-AE');
    } catch(e) {}
}

function updateTicker() {
    const fmt = (v, prev, elVal, elChg) => {
        if (!v) return;
        const el = document.getElementById(elVal);
        const ec = document.getElementById(elChg);
        el.textContent = v.toLocaleString('en',{minimumFractionDigits:2,maximumFractionDigits:4}) + ' AED';
        if (prev && ec) {
            const pct = ((v - prev) / prev * 100).toFixed(2);
            ec.textContent = (pct >= 0 ? '▲ ' : '▼ ') + Math.abs(pct) + '%';
            ec.className = 'rate-chg ' + (pct >= 0 ? 'up' : 'dn');
        }
        document.getElementById('side' + elVal.replace('ticker','')) &&
        (document.getElementById('side' + elVal.replace('ticker','')).textContent =
            v.toLocaleString('en',{minimumFractionDigits:4}) );
    };
    fmt(rates.USDT, prevRates.USDT, 'tickerUSDT', 'tickerUSDTChg');
    fmt(rates.BTC,  prevRates.BTC,  'tickerBTC',  'tickerBTCChg');
    fmt(rates.ETH,  prevRates.ETH,  'tickerETH',  'tickerETHChg');
    if (document.getElementById('sideUSDT'))
        document.getElementById('sideUSDT').textContent = (rates.USDT||0).toFixed(4);
    if (document.getElementById('sideBTC'))
        document.getElementById('sideBTC').textContent = (rates.BTC||0).toLocaleString('en');
    if (document.getElementById('sideETH'))
        document.getElementById('sideETH').textContent = (rates.ETH||0).toLocaleString('en');
}

fetchRates();
setInterval(fetchRates, 30000);

// ── Submit Buy ────────────────────────────────────────────
async function submitBuy(e) {
    e.preventDefault();
    const btn  = document.getElementById('btnSubmitBuy');
    const addr = document.getElementById('walletAddress').value.trim();
    if (!addr) { showToast('أدخل عنوان المحفظة', 'warning'); return; }
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري المعالجة...';

    const fd = new FormData(e.target);
    const payload = Object.fromEntries(fd.entries());
    payload.customer_name  = '<?= addslashes($_SESSION['user_data']['username'] ?? 'User') ?>';
    payload.customer_email = '';

    try {
        const r = await fetch('api/crypto.php?action=buy', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify(payload)
        });
        const d = await r.json();
        if (d.success) {
            window.location.href = 'crypto_confirm.php?ref=' + encodeURIComponent(d.reference) + '&type=buy';
        } else {
            showToast(d.message || 'فشل الطلب', 'error');
            btn.disabled = false; btn.innerHTML = '<i class="fas fa-bolt"></i> شراء الآن';
        }
    } catch(err) {
        showToast('خطأ في الاتصال', 'error');
        btn.disabled = false; btn.innerHTML = '<i class="fas fa-bolt"></i> شراء الآن';
    }
}

// ── Submit Sell ───────────────────────────────────────────
async function submitSell(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSubmitSell');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري المعالجة...';

    const fd = new FormData(e.target);
    const payload = Object.fromEntries(fd.entries());
    payload.currency = 'AED';

    try {
        const r = await fetch('api/crypto.php?action=sell', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify(payload)
        });
        const d = await r.json();
        if (d.success) {
            window.location.href = 'crypto_confirm.php?ref=' + encodeURIComponent(d.reference) + '&type=sell';
        } else {
            showToast(d.message || 'فشل الطلب', 'error');
            btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> تأكيد البيع';
        }
    } catch(err) {
        showToast('خطأ في الاتصال', 'error');
        btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> تأكيد البيع';
    }
}

// ── Create Wallet ─────────────────────────────────────────
async function createWallet() {
    const r = await fetch('api/crypto.php?action=wallet&network=TRC20&coin=USDT');
    const d = await r.json();
    const el = document.getElementById('walletResult');
    if (d.success) {
        el.style.display = '';
        el.innerHTML = `<div class="addr-display" style="font-size:.75rem;margin-top:8px">${d.address}</div>
        <p style="color:var(--success);font-size:.8rem;margin:6px 0">✓ تم إنشاء المحفظة</p>`;
        showToast('تم إنشاء المحفظة بنجاح', 'success');
    } else { showToast(d.message, 'error'); }
}

// ── Copy ──────────────────────────────────────────────────
function copyAddr(addr) {
    navigator.clipboard.writeText(addr).then(() => showToast('تم النسخ', 'success'));
}

// ── Toast ─────────────────────────────────────────────────
function showToast(msg, type = 'info') {
    const t = document.getElementById('toast');
    const colors = {success:'#4CAF50', error:'#ef5350', warning:'#ff9800', info:'var(--gold)'};
    t.style.borderColor = colors[type] || colors.info;
    t.textContent = msg;
    t.style.transform = 'translateX(-50%) translateY(0)';
    setTimeout(() => t.style.transform = 'translateX(-50%) translateY(80px)', 3000);
}
</script>
</body>
</html>
