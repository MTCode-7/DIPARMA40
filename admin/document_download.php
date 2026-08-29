<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/database.php';

requireAdmin();

$type = $_GET['type'] ?? '';
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || !in_array($type, ['kyc', 'support', 'brand'], true)) {
    http_response_code(400);
    exit('Invalid document request.');
}

$db = db();
$relativePath = null;
$downloadName = 'document';

if ($type === 'kyc') {
    $rows = $db->query('SELECT document_file, document_type FROM ' . DB_PREFIX . 'kyc_verifications WHERE id = ?', [$id]);
    $row = $rows[0] ?? null;
    if ($row) {
        $relativePath = $row['document_file'];
        $downloadName = 'kyc-' . $id . '-' . ($row['document_type'] ?: 'document');
    }
} elseif ($type === 'support') {
    $rows = $db->query('SELECT original_name, stored_path FROM ' . DB_PREFIX . 'support_documents WHERE id = ?', [$id]);
    $row = $rows[0] ?? null;
    if ($row) {
        $relativePath = $row['stored_path'];
        $downloadName = basename($row['original_name'] ?: 'support-document');
    }
} else {
    $rows = $db->query('SELECT brand_logo_path, brand_name FROM ' . DB_PREFIX . 'users WHERE id = ?', [$id]);
    $row = $rows[0] ?? null;
    if ($row) {
        $relativePath = $row['brand_logo_path'];
        $downloadName = ($row['brand_name'] ?: 'brand') . '-logo';
    }
}

if (!$relativePath) {
    http_response_code(404);
    exit('Document not found.');
}

$relativePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim((string)$relativePath, '/\\'));
$filePath = realpath(ROOT_PATH . DIRECTORY_SEPARATOR . $relativePath);
$allowedRoots = array_filter([
    realpath(ROOT_PATH . DIRECTORY_SEPARATOR . 'uploads'),
    realpath(ROOT_PATH . DIRECTORY_SEPARATOR . 'private_uploads'),
]);
$isAllowed = $filePath !== false && is_file($filePath);
if ($isAllowed) {
    $isAllowed = false;
    foreach ($allowedRoots as $root) {
        $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (strncmp($filePath, $prefix, strlen($prefix)) === 0) {
            $isAllowed = true;
            break;
        }
    }
}

if (!$isAllowed) {
    http_response_code(404);
    exit('Document unavailable.');
}

$mime = function_exists('mime_content_type') ? mime_content_type($filePath) : 'application/octet-stream';
header('Content-Type: ' . (is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream'));
header('Content-Length: ' . filesize($filePath));
header('Content-Disposition: inline; filename="' . rawurlencode($downloadName) . '"');
header('X-Content-Type-Options: nosniff');
readfile($filePath);
exit;
