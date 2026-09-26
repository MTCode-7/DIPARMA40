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
        echo json_encode(['success'=>false,'message'=>'التعديل اليدوي للرصيد أُلغي. الرصيد يتغير فقط بعد دفع حي على البوابة.']);
        break;

    case 'approve_withdraw':
        $ref = trim($p['reference']??'');
        $txn = $db->fetchOne("SELECT * FROM " . dp_table('wallet_transactions') . " WHERE reference=?",[$ref]);
        if(!$txn){echo json_encode(['success'=>false,'message'=>'معاملة غير موجودة']);break;}
        if($txn['status']!=='pending'){echo json_encode(['success'=>false,'message'=>'الحالة ليست معلقة']);break;}

        require_once __DIR__ . '/../lib/HotWalletService.php';
        $hw = HotWalletService::getInstance();
        $tx = $hw->sendUSDT($ref,$txn['to_address'],$txn['net_amount'],$txn['user_id']);

        if($tx['success']){
            $db->query("UPDATE " . dp_table('wallet_transactions') . " SET status='completed',tx_hash=? WHERE reference=?",[$tx['tx_hash'],$ref]);
            $db->query("UPDATE " . dp_table('user_crypto_wallets') . " SET locked=GREATEST(0,locked-?) WHERE user_id=? AND coin=? AND network=?",
                [$txn['amount'],$txn['user_id'],$txn['coin'],$txn['network']]);
            echo json_encode(['success'=>true,'tx_hash'=>$tx['tx_hash']]);
        } else {
            echo json_encode(['success'=>false,'message'=>$tx['message']]);
        }
        break;

    case 'reject_withdraw':
        $ref = trim($p['reference']??'');
        $txn = $db->fetchOne("SELECT * FROM " . dp_table('wallet_transactions') . " WHERE reference=?",[$ref]);
        if(!$txn){echo json_encode(['success'=>false,'message'=>'معاملة غير موجودة']);break;}

        // استرداد المبلغ
        $db->query(
            "UPDATE " . dp_table('user_crypto_wallets') . " SET balance=balance+?,locked=GREATEST(0,locked-?) WHERE user_id=? AND coin=? AND network=?",
            [$txn['amount'],$txn['amount'],$txn['user_id'],$txn['coin'],$txn['network']]
        );
        $db->query("UPDATE " . dp_table('wallet_transactions') . " SET status='cancelled',note='رُفض من الإدارة' WHERE reference=?",[$ref]);
        echo json_encode(['success'=>true]);
        break;

    default:
        echo json_encode(['success'=>false,'message'=>'action غير معروف']);
}
