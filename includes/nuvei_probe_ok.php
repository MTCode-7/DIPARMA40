<?php
http_response_code(200);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Content-Security-Policy: frame-ancestors *');
header_remove('X-Frame-Options');
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'HEAD') {
    exit;
}
echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>OK</title></head><body style="margin:0;background:#fff;color:#111;font:16px/1.4 sans-serif">OK</body></html>';
