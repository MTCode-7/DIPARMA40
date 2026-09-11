<?php
header('Location: checkout/myfatoorah.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''), true, 302);
exit();
