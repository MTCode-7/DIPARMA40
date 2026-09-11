<?php
header('Location: checkout/bank_nbe.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''), true, 302);
exit();
