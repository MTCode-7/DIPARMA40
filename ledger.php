<?php
/**
 * Backward-compatible redirect: old ledger.php → ledger/
 */
header('Location: ledger/', true, 301);
exit;
