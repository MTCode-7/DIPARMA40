<?php
/**
 * DI PARMA | Wallet API
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';
if(session_status()===PHP_SESSION_NONE) session_start();
if(empty($_SESSION['user_id'])){http_response_code(401);echo json_encode(['success'=>false,'message'=>'غير مصرّح']);exit;}

require_once __DIR__ . '/../lib/WalletManager.php';

$action  = strtolower(trim($_GET['action']??''));
$payload = json_decode(file_get_contents('php://input'),true) ?: $_POST;
$userId  = intval($_SESSION['user_id']);
$wm      = WalletManager::getInstance();

// GET requests مثل recent_ledger لا تحتاج CSRF
$csrfFreeActions = ['recent_ledger', 'balance'];
if (!in_array($action, $csrfFreeActions) && !verifyCsrfToken($payload['csrf_token']??'')){
    echo json_encode(['success'=>false,'message'=>'CSRF غير صالح']); exit;
}

switch($action){

    case 'deposit':
        $amount   = floatval($payload['amount']??0);
        $currency = strtoupper(trim($payload['currency']??'USD'));
        $gateway  = strtolower(trim($payload['gateway']??'paypal'));
        if($amount < 10){ echo json_encode(['success'=>false,'message'=>'الحد الأدنى 10']); break; }

        // إنشاء معاملة دفع عبر PayPal/Binance/Gate.io
        require_once __DIR__ . '/../lib/CardPaymentService.php';
        $siteUrl = defined('SITE_URL') ? SITE_URL : 'https://diparmas.com';
        $ref = 'WDEP' . date('Ymd') . strtoupper(substr(bin2hex(random_bytes(4)),0,8));

        // حفظ طلب الإيداع مؤقتاً
        db()->query(
            "INSERT INTO wallet_transactions (reference,user_id,type,wallet_type,currency,amount,fee,net_amount,status,gateway,note)
             VALUES (?,?,'deposit','fiat',?,?,?,?,'pending',?,?)",
            [$ref,$userId,$currency,$amount,round($amount*1.5/100,4),round($amount*0.985,4),$gateway,"إيداع معلق — بانتظار الدفع"]
        );

        $payResult = CardPaymentService::getInstance()->createPayment([
            'reference'     => $ref,
            'amount'        => $amount,
            'currency'      => strtolower($currency),
            'email'         => $_SESSION['email'] ?? 'user@diparmas.com',
            'user_id'       => $userId,
            'card_provider' => $gateway,
            'metadata'      => ['type'=>'wallet_deposit','user_id'=>$userId],
        ]);

        if(!$payResult['success']){
            echo json_encode($payResult); break;
        }

        $redirect = $payResult['checkout_url'] ?? $payResult['approve_url'] ?? '';
        echo json_encode(['success'=>true,'reference'=>$ref,'redirect'=>$redirect,'provider'=>$gateway]);
        break;

    case 'deposit_confirm':
        // يُستدعى بعد عودة المستخدم من بوابة الدفع
        $ref = trim($payload['reference']??'');
        if(empty($ref)){ echo json_encode(['success'=>false,'message'=>'reference مطلوب']); break; }

        $txn = db()->fetchOne("SELECT * FROM wallet_transactions WHERE reference=? AND user_id=?",[$ref,$userId]);
        if(!$txn){ echo json_encode(['success'=>false,'message'=>'معاملة غير موجودة']); break; }
        if($txn['status']==='completed'){ echo json_encode(['success'=>true,'message'=>'مكتمل مسبقاً']); break; }

        $result = $wm->depositFiat($userId,$txn['amount'],$txn['currency'],$txn['gateway'],$ref);
        if($result['success']){
            db()->query("UPDATE wallet_transactions SET status='completed' WHERE reference=?",[$ref]);
        }
        echo json_encode($result);
        break;

    case 'convert':
        $amount  = floatval($payload['amount']??0);
        $fiat    = strtoupper(trim($payload['fiat_currency']??'USD'));
        $coin    = strtoupper(trim($payload['coin']??'USDT'));
        $network = strtoupper(trim($payload['network']??'TRC20'));
        if($amount <= 0){ echo json_encode(['success'=>false,'message'=>'مبلغ غير صالح']); break; }
        echo json_encode($wm->convertFiatToCrypto($userId,$amount,$fiat,$coin,$network));
        break;

    case 'withdraw':
        $amount   = floatval($payload['amount']??0);
        $coin     = strtoupper(trim($payload['coin']??'USDT'));
        $network  = strtoupper(trim($payload['network']??'TRC20'));
        $addr     = trim($payload['to_address']??'');
        $skipLock = !empty($payload['skip_lock']) && ($_SESSION['role']??'') === 'admin';
        if($amount <= 0){ echo json_encode(['success'=>false,'message'=>'مبلغ غير صالح']); break; }
        if(empty($addr)){ echo json_encode(['success'=>false,'message'=>'العنوان مطلوب']); break; }
        echo json_encode($wm->withdrawCrypto($userId,$amount,$coin,$network,$addr,$skipLock));
        break;

    case 'balance':
        $fiats   = $wm->getFiatWallets($userId);
        $cryptos = $wm->getCryptoWallets($userId);
        echo json_encode(['success'=>true,'fiat'=>$fiats,'crypto'=>$cryptos]);
        break;

    case 'recent_ledger':
        // آخر عمليات Ledger POS
        $limit = min(20, intval($_GET['limit'] ?? 8));
        try {
            $rows = db()->query(
                "SELECT reference, amount, currency, status, transaction_type, created_at
                 FROM dp_transactions
                 WHERE gateway = 'diparma_ledger'
                 ORDER BY id DESC LIMIT ?",
                [$limit]
            );
            echo json_encode(['success' => true, 'transactions' => $rows ?: []]);
        } catch (Exception $e) {
            echo json_encode(['success' => true, 'transactions' => []]);
        }
        break;

    default:
        echo json_encode(['success'=>false,'message'=>'action غير معروف']);
}
