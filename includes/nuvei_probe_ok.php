<?php
http_response_code(200);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'HEAD') {
    exit;
}
echo 'OK';
