<?php
/**
 * DI PARMA | API v1 Router
 * يوجّه الطلبات للـ endpoints الصحيحة
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: X-Api-Key, X-Timestamp, X-Signature, Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204); exit;
}

// الإصدار والـ endpoint من URL
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
// يعمل سواء كان التطبيق على دومين الجذر أو داخل مجلد XAMPP مثل /DIPARMA40.
$path   = preg_replace('#^.*?/api/v1(?:/|$)#', '', $path);
$path   = trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];

$routes = [
    'GET'  => [
        'balance'      => __DIR__ . '/balance.php',
        'transactions' => __DIR__ . '/transactions.php',
        'docs'         => __DIR__ . '/docs.php',
        ''             => __DIR__ . '/docs.php',
    ],
    'POST' => [
        'charge'       => __DIR__ . '/charge.php',
        'refund'       => __DIR__ . '/charge.php',  // نفس الملف مع txn_type=refund
        'void'         => __DIR__ . '/charge.php',  // نفس الملف مع txn_type=void
        'capture'      => __DIR__ . '/charge.php',
    ],
];

$file = $routes[$method][$path] ?? null;
if ($file && file_exists($file)) {
    require $file;
    exit;
}

http_response_code(404);
echo json_encode([
    'success' => false,
    'error'   => 'not_found',
    'message' => "Endpoint /{$method} {$path} not found",
    'docs'    => 'https://diparmas.com/api/v1/docs',
]);
