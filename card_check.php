<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/gateways.php';

requireAdmin();

$currentLang = (isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'ar') ? 'ar' : 'en';
$pageDir     = $currentLang === 'en' ? 'ltr' : 'rtl';

// ── معالجة POST: فحص البطاقة ────────────────────────────
$result = null;
$error  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pan    = preg_replace('/\D/', '', trim($_POST['card_pan']    ?? ''));
    $expiry = trim($_POST['card_expiry'] ?? '');
    $cvv    = trim($_POST['card_cvv']    ?? '');
    $type   = strtoupper(trim($_POST['card_type_check'] ?? 'LIVE'));

    if (strlen($pan) < 13) {
        $error = $currentLang === 'en' ? 'Card number too short (min 13 digits).' : 'رقم البطاقة قصير جداً (13 رقم على الأقل).';
    } else {
        $bin6 = substr($pan, 0, 6);

        // ── BIN Lookup ──────────────────────────────────────
        $binData = [];
        $binUrl  = SITE_URL . '/api/bin_lookup.php?bin=' . $bin6;
        $binRaw  = @file_get_contents($binUrl);
        if ($binRaw) $binData = json_decode($binRaw, true) ?? [];

        // ── Luhn Check ──────────────────────────────────────
        function luhnCheck(string $num): bool {
            $sum = 0; $alt = false;
            for ($i = strlen($num) - 1; $i >= 0; $i--) {
                $d = (int)$num[$i];
                if ($alt) { $d *= 2; if ($d > 9) $d -= 9; }
                $sum += $d; $alt = !$alt;
            }
            return $sum % 10 === 0;
        }

        // ── Expiry parse ────────────────────────────────────
        $expiryValid = false;
        $expiryDate  = null;
        if (preg_match('/^(\d{2})\/(\d{2})$/', $expiry, $m)) {
            $month = (int)$m[1]; $year = (int)('20' . $m[2]);
            $expiryDate  = sprintf('%04d-%02d-01', $year, $month);
            $expiryValid = ($year > (int)date('Y')) ||
                           ($year === (int)date('Y') && $month >= (int)date('m'));
        }

        // ── نوع البطاقة من الرقم ────────────────────────────
        function detectScheme(string $pan): array {
            $p = substr($pan, 0, 4);
            if ($pan[0] === '4')                        return ['Visa',       'fab fa-cc-visa',       '#1a1f71'];
            if (preg_match('/^5[1-5]/', $pan))          return ['Mastercard', 'fab fa-cc-mastercard', '#eb001b'];
            if (preg_match('/^2(2[2-9]|[3-6]|7[01])/', $pan)) return ['Mastercard', 'fab fa-cc-mastercard', '#eb001b'];
            if (preg_match('/^3[47]/', $pan))           return ['Amex',       'fab fa-cc-amex',       '#007bc1'];
            if (preg_match('/^6(011|4[4-9]|5)/', $pan)) return ['Discover',  'fab fa-cc-discover',   '#ff6600'];
            if (preg_match('/^62/', $pan))              return ['UnionPay',   'fas fa-credit-card',   '#e21e28'];
            if (preg_match('/^9682/', $pan))            return ['Mada',       'fas fa-credit-card',   '#00843d'];
            return ['Unknown', 'fas fa-credit-card', '#888'];
        }

        [$scheme, $icon, $color] = detectScheme($pan);

        // ── تجميع النتيجة ────────────────────────────────────
        $result = [
            'pan_masked'   => str_repeat('●', strlen($pan) - 4) . substr($pan, -4),
            'pan_length'   => strlen($pan),
            'bin'          => $bin6,
            'scheme'       => $binData['scheme']       ?? $scheme,
            'brand'        => $binData['brand']        ?? $scheme,
            'type'         => $binData['type']         ?? 'credit',
            'bank'         => $binData['bank']         ?? null,
            'country'      => $binData['country']      ?? null,
            'country_name' => $binData['country_name'] ?? null,
            'prepaid'      => $binData['prepaid']      ?? false,
            'icon'         => $binData['icon']         ?? $icon,
            'color'        => $binData['color']        ?? $color,
            'luhn'         => luhnCheck($pan),
            'expiry_input' => $expiry,
            'expiry_valid' => $expiryValid,
            'expiry_date'  => $expiryDate,
            'cvv_present'  => !empty($cvv),
            'cvv_length'   => strlen($cvv),
            'card_type'    => $type,
            'source'       => $binData['source']       ?? 'local',
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="<?= $currentLang ?>" dir="<?= $pageDir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>DI PARMA | <?= $currentLang === 'en' ? 'Card Check' : 'فحص البطاقة' ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Cairo',sans-serif;background:#0a0f1e;color:#FFDFA0;min-height:100vh;padding:20px;}
.wrap{max-width:860px;margin:0 auto;}
.header{background:rgba(10,16,39,.94);border:1px solid rgba(255,215,0,.25);border-radius:16px;padding:22px 26px;margin-bottom:22px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;}
.header h1{font-size:1.5rem;background:linear-gradient(135deg,#FFE066,#FFD700);-webkit-background-clip:text;-webkit-text-fill-color:transparent;}
.btn{display:inline-flex;align-items:center;gap:8px;padding:10px 20px;background:linear-gradient(135deg,#FFD700,#B58E15);color:#000;border:none;border-radius:10px;text-decoration:none;font-weight:700;cursor:pointer;font-family:'Cairo',sans-serif;font-size:.9rem;}
.btn-out{background:transparent;border:1.5px solid rgba(255,215,0,.4);color:#FFD700;}
.card{background:rgba(10,16,39,.94);border:1px solid rgba(255,215,0,.2);border-radius:16px;padding:24px;margin-bottom:20px;}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.fg{background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:14px;}
.fg label{display:block;font-size:.75rem;color:#A8C5E0;font-weight:700;margin-bottom:7px;text-transform:uppercase;}
.fg input,.fg select{width:100%;background:rgba(0,0,0,.8);border:1.2px solid rgba(255,255,255,.1);border-radius:9px;padding:10px 12px;color:#E8F0FF;font-size:.9rem;font-family:'Cairo',sans-serif;}
.fg input:focus,.fg select:focus{outline:none;border-color:#FFD700;}
.span2{grid-column:span 2;}
.row{display:flex;align-items:center;gap:10px;padding:12px 0;border-bottom:1px solid rgba(255,215,0,.08);}
.row:last-child{border-bottom:none;}
.row .lbl{min-width:180px;font-size:.82rem;color:#aaa;}
.row .val{font-size:.9rem;font-weight:600;}
.badge{display:inline-block;padding:3px 12px;border-radius:999px;font-size:.8rem;font-weight:600;}
.ok{background:rgba(76,175,80,.2);color:#4CAF50;}
.warn{background:rgba(240,173,78,.2);color:#f0ad4e;}
.bad{background:rgba(217,83,79,.2);color:#d9534f;}
.card-visual{background:linear-gradient(135deg,#1a1f71,#0d47a1);border-radius:16px;padding:24px 28px;color:#fff;font-family:monospace;margin-bottom:20px;box-shadow:0 10px 40px rgba(0,0,0,.5);position:relative;overflow:hidden;}
.card-visual::before{content:'';position:absolute;inset:0;background:radial-gradient(circle at 80% 20%,rgba(255,215,0,.15),transparent 50%);}
.card-pan{font-size:1.4rem;letter-spacing:4px;margin:18px 0 10px;}
.card-info{display:flex;justify-content:space-between;font-size:.8rem;opacity:.85;}
.card-logo{position:absolute;top:20px;right:20px;font-size:2.2rem;}
.err{padding:14px;background:rgba(217,83,79,.1);border:1px solid rgba(217,83,79,.3);border-radius:10px;color:#EF9A9A;margin-bottom:16px;}
</style>
</head>
<body>
<div class="wrap">

<div class="header">
    <div>
        <h1><i class="fas fa-id-card"></i> <?= $currentLang === 'en' ? 'Card Check' : 'فحص البطاقة' ?></h1>
        <p style="color:#aaa;font-size:.85rem;margin-top:4px;"><?= $currentLang === 'en' ? 'Full card analysis: scheme, bank, issuer, balance type, validity' : 'تحليل شامل للبطاقة: الشبكة، البنك، المصدر، نوع الرصيد، الصلاحية' ?></p>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <a href="index.php" class="btn btn-out"><i class="fas fa-home"></i> <?= $currentLang === 'en' ? 'Home' : 'الرئيسية' ?></a>
        <a href="dashboard.php" class="btn btn-out"><i class="fas fa-chart-pie"></i> <?= $currentLang === 'en' ? 'Dashboard' : 'لوحة التحكم' ?></a>
    </div>
</div>

<?php if ($error): ?>
<div class="err"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- ── فورم الإدخال ── -->
<div class="card">
    <form method="POST">
        <div class="form-grid">
            <div class="fg span2">
                <label><i class="fas fa-credit-card"></i> <?= $currentLang === 'en' ? 'Card Number' : 'رقم البطاقة' ?></label>
                <input type="text" name="card_pan" id="cardPanCheck"
                       maxlength="19" placeholder="1234 5678 9012 3456"
                       value="<?= htmlspecialchars(isset($_POST['card_pan']) ? $_POST['card_pan'] : '') ?>"
                       oninput="fmtCard(this)" required>
                <div id="liveBinInfo" style="margin-top:10px;display:none;font-size:.85rem;color:#FFD700;"></div>
            </div>
            <div class="fg">
                <label><i class="fas fa-calendar-alt"></i> <?= $currentLang === 'en' ? 'Expiry Date' : 'تاريخ الانتهاء' ?></label>
                <input type="text" name="card_expiry" maxlength="5" placeholder="MM/YY"
                       value="<?= htmlspecialchars($_POST['card_expiry'] ?? '') ?>"
                       oninput="fmtExp(this)">
            </div>
            <div class="fg">
                <label><i class="fas fa-lock"></i> CVV <span style="color:#888;font-size:.8rem;">(<?= $currentLang === 'en' ? 'optional' : 'اختياري' ?>)</span></label>
                <input type="password" name="card_cvv" maxlength="4" placeholder="123"
                       value="<?= htmlspecialchars($_POST['card_cvv'] ?? '') ?>">
            </div>
            <div class="fg span2">
                <label><i class="fas fa-layer-group"></i> <?= $currentLang === 'en' ? 'Card Type' : 'نوع البطاقة' ?></label>
                <select name="card_type_check">
                    <option value="LIVE"  <?= ($_POST['card_type_check'] ?? 'LIVE') === 'LIVE'       ? 'selected' : '' ?>>💳 LIVE Card</option>
                    <option value="CLOUD" <?= ($_POST['card_type_check'] ?? '') === 'CLOUD'          ? 'selected' : '' ?>>☁️ CLOUD Card</option>
                    <option value="NFC"   <?= ($_POST['card_type_check'] ?? '') === 'NFC'            ? 'selected' : '' ?>>📡 NFC Card</option>
                    <option value="APPLE_PAY" <?= ($_POST['card_type_check'] ?? '') === 'APPLE_PAY' ? 'selected' : '' ?>> Apple Pay</option>
                    <option value="GOOGLE_PAY" <?= ($_POST['card_type_check'] ?? '') === 'GOOGLE_PAY'? 'selected' : '' ?>> Google Pay</option>
                </select>
            </div>
        </div>
        <button type="submit" class="btn" style="margin-top:18px;width:100%;justify-content:center;font-size:1rem;padding:13px;">
            <i class="fas fa-search"></i> <?= $currentLang === 'en' ? 'Check Card' : 'فحص البطاقة' ?>
        </button>
    </form>
</div>


<?php if ($result): ?>

<!-- ── البطاقة المرئية ── -->
<div class="card-visual" style="background:linear-gradient(135deg,<?= htmlspecialchars($result['color']) ?>cc,#0d0d0d);">
    <div class="card-logo"><i class="<?= htmlspecialchars($result['icon']) ?>"></i></div>
    <div style="font-size:.7rem;opacity:.7;letter-spacing:2px;">DI PARMA GATEWAY</div>
    <div class="card-pan">
        <?php
        $p = str_repeat('●', $result['pan_length'] - 4) . substr(preg_replace('/\D/','',$_POST['card_pan']),-4);
        echo implode(' ', str_split($p, 4));
        ?>
    </div>
    <div class="card-info">
        <div>
            <div style="font-size:.6rem;opacity:.6;"><?= $currentLang === 'en' ? 'CARD HOLDER' : 'حامل البطاقة' ?></div>
            <div><?= $currentLang === 'en' ? 'VERIFIED CARD' : 'بطاقة محققة' ?></div>
        </div>
        <div>
            <div style="font-size:.6rem;opacity:.6;"><?= $currentLang === 'en' ? 'EXPIRES' : 'تنتهي' ?></div>
            <div><?= htmlspecialchars($result['expiry_input'] ?: '??/??') ?></div>
        </div>
        <div style="font-size:1.5rem;"><?= $result['card_type'] === 'APPLE_PAY' ? '' : ($result['card_type'] === 'GOOGLE_PAY' ? '' : ($result['card_type'] === 'NFC' ? '📡' : ($result['card_type'] === 'CLOUD' ? '☁️' : '💳'))) ?></div>
    </div>
</div>

<!-- ── نتائج الفحص ── -->
<div class="card">
    <h3 style="color:#FFD700;margin-bottom:16px;font-size:1.1rem;"><i class="fas fa-microscope"></i> <?= $currentLang === 'en' ? 'Analysis Results' : 'نتائج التحليل' ?></h3>

    <!-- شبكة المعلومات الأساسية -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:20px;">
        <?php
        $cards = [
            ['fas fa-credit-card', $currentLang==='en'?'Network':'الشبكة',     $result['brand'],   $result['color']],
            ['fas fa-university',  $currentLang==='en'?'Issuer Bank':'البنك المصدر', $result['bank'] ?? ($currentLang==='en'?'Unknown':'غير معروف'), '#FFD700'],
            ['fas fa-globe',       $currentLang==='en'?'Country':'الدولة',     ($result['country_name'] ?? $result['country'] ?? ($currentLang==='en'?'Unknown':'غير معروف')), '#2196F3'],
            ['fas fa-layer-group', $currentLang==='en'?'Card Type':'نوع الرصيد', ucfirst($result['type']), '#9C27B0'],
        ];
        foreach ($cards as [$ico, $lbl, $val, $clr]):
        ?>
        <div style="background:rgba(255,255,255,.03);border:1px solid <?= $clr ?>33;border-radius:12px;padding:16px;text-align:center;">
            <i class="<?= $ico ?>" style="font-size:1.8rem;color:<?= $clr ?>;margin-bottom:8px;display:block;"></i>
            <div style="font-size:.72rem;color:#888;margin-bottom:4px;"><?= $lbl ?></div>
            <div style="font-weight:700;color:#E8F0FF;"><?= htmlspecialchars($val) ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- تفاصيل سطر بسطر -->
    <?php
    $rows = [
        ['fas fa-hashtag',       $currentLang==='en'?'BIN':'رقم BIN',              $result['bin'],                                    'val'],
        ['fas fa-ruler',         $currentLang==='en'?'Card Length':'طول الرقم',      $result['pan_length'].' '.'digits',               'val'],
        ['fas fa-check-double',  $currentLang==='en'?'Luhn Check':'فحص Luhn',       $result['luhn'] ? ($currentLang==='en'?'Valid ✅':'صالح ✅') : ($currentLang==='en'?'Invalid ❌':'غير صالح ❌'), $result['luhn']?'ok':'bad'],
        ['fas fa-calendar-check',$currentLang==='en'?'Expiry':'الصلاحية',           $result['expiry_input'] ? ($result['expiry_valid'] ? ($currentLang==='en'?'Valid ✅':'صالحة ✅') : ($currentLang==='en'?'Expired ❌':'منتهية ❌')) : ($currentLang==='en'?'Not provided':'لم يُدخَل'), $result['expiry_valid']?'ok':($result['expiry_input']?'bad':'warn')],
        ['fas fa-lock',          $currentLang==='en'?'CVV':'رمز الأمان',            $result['cvv_present'] ? ($currentLang==='en'?'Provided ('.$result['cvv_length'].' digits)':'مُدخَل ('.$result['cvv_length'].' أرقام)') : ($currentLang==='en'?'Not provided':'لم يُدخَل'), $result['cvv_present']?'ok':'warn'],
        ['fas fa-layer-group',   $currentLang==='en'?'Card Class':'نوع البطاقة',    $result['card_type'],                              'val'],
        ['fas fa-gift',          $currentLang==='en'?'Prepaid':'مسبق الدفع',        $result['prepaid'] ? ($currentLang==='en'?'Yes ⚠️':'نعم ⚠️') : ($currentLang==='en'?'No':'لا'),  $result['prepaid']?'warn':'ok'],
        ['fas fa-database',      $currentLang==='en'?'Data Source':'مصدر البيانات', strtoupper($result['source']),                    'val'],
    ];
    foreach ($rows as [$ico, $lbl, $val, $cls]):
    ?>
    <div class="row">
        <div class="lbl"><i class="<?= $ico ?>" style="color:#FFD700;margin-left:7px;margin-right:7px;width:16px;"></i><?= $lbl ?></div>
        <div class="val <?= $cls !== 'val' ? "badge {$cls}" : '' ?>"><?= htmlspecialchars($val) ?></div>
    </div>
    <?php endforeach; ?>

    <!-- نوع الرصيد: LIVE vs CLOUD -->
    <div style="margin-top:20px;padding:16px;border-radius:12px;background:rgba(255,215,0,.04);border:1px solid rgba(255,215,0,.2);">
        <h4 style="color:#FFD700;margin-bottom:12px;"><i class="fas fa-wallet"></i> <?= $currentLang==='en'?'Balance Type Analysis':'تحليل نوع الرصيد' ?></h4>
        <?php
        $isCloud  = in_array($result['card_type'], ['CLOUD','APPLE_PAY','GOOGLE_PAY']);
        $isNFC    = $result['card_type'] === 'NFC';
        $typeDesc = match($result['card_type']) {
            'LIVE'       => $currentLang==='en' ? 'Physical card with live bank balance. CVV required for online transactions. Funds are held in a real bank account.' : 'بطاقة فيزيائية برصيد بنكي حي. CVV مطلوب للمعاملات الإلكترونية. الأموال محفوظة في حساب بنكي حقيقي.',
            'CLOUD'      => $currentLang==='en' ? 'Virtual tokenized card. No physical CVV. Funds managed via cloud wallet or digital banking platform.' : 'بطاقة رقمية مُرمَّزة (Token). لا CVV فيزيائي. الأموال تُدار عبر محفظة سحابية أو منصة بنكية رقمية.',
            'NFC'        => $currentLang==='en' ? 'Contactless card via NFC. Combines physical card with digital token for tap-to-pay transactions.' : 'بطاقة لاتلامسية عبر NFC. تجمع بين البطاقة الفيزيائية والتوكن الرقمي لمعاملات الدفع بالتلامس.',
            'APPLE_PAY'  => $currentLang==='en' ? 'Apple Pay tokenized payment. Stored in Apple Secure Element. Authenticated via Face ID / Touch ID. Balance from linked card or Apple Card.' : 'دفع Apple Pay مُرمَّز. محفوظ في Apple Secure Element. المصادقة عبر Face ID / Touch ID. الرصيد من البطاقة المرتبطة أو Apple Card.',
            'GOOGLE_PAY' => $currentLang==='en' ? 'Google Pay tokenized payment. Backed by Google Wallet. Authenticated via biometric or PIN. Balance from linked bank card or Google Pay balance.' : 'دفع Google Pay مُرمَّز. مدعوم بـ Google Wallet. المصادقة عبر بصمة أو PIN. الرصيد من البطاقة البنكية المرتبطة أو رصيد Google Pay.',
            default      => $currentLang==='en' ? 'Unknown card type.' : 'نوع بطاقة غير معروف.',
        };
        ?>
        <div style="display:flex;align-items:flex-start;gap:14px;">
            <span style="font-size:2.5rem;"><?= match($result['card_type']) { 'LIVE'=>'💳','CLOUD'=>'☁️','NFC'=>'📡','APPLE_PAY'=>'','GOOGLE_PAY'=>'', default=>'💳' } ?></span>
            <div>
                <div style="font-weight:700;color:#E8F0FF;margin-bottom:6px;"><?= $result['card_type'] ?></div>
                <div style="font-size:.88rem;color:#ccc;line-height:1.7;"><?= $typeDesc ?></div>
            </div>
        </div>
        <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;">
            <span class="badge <?= $result['luhn'] ? 'ok' : 'bad' ?>"><i class="fas fa-check"></i> Luhn: <?= $result['luhn'] ? 'Pass' : 'Fail' ?></span>
            <span class="badge <?= $result['expiry_valid'] ? 'ok' : ($result['expiry_input'] ? 'bad' : 'warn') ?>"><i class="fas fa-calendar"></i> <?= $currentLang==='en'?'Expiry':'الصلاحية' ?>: <?= $result['expiry_input'] ? ($result['expiry_valid'] ? 'Valid' : 'Expired') : 'N/A' ?></span>
            <span class="badge <?= $isCloud ? 'warn' : 'ok' ?>"><i class="fas fa-wifi"></i> <?= $isCloud ? 'Cloud/Token' : ($isNFC ? 'NFC' : 'Physical') ?></span>
            <?php if ($result['prepaid']): ?><span class="badge warn">Prepaid</span><?php endif; ?>
        </div>
    </div>
</div>

<?php endif; ?>

</div><!-- /.wrap -->

<script>
function fmtCard(el) {
    let v = el.value.replace(/\D/g,'').slice(0,16);
    el.value = v.replace(/(.{4})/g,'$1 ').trim();
    // BIN live lookup
    if (v.length >= 6) fetchBinLive(v.substring(0,6));
}
function fmtExp(el) {
    let v = el.value.replace(/\D/g,'').slice(0,4);
    if (v.length >= 2) { let m=parseInt(v.slice(0,2)); if(m>12)v='12'+v.slice(2); if(v.length>2)v=v.slice(0,2)+'/'+v.slice(2); }
    el.value = v;
}
let _lastBinC = '';
function fetchBinLive(bin6) {
    if (bin6 === _lastBinC) return;
    _lastBinC = bin6;
    fetch('<?= SITE_URL ?>/api/bin_lookup.php?bin=' + encodeURIComponent(bin6))
        .then(r => r.json())
        .then(d => {
            const box = document.getElementById('liveBinInfo');
            if (!d.success || !box) return;
            box.style.display = 'block';
            box.innerHTML = `<i class="${d.icon}" style="color:${d.color};font-size:1.4rem;vertical-align:middle;margin-left:8px;"></i>`
                + `<strong style="color:${d.color}">${d.brand}</strong>`
                + (d.bank ? ` &nbsp;·&nbsp; 🏦 ${d.bank}` : '')
                + (d.country_name ? ` &nbsp;·&nbsp; ${getFlagEmoji(d.country)}${d.country_name}` : '')
                + ` &nbsp;·&nbsp; <span style="font-size:.78rem;color:#aaa">${d.type || ''}</span>`
                + (d.prepaid ? ' &nbsp;<span style="color:#f0ad4e">Prepaid</span>' : '');
        }).catch(() => {});
}
function getFlagEmoji(code) {
    if (!code || code.length !== 2) return '';
    const b = 0x1F1E6;
    return String.fromCodePoint(b + code.toUpperCase().charCodeAt(0) - 65)
         + String.fromCodePoint(b + code.toUpperCase().charCodeAt(1) - 65) + ' ';
}
</script>
</body>
</html>
