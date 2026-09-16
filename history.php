<?php
/**
 * Backward-compatible redirect: old history.php → transactions.php
 */
header('Location: transactions.php', true, 301);
exit;
