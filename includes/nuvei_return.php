<?php
/**
 * Nuvei Control Panel Test URLs + Payment Page redirects.
 * Control Panel iframes these URLs from cpanel.nuvei.com and requires HTTP 200.
 */
if (!headers_sent()) {
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, HEAD, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Accept');
    header('Allow: GET, POST, HEAD, OPTIONS');
    header('Content-Security-Policy: frame-ancestors *');
    header_remove('X-Frame-Options');
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'OPTIONS' || $method === 'HEAD') {
    exit;
}
echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>OK</title></head><body style="margin:0;background:#fff;color:#111;font:16px/1.4 sans-serif">OK</body></html>';
