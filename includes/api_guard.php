<?php
/**
 * Session+CSRF or HMAC API-key auth for payment endpoints.
 */
function dp_require_payment_auth(?array $payload): int
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $userId = intval($_SESSION['user_id'] ?? 0);
    if ($userId > 0) {
        if (!function_exists('verifyCsrfToken') || !verifyCsrfToken($payload['csrf_token'] ?? '')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        return $userId;
    }

    if (!class_exists('ApiAuth')) {
        require_once __DIR__ . '/../api/v1/ApiAuth.php';
    }

    try {
        $client = ApiAuth::verify();
        $uid = (int)($client['user_id'] ?? 0);
        if ($uid <= 0) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'API client is not assigned to a user account'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        return $uid;
    } catch (Throwable $e) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Authentication required'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
