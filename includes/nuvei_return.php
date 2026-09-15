<?php
/**
 * Nuvei Control Panel Test URLs + Payment Page callbacks.
 * Docs: respond with HTTP 200 OK (My Integration Settings).
 * Payment confirmation is DMN-only, not this redirect.
 */
if (!function_exists('nuvei_send_ok_headers')) {
    function nuvei_send_ok_headers(): void
    {
        http_response_code(200);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, HEAD, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Accept');
        header('Allow: GET, POST, HEAD, OPTIONS');
        header_remove('X-Frame-Options');
    }
}

nuvei_send_ok_headers();
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'OPTIONS' || $method === 'HEAD') {
    exit;
}
echo 'OK';
