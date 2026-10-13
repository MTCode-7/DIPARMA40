<?php
/**
 * DI PARMA | API v1 Router
 * يوجّه الطلبات للـ endpoints الصحيحة
 */
ini_set('display_errors', '0');
ob_start();

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    if (($error['file'] ?? '') !== __FILE__) {
        return;
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'success' => false,
        'error' => 'server_error',
        'message' => 'Internal server error',
    ]);
});

try {
    require_once __DIR__ . '/../../includes/config.php';
} catch (Throwable $e) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => 'server_error',
        'message' => 'Internal server error',
    ]);
    exit;
}

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: X-Api-Key, X-Timestamp, X-Signature, Content-Type, Authorization');

$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))));
if ($requestOrigin !== '' && in_array($requestOrigin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $requestOrigin);
    header('Vary: Origin');
}

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
