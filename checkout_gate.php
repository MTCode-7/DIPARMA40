<?php
header('Location: checkout/gate_io.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''), true, 302);
exit();
