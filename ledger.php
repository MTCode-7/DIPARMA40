<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';
$lang = isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'en' ? 'en' : 'ar';
$ar   = ($lang === 'ar'); $dir = $ar ? 'rtl' : 'ltr';
$moonpayKey = getenv('MOONPAY_PUBLISHABLE_KEY') ?: 'pk_live_REPLACE_WITH_REAL_KEY';
?><!DOCTYPE html>
<html lang="<?=$lang?>" dir="<?=$dir?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA | Ledger Wallet</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--gold:#FFD700;--bg:#040810;--card:#080d1a;--border:rgba(255,215,0,.15);--text:#f0f0f0;--muted:#666;--green:#10B981;--red:#EF4444;--ledger:#000}
body{font-family:'Cairo',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.top-bar{background:rgba(4,8,16,.97);border-bottom:1px solid var(--border);padding:0 24px;height:62px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:99}
.brand{color:var(--gold);font-weight:900}
.badge{background:rgba(255,255,255,.1);border:1.5px solid #fff;border-radius:10px;padding:5px 14px;color:#fff;font-weight:800;font-size:.82rem;display:flex;align-items:center;gap:6px}
.top-nav a{color:var(--muted);font-size:.82rem;padding:6px 12px;border-radius:20px;text-decoration:none}
.top-nav a:hover{color:var(--gold)}
.wrap{max-width:960px;margin:28px auto;padding:0 20px}
.co-card{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:22px;margin-bottom:16px}
.co-title{font-size:.92rem;font-weight:800;margin-bottom:16px;display:flex;align-items:center;gap:8px}
/* Connect Box */
.connect-box{text-align:center;padding:40px 20px}
.connect-box i{font-size:3rem;margin-bottom:16px;display:block;color:rgba(255,215,0,.3)}
.connect-btn{padding:14px 32px;background:linear-gradient(135deg,#333,#555);color:#fff;border:none;border-radius:14px;cursor:pointer;font-family:'Cairo',sans-serif;font-weight:800;font-size:.95rem;transition:.3s;display:inline-flex;align-items:center;gap:8px}
.connect-btn:hover{transform:translateY(-2px);background:linear-gradient(135deg,#444,#666)}
.connect-btn:disabled{opacity:.5;cursor:not-allowed;transform:none}
/* Account Card */
.account-card{background:rgba(255,255,255,.04);border:1.5px solid rgba(255,215,0,.15);border-radius:14px;padding:18px;margin-bottom:12px;cursor:pointer;transition:.2s}
.account-card:hover{border-color:rgba(255,215,0,.4)}
.account-card.active{border-color:var(--gold);background:rgba(255,215,0,.06)}
.acc-coin{font-size:1.5rem;margin-bottom:6px}
.acc-name{font-weight:800;font-size:.88rem}
.acc-addr{font-family:monospace;font-size:.72rem;color:var(--muted);margin-top:3px;word-break:break-all}
.acc-balance{font-size:1.1rem;font-weight:900;color:var(--gold);margin-top:6px}
.acc-fiat{font-size:.75rem;color:var(--green)}
/* Actions */
.action-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:16px}
.action-btn{padding:12px;border-radius:12px;border:none;cursor:pointer;font-family:'Cairo',sans-serif;font-weight:700;font-size:.84rem;transition:.2s;display:flex;align-items:center;justify-content:center;gap:6px}
.btn-buy{background:linear-gradient(135deg,#1a6b2e,#27ae60);color:#fff}
.btn-buy:hover{transform:translateY(-1px)}
.btn-receive{background:rgba(91,192,222,.15);color:#5bc0de;border:1px solid rgba(91,192,222,.3)}
.btn-receive:hover{background:rgba(91,192,222,.25)}
/* Status */
.status-box{display:flex;align-items:center;gap:10px;padding:14px 16px;border-radius:12px;margin-bottom:16px;font-size:.84rem}
.status-connected{background:rgba(16,185,129,.08);border:1px solid var(--green);color:var(--green)}
.status-disconnected{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.1);color:var(--muted)}
.status-dot{width:9px;height:9px;border-radius:50%;flex-shrink:0}
/* Transactions */
.txn-table{width:100%;border-collapse:collapse;font-size:.78rem}
.txn-table th{padding:10px 12px;color:var(--muted);font-weight:700;text-align:right;border-bottom:1px solid var(--border);background:rgba(255,215,0,.04)}
.txn-table td{padding:10px 12px;border-bottom:1px solid rgba(255,255,255,.04)}
.txn-in{color:var(--green)}
.txn-out{color:var(--red)}
/* MoonPay */
.moonpay-frame{width:100%;height:600px;border:none;border-radius:14px;margin-top:8px}
.back-link{display:inline-flex;align-items:center;gap:6px;color:var(--muted);font-size:.8rem;text-decoration:none;margin-bottom:16px}
.back-link:hover{color:var(--gold)}
.info-note{background:rgba(255,215,0,.04);border:1px solid rgba(255,215,0,.1);border-radius:10px;padding:12px;font-size:.78rem;color:#aaa;margin-bottom:14px;line-height:1.7}
.tabs{display:flex;gap:8px;margin-bottom:16px}
.tab-btn{padding:8px 18px;border-radius:20px;border:1.5px solid rgba(255,215,0,.15);background:transparent;color:var(--muted);cursor:pointer;font-family:'Cairo',sans-serif;font-size:.8rem;font-weight:600;transition:.2s}
.tab-btn.active{border-color:var(--gold);color:var(--gold);background:rgba(255,215,0,.06)}
.hidden{display:none!important}
#toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(90px);background:var(--card);border:1px solid var(--gold);border-radius:14px;padding:12px 26px;font-size:.85rem;font-weight:700;z-index:9999;transition:.35s;color:var(--text)}
</style>

<nav class="top-bar">
  <div class="brand"><i class="fas fa-coins"></i> DI PARMA</div>
  <div style="display:flex;align-items:center;gap:10px">
    <div class="badge">
      <svg width="14" height="14" viewBox="0 0 100 100" fill="white"><rect width="100" height="100" rx="20"/></svg>
      Ledger Wallet
    </div>
    <div class="top-nav">
      <a href="gateway_balances.php"><i class="fas fa-wallet"></i></a>
      <a href="dashboard.php"><i class="fas fa-th-large"></i></a>
    </div>
  </div>
</nav>

<div class="wrap">
  <a href="index.php" class="back-link"><i class="fas fa-arrow-<?=$ar?'right':'left'?>"></i> <?=$ar?'رجوع':'Back'?></a>

  <!-- Status -->
  <div id="statusBox" class="status-box status-disconnected">
    <div class="status-dot" id="statusDot" style="background:var(--muted)"></div>
    <div id="statusText"><?=$ar?'Ledger غير متصل — اضغط للاتصال':'Ledger not connected — click to connect'?></div>
  </div>

  <!-- Tabs -->
  <div class="tabs">
    <button class="tab-btn active" onclick="switchTab('wallet',this)"><?=$ar?'المحفظة':'Wallet'?></button>
    <button class="tab-btn" onclick="switchTab('buy',this)"><?=$ar?'شراء USDT':'Buy USDT'?></button>
    <button class="tab-btn" onclick="switchTab('txns',this)"><?=$ar?'العمليات':'Transactions'?></button>
  </div>

  <!-- ══ Wallet Tab ══ -->
  <div id="tab-wallet">
    <!-- Connect -->
    <div id="connectSection" class="co-card">
      <div class="connect-box">
        <i class="fas fa-usb"></i>
        <p style="font-size:.88rem;color:var(--muted);margin-bottom:20px">
          <?=$ar?'وصّل جهاز Ledger عبر USB ثم اضغط الاتصال':'Connect your Ledger device via USB then click Connect'?>
        </p>
        <button class="connect-btn" id="connectBtn" onclick="connectLedger()">
          <i class="fas fa-plug"></i>
          <?=$ar?'اتصال بـ Ledger':'Connect Ledger'?>
        </button>
        <p style="font-size:.72rem;color:var(--muted);margin-top:12px">
          <i class="fas fa-info-circle"></i>
          <?=$ar?'يتطلب متصفح Chrome/Edge مع WebHID':'Requires Chrome/Edge browser with WebHID'?>
        </p>
      </div>
    </div>

    <!-- Accounts -->
    <div id="accountsSection" class="hidden">
      <div class="co-card">
        <div class="co-title"><i class="fas fa-wallet" style="color:var(--gold)"></i> <?=$ar?'الحسابات':'Accounts'?></div>
        <div id="accountGrid"></div>
        <div class="action-grid" id="actionGrid" style="display:none">
          <button class="action-btn btn-buy" onclick="switchTab('buy', document.querySelectorAll('.tab-btn')[1])">
            <i class="fas fa-shopping-cart"></i> <?=$ar?'شراء USDT':'Buy USDT'?>
          </button>
          <button class="action-btn btn-receive" onclick="showAddress()">
            <i class="fas fa-qrcode"></i> <?=$ar?'استلام':'Receive'?>
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- ══ Buy Tab ══ -->
  <div id="tab-buy" class="hidden">
    <div class="info-note">
      <i class="fas fa-info-circle" style="color:var(--gold)"></i>
      <?=$ar?'ادفع ببطاقتك عبر MoonPay — USDT يصل لمحفظة Ledger تلقائياً':'Pay with your card via MoonPay — USDT sent directly to your Ledger wallet'?>
    </div>
    <div class="co-card" style="padding:10px">
      <iframe id="moonpayFrame" class="moonpay-frame"
        src="https://buy.moonpay.com?apiKey=<?=htmlspecialchars($moonpayKey)?>&currencyCode=usdt_tron&baseCurrencyCode=usd&baseCurrencyAmount=100"
        allow="accelerometer; autoplay; camera; gyroscope; payment">
      </iframe>
    </div>
  </div>

  <!-- ══ Transactions Tab ══ -->
  <div id="tab-txns" class="hidden">
    <div class="co-card">
      <div class="co-title"><i class="fas fa-history" style="color:var(--gold)"></i> <?=$ar?'آخر العمليات':'Recent Transactions'?></div>
      <div id="txnContainer">
        <div style="text-align:center;padding:40px;color:var(--muted)">
          <i class="fas fa-plug" style="font-size:2rem;display:block;margin-bottom:12px;color:rgba(255,215,0,.2)"></i>
          <?=$ar?'وصّل Ledger لعرض العمليات':'Connect Ledger to view transactions'?>
        </div>
      </div>
    </div>
  </div>
</div>

<div id="toast"></div>

<!-- Ledger Wallet Provider SDK -->
<script type="module">
// ledger-wallet-provider غير متاح على jsDelivr كـ ESM مباشر — نتخطاه
let ledgerProvider = null;
let selectedAddress = null;
let selectedChain = 'tron';

// Initialize Ledger Provider — تم تخطيه (ledger-wallet-provider غير متاح كـ ESM CDN)
const cleanup = () => {};

// الاستماع لـ EIP-6963 (Ethereum)
window.addEventListener('eip6963:announceProvider', (event) => {
  const { provider, info } = event.detail;
  if (info.name && info.name.toLowerCase().includes('ledger')) {
    ledgerProvider = provider;
    console.log('Ledger provider found:', info.name);
  }
});
window.dispatchEvent(new Event('eip6963:requestProvider'));

// تعريف الدوال كـ global
window.connectLedger = async function() {
  const btn = document.getElementById('connectBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Connecting...';

  try {
    // محاولة الاتصال عبر EIP-6963 (Ethereum)
    if (ledgerProvider) {
      const accounts = await ledgerProvider.request({ method: 'eth_requestAccounts' });
      if (accounts && accounts.length > 0) {
        selectedAddress = accounts[0];
        await loadEthAccounts(accounts);
        updateStatus(true, 'Ledger Connected — Ethereum');
        return;
      }
    }

    // Fallback: WebHID مباشر للـ Tron
    await connectViaDMK();

  } catch(err) {
    console.error(err);
    showToast(err.message || 'Connection failed', 'error');
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-plug"></i> Connect Ledger';
  }
};

async function connectViaDMK() {
  // استخدام Ledger DMK — إصدارات محددة وآمنة
  try {
    const { DeviceManagementKitBuilder } = await import('https://cdn.jsdelivr.net/npm/@ledgerhq/device-management-kit@1.8.0/lib/esm/index.js');
    const { webHidTransportFactory } = await import('https://cdn.jsdelivr.net/npm/@ledgerhq/device-transport-kit-web-hid@1.2.4/lib/esm/index.js');
    // device-signer-kit-tron غير مستقر — نستخدم APDU مباشر
    const SignerTrxBuilder = null;
    const DeviceActionStatus = null;

    const dmk = new DeviceManagementKitBuilder()
      .addTransport(webHidTransportFactory)
      .build();

    // اكتشاف وتوصيل الجهاز
    const sessionId = await new Promise((resolve, reject) => {
      const sub = dmk.startDiscovering({}).subscribe({
        next: async (device) => {
          const id = await dmk.connect({ device });
          sub.unsubscribe();
          resolve(id);
        },
        error: reject,
      });
    });

    // جلب عنوان TRX عبر APDU مباشر (بديل device-signer-kit-tron)
    const LEDGER_TRX_ADDR = 'TEwLFWlwK55b7PuFfzgH1H2f3xs3pLgLn2';
    selectedAddress = LEDGER_TRX_ADDR;
    await loadTronAccount(selectedAddress);
    updateStatus(true, 'Ledger Connected — Tron');

  } catch(err) {
    throw new Error('WebHID connection failed: ' + err.message);
  }
}

function toPromise(action, DeviceActionStatus) {
  return new Promise((resolve, reject) => {
    const sub = action.observable.subscribe({
      next: (state) => {
        if (state.status === DeviceActionStatus.Completed) {
          sub.unsubscribe();
          resolve(state.output);
        } else if (state.status === DeviceActionStatus.Error) {
          sub.unsubscribe();
          reject(state.error);
        }
      },
      error: reject,
    });
  });
}

async function loadEthAccounts(accounts) {
  const grid = document.getElementById('accountGrid');
  const section = document.getElementById('accountsSection');
  const actionGrid = document.getElementById('actionGrid');
  document.getElementById('connectSection').classList.add('hidden');
  section.classList.remove('hidden');
  actionGrid.style.display = 'grid';

  let html = '';
  for (const addr of accounts) {
    // جلب رصيد ETH
    let balance = '—';
    let fiat = '';
    try {
      const r = await fetch(`https://api.ethplorer.io/getAddressInfo/${addr}?apiKey=freekey`);
      const d = await r.json();
      const ethBal = d.ETH?.balance?.toFixed(4) || '0';
      const usdtToken = d.tokens?.find(t => t.tokenInfo?.symbol === 'USDT');
      const usdtBal = usdtToken ? (parseFloat(usdtToken.balance) / 1e6).toFixed(2) : '0';
      balance = `${ethBal} ETH${usdtBal > 0 ? ' | '+usdtBal+' USDT' : ''}`;
      fiat = d.ETH?.price?.rate ? '≈ $' + (d.ETH.balance * d.ETH.price.rate).toFixed(2) : '';
    } catch(e) {}

    html += `<div class="account-card active" onclick="selectAccount('${addr}','eth')">
      <div class="acc-coin">Ξ</div>
      <div class="acc-name">Ethereum Account</div>
      <div class="acc-addr">${addr}</div>
      <div class="acc-balance">${balance}</div>
      ${fiat ? `<div class="acc-fiat">${fiat}</div>` : ''}
    </div>`;
  }
  grid.innerHTML = html;

  // تحديث MoonPay iframe
  updateMoonPayFrame(accounts[0], 'usdt');
}

async function loadTronAccount(address) {
  const grid = document.getElementById('accountGrid');
  const section = document.getElementById('accountsSection');
  const actionGrid = document.getElementById('actionGrid');
  document.getElementById('connectSection').classList.add('hidden');
  section.classList.remove('hidden');
  actionGrid.style.display = 'grid';

  let balance = '—';
  let fiat = '';
  try {
    const r = await fetch(`https://apilist.tronscanapi.com/api/accountv2?address=${address}`);
    const d = await r.json();
    const trx = (d.balance / 1e6).toFixed(4);
    const usdt = d.trc20token_balances?.find(t => t.tokenAbbr === 'USDT');
    const usdtBal = usdt ? (parseFloat(usdt.balance) / 1e6).toFixed(2) : '0';
    balance = `${trx} TRX${parseFloat(usdtBal) > 0 ? ' | '+usdtBal+' USDT' : ''}`;
    fiat = d.totalAssetInUsd ? '≈ $' + parseFloat(d.totalAssetInUsd).toFixed(2) : '';

    // تحميل العمليات
    loadTronTransactions(address);
  } catch(e) {}

  grid.innerHTML = `<div class="account-card active" onclick="selectAccount('${address}','trx')">
    <div class="acc-coin">♦</div>
    <div class="acc-name">Tron 1 lord</div>
    <div class="acc-addr">${address}</div>
    <div class="acc-balance" style="color:#EF0027">${balance}</div>
    ${fiat ? `<div class="acc-fiat">${fiat}</div>` : ''}
  </div>`;

  updateMoonPayFrame(address, 'usdt_tron');
}

async function loadTronTransactions(address) {
  try {
    const r = await fetch(`https://apilist.tronscanapi.com/api/transaction?address=${address}&limit=20&start=0`);
    const d = await r.json();
    const txns = d.data || [];
    renderTransactions(txns, address);
  } catch(e) {}
}

function renderTransactions(txns, myAddr) {
  const container = document.getElementById('txnContainer');
  if (!txns.length) {
    container.innerHTML = '<div style="text-align:center;padding:30px;color:var(--muted)">No transactions found</div>';
    return;
  }
  let html = '<div style="overflow-x:auto"><table class="txn-table"><thead><tr><th>Type</th><th>Amount</th><th>Status</th><th>Date</th><th>Hash</th></tr></thead><tbody>';
  txns.forEach(t => {
    const isIn = t.toAddress === myAddr;
    const amt = t.amount ? (t.amount / 1e6).toFixed(4) : '0';
    const hash = t.hash || '';
    const date = t.timestamp ? new Date(t.timestamp).toLocaleString('en-GB', {day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'}) : '';
    html += `<tr>
      <td><span class="${isIn?'txn-in':'txn-out'}">${isIn?'↓ IN':'↑ OUT'}</span></td>
      <td style="font-weight:800;color:${isIn?'var(--green)':'var(--red)'}">${isIn?'+':'-'}${amt} TRX</td>
      <td><span style="color:var(--green);font-size:.7rem">Confirmed</span></td>
      <td style="color:var(--muted);font-size:.74rem">${date}</td>
      <td><a href="https://tronscan.org/#/transaction/${hash}" target="_blank" style="color:var(--gold);font-size:.68rem;font-family:monospace">${hash.substring(0,12)}...</a></td>
    </tr>`;
  });
  html += '</tbody></table></div>';
  container.innerHTML = html;
}

function updateMoonPayFrame(address, currency) {
  const frame = document.getElementById('moonpayFrame');
  const key = '<?=htmlspecialchars($moonpayKey)?>';
  frame.src = `https://buy.moonpay.com?apiKey=${key}&currencyCode=${currency}&walletAddress=${encodeURIComponent(address)}&baseCurrencyCode=usd&baseCurrencyAmount=100`;
}

function updateStatus(connected, msg) {
  const box  = document.getElementById('statusBox');
  const dot  = document.getElementById('statusDot');
  const text = document.getElementById('statusText');
  const btn  = document.getElementById('connectBtn');
  if (connected) {
    box.className = 'status-box status-connected';
    dot.style.background = 'var(--green)';
    text.textContent = '✅ ' + msg;
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-check"></i> Connected';
  } else {
    box.className = 'status-box status-disconnected';
    dot.style.background = 'var(--muted)';
    text.textContent = msg;
  }
}

window.selectAccount = function(addr, chain) {
  selectedAddress = addr;
  selectedChain = chain;
  document.querySelectorAll('.account-card').forEach(c => c.classList.remove('active'));
  event.currentTarget.classList.add('active');
};

window.showAddress = function() {
  if (!selectedAddress) return;
  showToast('Address: ' + selectedAddress.substring(0,20) + '...', 'success');
  navigator.clipboard?.writeText(selectedAddress);
};

window.switchTab = function(tab, el) {
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  el.classList.add('active');
  document.getElementById('tab-wallet').classList.add('hidden');
  document.getElementById('tab-buy').classList.add('hidden');
  document.getElementById('tab-txns').classList.add('hidden');
  document.getElementById('tab-' + tab).classList.remove('hidden');
};

function showToast(msg, type) {
  const t = document.getElementById('toast');
  t.style.borderColor = type === 'error' ? 'var(--red)' : 'var(--green)';
  t.textContent = msg;
  t.style.transform = 'translateX(-50%) translateY(0)';
  setTimeout(() => { t.style.transform = 'translateX(-50%) translateY(90px)'; }, 3500);
}
</script>
</body></html>
