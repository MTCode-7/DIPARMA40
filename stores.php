<?php
session_start();
if (isset($_GET['lang']) && in_array($_GET['lang'], ['ar', 'en'], true)) {
    setcookie('di_parma_lang', $_GET['lang'], time() + 31536000, '/');
    $_COOKIE['di_parma_lang'] = $_GET['lang'];
}
$lang = isset($_GET['lang']) && in_array($_GET['lang'], ['ar', 'en'], true)
    ? $_GET['lang']
    : (isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'en' ? 'en' : 'ar');
$ar = $lang === 'ar';
$dir = $ar ? 'rtl' : 'ltr';

$stores = [
    'Louis Vuitton','Armani','Gucci','Chanel','Dior','Prada','Hermes','Rolex','Cartier','Tiffany & Co.',
    'Bottega Veneta','Saint Laurent','Balenciaga','Givenchy','Valentino','Versace','Burberry','Fendi','Celine','Loewe',
    'Tom Ford','Brunello Cucinelli','Moncler','Loro Piana','Ermenegildo Zegna','Dolce & Gabbana','Alexander McQueen','Maison Margiela','Lanvin','Chloe',
    'Miu Miu','Marc Jacobs','Ralph Lauren','Michael Kors','Coach','Kate Spade','Tory Burch','Jimmy Choo','Christian Louboutin','Manolo Blahnik',
    'Salvatore Ferragamo','Tod s','Hugo Boss','Paul Smith','Etro','Missoni','Marni','Dries Van Noten','Isabel Marant','Alaia',
    'Oscar de la Renta','Carolina Herrera','Elie Saab','Ralph & Russo','Zuhair Murad','Nina Ricci','Valextra','Moynat','Goyard','Delvaux',
    'Smythson','Globe-Trotter','Rimowa','Tumi','Samsonite Black Label','Montblanc','S.T. Dupont','Berluti','Churchs','John Lobb',
    'Breguet','Patek Philippe','Audemars Piguet','Vacheron Constantin','Omega','Cartier Watches','IWC Schaffhausen','Jaeger-LeCoultre','Panerai','Piaget',
    'Chopard','Van Cleef & Arpels','Bulgari','Graff','Harry Winston','Buccellati','David Yurman','Mikimoto','Chaumet','Boucheron',
    'Messika','De Beers','Georg Jensen','Pomellato','Damiani','Mikimoto Pearls','Tasaki','Repossi','Fred Paris','Dinh Van',
    'Lamborghini','Ferrari','Porsche','Bentley','Rolls-Royce','Aston Martin','McLaren','Maserati','Bugatti','Maybach',
    'Mercedes-AMG','BMW Alpina','Range Rover','Land Rover','Jaguar','Cadillac','Lincoln','Lexus','Infiniti','Genesis',
    'Tesla','Lucid Motors','Rivian','Polestar','Lotus Cars','Koenigsegg','Pagani','Rimac','Hennessey','Brabus',
    'Net-a-Porter','Farfetch','Mytheresa','Saks Fifth Avenue','Neiman Marcus','Nordstrom','Bloomingdale s','Harrods','Selfridges','Bergdorf Goodman',
    'Galeries Lafayette','Printemps','Le Bon Marche','10 Corso Como','MatchesFashion','Mr Porter','SSENSE','LuisaViaRoma','Ounass','Moda Operandi',
    'The Webster','Dover Street Market','Browns Fashion','Level Shoes','Lane Crawford','I.T','Joyce Boutique','Club 21','The Luxury Closet','Vestiaire Collective',
    'Apple','Microsoft','Sony','Bang & Olufsen','Bose','Dyson','Leica','Hasselblad','Nespresso','Bang & Olufsen Beoplay',
    'Baccarat','Christofle','Wedgwood','Waterford','Lladró','Meissen','Royal Copenhagen','Hermes Home','Fendi Casa','Armani Casa',
    'Ralph Lauren Home','Versace Home','Dolce & Gabbana Casa','Bottega Veneta Home','Lalique','Daum','Tom Dixon','Vitra','Poltrona Frau','Minotti',
    'Four Seasons','The Ritz-Carlton','St. Regis','Mandarin Oriental','Aman','Rosewood Hotels','Bulgari Hotels','Belmond','Six Senses','One&Only',
    'Waldorf Astoria','Park Hyatt','Capella Hotels','Jumeirah','Shangri-La','Edition Hotels','Montage Hotels','Oberoi Hotels','Cheval Blanc','Raffles Hotels',
];
$stores = array_slice($stores, 0, 200);

function storeInitials(string $name): string {
    $words = preg_split('/\s+/', trim($name));
    return count($words) > 1
        ? strtoupper(substr($words[0], 0, 1) . substr($words[1], 0, 1))
        : strtoupper(substr($name, 0, 2));
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $dir ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>DIPARMA | <?= $ar ? 'المتاجر الفاخرة' : 'Luxury Stores' ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box}body{margin:0;background:#040810;color:#f0f0f0;font-family:Cairo,Arial,sans-serif}.wrap{max-width:1280px;margin:auto;padding:28px 20px 50px}.top{display:flex;align-items:center;justify-content:space-between;gap:15px;flex-wrap:wrap;margin-bottom:36px}.brand{color:#ffd700;font-weight:900;font-size:1.2rem}.back{color:#ffd700;text-decoration:none;border:1px solid rgba(255,215,0,.3);padding:8px 15px;border-radius:10px;font-size:.8rem}.hero{text-align:center;margin:30px auto 35px;max-width:780px}.hero h1{font-size:clamp(1.8rem,4vw,3rem);margin:0 0 12px;color:#ffd700}.hero p{color:#aaa;line-height:1.8;font-size:.9rem}.notice{border:1px solid rgba(255,215,0,.25);background:rgba(255,215,0,.06);color:#e8d98a;padding:14px 18px;border-radius:12px;text-align:center;font-size:.8rem;margin-bottom:28px}.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:12px}.store{background:#080d1a;border:1px solid rgba(255,215,0,.12);border-radius:10px;padding:14px 13px;min-height:106px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;font-size:.82rem;font-weight:700;transition:.2s}.store:hover{border-color:#ffd700;transform:translateY(-2px)}.store-logo{width:48px;height:48px;border-radius:12px;border:1px solid rgba(255,215,0,.38);background:linear-gradient(145deg,#1d1a0b,#080d1a);display:flex;align-items:center;justify-content:center;color:#ffd700;font-family:Georgia,serif;font-size:1rem;font-weight:900;letter-spacing:1px;margin-bottom:9px;box-shadow:0 5px 18px rgba(0,0,0,.25)}.store-name{line-height:1.35}.count{text-align:center;color:#888;font-size:.75rem;margin-bottom:18px}@media(max-width:520px){.grid{grid-template-columns:repeat(2,minmax(0,1fr))}.store{font-size:.72rem;padding:12px 8px}.store-logo{width:42px;height:42px;font-size:.85rem}}
</style>
</head>
<body><main class="wrap">
<header class="top"><div class="brand">DIPARMA ULTIMATE GATEWAY</div><a class="back" href="landing.php"><?= $ar ? 'العودة للرئيسية' : 'Back Home' ?></a></header>
<section class="hero"><h1><?= $ar ? 'دعوة خاصة لأكبر الماركات العالمية' : 'Exclusive Invitation for the World\'s Biggest Brands' ?></h1><p><?= $ar ? '200 اسمًا عالميًا مرشحًا لقسم المتاجر الإلكترونية الفاخرة.' : '200 global names shortlisted for the luxury online stores section.' ?></p></section>
<div class="notice"><strong><?= $ar ? 'مكانكم محجوز لدينا — دعوة خاصة' : 'Your place is reserved with us — exclusive invitation' ?></strong><br><?= $ar ? 'الدعوة مخصصة لأكبر الماركات العالمية فقط. القبول النهائي يتطلب الاشتراك والتحقق من الملكية والإيراد السنوي الذي يتجاوز 100 مليون دولار، وتصدر الموافقة أو الإزالة من الإدارة فقط.' : 'This invitation is exclusively for the world\'s biggest brands. Final acceptance requires subscription, verified ownership, and annual revenue above $100 million. Approval and removal are controlled by administration.' ?></div>
<div class="count"><?= count($stores) ?> <?= $ar ? 'متجر وعلامة' : 'stores and brands' ?></div>
<section class="grid"><?php foreach ($stores as $store): ?><div class="store"><div class="store-logo" aria-label="<?= htmlspecialchars($store) ?> logo"><?= htmlspecialchars(storeInitials($store)) ?></div><span class="store-name"><?= htmlspecialchars($store) ?></span></div><?php endforeach; ?></section>
</main></body></html>
