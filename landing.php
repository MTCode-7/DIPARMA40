<?php
if(session_status()===PHP_SESSION_NONE)session_start();
if (isset($_GET['lang']) && in_array($_GET['lang'], ['ar', 'en'], true)) {
  setcookie('di_parma_lang', $_GET['lang'], time() + 31536000, '/');
  $_COOKIE['di_parma_lang'] = $_GET['lang'];
}
$lang=isset($_GET['lang'])&&in_array($_GET['lang'],['ar','en'],true)?$_GET['lang']:(isset($_COOKIE['di_parma_lang'])&&$_COOKIE['di_parma_lang']==='en'?'en':'ar');
$ar=$lang==='ar';$dir=$ar?'rtl':'ltr';
?><!DOCTYPE html>
<html lang="<?=$lang?>" dir="<?=$dir?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>DI PARMA — <?=$ar?'منصة الدفع الشاملة':'Universal Payment Platform'?></title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--gold:#FFD700;--gold2:#FFB700;--bg:#040810;--card:#080d1a;--card2:#0a1020;
  --border:rgba(255,215,0,.12);--text:#f0f0f0;--muted:#777;--green:#10B981;--blue:#3B82F6;--purple:#8B5CF6}
html{scroll-behavior:smooth}
body{font-family:'Cairo',sans-serif;background:var(--bg);color:var(--text);overflow-x:hidden}
a{text-decoration:none;color:inherit}
</style>
</head>
<body>
<!-- NAV -->
<style>
.nav{position:fixed;top:0;width:100%;z-index:999;background:rgba(4,8,16,.95);backdrop-filter:blur(20px);
  border-bottom:1px solid var(--border);padding:0 32px;height:64px;display:flex;align-items:center;justify-content:space-between}
.nav-brand{color:var(--gold);font-weight:900;font-size:1.15rem;display:flex;align-items:center;gap:8px}
.nav-links{display:flex;gap:4px}
.nav-links a{color:var(--muted);font-size:.82rem;padding:7px 14px;border-radius:20px;transition:.2s}
.nav-links a:hover{color:var(--gold);background:rgba(255,215,0,.07)}
.nav-actions{display:flex;gap:8px;align-items:center}
.btn-nav-reg{background:linear-gradient(135deg,var(--gold),var(--gold2));color:#000;padding:9px 22px;
  border-radius:22px;font-weight:800;font-size:.82rem;transition:.2s}
.btn-nav-reg:hover{opacity:.9;transform:scale(1.03)}
.btn-nav-login{border:1.5px solid rgba(255,215,0,.3);color:var(--gold);padding:8px 20px;
  border-radius:22px;font-size:.82rem;font-weight:700;transition:.2s}
.btn-nav-login:hover{background:rgba(255,215,0,.08)}
.lang-btn{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);color:#ccc;
  padding:7px 14px;border-radius:20px;font-size:.78rem;cursor:pointer;transition:.2s}
.lang-btn:hover{border-color:var(--gold);color:var(--gold)}
@media(max-width:768px){.nav-links{display:none}.nav{padding:0 16px}}
</style>
<nav class="nav">
  <a href="/landing.php" class="nav-brand"><i class="fas fa-coins"></i> DI PARMA</a>
  <div class="nav-links">
    <a href="#about"><?=$ar?'عن الشركة':'About'?></a>
    <a href="#ramp"><?=$ar?'كريبتو':'Crypto'?></a>
    <a href="#otc">OTC</a>
    <a href="#gateways"><?=$ar?'البوابات':'Gateways'?></a>
    <a href="#markets"><?=$ar?'الأسواق':'Markets'?></a>
    <a href="/stores.php"><?=$ar?'المتاجر الفاخرة':'Luxury Stores'?></a>
    <a href="#business"><?=$ar?'رجال الأعمال':'Business'?></a>
    <a href="#charity"><?=$ar?'خيري':'Charity'?></a>
    <a href="/login.php"><?=$ar?'الدخول':'Login'?></a>
  </div>
  <div class="nav-actions">
    <button class="lang-btn" onclick="setLang('<?=$ar?'en':'ar'?>')"><?=$ar?'EN':'ع'?></button>
    <a href="/register.php" class="btn-nav-reg"><i class="fas fa-user-plus"></i> <?=$ar?'إنشاء حساب':'Register'?></a>
    <a href="/login.php" class="btn-nav-login"><i class="fas fa-sign-in-alt"></i> <?=$ar?'دخول':'Login'?></a>
  </div>
</nav>
<!-- HERO -->
<style>
.hero{min-height:100vh;display:flex;align-items:center;justify-content:center;text-align:center;
  padding:100px 20px 60px;position:relative;overflow:hidden}
.hero-bg{position:absolute;inset:0;background:radial-gradient(ellipse 100% 80% at 50% -10%,
  rgba(255,215,0,.07),transparent 65%);pointer-events:none}
.hero-grid{position:absolute;inset:0;background-image:
  linear-gradient(rgba(255,215,0,.03) 1px,transparent 1px),
  linear-gradient(90deg,rgba(255,215,0,.03) 1px,transparent 1px);
  background-size:60px 60px;pointer-events:none}
.hero-inner{position:relative;max-width:920px;margin:0 auto}
.hero-tag{display:inline-flex;align-items:center;gap:8px;background:rgba(255,215,0,.08);
  border:1px solid rgba(255,215,0,.2);border-radius:30px;padding:7px 20px;font-size:.78rem;
  color:var(--gold);margin-bottom:28px;letter-spacing:1px}
.hero h1{font-size:clamp(2.4rem,6vw,4.8rem);font-weight:900;line-height:1.08;margin-bottom:22px}
.hero h1 .g{background:linear-gradient(135deg,var(--gold),#fff 50%,var(--gold));
  -webkit-background-clip:text;-webkit-text-fill-color:transparent}
.hero-sub{font-size:clamp(.9rem,2vw,1.1rem);color:var(--muted);max-width:680px;
  margin:0 auto 40px;line-height:1.8}
.hero-btns{display:flex;gap:14px;justify-content:center;flex-wrap:wrap;margin-bottom:56px}
.btn-gold{background:linear-gradient(135deg,var(--gold),var(--gold2));color:#000;padding:15px 36px;
  border-radius:32px;font-weight:800;font-size:.95rem;display:inline-flex;align-items:center;gap:9px;transition:.3s}
.btn-gold:hover{transform:translateY(-2px);box-shadow:0 12px 35px rgba(255,215,0,.25)}
.btn-out{background:transparent;color:var(--text);padding:14px 32px;border-radius:32px;
  font-weight:700;font-size:.95rem;border:1.5px solid rgba(255,255,255,.15);
  display:inline-flex;align-items:center;gap:9px;transition:.3s}
.btn-out:hover{border-color:var(--gold);color:var(--gold)}
.hero-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:1px;
  background:var(--border);border:1px solid var(--border);border-radius:20px;
  overflow:hidden;max-width:680px;margin:0 auto}
.hs{background:rgba(255,255,255,.025);padding:20px 12px;text-align:center}
.hs-n{font-size:1.5rem;font-weight:900;color:var(--gold)}
.hs-l{font-size:.68rem;color:var(--muted);margin-top:4px}
@media(max-width:600px){.hero-stats{grid-template-columns:repeat(2,1fr)}}
</style>
<section class="hero">
  <div class="hero-bg"></div><div class="hero-grid"></div>
  <div class="hero-inner">
    <div class="hero-tag">
      <span style="width:7px;height:7px;border-radius:50%;background:#4CAF50;display:inline-block"></span>
      <?=$ar?'منصة مدفوعات لايف — متاحة 24/7':'Live Payment Platform — Available 24/7'?>
    </div>
    <h1><?=$ar?'<span class="g">DI PARMA</span><br>منصة الدفع الشاملة':'<span class="g">DI PARMA</span><br>Universal Payment Platform'?></h1>
    <p class="hero-sub"><?=$ar?
      'تحويل بنكي · كريبتو · OTC · On/Off Ramp · محافظ رقمية · بطاقات ائتمان — كل شيء في منصة واحدة':
      'Bank Transfer · Crypto · OTC · On/Off Ramp · Digital Wallets · Cards — Everything in One Platform'?></p>
    <div class="hero-btns">
      <a href="/register.php" class="btn-gold"><i class="fas fa-rocket"></i> <?=$ar?'ابدأ الآن':'Get Started'?></a>
        <a href="/stores.php" class="btn-out"><i class="fas fa-store"></i> <?=$ar?'دعوة لأكبر الماركات':'Invitation for Top Brands'?></a>
      <a href="#about" class="btn-out"><i class="fas fa-play-circle"></i> <?=$ar?'اكتشف الخدمات':'Explore'?></a>
    </div>
    <div class="hero-stats">
      <div class="hs"><div class="hs-n">847</div><div class="hs-l"><?=$ar?'بوابة دفع':'Payment Gateways'?></div></div>
      <div class="hs"><div class="hs-n">100</div><div class="hs-l"><?=$ar?'بنك':'Banks'?></div></div>
      <div class="hs"><div class="hs-n">196</div><div class="hs-l"><?=$ar?'دولة':'Countries'?></div></div>
      <div class="hs"><div class="hs-n">50+</div><div class="hs-l"><?=$ar?'عملة رقمية':'Cryptos'?></div></div>
      <div class="hs"><div class="hs-n">24/7</div><div class="hs-l"><?=$ar?'متاح دائماً':'Always On'?></div></div>
    </div>
  </div>
</section>
<!-- SHARED STYLES -->
<style>
.sec{padding:88px 24px;max-width:1280px;margin:0 auto}
.sec-full{border-top:1px solid var(--border);border-bottom:1px solid var(--border);background:rgba(255,255,255,.013)}
.sec-hd{text-align:center;margin-bottom:52px}
.sec-tag{display:inline-block;background:rgba(255,215,0,.08);border:1px solid rgba(255,215,0,.2);
  border-radius:20px;padding:4px 16px;font-size:.7rem;color:var(--gold);margin-bottom:10px;letter-spacing:1px}
.sec-hd h2{font-size:clamp(1.5rem,3.5vw,2.4rem);font-weight:900;margin-bottom:10px}
.sec-hd h2 span{color:var(--gold)}
.divider{width:48px;height:3px;background:linear-gradient(90deg,transparent,var(--gold),transparent);margin:12px auto}
.sec-hd p{color:var(--muted);font-size:.88rem;max-width:580px;margin:0 auto;line-height:1.75}
.grid3{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:20px}
.grid4{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:18px}
.card{background:var(--card);border:1px solid var(--border);border-radius:20px;padding:28px 24px;
  transition:.3s;position:relative;overflow:hidden}
.card:hover{transform:translateY(-4px);box-shadow:0 20px 50px rgba(0,0,0,.4);border-color:rgba(255,215,0,.2)}
.cline{position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,transparent,var(--cl,var(--gold)),transparent)}
.ico{font-size:2.2rem;margin-bottom:14px;display:block}
.card h3{font-size:1rem;font-weight:800;margin-bottom:9px}
.card p{font-size:.81rem;color:var(--muted);line-height:1.7;margin-bottom:14px}
.tags{display:flex;gap:6px;flex-wrap:wrap}
.tag{padding:3px 10px;border-radius:10px;font-size:.67rem;font-weight:600;background:rgba(255,255,255,.06);color:#aaa}
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:56px;align-items:center}
.two-col .vis{background:var(--card2);border:1px solid var(--border);border-radius:24px;padding:32px;min-height:280px;display:flex;align-items:center;justify-content:center}
.feat{list-style:none;display:flex;flex-direction:column;gap:10px}
.feat li{display:flex;align-items:center;gap:10px;font-size:.87rem;color:#ccc}
.feat li i{color:var(--gold);width:18px}
@media(max-width:768px){.two-col{grid-template-columns:1fr}}
</style>

<!-- ═══ SECTION 1: عن الشركة (مثل Stripe) ═══ -->
<section class="sec" id="about">
  <div class="sec-hd">
    <div class="sec-tag"><?=$ar?'من نحن':'About Us'?></div>
    <h2><?=$ar?'<span>DI PARMA</span> — منصة مالية من الجيل القادم':'<span>DI PARMA</span> — Next-Gen Financial Platform'?></h2>
    <div class="divider"></div>
    <p><?=$ar?'نبني البنية التحتية المالية الشاملة لعملك — من بطاقات الائتمان إلى الكريبتو، من التحويل البنكي إلى OTC':'We build comprehensive financial infrastructure for your business — cards to crypto, bank transfers to OTC'?></p>
  </div>
  <div class="two-col">
    <div>
      <h2 style="font-size:clamp(1.5rem,3vw,2.2rem);font-weight:900;margin-bottom:16px;line-height:1.25">
        <?=$ar?'كل ما تحتاجه<br><span style="color:var(--gold)">في منصة واحدة</span>':'Everything You Need<br><span style="color:var(--gold)">In One Platform</span>'?>
      </h2>
      <p style="color:var(--muted);line-height:1.8;margin-bottom:24px;font-size:.88rem">
        <?=$ar?'DI PARMA تجمع أكثر من 200 بوابة دفع عالمية، معاملات OTC، On Ramp/Off Ramp، جميع أنواع الكريبتو، خدمات رجال الأعمال، والجمعيات الخيرية في واجهة واحدة متكاملة.':
        'DI PARMA unifies 200+ global payment gateways, OTC transactions, On/Off Ramp, all crypto types, business services, and charity operations in one integrated interface.'?>
      </p>
      <ul class="feat">
        <li><i class="fas fa-check-circle"></i> <?=$ar?'ISO/IEC 27001 + PCI DSS Level 1':'ISO/IEC 27001 + PCI DSS Level 1'?></li>
        <li><i class="fas fa-check-circle"></i> <?=$ar?'دعم 150+ عملة فيات وكريبتو':'150+ fiat & crypto currencies'?></li>
        <li><i class="fas fa-check-circle"></i> <?=$ar?'تسوية فورية يومية':'Instant daily settlement'?></li>
        <li><i class="fas fa-check-circle"></i> <?=$ar?'دعم فني 24/7':'24/7 technical support'?></li>
        <li><i class="fas fa-check-circle"></i> <?=$ar?'تشفير كامل ومؤمن':'Full encryption & secured'?></li>
      </ul>
    </div>
    <div class="vis">
      <div style="text-align:center">
        <div style="font-size:3.5rem;margin-bottom:14px">💎</div>
        <div style="font-size:1.2rem;font-weight:900;color:var(--gold);margin-bottom:6px">DI PARMA</div>
        <div style="font-size:.78rem;color:var(--muted);margin-bottom:20px"><?=$ar?'منصة الدفع الشاملة':'Universal Payment Hub'?></div>
        <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap">
          <?php foreach(['Visa','Mastercard','USDT','BTC','PayPal','Wise','Gate.io','Binance'] as $b): ?>
          <span style="background:rgba(255,215,0,.08);border:1px solid rgba(255,215,0,.18);border-radius:10px;padding:4px 11px;font-size:.7rem;color:var(--gold)"><?=$b?></span>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</section>
<!-- ═══ SECTION 2: On/Off Ramp + كريبتو (مثل Ramp Network) ═══ -->
<div class="sec-full">
<div class="sec" id="ramp">
  <div class="sec-hd">
    <div class="sec-tag">On Ramp / Off Ramp / Crypto</div>
    <h2><?=$ar?'تحويل <span>فيات ⇄ كريبتو</span> بسهولة':'Easy <span>Fiat ⇄ Crypto</span> Conversion'?></h2>
    <div class="divider"></div>
    <p><?=$ar?'اشترِ وبيع العملات الرقمية بالعملات التقليدية مباشرة — PayPal, Wise, Binance, Gate.io':'Buy and sell digital currencies with fiat directly — PayPal, Wise, Binance, Gate.io'?></p>
  </div>
  <div class="grid3">
    <div class="card" style="--cl:#F7931A"><div class="cline"></div>
      <span class="ico">🚀</span>
      <h3>On Ramp</h3>
      <p><?=$ar?'حوّل عملتك التقليدية (USD/AED/EUR/SAR) إلى USDT أو BTC أو ETH فوراً عبر بطاقتك أو حسابك البنكي أو PayPal':'Convert fiat (USD/AED/EUR/SAR) to USDT/BTC/ETH instantly via card, bank, or PayPal'?></p>
      <div class="tags"><span class="tag">Visa/MC</span><span class="tag">PayPal</span><span class="tag">Bank</span></div>
    </div>
    <div class="card" style="--cl:#10B981"><div class="cline"></div>
      <span class="ico">💸</span>
      <h3>Off Ramp</h3>
      <p><?=$ar?'حوّل كريبتوك إلى عملة تقليدية واسحبها مباشرة لحسابك البنكي أو بطاقتك في 150+ دولة':'Convert crypto to fiat and withdraw directly to bank or card in 150+ countries'?></p>
      <div class="tags"><span class="tag">USDT</span><span class="tag">BTC</span><span class="tag">ETH</span></div>
    </div>
    <div class="card" style="--cl:#8B5CF6"><div class="cline"></div>
      <span class="ico">🔄</span>
      <h3><?=$ar?'تحويل كريبتو':'Crypto Transfer'?></h3>
      <p><?=$ar?'تحويل من محفظة إلى محفظة عبر شبكات TRC20 وERC20 وBEP20 — سرعة فائقة وعمولة منخفضة':'Wallet-to-wallet via TRC20, ERC20, BEP20 networks — high speed, low fee'?></p>
      <div class="tags"><span class="tag">TRC20</span><span class="tag">ERC20</span><span class="tag">BEP20</span></div>
    </div>
    <div class="card" style="--cl:#FFD700"><div class="cline"></div>
      <span class="ico">🟡</span>
      <h3>Binance</h3>
      <p><?=$ar?'تداول مباشر عبر Binance — شراء وبيع وتحويل لجميع العملات الرقمية بأفضل الأسعار في السوق':'Direct trading via Binance — buy, sell, transfer all digital currencies at best market rates'?></p>
      <div class="tags"><span class="tag">Spot</span><span class="tag">P2P</span><span class="tag">Pay</span></div>
    </div>
    <div class="card" style="--cl:#E53E3E"><div class="cline"></div>
      <span class="ico">🔴</span>
      <h3>Gate.io</h3>
      <p><?=$ar?'منصة Gate.io للتداول المتقدم — دعم آلاف الأزواج والعملات الرقمية مع Gate Pay للدفع الفوري':'Gate.io advanced trading — thousands of pairs with Gate Pay for instant payments'?></p>
      <div class="tags"><span class="tag">Gate Pay</span><span class="tag">Spot</span><span class="tag">Wallet</span></div>
    </div>
    <div class="card" style="--cl:#06B6D4"><div class="cline"></div>
      <span class="ico">🌙</span>
      <h3>MoonPay / Transak</h3>
      <p><?=$ar?'شراء كريبتو بالبطاقة مباشرة عبر MoonPay وTransak — أسرع طريقة للدخول إلى عالم الكريبتو':'Buy crypto by card directly via MoonPay & Transak — fastest way into crypto'?></p>
      <div class="tags"><span class="tag">MoonPay</span><span class="tag">Transak</span><span class="tag">Ramp</span></div>
    </div>
  </div>
</div>
</div>
<!-- ═══ SECTION 3: OTC ═══ -->
<div class="sec" id="otc">
  <div class="sec-hd">
    <div class="sec-tag">OTC Trading</div>
    <h2><?=$ar?'تداول <span>OTC</span> للمبالغ الكبيرة':'Large Volume <span>OTC</span> Trading'?></h2>
    <div class="divider"></div>
    <p><?=$ar?'خدمة تداول خارج البورصة للعملاء المؤسسيين والصفقات الكبيرة — أسعار مخصصة وتنفيذ فوري':'Off-exchange trading for institutional clients — custom rates and instant execution'?></p>
  </div>
  <div class="two-col">
    <div style="background:var(--card2);border:1px solid var(--border);border-radius:24px;padding:32px">
      <div style="background:rgba(255,215,0,.06);border:1px solid rgba(255,215,0,.2);border-radius:14px;padding:18px;margin-bottom:12px">
        <div style="color:var(--muted);font-size:.75rem;margin-bottom:6px"><?=$ar?'أنت تبيع':'You Sell'?></div>
        <div style="font-size:1.7rem;font-weight:900">1,000,000 <span style="color:var(--gold);font-size:1rem">USDT</span></div>
      </div>
      <div style="text-align:center;padding:8px;color:var(--gold)"><i class="fas fa-exchange-alt"></i></div>
      <div style="background:rgba(16,185,129,.06);border:1px solid rgba(16,185,129,.2);border-radius:14px;padding:18px">
        <div style="color:var(--muted);font-size:.75rem;margin-bottom:6px"><?=$ar?'أنت تحصل':'You Receive'?></div>
        <div style="font-size:1.7rem;font-weight:900;color:#10B981">999,500 <span style="font-size:1rem">USD</span></div>
      </div>
    </div>
    <div>
      <h2 style="font-size:clamp(1.4rem,2.8vw,2rem);font-weight:900;margin-bottom:14px;line-height:1.25">
        <?=$ar?'صفقات OTC<br><span style="color:var(--gold)">بدون حدود</span>':'OTC Deals<br><span style="color:var(--gold)">No Limits</span>'?>
      </h2>
      <p style="color:var(--muted);line-height:1.8;margin-bottom:22px;font-size:.87rem">
        <?=$ar?'نوفر خدمة OTC متكاملة — تنفيذ فوري، أسعار تنافسية مخصصة، وسرية تامة للصفقات الكبيرة بأي عملة.':
        'Comprehensive OTC service — instant execution, custom competitive rates, full confidentiality for large deals in any currency.'?>
      </p>
      <ul class="feat">
        <li><i class="fas fa-bolt"></i> <?=$ar?'تنفيذ فوري بدون انزلاق سعري':'Instant execution without slippage'?></li>
        <li><i class="fas fa-lock"></i> <?=$ar?'سرية تامة للصفقات':'Full transaction confidentiality'?></li>
        <li><i class="fas fa-chart-line"></i> <?=$ar?'أسعار تنافسية مخصصة':'Custom competitive rates'?></li>
        <li><i class="fas fa-headset"></i> <?=$ar?'دعم VIP على مدار الساعة':'24/7 VIP support'?></li>
        <li><i class="fas fa-globe"></i> <?=$ar?'تغطية 150+ دولة':'150+ countries coverage'?></li>
      </ul>
    </div>
  </div>
</div>
<!-- ═══ SECTION 4: الأسواق ═══ -->
<div class="sec-full">
<div class="sec" id="markets">
  <div class="sec-hd">
    <div class="sec-tag">Trading Markets</div>
    <h2><?=$ar?'تداول <span>الفوركس والسلع والكريبتو</span>':'Forex, Commodities & <span>Crypto Trading</span>'?></h2>
    <div class="divider"></div>
    <p><?=$ar?'وصول موحد إلى أسواق العملات الأجنبية والسلع الأمريكية والعملات الرقمية عبر بنية دفع آمنة ومرنة.':'Unified access to forex, US commodities, and digital currency markets through secure, flexible payment infrastructure.'?></p>
  </div>
  <div class="grid3">
    <div class="card" style="--cl:#3B82F6"><div class="cline"></div>
      <span class="ico">💱</span>
      <h3><?=$ar?'تداول الفوركس':'Forex Trading'?></h3>
      <p><?=$ar?'تنفيذ وإدارة معاملات العملات الأجنبية الرئيسية عبر حلول دفع وتسوية دولية.':'Execute and manage major foreign exchange transactions with international payment and settlement solutions.'?></p>
      <div class="tags"><span class="tag">FX</span><span class="tag">USD</span><span class="tag">EUR</span></div>
    </div>
    <div class="card" style="--cl:#F59E0B"><div class="cline"></div>
      <span class="ico">📊</span>
      <h3><?=$ar?'السلع الأمريكية':'US Commodities'?></h3>
      <p><?=$ar?'مدفوعات وتسويات لتداول السلع الأمريكية مع متابعة واضحة للصفقات والتحويلات.':'Payments and settlement for US commodity trading with clear transaction and transfer tracking.'?></p>
      <div class="tags"><span class="tag">US Markets</span><span class="tag">Commodities</span><span class="tag">Settlement</span></div>
    </div>
    <div class="card" style="--cl:#10B981"><div class="cline"></div>
      <span class="ico">₿</span>
      <h3><?=$ar?'العملات الرقمية':'Digital Currencies'?></h3>
      <p><?=$ar?'شراء وبيع وتحويل العملات الرقمية عبر المحافظ والبوابات المتكاملة ودعم شبكات متعددة.':'Buy, sell, and transfer digital currencies through integrated wallets, gateways, and multi-network support.'?></p>
      <div class="tags"><span class="tag">BTC</span><span class="tag">USDT</span><span class="tag">ETH</span></div>
    </div>
  </div>
</div>
</div>
<!-- ═══ SECTION 5: بوابات الدفع ═══ -->
<div class="sec-full">
<div class="sec" id="gateways">
  <div class="sec-hd">
    <div class="sec-tag"><?=$ar?'بوابات الدفع':'Payment Gateways'?></div>
    <h2><?=$ar?'<span>847</span> بوابة دفع في منصة واحدة':'<span>847</span> Gateways, One Platform'?></h2>
    <div class="divider"></div>
    <p><?=$ar?'من PayPal إلى Wise، من Binance إلى MyFatoorah — جميع البوابات فعّالة ومتاحة فوراً':'From PayPal to Wise, Binance to MyFatoorah — all gateways active and available instantly'?></p>
  </div>
  <?php
  $gws=[
    ['💳','Visa / MC','Card','#3B82F6'],['🅿️','PayPal','Wallet','#003087'],
    ['💼','Wise','Transfer','#9FCC2E'],['🟡','Binance','Crypto','#F7B731'],
    ['🔴','Gate.io','Crypto','#E53E3E'],['🏦','MyFatoorah','Card','#00897B'],
    ['🌙','MoonPay','Ramp','#7B3FE4'],['🔵','Transak','Ramp','#1E90FF'],
    ['🟢','Ramp Network','Ramp','#10B981'],['💰','Checkout.com','Card','#1A1A2E'],
    ['🔷','PayTabs','Card','#0056D2'],['🟠','Crypto.com','Crypto','#002D74'],
    ['⚡','OKX','Crypto','#333'],['🔵','Bybit','Crypto','#F7A600'],
    ['🏛️','SWIFT','Bank','#4A90D9'],['🏦','SEPA','Bank','#2C6FAC'],
    ['💵','ACH','Bank','#27AE60'],['🔄','Braintree','Card','#009CDE'],
    ['🟣','KuCoin','Crypto','#23AF91'],['🌐','RedotPay','Wallet','#FF4500'],
  ];
  ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:12px;margin-bottom:40px">
    <?php foreach($gws as $g): ?>
    <div style="background:var(--card2);border:1px solid var(--border);border-radius:14px;padding:16px 10px;text-align:center;transition:.3s;cursor:default"
         onmouseover="this.style.borderColor='rgba(255,215,0,.3)';this.style.transform='translateY(-2px)'"
         onmouseout="this.style.borderColor='var(--border)';this.style.transform='translateY(0)'">
      <div style="font-size:1.7rem;margin-bottom:7px"><?=$g[0]?></div>
      <div style="font-size:.72rem;font-weight:700;color:#ccc;margin-bottom:3px">
        <span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#4CAF50;margin-<?=$ar?'left':'right'?>:4px;vertical-align:middle"></span>
        <?=$g[1]?>
      </div>
      <div style="font-size:.62rem;color:var(--muted)"><?=$g[2]?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <!-- بانر إحصاء -->
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:14px">
    <?php $gstats=[['847','Payment Gateways',($ar?'بوابة دفع':'')],['100','Banks',($ar?'بنك':'')],
      ['196','Countries',($ar?'دولة':'')],['50+','Digital Currencies',($ar?'عملة رقمية':'')]];
    foreach($gstats as $s): ?>
    <div style="background:rgba(255,215,0,.04);border:1px solid rgba(255,215,0,.1);border-radius:14px;padding:18px;text-align:center">
      <div style="font-size:1.4rem;font-weight:900;color:var(--gold)"><?=$s[0]?></div>
      <div style="font-size:.7rem;color:var(--muted);margin-top:4px"><?=$ar?$s[2]:$s[1]?></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
</div>
<!-- ═══ SECTION 5: رجال الأعمال ═══ -->
<div class="sec" id="business">
  <div class="sec-hd">
    <div class="sec-tag"><?=$ar?'خدمات رجال الأعمال':'Business Services'?></div>
    <h2><?=$ar?'حلول مالية لـ <span>رجال الأعمال</span>':'Financial Solutions for <span>Business Leaders</span>'?></h2>
    <div class="divider"></div>
    <p><?=$ar?'سيارات فاخرة وألماس ومجوهرات — معاملات دولية آمنة وفورية':'Luxury cars and diamonds & jewelry — secure and instant international transactions'?></p>
  </div>
  <div class="grid3">
    <div class="card" style="--cl:#FFD700"><div class="cline"></div>
      <span class="ico">🚗</span>
      <h3><?=$ar?'السيارات الفاخرة':'Luxury Cars'?></h3>
      <p><?=$ar?'دفع ثمن السيارات الفاخرة والمركبات الثمينة دولياً — ضمانات دفع، حجز مبالغ، وتأكيد الصفقة قبل الشحن.':'Pay for luxury and premium vehicles internationally — payment guarantees, fund holds, deal confirmation before shipment.'?></p>
      <div class="tags"><span class="tag">Escrow</span><span class="tag">Hold</span><span class="tag">Verify</span></div>
    </div>
    <div class="card" style="--cl:#E8D5B7"><div class="cline"></div>
      <span class="ico">💎</span>
      <h3><?=$ar?'الألماس والمجوهرات':'Diamonds & Jewelry'?></h3>
      <p><?=$ar?'صفقات الألماس والمجوهرات الفاخرة — تحقق من الأصالة، دفع آمن، وتسوية دولية مضمونة للصفقات متعددة الملايين.':'Diamond and luxury jewelry deals — authenticity verification, secure payment, guaranteed international settlement for multi-million deals.'?></p>
      <div class="tags"><span class="tag">Verified</span><span class="tag">Escrow</span><span class="tag">Global</span></div>
    </div>
    <div class="card" style="--cl:#8B5CF6"><div class="cline"></div>
      <span class="ico">🏢</span>
      <h3><?=$ar?'الشركات والمؤسسات':'Corporate & Institutions'?></h3>
      <p><?=$ar?'حلول مالية مؤسسية شاملة — رواتب دولية، مدفوعات موردين، تحويل بنكي مؤسسي، وإدارة مالية متكاملة.':'Comprehensive corporate solutions — international payroll, vendor payments, institutional transfers, integrated financial management.'?></p>
      <div class="tags"><span class="tag">Payroll</span><span class="tag">B2B</span><span class="tag">SWIFT</span></div>
    </div>
  </div>
</div>
<!-- ═══ SECTION 6: جميع الخدمات ═══ -->
<div class="sec-full">
<div class="sec" id="services">
  <div class="sec-hd">
    <div class="sec-tag"><?=$ar?'جميع الخدمات':'All Services'?></div>
    <h2><?=$ar?'منصة <span>واحدة</span> — كل ما تحتاجه':'<span>One</span> Platform — Everything You Need'?></h2>
    <div class="divider"></div>
    <p><?=$ar?'مدفوعات فورية، تحويل دولي، كريبتو، OTC، خيري — كل شيء':'Instant payments, international transfer, crypto, OTC, charity — everything'?></p>
  </div>
  <?php
  $services=[
    ['💳',$ar?'بطاقات ائتمان':'Credit Cards',$ar?'Visa/MC/Amex':'Visa/MC/Amex'],
    ['🅿️',$ar?'محافظ رقمية':'Digital Wallets',$ar?'PayPal/RedotPay':'PayPal/RedotPay'],
    ['💼',$ar?'تحويل بنكي':'Bank Transfer',$ar?'Wise/SWIFT/SEPA':'Wise/SWIFT/SEPA'],
    ['🟡',$ar?'كريبتو':'Crypto',$ar?'Binance/Gate.io':'Binance/Gate.io'],
    ['🔄',$ar?'OTC تداول':'OTC Trading',$ar?'صفقات كبيرة':'Large Deals'],
    ['🚀',$ar?'On Ramp':'On Ramp',$ar?'فيات → كريبتو':'Fiat → Crypto'],
    ['💸',$ar?'Off Ramp':'Off Ramp',$ar?'كريبتو → فيات':'Crypto → Fiat'],
    ['💎',$ar?'ألماس':'Diamonds',$ar?'تحقق/دفع':'Verify/Pay'],
    ['🚗',$ar?'سيارات فاخرة':'Luxury Cars',$ar?'ضمان دفع':'Payment Guarantee'],
    ['🤲',$ar?'جمعيات خيرية':'Charities',$ar?'تبرعات':'Donations'],
    ['🏠',$ar?'إيجار وسكن':'Rent & Housing',$ar?'فواتير':'Bills'],
    ['📦',$ar?'تجارة صغيرة':'Small Business',$ar?'QR/Pay Link':'QR/Pay Link'],
    ['🎓',$ar?'تعليم':'Education',$ar?'منح دراسية':'Scholarships'],
    ['🏦',$ar?'بنوك':'Banks',$ar?'SWIFT/ACH/SEPA':'SWIFT/ACH/SEPA'],
    ['🔒',$ar?'دفع آمن':'Secure Pay',$ar?'مشفر':'Encrypted'],
  ];
  ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:14px">
    <?php foreach($services as $s): ?>
    <div style="background:var(--card);border:1px solid var(--border);border-radius:16px;padding:20px 16px;text-align:center;transition:.3s"
         onmouseover="this.style.borderColor='rgba(255,215,0,.25)';this.style.transform='translateY(-3px)'"
         onmouseout="this.style.borderColor='var(--border)';this.style.transform='translateY(0)'">
      <div style="font-size:2rem;margin-bottom:10px"><?=$s[0]?></div>
      <div style="font-size:.82rem;font-weight:800;color:#e0e0e0;margin-bottom:4px"><?=$s[1]?></div>
      <div style="font-size:.68rem;color:var(--muted)"><?=$s[2]?></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
</div>
<!-- ═══ SECTION 7: خيري ═══ -->
<div class="sec" id="charity">
  <div class="sec-hd">
    <div class="sec-tag"><?=$ar?'المجتمع والإنسانية':'Community & Humanity'?></div>
    <h2><?=$ar?'الخير والمعيشة — <span>حلول مجتمعية</span>':'Charity & Living — <span>Community Solutions</span>'?></h2>
    <div class="divider"></div>
    <p><?=$ar?'نساند الجمعيات الخيرية والأفراد في استقبال التبرعات ومعالجة المدفوعات اليومية':'We support charities and individuals in receiving donations and processing daily payments'?></p>
  </div>
  <div class="grid3">
    <div class="card" style="--cl:#10B981"><div class="cline"></div>
      <span class="ico">🤲</span>
      <h3><?=$ar?'الجمعيات الخيرية':'Charities & NGOs'?></h3>
      <p><?=$ar?'منصة تبرع كاملة — استقبال تبرعات من 150+ دولة بأي عملة، إيصالات آلية، وتقارير شفافية فورية.':'Full donation platform — receive from 150+ countries in any currency, automated receipts, instant transparency reports.'?></p>
      <div class="tags"><span class="tag">NGO</span><span class="tag">150+ Currencies</span></div>
    </div>
    <div class="card" style="--cl:#F59E0B"><div class="cline"></div>
      <span class="ico">🍽️</span>
      <h3><?=$ar?'مساعدات المعيشة':'Living Aid'?></h3>
      <p><?=$ar?'توزيع مساعدات مالية مباشرة للأسر المحتاجة — تحويل فوري، رسوم صفر، تتبع كامل.':'Direct aid to families in need — instant transfer, zero fees, full tracking.'?></p>
      <div class="tags"><span class="tag">Instant</span><span class="tag">Zero Fee</span></div>
    </div>
    <div class="card" style="--cl:#EC4899"><div class="cline"></div>
      <span class="ico">🏠</span>
      <h3><?=$ar?'الإيجار والفواتير':'Rent & Bills'?></h3>
      <p><?=$ar?'دفع إيجارات وفواتير يومية — كهرباء، ماء، إنترنت — بأي طريقة دفع مع تذكير تلقائي.':'Pay rent and daily bills — electricity, water, internet — any payment method with auto-reminders.'?></p>
      <div class="tags"><span class="tag">Rent</span><span class="tag">Bills</span></div>
    </div>
    <div class="card" style="--cl:#3B82F6"><div class="cline"></div>
      <span class="ico">🏥</span>
      <h3><?=$ar?'صحة وتعليم':'Health & Education'?></h3>
      <p><?=$ar?'تمويل جماعي للمشاريع الصحية والتعليمية — جمع التبرعات بشفافية مع لوحة تحكم للمانحين.':'Crowdfunding for health and education — transparent with donor dashboard.'?></p>
      <div class="tags"><span class="tag">Crowdfunding</span><span class="tag">Dashboard</span></div>
    </div>
    <div class="card" style="--cl:#8B5CF6"><div class="cline"></div>
      <span class="ico">📦</span>
      <h3><?=$ar?'التجارة الصغيرة':'Small Business'?></h3>
      <p><?=$ar?'بوابة دفع للتجار الصغار — رابط دفع فوري، QR Code، وتسوية يومية مباشرة.':'Payment gateway for small merchants — instant pay link, QR, daily settlement.'?></p>
      <div class="tags"><span class="tag">QR</span><span class="tag">Pay Link</span></div>
    </div>
    <div class="card" style="--cl:#06B6D4"><div class="cline"></div>
      <span class="ico">🎓</span>
      <h3><?=$ar?'المنح الدراسية':'Scholarships'?></h3>
      <p><?=$ar?'تحويل منح دراسية مباشرة للطلاب في أي دولة عبر USDT أو Wise أو PayPal.':'Transfer scholarships directly to students anywhere via USDT, Wise, or PayPal.'?></p>
      <div class="tags"><span class="tag">Global</span><span class="tag">USDT</span><span class="tag">Wise</span></div>
    </div>
  </div>
</div>
<!-- ═══ SECTION 8: تسجيل / دخول ═══ -->
<div class="sec-full">
<div class="sec" id="auth" style="max-width:900px">
  <div class="sec-hd">
    <div class="sec-tag"><?=$ar?'الوصول للمنصة':'Platform Access'?></div>
    <h2><?=$ar?'ابدأ رحلتك مع <span>DI PARMA</span>':'Start Your Journey with <span>DI PARMA</span>'?></h2>
    <div class="divider"></div>
    <p><?=$ar?'سجّل حسابك أو ادخل لحسابك الحالي — الوصول يتطلب موافقة الإدارة لضمان الأمان':'Register or login — access requires admin approval to ensure security'?></p>
  </div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;max-width:700px;margin:0 auto">
    <!-- تسجيل -->
    <div style="background:linear-gradient(135deg,rgba(255,215,0,.08),rgba(255,183,0,.04));
      border:1px solid rgba(255,215,0,.25);border-radius:24px;padding:36px 28px;text-align:center">
      <div style="font-size:2.5rem;margin-bottom:16px">🚀</div>
      <h3 style="font-size:1.15rem;font-weight:900;margin-bottom:10px;color:var(--gold)"><?=$ar?'إنشاء حساب':'Create Account'?></h3>
      <p style="font-size:.82rem;color:var(--muted);line-height:1.7;margin-bottom:24px">
        <?=$ar?'أدخل بياناتك ووثّق هويتك — سيتم مراجعة طلبك والموافقة عليه من قبل الإدارة قبل تفعيل حسابك.':
        'Enter your details and verify identity — your request will be reviewed and approved by admin before account activation.'?>
      </p>
      <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:20px">
        <div style="display:flex;align-items:center;gap:8px;font-size:.78rem;color:#ccc">
          <i class="fas fa-check" style="color:#10B981;width:16px"></i>
          <?=$ar?'تعبئة بيانات كاملة':'Fill complete details'?>
        </div>
        <div style="display:flex;align-items:center;gap:8px;font-size:.78rem;color:#ccc">
          <i class="fas fa-check" style="color:#10B981;width:16px"></i>
          <?=$ar?'رفع مستندات التوثيق':'Upload verification documents'?>
        </div>
        <div style="display:flex;align-items:center;gap:8px;font-size:.78rem;color:#ccc">
          <i class="fas fa-check" style="color:#10B981;width:16px"></i>
          <?=$ar?'انتظار موافقة الإدارة':'Wait for admin approval'?>
        </div>
      </div>
      <a href="/register.php" style="display:block;background:linear-gradient(135deg,var(--gold),var(--gold2));
        color:#000;padding:14px;border-radius:14px;font-weight:800;font-size:.9rem;transition:.2s"
        onmouseover="this.style.opacity='.9'" onmouseout="this.style.opacity='1'">
        <i class="fas fa-user-plus"></i> <?=$ar?'إنشاء حساب جديد':'Create New Account'?>
      </a>
    </div>
    <!-- دخول -->
    <div style="background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:24px;padding:36px 28px;text-align:center">
      <div style="font-size:2.5rem;margin-bottom:16px">🔐</div>
      <h3 style="font-size:1.15rem;font-weight:900;margin-bottom:10px"><?=$ar?'تسجيل الدخول':'Login'?></h3>
      <p style="font-size:.82rem;color:var(--muted);line-height:1.7;margin-bottom:24px">
        <?=$ar?'لديك حساب مفعّل؟ ادخل بياناتك للوصول إلى لوحة التحكم الكاملة وجميع الخدمات.':
        'Have an active account? Enter your credentials to access the full dashboard and all services.'?>
      </p>
      <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:20px">
        <div style="display:flex;align-items:center;gap:8px;font-size:.78rem;color:#ccc">
          <i class="fas fa-check" style="color:var(--blue);width:16px"></i>
          <?=$ar?'لوحة تحكم كاملة':'Full dashboard'?>
        </div>
        <div style="display:flex;align-items:center;gap:8px;font-size:.78rem;color:#ccc">
          <i class="fas fa-check" style="color:var(--blue);width:16px"></i>
          <?=$ar?'جميع الخدمات متاحة':'All services available'?>
        </div>
        <div style="display:flex;align-items:center;gap:8px;font-size:.78rem;color:#ccc">
          <i class="fas fa-check" style="color:var(--blue);width:16px"></i>
          <?=$ar?'تاريخ معاملات كامل':'Full transaction history'?>
        </div>
      </div>
      <a href="/login.php" style="display:block;background:rgba(59,130,246,.15);
        border:1px solid rgba(59,130,246,.4);color:#93C5FD;padding:14px;border-radius:14px;
        font-weight:800;font-size:.9rem;transition:.2s"
        onmouseover="this.style.background='rgba(59,130,246,.25)'" onmouseout="this.style.background='rgba(59,130,246,.15)'">
        <i class="fas fa-sign-in-alt"></i> <?=$ar?'دخول للحساب':'Login to Account'?>
      </a>
    </div>
  </div>
</div>
</div>
<!-- FOOTER -->
<footer style="background:#020609;border-top:1px solid var(--border);padding:56px 32px 32px">
  <div style="max-width:1280px;margin:0 auto">
    <div style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:40px;margin-bottom:48px">
      <div>
        <div style="color:var(--gold);font-weight:900;font-size:1.2rem;margin-bottom:14px">
          <i class="fas fa-coins"></i> DI PARMA
        </div>
        <p style="color:var(--muted);font-size:.82rem;line-height:1.8;max-width:280px">
          <?=$ar?'منصة الدفع الشاملة — تحويل بنكي، كريبتو، OTC، بوابات دفع متعددة في مكان واحد.':
          'Universal payment platform — bank transfer, crypto, OTC, multiple gateways in one place.'?>
        </p>
        <div style="display:flex;gap:10px;margin-top:16px">
          <?php foreach(['fab fa-twitter','fab fa-telegram','fab fa-linkedin','fab fa-instagram'] as $ic): ?>
          <a href="#" style="width:36px;height:36px;background:rgba(255,215,0,.08);border:1px solid rgba(255,215,0,.15);
            border-radius:50%;display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:.8rem;transition:.2s"
            onmouseover="this.style.color='var(--gold)';this.style.borderColor='rgba(255,215,0,.4)'"
            onmouseout="this.style.color='var(--muted)';this.style.borderColor='rgba(255,215,0,.15)'">
            <i class="<?=$ic?>"></i>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
      <div>
        <div style="font-weight:800;margin-bottom:14px;font-size:.85rem"><?=$ar?'الخدمات':'Services'?></div>
        <?php $fl1=$ar?['OTC','On Ramp','Off Ramp','تحويل بنكي','كريبتو']:['OTC','On Ramp','Off Ramp','Bank Transfer','Crypto'];
        foreach($fl1 as $l): ?>
        <div style="margin-bottom:8px"><a href="#" style="color:var(--muted);font-size:.8rem;transition:.2s"
          onmouseover="this.style.color='var(--gold)'" onmouseout="this.style.color='var(--muted)'"><?=$l?></a></div>
        <?php endforeach; ?>
      </div>
      <div>
        <div style="font-weight:800;margin-bottom:14px;font-size:.85rem"><?=$ar?'قطاعات':'Sectors'?></div>
        <?php $fl2=$ar?['سيارات','ألماس','خيري']:['Cars','Diamonds','Charity'];
        foreach($fl2 as $l): ?>
        <div style="margin-bottom:8px"><a href="#" style="color:var(--muted);font-size:.8rem;transition:.2s"
          onmouseover="this.style.color='var(--gold)'" onmouseout="this.style.color='var(--muted)'"><?=$l?></a></div>
        <?php endforeach; ?>
      </div>
      <div>
        <div style="font-weight:800;margin-bottom:14px;font-size:.85rem"><?=$ar?'الحساب':'Account'?></div>
        <div style="margin-bottom:8px"><a href="/register.php" style="color:var(--muted);font-size:.8rem;transition:.2s"
          onmouseover="this.style.color='var(--gold)'" onmouseout="this.style.color='var(--muted)'"><?=$ar?'إنشاء حساب':'Register'?></a></div>
        <div style="margin-bottom:8px"><a href="/login.php" style="color:var(--muted);font-size:.8rem;transition:.2s"
          onmouseover="this.style.color='var(--gold)'" onmouseout="this.style.color='var(--muted)'"><?=$ar?'تسجيل الدخول':'Login'?></a></div>
        <div style="margin-top:20px">
          <div style="font-size:.68rem;color:var(--muted);margin-bottom:8px">CERTIFIED</div>
          <div style="font-size:.7rem;color:#888;line-height:1.8">ISO/IEC 27001<br>PCI DSS Level 1</div>
        </div>
      </div>
    </div>
    <div style="border-top:1px solid var(--border);padding-top:24px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
      <div style="font-size:.75rem;color:var(--muted)">© 2025 DI PARMA. <?=$ar?'جميع الحقوق محفوظة':'All rights reserved'?></div>
      <div style="display:flex;gap:16px">
        <a href="#" style="font-size:.72rem;color:var(--muted)"><?=$ar?'الخصوصية':'Privacy'?></a>
        <a href="#" style="font-size:.72rem;color:var(--muted)"><?=$ar?'الشروط':'Terms'?></a>
        <a href="#" style="font-size:.72rem;color:var(--muted)"><?=$ar?'الأمان':'Security'?></a>
      </div>
    </div>
  </div>
</footer>

<!-- SCRIPTS -->
<script>
function setLang(l){document.cookie='di_parma_lang='+l+';path=/;max-age=31536000';window.location.href=window.location.pathname+'?lang='+encodeURIComponent(l)}

// Smooth scroll + nav highlight
document.querySelectorAll('a[href^="#"]').forEach(a=>{
  a.addEventListener('click',e=>{
    const t=document.querySelector(a.getAttribute('href'));
    if(t){e.preventDefault();t.scrollIntoView({behavior:'smooth',block:'start'})}
  })
})

// Scroll reveal
const obs=new IntersectionObserver(entries=>{
  entries.forEach(e=>{if(e.isIntersecting){e.target.style.opacity='1';e.target.style.transform='translateY(0)'}})
},{threshold:.1})
document.querySelectorAll('.card,.sec-hd,.two-col').forEach(el=>{
  el.style.opacity='0';el.style.transform='translateY(24px)';
  el.style.transition='opacity .6s ease,transform .6s ease';obs.observe(el)
})

// Counter animation
function animateCounter(el,target,suffix=''){
  let start=0;const dur=2000;const step=dur/60;
  const inc=target/60;
  const t=setInterval(()=>{
    start=Math.min(start+inc,target);
    el.textContent=Math.floor(start).toLocaleString()+suffix;
    if(start>=target)clearInterval(t)
  },step)
}
const cobs=new IntersectionObserver(entries=>{
  entries.forEach(e=>{
    if(e.isIntersecting&&!e.target.dataset.animated){
      e.target.dataset.animated='1';
      const v=e.target.dataset.val;const s=e.target.dataset.suffix||'';
      if(v)animateCounter(e.target,+v,s)
    }
  })
},{threshold:.5})
document.querySelectorAll('.hs-n').forEach(el=>{
  const txt=el.textContent;
  const m=txt.match(/(\d+)/);
  if(m){el.dataset.val=m[1];el.dataset.suffix=txt.replace(m[1],'');cobs.observe(el)}
})
</script>
</body>
</html>
