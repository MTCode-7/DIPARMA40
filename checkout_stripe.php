<?php
header('Location: checkout/stripe.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''), true, 302);
exit();
