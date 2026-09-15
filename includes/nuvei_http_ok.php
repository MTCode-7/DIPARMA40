<?php
/**
 * Nuvei Control Panel probes Success/Pending/Back/Failure URLs
 * and requires HTTP 200 without login or redirects.
 */
http_response_code(200);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'HEAD') {
    exit;
}

function nuvei_ok_page(string $title, string $ar, string $en, string $extra = ''): void
{
    $pos = '/pos/index.php';
    echo '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>DI PARMA | ' . htmlspecialchars($title) . '</title>';
    echo '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0b1220;color:#e5e7eb;font-family:Arial,sans-serif}.box{width:min(520px,92vw);background:#111827;border:1px solid #d4af37;border-radius:16px;padding:28px}a{display:inline-block;margin-top:16px;padding:10px 16px;border-radius:10px;background:#d4af37;color:#111;text-decoration:none;font-weight:700}</style>';
    echo '</head><body><div class="box"><h1>' . htmlspecialchars($ar) . '</h1>';
    echo '<p>' . htmlspecialchars($en) . '</p>';
    if ($extra !== '') {
        echo '<p style="color:#9ca3af;word-break:break-all">' . htmlspecialchars($extra) . '</p>';
    }
    echo '<p>OK</p><a href="' . htmlspecialchars($pos) . '">POS</a></div></body></html>';
}
