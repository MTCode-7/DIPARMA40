<?php
require dirname(__DIR__) . '/includes/config.php';
require dirname(__DIR__) . '/pos/lib/operations.php';
require dirname(__DIR__) . '/lib/Adapters/PayPalAdapter.php';
require dirname(__DIR__) . '/lib/Adapters/SquareAdapter.php';
$p = new PayPalAdapter();
$s = new SquareAdapter();
$modes = ['2D', '3D', 'MOTO', 'OFFLINE', 'CHARGE', 'HOLD', 'CAPTURE', 'REFUND', 'CANCEL', 'ADVICE'];
$bad = [];
foreach ($modes as $m) {
    if (!$p->supports($m)) {
        $bad[] = 'paypal ' . $m;
    }
    if (!$s->supports($m)) {
        $bad[] = 'square ' . $m;
    }
}
echo $bad === [] ? "supports=ok\n" : ("supports=fail " . implode(',', $bad) . "\n");
echo method_exists($p, 'reauthorize') ? "reauthorize=yes\n" : "reauthorize=no\n";
echo 'label=' . pos_approval_receipt_label('123456789012') . "\n";
echo 'match=' . (pos_approval_codes_match('123456789012', '789012') ? 'yes' : 'no') . "\n";
echo 'full=' . (pos_approval_codes_match('123456789012', '123456789012') ? 'yes' : 'no') . "\n";
exit($bad === [] && pos_approval_receipt_label('123456789012') === '789012' ? 0 : 1);
