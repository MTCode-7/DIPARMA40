<?php
/**
 * DI PARMA | Diamond Catalog — كتالوج الألماس الفاخر
 * صفحة عامة — لا تحتاج تسجيل دخول
 */

// بدون auth — صفحة عامة
if (session_status() === PHP_SESSION_NONE) session_start();

define('SITE_URL', 'https://diparmas.com');

$currentLang = isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'ar' ? 'ar' : 'en';
$isAr = $currentLang === 'ar';
$pageDir = $isAr ? 'rtl' : 'ltr';

// المنتجات — مجموعتان كل مجموعة 5 منتجات
$products = [
    // المجموعة الأولى
    [
        'id'      => 1,
        'name_ar' => 'ألماس فاخر — فئة A',
        'name_en' => 'Luxury Diamond — Grade A',
        'price'   => 100000,
        'qty'     => 1,
        'icon'    => '💎',
        'color'   => '#42A5F5',
        'badge'   => $isAr ? 'قطعة واحدة فقط' : 'Only 1 Piece',
        'group'   => 1,
    ],
    [
        'id'      => 2,
        'name_ar' => 'ألماس ملكي — فئة B',
        'name_en' => 'Royal Diamond — Grade B',
        'price'   => 500000,
        'qty'     => 1,
        'icon'    => '💎',
        'color'   => '#7E57C2',
        'badge'   => $isAr ? 'متاح' : 'Available',
        'group'   => 1,
    ],
    [
        'id'      => 3,
        'name_ar' => 'ألماس إمبراطوري',
        'name_en' => 'Imperial Diamond',
        'price'   => 1000000,
        'qty'     => 1,
        'icon'    => '💎',
        'color'   => '#EF5350',
        'badge'   => $isAr ? 'قطعة واحدة فقط' : 'Only 1 Piece',
        'group'   => 1,
    ],
    [
        'id'      => 4,
        'name_ar' => 'ألماس نادر',
        'name_en' => 'Rare Diamond',
        'price'   => 2000000,
        'qty'     => 1,
        'icon'    => '💎',
        'color'   => '#FFD700',
        'badge'   => $isAr ? 'قطعة واحدة فقط' : 'Only 1 Piece',
        'group'   => 1,
    ],
    [
        'id'      => 5,
        'name_ar' => 'ألماس أسطوري',
        'name_en' => 'Legendary Diamond',
        'price'   => 4000000,
        'qty'     => 1,
        'icon'    => '💎',
        'color'   => '#FF7043',
        'badge'   => $isAr ? 'قطعة واحدة فقط' : 'Only 1 Piece',
        'group'   => 1,
    ],
    // المجموعة الثانية — الأطقم
    [
        'id'      => 6,
        'name_ar' => 'طقم سوليتير فاخر',
        'name_en' => 'Luxury Solitaire Set',
        'brand'   => 'Graff',
        'carats'  => '10',
        'cut'     => 'Round Brilliant',
        'desc_ar' => 'طقم سوليتير فخم من Graff، حجر مركزي 10 قيراط، لمعة استثنائية وبياض فائق',
        'desc_en' => 'Exceptional Graff solitaire set, 10ct center stone, extraordinary brilliance',
        'price'   => 10000000,
        'qty'     => 1,
        'icon'    => '💍',
        'color'   => '#42A5F5',
        'badge'   => 'Graff — 10ct',
        'group'   => 2,
    ],
    [
        'id'      => 7,
        'name_ar' => 'طقم كمثرى فاخر',
        'name_en' => 'Pear Shape Luxury Set',
        'brand'   => 'Cartier',
        'carats'  => '20',
        'cut'     => 'Pear Shape',
        'desc_ar' => 'طقم كمثرى ملكي من Cartier، 20 قيراط، قطع كمثرى احترافية ونقاء استثنائي D/IF',
        'desc_en' => 'Royal Cartier pear shape set, 20ct, exceptional D/IF clarity',
        'price'   => 12000000,
        'qty'     => 1,
        'icon'    => '💍',
        'color'   => '#AB47BC',
        'badge'   => 'Cartier — 20ct',
        'group'   => 2,
    ],
    [
        'id'      => 8,
        'name_ar' => 'طقم إميرالد ملكي',
        'name_en' => 'Emerald Royal Set',
        'brand'   => 'Tiffany & Co.',
        'carats'  => '30',
        'cut'     => 'Emerald Cut',
        'desc_ar' => 'طقم إميرالد من Tiffany & Co.، 30 قيراط، قطع إميرالد كلاسيكي، شفافية نادرة',
        'desc_en' => 'Tiffany & Co. emerald cut set, 30ct, rare transparency and classic elegance',
        'price'   => 15000000,
        'qty'     => 1,
        'icon'    => '💍',
        'color'   => '#26A69A',
        'badge'   => 'Tiffany — 30ct',
        'group'   => 2,
    ],
    [
        'id'      => 9,
        'name_ar' => 'الطقم الملكي',
        'name_en' => 'Royal Masterpiece',
        'brand'   => 'Bulgari',
        'carats'  => '40',
        'cut'     => 'Cushion Cut',
        'desc_ar' => 'تحفة Bulgari الملكية، 40 قيراط، قطع كوشن فاخر، مزيج من الفن والندرة المطلقة',
        'desc_en' => 'Bulgari Royal Masterpiece, 40ct cushion cut, art & absolute rarity combined',
        'price'   => 20000000,
        'qty'     => 1,
        'icon'    => '👑',
        'color'   => '#FFD700',
        'badge'   => 'Bulgari — 40ct',
        'group'   => 2,
    ],
    [
        'id'      => 10,
        'name_ar' => 'طقم ألماس أصفر فاخر',
        'name_en' => 'Fancy Yellow Diamond Set',
        'brand'   => 'Graff',
        'carats'  => '50',
        'cut'     => 'Radiant Cut',
        'desc_ar' => 'طقم ألماس أصفر فاخر من Graff، 50 قيراط، لون Fancy Intense Yellow، نادر جداً',
        'desc_en' => 'Graff Fancy Intense Yellow diamond set, 50ct, extremely rare radiant cut',
        'price'   => 14000000,
        'qty'     => 1,
        'icon'    => '💛',
        'color'   => '#FFA726',
        'badge'   => 'Graff — 50ct Yellow',
        'group'   => 2,
    ],
];
$g1 = array_filter($products, fn($p) => $p['group'] === 1);
$g2 = array_filter($products, fn($p) => $p['group'] === 2);
?>
<!DOCTYPE html>
<html lang="<?= $currentLang ?>" dir="<?= $pageDir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>DI PARMA | <?= $isAr ? 'كتالوج الألماس الفاخر' : 'Luxury Diamond Catalog' ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--gold:#FFD700;--gold2:#ffb700;--bg:#050810;--card:#0a0e1a;--border:rgba(255,215,0,.15);--text:#f0f0f0;--muted:#888}
body{font-family:'Cairo',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;overflow-x:hidden}

/* Hero */
.hero{text-align:center;padding:80px 20px 40px;background:radial-gradient(ellipse at center top,rgba(255,215,0,.08) 0%,transparent 70%)}
.hero-logo{color:var(--gold);font-size:1rem;font-weight:800;margin-bottom:16px;letter-spacing:4px}
.hero h1{font-size:clamp(2rem,5vw,3.5rem);font-weight:800;background:linear-gradient(135deg,#fff 30%,var(--gold) 70%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;margin-bottom:12px}
.hero p{color:var(--muted);font-size:1rem;max-width:600px;margin:0 auto 30px}
.hero-badges{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-bottom:40px}
.hero-badge{background:rgba(255,215,0,.08);border:1px solid rgba(255,215,0,.25);border-radius:20px;padding:6px 16px;font-size:.78rem;color:var(--gold)}

/* Divider */
.section-title{text-align:center;margin:60px 0 30px}
.section-title h2{font-size:1.6rem;font-weight:800;color:var(--gold);margin-bottom:8px}
.section-title p{color:var(--muted);font-size:.88rem}
.divider{width:60px;height:3px;background:linear-gradient(90deg,transparent,var(--gold),transparent);margin:12px auto}

/* Grid */
.products-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:20px;padding:0 20px;max-width:1300px;margin:0 auto}

/* Card */
.product-card{background:var(--card);border:1.5px solid var(--border);border-radius:20px;padding:28px 22px;
    transition:.3s;cursor:pointer;position:relative;overflow:hidden}
.product-card:hover{transform:translateY(-4px);box-shadow:0 20px 50px rgba(255,215,0,.1)}
.product-card::before{content:'';position:absolute;inset:0;background:radial-gradient(circle at top right,var(--card-color,rgba(255,215,0,.04)),transparent 70%);pointer-events:none}
.card-badge{display:inline-block;padding:3px 12px;border-radius:20px;font-size:.72rem;font-weight:700;margin-bottom:14px;background:rgba(255,215,0,.1);color:var(--gold);border:1px solid rgba(255,215,0,.2)}
.card-icon{font-size:2.5rem;margin-bottom:12px;display:block}
.card-name{font-size:1.05rem;font-weight:700;margin-bottom:6px;color:#fff}
.card-price{font-size:1.5rem;font-weight:800;color:var(--gold);margin-bottom:4px}
.card-price-usd{font-size:.78rem;color:var(--muted)}
.card-qty{font-size:.78rem;color:#aaa;margin:10px 0 16px;display:flex;align-items:center;gap:5px}
.qty-dot{width:8px;height:8px;border-radius:50%;background:#4CAF50;display:inline-block}
.btn-buy{width:100%;padding:12px;border:none;border-radius:12px;font-family:'Cairo',sans-serif;font-weight:700;font-size:.9rem;cursor:pointer;transition:.2s;background:linear-gradient(135deg,var(--gold),var(--gold2));color:#000}
.btn-buy:hover{opacity:.9;transform:scale(1.02)}

/* Footer */
footer{text-align:center;padding:40px 20px;color:var(--muted);font-size:.82rem;border-top:1px solid var(--border);margin-top:80px}
footer a{color:var(--gold);text-decoration:none}

/* Nav */
.topbar{background:rgba(0,0,0,.9);border-bottom:1px solid var(--border);padding:14px 24px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100}
.topbar-brand{color:var(--gold);font-weight:800;font-size:1rem;text-decoration:none}
.topbar-links{display:flex;gap:12px}
.topbar-links a{color:var(--muted);font-size:.82rem;text-decoration:none;padding:6px 12px;border-radius:8px;transition:.2s}
.topbar-links a:hover{background:rgba(255,215,0,.07);color:var(--gold)}

@media(max-width:600px){.products-grid{grid-template-columns:1fr}.hero h1{font-size:1.8rem}}
</style>
</head>
<body>
<!-- Nav -->
<nav class="topbar">
    <a href="index.php" class="topbar-brand"><i class="fas fa-coins"></i> DI PARMA</a>
    <div class="topbar-links">
        <a href="index.php"><i class="fas fa-home"></i> <?= $isAr ? 'الرئيسية' : 'Home' ?></a>
        <a href="checkout_router.php"><i class="fas fa-shopping-cart"></i> <?= $isAr ? 'الدفع' : 'Checkout' ?></a>
    </div>
</nav>

<!-- Hero -->
<div class="hero">
    <div class="hero-logo">💎 DI PARMA DIAMONDS</div>
    <h1><?= $isAr ? 'كتالوج الألماس الفاخر' : 'Luxury Diamond Catalog' ?></h1>
    <p><?= $isAr ? 'أندر وأجمل قطع الألماس في العالم — موثقة ومعتمدة' : 'The rarest and most exquisite diamonds in the world — certified & authenticated' ?></p>
    <div class="hero-badges">
        <span class="hero-badge"><i class="fas fa-shield-halved"></i> GIA Certified</span>
        <span class="hero-badge"><i class="fas fa-certificate"></i> ISO/IEC 27001</span>
        <span class="hero-badge"><i class="fas fa-lock"></i> PCI DSS Level 1</span>
        <span class="hero-badge"><i class="fas fa-globe"></i> <?= $isAr ? 'شحن عالمي' : 'Worldwide Shipping' ?></span>
    </div>
</div>

<!-- المجموعة الأولى -->
<div class="section-title">
    <h2>💎 <?= $isAr ? 'مجموعة الألماس الفردي' : 'Individual Diamond Collection' ?></h2>
    <div class="divider"></div>
    <p><?= $isAr ? 'قطع ألماس فردية نادرة من أعلى الدرجات' : 'Rare individual diamonds of the highest grades' ?></p>
</div>

<div class="products-grid">
<?php foreach ($g1 as $p): ?>
<div class="product-card" style="--card-color:<?= $p['color'] ?>22;border-color:<?= $p['color'] ?>44"
     onclick="buyProduct(<?= $p['id'] ?>, '<?= $isAr ? addslashes($p['name_ar']) : addslashes($p['name_en']) ?>', <?= $p['price'] ?>)">
    <span class="card-badge" style="background:<?= $p['color'] ?>18;color:<?= $p['color'] ?>;border-color:<?= $p['color'] ?>44">
        <?= htmlspecialchars($p['badge']) ?>
    </span>
    <span class="card-icon"><?= $p['icon'] ?></span>
    <div class="card-name"><?= $isAr ? htmlspecialchars($p['name_ar']) : htmlspecialchars($p['name_en']) ?></div>
    <div class="card-price" style="color:<?= $p['color'] ?>"><?= number_format($p['price']) ?> <span style="font-size:.9rem">USD</span></div>
    <div class="card-price-usd"><?= $isAr ? 'السعر شامل الضرائب' : 'Price inclusive of taxes' ?></div>
    <div class="card-qty"><span class="qty-dot"></span><?= $isAr ? 'متوفر: ' . $p['qty'] . ' قطعة' : 'Available: ' . $p['qty'] . ' piece' ?></div>
    <button class="btn-buy" style="background:linear-gradient(135deg,<?= $p['color'] ?>,<?= $p['color'] ?>aa)">
        <i class="fas fa-shopping-cart"></i> <?= $isAr ? 'اشتري الآن' : 'Buy Now' ?>
    </button>
</div>
<?php endforeach; ?>
</div>

<!-- المجموعة الثانية -->
<div class="section-title" style="margin-top:80px">
    <h2>👑 <?= $isAr ? 'كتالوج الأطقم الملكية' : 'Royal Diamond Sets Catalog' ?></h2>
    <div class="divider"></div>
    <p><?= $isAr ? 'أطقم ألماس ملكية فريدة من نوعها' : 'Unique royal diamond jewelry sets' ?></p>
</div>

<div class="products-grid">
<?php foreach ($g2 as $p): ?>
<div class="product-card" style="--card-color:<?= $p['color'] ?>22;border-color:<?= $p['color'] ?>44"
     onclick="buyProduct(<?= $p['id'] ?>, '<?= $isAr ? addslashes($p['name_ar']) : addslashes($p['name_en']) ?>', <?= $p['price'] ?>)">
    <span class="card-badge" style="background:<?= $p['color'] ?>18;color:<?= $p['color'] ?>;border-color:<?= $p['color'] ?>44">
        <?= htmlspecialchars($p['badge']) ?>
    </span>
    <span class="card-icon"><?= $p['icon'] ?></span>
    <div class="card-name"><?= $isAr ? htmlspecialchars($p['name_ar']) : htmlspecialchars($p['name_en']) ?></div>

    <!-- تفاصيل الطقم -->
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin:8px 0">
        <span style="background:rgba(255,255,255,.06);border-radius:6px;padding:3px 10px;font-size:.72rem;color:#ddd">
            <i class="fas fa-gem" style="color:<?= $p['color'] ?>"></i> <?= $p['carats'] ?>ct
        </span>
        <span style="background:rgba(255,255,255,.06);border-radius:6px;padding:3px 10px;font-size:.72rem;color:#ddd">
            <i class="fas fa-cut" style="color:<?= $p['color'] ?>"></i> <?= $p['cut'] ?>
        </span>
        <span style="background:rgba(255,255,255,.06);border-radius:6px;padding:3px 10px;font-size:.72rem;color:<?= $p['color'] ?>">
            <?= $p['brand'] ?>
        </span>
    </div>

    <p style="font-size:.78rem;color:#aaa;margin:6px 0 10px;line-height:1.5">
        <?= $isAr ? htmlspecialchars($p['desc_ar']) : htmlspecialchars($p['desc_en']) ?>
    </p>

    <div class="card-price" style="color:<?= $p['color'] ?>"><?= number_format($p['price']) ?> <span style="font-size:.9rem">USD</span></div>
    <div class="card-price-usd"><?= $isAr ? 'السعر شامل الضرائب' : 'Price inclusive of taxes' ?></div>
    <div class="card-qty"><span class="qty-dot"></span><?= $isAr ? 'متوفر: ' . $p['qty'] . ' طقم' : 'Available: ' . $p['qty'] . ' set' ?></div>
    <button class="btn-buy" style="background:linear-gradient(135deg,<?= $p['color'] ?>,<?= $p['color'] ?>aa)">
        <i class="fas fa-crown"></i> <?= $isAr ? 'اشتري الآن' : 'Buy Now' ?>
    </button>
</div>
<?php endforeach; ?>
</div>

<footer>
    <p>© 2026 DI PARMA — <?= $isAr ? 'جميع الحقوق محفوظة' : 'All Rights Reserved' ?></p>
    <p style="margin-top:8px">
        <a href="index.php"><?= $isAr ? 'الرئيسية' : 'Home' ?></a> &nbsp;|&nbsp;
        <a href="checkout_router.php"><?= $isAr ? 'الدفع' : 'Checkout' ?></a>
    </p>
</footer>

<script>
function buyProduct(id, name, price) {
    // توجيه لـ checkout مع بيانات المنتج
    var params = new URLSearchParams({
        product_id:   id,
        product_name: name,
        amount:       price,
        currency:     'USD',
        type:         'diamond'
    });
    window.location.href = 'checkout_router.php?' + params.toString();
}
</script>
</body>
</html>
