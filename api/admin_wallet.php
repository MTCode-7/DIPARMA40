<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';
if(session_status()===PHP_SESSION_NONE) session_start();
if(empty($_SESSION['user_id'])||($_SESSION['role']??'')!=='admin'){
    http_response_code(401);echo json_encode(['success'=>false,'message'=>'غير مصرّح']);exit;
}
require_once __DIR__ . '/../lib/WalletManager.php';

$p  = json_decode(file_get_contents('php://input'),true) ?: $_POST;
if(!verifyCsrfToken($p['csrf_token']??'')){echo json_encode(['success'=>false,'message'=>'CSRF غير صالح']);exit;}

$db     = db();
$action = $p['action'] ?? '';

switch($action){

    case 'admin_adjust':
        $uid      = intval($p['user_id']??0);
        $type     = $p['type']??'admin_credit'; // admin_credit | admin_debit
        $wallet   = $p['wallet_type']??'fiat';  // fiat | crypto
        $currency = strtoupper(trim($p['currency']??'USD'));
        $amount   = floatval($p['amount']??0);
        $note     = trim($p['note']??'');
        $network  = strtoupper(trim($p['network']??'TRC20'));

        if($amount<=0){echo json_encode(['success'=>false,'message'=>'مبلغ غير صالح']);break;}

        $ref = 'ADJ'.date('Ymd').strtoupper(substr(bin2hex(random_bytes(4)),0,8));

        try {
            if($wallet==='fiat'){
                if($type==='admin_credit'){
                    $db->query(
                        "INSERT INTO user_fiat_wallets (user_id,currency,balance) VALUES (?,?,?)
                         ON DUPLICATE KEY UPDATE balance=balance+?",
                        [$uid,$currency,$amount,$amount]
                    );
                } else {
                    $db->query(
                        "UPDATE user_fiat_wallets SET balance=GREATEST(0,balance-?) WHERE user_id=? AND currency=?",
                        [$amount,$uid,$currency]
                    );
                }
            } else {
                if($type==='admin_credit'){
                    $db->query(
                        "INSERT INTO user_crypto_wallets (user_id,coin,network,balance) VALUES (?,?,?,?)
                         ON DUPLICATE KEY UPDATE balance=balance+?",
                        [$uid,$currency,$network,$amount,$amount]
                    );
                } else {
                    $db->query(
                        "UPDATE user_crypto_wallets SET balance=GREATEST(0,balance-?) WHERE user_id=? AND coin=? AND network=?",
                        [$amount,$uid,$currency,$network]
                    );
                }
            }

            $db->query(
                "INSERT INTO wallet_transactions (reference,user_id,type,wallet_type,currency,network,amount,fee,net_amount,status,note)
                 VALUES (?,?,?,?,?,?,?,0,?,'completed',?)",
                [$ref,$uid,$type,$wallet,$currency,$network,$amount,$amount,$note?:"تعديل يدوي من الإدارة"]
            );

            echo json_encode(['success'=>true,'reference'=>$ref]);
        } catch(Exception $e){
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        break;

    case 'approve_withdraw':
        $ref = trim($p['reference']??'');
        $txn = $db->fetchOne("SELECT * FROM wallet_transactions WHERE reference=?",[$ref]);
        if(!$txn){echo json_encode(['success'=>false,'message'=>'معاملة غير موجودة']);break;}
        if($txn['status']!=='pending'){echo json_encode(['success'=>false,'message'=>'الحالة ليست معلقة']);break;}

        require_once __DIR__ . '/../lib/HotWalletService.php';
        $hw = HotWalletService::getInstance();
        $tx = $hw->sendUSDT($ref,$txn['to_address'],$txn['net_amount'],$txn['user_id']);

        if($tx['success']){
            $db->query("UPDATE wallet_transactions SET status='completed',tx_hash=? WHERE reference=?",[$tx['tx_hash'],$ref]);
            $db->query("UPDATE user_crypto_wallets SET locked=GREATEST(0,locked-?) WHERE user_id=? AND coin=? AND network=?",
                [$txn['amount'],$txn['user_id'],$txn['coin'],$txn['network']]);
            echo json_encode(['success'=>true,'tx_hash'=>$tx['tx_hash']]);
        } else {
            echo json_encode(['success'=>false,'message'=>$tx['message']]);
        }
        break;

    case 'reject_withdraw':
        $ref = trim($p['reference']??'');
        $txn = $db->fetchOne("SELECT * FROM wallet_transactions WHERE reference=?",[$ref]);
        if(!$txn){echo json_encode(['success'=>false,'message'=>'معاملة غير موجودة']);break;}

        // استرداد المبلغ
        $db->query(
            "UPDATE user_crypto_wallets SET balance=balance+?,locked=GREATEST(0,locked-?) WHERE user_id=? AND coin=? AND network=?",
            [$txn['amount'],$txn['amount'],$txn['user_id'],$txn['coin'],$txn['network']]
        );
        $db->query("UPDATE wallet_transactions SET status='cancelled',note='رُفض من الإدارة' WHERE reference=?",[$ref]);
        echo json_encode(['success'=>true]);
        break;

    default:
        echo json_encode(['success'=>false,'message'=>'action غير معروف']);
}
