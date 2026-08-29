<?php
/**
 * ============================================================
 * DI PARMA | إضافة بيانات اتصال حقيقية
 * ============================================================
 */

http_response_code(410);
exit('Test connection data creation is disabled. Configure real gateway credentials in the admin panel.');

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__));
}

require_once ROOT_PATH . '/includes/config.php';
require_once ROOT_PATH . '/includes/database.php';

$db = db();

// البنك والبيانات الأساسية
$bankNames = [
    "Stripe", "PayPal", "Square", "2Checkout", "Worldpay",
    "Authorize.net", "Braintree", "Adyen", "Wise", "Wise",
    "Bank Transfer", "SWIFT", "ACH", "SEPA", "Local",
    "Chase", "Bank of America", "Wells Fargo", "Citibank", "HSBC",
    "Deutsche Bank", "ING", "Santander", "BNP Paribas", "Barclays",
    "Goldman Sachs", "Morgan Stanley", "JP Morgan", "State Street", "Fidelity",
    "Capital One", "American Express", "Discover", "Visa", "Mastercard",
    "UnionPay", "JCB", "Diners Club", "RuPay", "Mir",
    "Maestro", "Electron", "Plus", "Pulse", "Interlink",
    "Cirrus", "Allpoint", "MoneyPass", "Euronet", "Global ATM",
    "Fiserv", "FIS", "Jack Henry", "SS&C", "SS&C",
    "Temenos", "Finastra", "Misys", "Mambu", "Thought Machine",
    "Black Swan", "nCino", "Blend Labs", "SoFi", "Revolut",
    "Wise", "N26", "Bunq", "Atom Bank", "Starling",
    "Monzo", "Tandem", "TM", "C6 Bank", "Banco Inter",
    "Nubank", "Picpay", "Pagatodo", "PayTechUY", "Sicredi",
    "Alipay", "WeChat Pay", "JD Pay", "Baidu Wallet", "Huawei Pay",
    "Apple Pay", "Google Pay", "Samsung Pay", "Fitbit Pay", "Garmin Pay",
    "Klarna", "Affirm", "Afterpay", "Sezzle", "Quadpay"
];

$electronicPayments = [
    "2Checkout Instant", "Alipay", "Amazon Pay", "Apple Pay",
    "Bitpay", "Braintree PayPal", "Click2Pay", "Coinbase Commerce",
    "Crypto.com Pay", "DirectDebit", "Discover", "Google Pay",
    "Klarna", "Mastercard", "Moneybookers", "Neteller",
    "PayPal Express", "PayPal Checkout", "Payoneer", "paysafecard",
    "Perfect Money", "QIWI", "Revolut", "Samsung Pay",
    "Skrill", "Square Cash", "Stripe", "Swish",
    "Trustly", "Visa", "Visa Electron", "Wise",
    "WorldPay", "BNPL Partners", "Afterpay", "Affirm",
    "Sezzle", "Quadpay", "Klarna Pay", "PayPal Credit",
    "Amazon Credit", "Apple Card", "Google Pay Send", "Samsung Wallet",
    "WeChat Pay", "Alipay+", "Fiserv", "GlobalCollect",
    "Worldline", "First Data", "Chase Paymentech", "Acquirers",
    "PCI DSS Certified", "ISO 27001 Certified", "SOC 2 Certified", "GDPR Compliant",
    "PCI Level 1", "Tokenization Enabled", "3D Secure", "AVS Enabled",
    "CVV2 Enabled", "Address Verification", "Anti-Fraud Tools", "Chargeback Protection",
    "Dispute Management", "Real-time Processing", "Settlement Tools", "Merchant Portal",
    "API Integration", "Webhook Support", "Batch Processing", "Recurring Billing",
    "Subscription Support", "Multi-currency", "Multi-language", "24/7 Support"
];

$cryptoCurrencies = [
    "Bitcoin Payments", "Ethereum Payments", "Litecoin Payments", "Ripple XRP",
    "Bitcoin Cash", "Stellar Lumens", "Cardano", "Polkadot",
    "Solana", "Dogecoin", "Shiba Inu", "Monero",
    "Zcash", "Dash", "NEO", "EOS",
    "TRON", "Binance Coin", "Maker", "Uniswap",
    "Compound", "Aave", "Curve", "Balancer",
    "Yearn Finance", "Sushi", "PancakeSwap", "Quickswap",
    "Raydium", "Orca", "Magic Eden", "Blur",
    "OpenSea", "Rarible", "SuperRare", "Foundation",
    "Makersplace", "Nifty Gateway", "NBA Top Shot", "Sorare",
    "Decentraland", "Sandbox", "Enjin", "Axie Infinity",
    "Gala Games", "Flow", "ImmutableX", "StarkNet",
    "Arbitrum", "Optimism", "Polygon", "Avalanche",
    "Fantom", "Harmony", "Celo", "Near",
    "Algorithm", "Cosmos", "Tendermint", "Kava",
    "Terra", "Anchor Protocol", "Mirror Protocol", "Olympus DAO",
    "Wonderland", "Klima", "Toucan", "Offsetra"
];

$wallets = [
    "MetaMask Wallet", "Trust Wallet", "Wallet Connect", "Coinbase Wallet",
    "Ledger Wallet", "Trezor Wallet", "KeepKey", "GridPlus",
    "MyEtherWallet", "MyCrypto", "Argent", "Gnosis Safe",
    "Safe Multisig", "Dharma Wallet", "Dharma Protocol", "Instadapp",
    "DeFi Saver", "Set Protocol", "TokenSets", "Zapper",
    "Yearn UI", "Curve UI", "Aave UI", "Compound UI",
    "Uniswap UI", "SushiSwap UI", "Balancer UI", "Bancor UI",
    "dYdX UI", "Synthetix UI", "Futuresexchange UI", "bZx UI",
    "Perpetual Protocol", "dYdX Perpetuals", "GMX", "Perps",
    "KWM Wallet", "Alpha Wallet", "Wallet.io", "SafeEther",
    "Etherscan Wallet", "Blockchain.com Wallet", "Exodus Wallet", "Atomic Wallet",
    "Cake Wallet", "Edge Wallet", "Coinomi", "Copay",
    "GreenBits", "Jaxx", "Mycelium", "Samourai",
    "Electrum", "Wasabi", "Bitcoin Core", "Lightning Network",
    "Stacking Sats", "Casa Node", "BTCPay", "Nodl",
    "Start9", "Umbrel", "Citadel", "Embassy",
    "Zeus LN", "Bluewallet", "Breez", "Muun"
];

$socialMedia = [
    "Facebook Payments", "Instagram Shop", "WhatsApp Pay", "Telegram Pay",
    "TikTok Payments", "Snapchat Pay", "Twitter/X Pay", "LinkedIn Payments",
    "YouTube Monetization", "Discord Store", "Twitch Bits", "Patreon",
    "OnlyFans Payments", "Substack", "Medium Membership", "Rumble",
    "Mastodon", "Bluesky", "Threads", "BeReal",
    "Viber Pay", "Signal Payments", "Messenger Pay", "WeChat Moments",
    "QQ Pay", "Douyin", "Kuaishou", "Bilibili",
    "Niconico", "Mixi", "Line Pay", "Kakao Pay",
    "Naver Pay", "Coupang Pay", "Gmarket", "11st",
    "Shopee", "Lazada", "Tik Shop", "Pinduoduo",
    "Meituan", "Didi Pay", "Alipay HK", "Touch n Go",
    "Grabpay", "Dana", "GCash", "Paymaya",
    "Viber Business", "Telegram Business", "WhatsApp Business", "Instagram Business",
    "TikTok Shop", "Snapchat Ads", "Pinterest Shop", "Reddit Ads",
    "Tumblr", "Nextdoor", "Quora", "Hacker News",
    "Medium", "Dev.to", "Hashnode", "Substack",
    "Ghost", "Revue", "Beehiiv", "ConvertKit",
    "Mailchimp", "ActiveCampaign", "HubSpot", "Klaviyo",
    "Braze", "Segment", "mParticle", "Tealium",
    "Adobe Experience Cloud", "Salesforce", "Oracle", "SAP",
    "Microsoft Dynamics", "NetSuite", "Workday", "Zendesk"
];

$games = [
    "Steam Payments", "Epic Games Store", "GOG", "Itch.io",
    "Unity Asset Store", "Unreal Marketplace", "Roblox Robux", "Fortnite",
    "PUBG Mobile", "Call of Duty Mobile", "Genshin Impact", "Honkai Star Rail",
    "Valorant", "League of Legends", "Dota 2", "Counter-Strike 2",
    "Overwatch 2", "World of Warcraft", "Final Fantasy XIV", "Elder Scrolls Online",
    "Guild Wars 2", "Black Desert Online", "Lost Ark", "New World",
    "Diablo IV", "Path of Exile", "Albion Online", "Lineage II",
    "MapleStory", "RuneScape", "Old School RuneScape", "Oldschool RuneScape",
    "EVE Online", "EVerlasting", "Star Citizen", "Dual Universe",
    "Minecraft", "Roblox", "Rec Room", "VRChat",
    "Beat Saber", "Half-Life Alyx", "Pavlov", "Blade & Sorcery",
    "The Witcher 3", "Cyberpunk 2077", "Baldur's Gate 3", "Starfield",
    "Elden Ring", "Dark Souls", "Bloodborne", "Sekiro",
    "God of War", "Ghost of Tsushima", "Horizon Forbidden West", "Final Fantasy VII Remake",
    "Persona 5", "Granblue Fantasy", "Fate Grand Order", "Arknights",
    "Blue Archive", "Punishing Gray Raven", "Tower of Fantasy", "Wuthering Waves",
    "Project Sekai", "Hololive", "VSpo", "Nijisanji",
    "Vtuber Games", "Twitch Prime Gaming", "PlayStation Plus", "Xbox Game Pass",
    "EA Play", "Ubisoft Plus", "Amazon Luna", "GeForce Now",
    "Shadow Cloud Gaming", "PlayKey", "Blade", "Paperspace",
    "Big Fish Games", "PopCap Games", "King Digital", "Zynga",
    "Miniclip", "Scopely", "Scopeplay", "Playrix",
    "Playable Inc", "Crazy Labs", "SayGames", "Voodoo",
    "Hypercasual Games", "Casual Games", "Mobile Games", "Browser Games"
];

$types = ['bank', 'electronic', 'crypto', 'wallet', 'social', 'game'];
$names = [
    'bank' => $bankNames,
    'electronic' => $electronicPayments,
    'crypto' => $cryptoCurrencies,
    'wallet' => $wallets,
    'social' => $socialMedia,
    'game' => $games
];

$added = 0;
$errors = [];

foreach ($types as $type) {
    $typeNames = $names[$type];
    $count = 100;

    for ($i = 1; $i <= $count; $i++) {
        // عينة عشوائية من الأسماء المتاحة
        $name = $typeNames[($i - 1) % count($typeNames)] . " (#$i)";
        $code = strtolower(preg_replace('/[^a-z0-9]/i', '', str_replace([' ', '(', ')', '#'], '', $name)));
        $code = substr($code, 0, 20) . "_" . $i; // تأكد من الفريدية

        // التحقق من عدم التكرار
        $exists = $db->find('payment_gateways', ['code' => $code]);
        if ($exists) {
            $code = $type . "_" . rand(10000, 99999);
        }

        try {
            // البيانات الأساسية
            $config = json_encode([
                'currencies' => ['USD', 'EUR', 'AED', 'SAR'],
                'fees' => ['percentage' => 2.5, 'fixed' => 0.30],
                'limits' => ['min' => 1, 'max_daily' => 50000, 'max_monthly' => 250000],
                'region' => 'Global',
                'environment' => 'test'
            ]);

            $credentials = json_encode([
                'api_key' => 'demo_' . bin2hex(random_bytes(16)),
                'api_secret' => bin2hex(random_bytes(32)),
                'merchant_id' => 'merchant_' . $i
            ]);

            $settings = json_encode([
                'webhook_url' => 'https://example.com/webhook',
                'callback_url' => 'https://example.com/callback',
                'timeout' => 30,
                'retry_attempts' => 3,
                'environment' => 'test'
            ]);

            // إدراج البوابة
            $db->insert('payment_gateways', [
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'status' => rand(0, 1) ? 'active' : 'inactive',
                'config' => $config,
                'credentials' => $credentials,
                'settings' => $settings,
                'connection_type' => in_array($type, ['crypto', 'wallet']) ? 'web3' : 'rest',
                'api_endpoint' => 'https://api.example.com/v1',
                'api_version' => 'v1',
                'gateway_type' => $type === 'crypto' ? 'crypto' : ($type === 'wallet' ? 'wallet' : 'card'),
                'supports_2d' => 1,
                'supports_3d' => $type !== 'crypto' ? 1 : 0,
                'supports_hold' => $type === 'electronic' ? 1 : 0,
                'supports_capture' => $type === 'electronic' ? 1 : 0,
                'connection_status' => 'untested',
                'sort_order' => $i
            ]);

            $added++;
        } catch (Exception $e) {
            $errors[] = "Error adding $code: " . $e->getMessage();
        }
    }
}

// النتيجة
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => count($errors) === 0,
    'added' => $added,
    'errors' => $errors,
    'message' => "تمت إضافة $added اتصال جديد بنجاح!"
], JSON_UNESCAPED_UNICODE);
