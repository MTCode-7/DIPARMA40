<?php
/**
 * ============================================================
 * DI PARMA | My Wallet — Ledger + Tron + MetaMask + internal
 * ============================================================
 */

require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/performance.php';
require_once __DIR__ . '/includes/db_optimized.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/lib/WalletService.php';

$ar = is_ar();
$db = db();
dp_ensure_indexes();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$csrfToken = generateCsrfToken();

$ledgerEnv = defined('LEDGER_TRC20_ADDRESS') ? trim((string) LEDGER_TRC20_ADDRESS) : '';

// Ensure linked_wallets table + load saved links
$linkedTable = DB_PREFIX . 'linked_wallets';
try {
    $db->execute(
        "CREATE TABLE IF NOT EXISTS `{$linkedTable}` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED NOT NULL,
            `provider` VARCHAR(32) NOT NULL,
            `network` VARCHAR(32) NOT NULL DEFAULT '',
            `address` VARCHAR(128) NOT NULL,
            `label` VARCHAR(120) DEFAULT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'active',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL DEFAULT NULL,
            UNIQUE KEY `uniq_user_provider` (`user_id`, `provider`),
            KEY `idx_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
} catch (Throwable $e) {}

$linked = ['ledger' => '', 'tron' => '', 'metamask' => ''];
try {
    $rows = $db->query("SELECT provider, address FROM `{$linkedTable}` WHERE user_id = ? AND status='active'", [$userId]) ?: [];
    foreach ($rows as $r) {
        $p = strtolower((string) ($r['provider'] ?? ''));
        if (isset($linked[$p])) {
            $linked[$p] = trim((string) $r['address']);
        }
    }
} catch (Throwable $e) {}

// Fallback Ledger from .env if user has no saved link
if ($linked['ledger'] === '' && preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $ledgerEnv)) {
    $linked['ledger'] = $ledgerEnv;
}
// Tron can share Ledger TRC20 address as display fallback
if ($linked['tron'] === '' && $linked['ledger'] !== '') {
    $linked['tron'] = $linked['ledger'];
}

try {
    $wallets = $db->query("SELECT * FROM " . DB_PREFIX . "wallets WHERE user_id = ? ORDER BY created_at DESC", [$userId]) ?: [];
} catch (Exception $e) {
    $wallets = [];
}

try {
    $userWallets = $db->query("SELECT network, coin, address, status, created_at FROM " . DB_PREFIX . "user_wallets WHERE user_id = ? ORDER BY network ASC", [$userId]) ?: [];
} catch (Exception $e) {
    $userWallets = [];
}

try {
    $cryptoWallets = $db->query("SELECT coin, network, balance, updated_at FROM " . DB_PREFIX . "user_crypto_wallets WHERE user_id = ? ORDER BY network ASC, coin ASC", [$userId]) ?: [];
} catch (Exception $e) {
    $cryptoWallets = [];
}

$totalFiat = array_sum(array_map(static fn(array $w): float => (float)($w['balance'] ?? 0), $wallets));
$totalCrypto = array_sum(array_map(static fn(array $w): float => (float)($w['balance'] ?? 0), $cryptoWallets));

try {
    $ledgerMoves = $db->query("SELECT * FROM " . DB_PREFIX . "ledger WHERE user_id = ? ORDER BY created_at DESC LIMIT 50", [$userId]) ?: [];
} catch (Exception $e) {
    $ledgerMoves = [];
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang ?? 'en') ?>" dir="<?= htmlspecialchars($pageDir ?? 'ltr') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>DI PARMA | <?= dp_t('My Wallet', 'محفظتي') ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Cairo',sans-serif;background:#0b0f17;color:#ffdfa0;padding:20px}
.container{max-width:1100px;margin:0 auto}
.card{background:rgba(10,16,39,.95);border:1px solid rgba(255,215,0,.2);border-radius:16px;padding:22px;margin-bottom:18px;box-shadow:0 10px 30px rgba(0,0,0,.5)}
.nav{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px}
.nav a{color:#fff;text-decoration:none;padding:8px 16px;border:1px solid rgba(255,215,0,.2);border-radius:999px;font-size:.9rem}
.nav a:hover,.nav a.active{background:rgba(255,215,0,.1);border-color:#FFD700;color:#FFD700}
.balance{font-size:2rem;color:#FFD700;font-weight:800;margin-top:8px}
table{width:100%;border-collapse:collapse;margin-top:12px}
th,td{padding:10px;border-bottom:1px solid rgba(255,255,255,.05);text-align:<?= $ar ? 'right' : 'left' ?>;font-size:.9rem}
th{color:#888}
.badge{display:inline-block;padding:4px 10px;border-radius:999px;font-size:.75rem;font-weight:700;background:rgba(255,215,0,.15);color:#FFD700;border:1px solid rgba(255,215,0,.3)}
.badge.off{background:rgba(239,83,80,.12);color:#ef5350;border-color:rgba(239,83,80,.3)}
.badge.on{background:rgba(16,185,129,.12);color:#10B981;border-color:rgba(16,185,129,.35)}
.empty-state{text-align:center;padding:24px;color:#777;font-size:.9rem}
.wallet-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:14px;margin-top:12px}
.wallet-box{background:rgba(255,255,255,.03);border:1px solid rgba(255,215,0,.1);border-radius:12px;padding:18px}
.connect-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px;margin-bottom:18px}
.connect-card{background:rgba(10,16,39,.98);border:1px solid rgba(255,215,0,.22);border-radius:16px;padding:18px;display:flex;flex-direction:column;gap:10px;min-height:220px}
.connect-card.ledger{border-color:rgba(255,215,0,.4)}
.connect-card.tron{border-color:rgba(239,68,68,.35)}
.connect-card.meta{border-color:rgba(246,133,27,.4)}
.connect-card h3{color:#FFD700;font-size:1.05rem;display:flex;align-items:center;gap:8px}
.connect-card p{color:#9aa;font-size:.82rem;line-height:1.45;flex:1}
.mono{font-family:Consolas,monospace;word-break:break-all;direction:ltr;text-align:left;color:#fff;font-size:.82rem;background:rgba(0,0,0,.25);padding:8px 10px;border-radius:8px}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:9px 14px;border-radius:10px;border:1px solid rgba(255,215,0,.25);background:rgba(255,215,0,.1);color:#FFD700;text-decoration:none;font-weight:700;cursor:pointer;font-family:inherit;font-size:.84rem}
.btn:hover{background:rgba(255,215,0,.2)}
.btn-solid{background:linear-gradient(135deg,#FFD700,#FFB700);color:#111;border:none}
.btn-row{display:flex;flex-wrap:wrap;gap:8px}
.bal-mini{display:flex;gap:10px;flex-wrap:wrap;font-size:.8rem;color:#ccc}
.bal-mini span{background:rgba(0,0,0,.25);padding:6px 10px;border-radius:8px}
#toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(100px);background:#0a1020;border:1px solid rgba(255,215,0,.3);color:#FFD700;padding:12px 20px;border-radius:12px;z-index:99;transition:.3s;max-width:90%}
#toast.show{transform:translateX(-50%) translateY(0)}
</style>
</head>
<body>
<div class="container">
  <div class="nav">
    <a href="dashboard.php"><?= dp_t('Dashboard', 'لوحة التحكم') ?></a>
    <a href="wallets.php" class="active"><?= dp_t('My Wallet', 'محفظتي') ?></a>
    <a href="ledger/">Ledger</a>
    <a href="crypto.php">Crypto</a>
    <a href="checkout_router.php"><?= dp_t('Checkout', 'الدفع') ?></a>
    <a href="invoices.php"><?= dp_t('Invoices', 'الفواتير') ?></a>
  </div>

  <h1 style="color:#FFD700;font-size:1.5rem;margin-bottom:6px"><?= dp_t('My Wallets', 'محافظي') ?></h1>
  <p style="color:#888;margin-bottom:16px;font-size:.9rem">
    <?= dp_t('Connect Ledger, Tron (TronLink), and MetaMask. Linked addresses are saved to your account.', 'اربط Ledger وTron (TronLink) وMetaMask. تُحفظ العناوين في حسابك.') ?>
  </p>

  <div class="connect-grid">
    <!-- Ledger -->
    <div class="connect-card ledger">
      <h3><i class="fas fa-usb"></i> Ledger</h3>
      <p><?= dp_t('Hardware wallet — TRC20 settlement destination for POS / Checkout.', 'محفظة أجهزة — وجهة TRC20 لتسوية POS / Checkout.') ?></p>
      <div class="badge <?= $linked['ledger'] ? 'on' : 'off' ?>" id="badge-ledger"><?= $linked['ledger'] ? dp_t('Linked', 'مربوط') : dp_t('Not linked', 'غير مربوط') ?></div>
      <div class="mono" id="addr-ledger"><?= $linked['ledger'] ? htmlspecialchars($linked['ledger']) : '—' ?></div>
      <div class="bal-mini" id="bal-ledger"><span>TRX …</span><span>USDT …</span></div>
      <div class="btn-row">
        <a class="btn btn-solid" href="ledger/"><i class="fas fa-plug"></i> <?= dp_t('Connect Ledger', 'ربط Ledger') ?></a>
        <?php if ($linked['ledger']): ?>
        <button type="button" class="btn" onclick="saveLink('ledger', document.getElementById('addr-ledger').textContent.trim())"><i class="fas fa-save"></i> <?= dp_t('Save', 'حفظ') ?></button>
        <button type="button" class="btn" onclick="copyAddr('ledger')"><i class="fas fa-copy"></i></button>
        <?php endif; ?>
      </div>
    </div>

    <!-- Tron / TronLink -->
    <div class="connect-card tron">
      <h3><i class="fas fa-bolt"></i> Tron / TronLink</h3>
      <p><?= dp_t('Connect TronLink browser wallet (or paste a T… address).', 'اربط محفظة TronLink في المتصفح (أو الصق عنوان T…).') ?></p>
      <div class="badge <?= $linked['tron'] ? 'on' : 'off' ?>" id="badge-tron"><?= $linked['tron'] ? dp_t('Linked', 'مربوط') : dp_t('Not linked', 'غير مربوط') ?></div>
      <div class="mono" id="addr-tron"><?= $linked['tron'] ? htmlspecialchars($linked['tron']) : '—' ?></div>
      <div class="bal-mini" id="bal-tron"><span>TRX …</span><span>USDT …</span></div>
      <div class="btn-row">
        <button type="button" class="btn btn-solid" onclick="connectTron()"><i class="fas fa-link"></i> <?= dp_t('Connect TronLink', 'ربط TronLink') ?></button>
        <button type="button" class="btn" onclick="pasteTron()"><i class="fas fa-paste"></i> <?= dp_t('Paste', 'لصق') ?></button>
        <button type="button" class="btn" onclick="copyAddr('tron')"><i class="fas fa-copy"></i></button>
        <button type="button" class="btn" onclick="unlinkWallet('tron')"><i class="fas fa-unlink"></i></button>
      </div>
    </div>

    <!-- MetaMask -->
    <div class="connect-card meta">
      <h3><i class="fab fa-ethereum"></i> MetaMask</h3>
      <p><?= dp_t('Connect MetaMask (Ethereum / ERC-20). Requires the MetaMask extension.', 'اربط MetaMask (Ethereum / ERC-20). يتطلب إضافة MetaMask.') ?></p>
      <div class="badge <?= $linked['metamask'] ? 'on' : 'off' ?>" id="badge-metamask"><?= $linked['metamask'] ? dp_t('Linked', 'مربوط') : dp_t('Not linked', 'غير مربوط') ?></div>
      <div class="mono" id="addr-metamask"><?= $linked['metamask'] ? htmlspecialchars($linked['metamask']) : '—' ?></div>
      <div class="bal-mini" id="bal-metamask"><span>ETH …</span></div>
      <div class="btn-row">
        <button type="button" class="btn btn-solid" onclick="connectMetaMask()"><i class="fab fa-ethereum"></i> <?= dp_t('Connect MetaMask', 'ربط MetaMask') ?></button>
        <button type="button" class="btn" onclick="copyAddr('metamask')"><i class="fas fa-copy"></i></button>
        <button type="button" class="btn" onclick="unlinkWallet('metamask')"><i class="fas fa-unlink"></i></button>
      </div>
    </div>
  </div>

  <div class="card">
    <h2 style="color:#FFD700;margin-bottom:8px;font-size:1.2rem"><?= dp_t('Internal wallet', 'المحفظة الداخلية') ?></h2>
    <?php if (empty($wallets)): ?>
      <div class="wallet-box"><div style="color:#888"><?= dp_t('Available balance', 'الرصيد المتوفر') ?></div><div class="balance">0.00 USD</div></div>
    <?php else: ?>
      <div class="wallet-grid">
        <?php foreach ($wallets as $wallet): ?>
          <div class="wallet-box">
            <div style="color:#888"><?= htmlspecialchars($wallet['currency'] ?? 'USD') ?></div>
            <div class="balance"><?= number_format((float)($wallet['balance'] ?? 0), 2) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2 style="color:#FFD700;margin-bottom:8px;font-size:1.2rem"><?= dp_t('Coin & network balances', 'أرصدة العملات والشبكات') ?></h2>
    <?php if (empty($cryptoWallets)): ?>
      <div class="empty-state"><?= dp_t('No crypto balances on file.', 'لا توجد أرصدة كريبتو مسجلة.') ?></div>
    <?php else: ?>
      <div class="wallet-grid">
        <?php foreach ($cryptoWallets as $wallet): ?>
          <div class="wallet-box">
            <div style="color:#FFD700;font-weight:800"><?= htmlspecialchars((string)$wallet['coin']) ?></div>
            <div style="color:#aaa;margin-top:4px"><?= htmlspecialchars((string)$wallet['network']) ?></div>
            <div class="balance"><?= number_format((float)$wallet['balance'], 8) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2 style="color:#FFD700;margin-bottom:8px;font-size:1.2rem"><?= dp_t('Saved deposit addresses', 'عناوين الإيداع المحفوظة') ?></h2>
    <?php if (empty($userWallets)): ?>
      <div class="empty-state"><?= dp_t('No digital wallet yet. Link above or create from Crypto.', 'لا توجد محفظة رقمية بعد. اربط أعلاه أو أنشئ من Crypto.') ?></div>
      <div class="btn-row" style="justify-content:center"><a class="btn" href="crypto.php"><i class="fas fa-plus"></i> Crypto</a></div>
    <?php else: ?>
      <div class="wallet-grid">
        <?php foreach ($userWallets as $wallet): ?>
          <div class="wallet-box">
            <div style="color:#FFD700;font-weight:700"><?= htmlspecialchars($wallet['network'] . ' / ' . $wallet['coin']) ?></div>
            <div class="mono" style="margin-top:8px"><?= htmlspecialchars($wallet['address']) ?></div>
            <div style="margin-top:8px"><span class="badge"><?= htmlspecialchars($wallet['status']) ?></span></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3 style="color:#FFD700;margin-bottom:12px"><?= dp_t('Movement history', 'سجل الحركة') ?></h3>
    <?php if (empty($ledgerMoves)): ?>
      <div class="empty-state"><?= dp_t('No ledger entries yet.', 'لا توجد حركات بعد.') ?></div>
    <?php else: ?>
      <div style="overflow-x:auto">
        <table>
          <thead><tr>
            <th><?= dp_t('Type', 'النوع') ?></th>
            <th><?= dp_t('Amount', 'المبلغ') ?></th>
            <th><?= dp_t('Reference', 'المرجع') ?></th>
            <th><?= dp_t('Date', 'التاريخ') ?></th>
          </tr></thead>
          <tbody>
          <?php foreach ($ledgerMoves as $entry): ?>
            <tr>
              <td><span class="badge"><?= htmlspecialchars($entry['type'] ?? '-') ?></span></td>
              <td style="color:#FFD700;font-weight:700"><?= number_format((float)($entry['amount'] ?? 0), 2) ?> <?= htmlspecialchars($entry['currency'] ?? 'USD') ?></td>
              <td style="direction:ltr;color:#aaa"><?= htmlspecialchars($entry['reference'] ?? '-') ?></td>
              <td style="color:#888"><?= htmlspecialchars($entry['created_at'] ?? '-') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
<div id="toast"></div>
<script>
const CSRF = <?= json_encode($csrfToken) ?>;
const I18N = {
  copied: <?= json_encode(dp_t('Copied', 'تم النسخ'), JSON_UNESCAPED_UNICODE) ?>,
  linked: <?= json_encode(dp_t('Linked', 'مربوط'), JSON_UNESCAPED_UNICODE) ?>,
  notLinked: <?= json_encode(dp_t('Not linked', 'غير مربوط'), JSON_UNESCAPED_UNICODE) ?>,
  noMeta: <?= json_encode(dp_t('MetaMask not found. Install the extension.', 'MetaMask غير موجود. ثبّت الإضافة.'), JSON_UNESCAPED_UNICODE) ?>,
  noTron: <?= json_encode(dp_t('TronLink not found. Install TronLink or paste address.', 'TronLink غير موجود. ثبّته أو الصق العنوان.'), JSON_UNESCAPED_UNICODE) ?>,
  pasteTron: <?= json_encode(dp_t('Paste Tron address (starts with T)', 'الصق عنوان Tron (يبدأ بـ T)'), JSON_UNESCAPED_UNICODE) ?>,
  saved: <?= json_encode(dp_t('Wallet linked', 'تم ربط المحفظة'), JSON_UNESCAPED_UNICODE) ?>,
  unlinked: <?= json_encode(dp_t('Wallet unlinked', 'تم فك الربط'), JSON_UNESCAPED_UNICODE) ?>,
  fail: <?= json_encode(dp_t('Failed', 'فشل'), JSON_UNESCAPED_UNICODE) ?>,
};

function toast(msg) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.classList.add('show');
  clearTimeout(t._tm);
  t._tm = setTimeout(() => t.classList.remove('show'), 3200);
}

function setAddr(provider, addr) {
  const el = document.getElementById('addr-' + provider);
  const badge = document.getElementById('badge-' + provider);
  if (el) el.textContent = addr || '—';
  if (badge) {
    badge.textContent = addr ? I18N.linked : I18N.notLinked;
    badge.className = 'badge ' + (addr ? 'on' : 'off');
  }
}

async function apiLink(body) {
  const r = await fetch('api/link_wallet.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify({ csrf_token: CSRF, ...body }),
  });
  return r.json();
}

async function saveLink(provider, address, network, silent) {
  if (!address || address === '—') return;
  const d = await apiLink({ action: 'link', provider, address, network: network || '' });
  if (d.success) {
    setAddr(provider, address);
    if (!silent) toast(I18N.saved);
    if (provider === 'ledger' || provider === 'tron') loadTronBal(provider, address);
    if (provider === 'metamask') loadEthBal(address);
  } else if (!silent) {
    toast(d.message || I18N.fail);
  }
}

async function unlinkWallet(provider) {
  const d = await apiLink({ action: 'unlink', provider });
  if (d.success) {
    setAddr(provider, '');
    toast(I18N.unlinked);
  } else toast(d.message || I18N.fail);
}

function copyAddr(provider) {
  const t = (document.getElementById('addr-' + provider)?.textContent || '').trim();
  if (!t || t === '—') return;
  navigator.clipboard?.writeText(t).then(() => toast(I18N.copied)).catch(() => toast(t));
}

async function connectTron() {
  try {
    let addr = '';
    if (window.tronLink?.request) {
      await window.tronLink.request({ method: 'tron_requestAccounts' });
    }
    if (window.tronWeb?.defaultAddress?.base58) {
      addr = window.tronWeb.defaultAddress.base58;
    } else if (window.tronLink?.tronWeb?.defaultAddress?.base58) {
      addr = window.tronLink.tronWeb.defaultAddress.base58;
    }
    if (!addr) {
      toast(I18N.noTron);
      return;
    }
    await saveLink('tron', addr, 'TRC20');
  } catch (e) {
    toast(e.message || I18N.noTron);
  }
}

function pasteTron() {
  const v = window.prompt(I18N.pasteTron, '');
  if (!v) return;
  const addr = v.trim();
  if (!/^T[1-9A-HJ-NP-Za-km-z]{33}$/.test(addr)) {
    toast(I18N.fail);
    return;
  }
  saveLink('tron', addr, 'TRC20');
}

async function connectMetaMask() {
  if (!window.ethereum) {
    toast(I18N.noMeta);
    window.open('https://metamask.io/download/', '_blank');
    return;
  }
  try {
    const accounts = await window.ethereum.request({ method: 'eth_requestAccounts' });
    const addr = accounts?.[0];
    if (!addr) throw new Error('No account');
    await saveLink('metamask', addr, 'ERC20');
  } catch (e) {
    toast(e.message || I18N.fail);
  }
}

async function loadTronBal(provider, address) {
  const box = document.getElementById('bal-' + provider);
  if (!box || !address || address === '—') return;
  try {
    const r = await fetch('api/ledger_tron.php?action=balance&address=' + encodeURIComponent(address), { credentials: 'same-origin' });
    const d = await r.json();
    if (!d.success) throw new Error('x');
    box.innerHTML = `<span>TRX ${Number(d.trx||0).toFixed(4)}</span><span>USDT ${Number(d.usdt||0).toFixed(2)}</span><span>≈ $${Number(d.total_usd||0).toFixed(2)}</span>`;
  } catch (_) {
    box.innerHTML = '<span>TRX —</span><span>USDT —</span>';
  }
}

async function loadEthBal(address) {
  const box = document.getElementById('bal-metamask');
  if (!box || !address || address === '—') return;
  try {
    // public eth RPC via Cloudflare eth gateway hex balance
    const r = await fetch('https://cloudflare-eth.com', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'eth_getBalance', params: [address, 'latest'] }),
    });
    const d = await r.json();
    const wei = BigInt(d.result || '0x0');
    const eth = Number(wei) / 1e18;
    box.innerHTML = `<span>ETH ${eth.toFixed(6)}</span>`;
  } catch (_) {
    box.innerHTML = '<span>ETH —</span>';
  }
}

// Init balances for already-linked addresses
(function init() {
  const ledger = (document.getElementById('addr-ledger')?.textContent || '').trim();
  const tron = (document.getElementById('addr-tron')?.textContent || '').trim();
  const meta = (document.getElementById('addr-metamask')?.textContent || '').trim();
  if (ledger && ledger !== '—') {
    loadTronBal('ledger', ledger);
    saveLink('ledger', ledger, 'TRC20', true);
  }
  if (tron && tron !== '—') loadTronBal('tron', tron);
  if (meta && meta !== '—') loadEthBal(meta);
})();
</script>
</body>
</html>
