<?php
/**
 * شريط POS | CHECKOUT | LINK لكل بوابة.
 */
if (!function_exists('activity_channel_route')) {
    require_once __DIR__ . '/activity_flow.php';
}

function diparma_channel_query(array $extra = []): string
{
    $clean = [];
    foreach ($extra as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $clean[(string) $key] = $value;
    }
    return $clean === [] ? '' : ('?' . http_build_query($clean));
}

function diparma_gateway_channel_bar(
    string $code,
    string $active,
    string $basePath = '',
    bool $ar = false,
    array $extra = []
): string {
    $code = strtolower(trim($code));
    $active = strtolower(trim($active));
    if (!in_array($active, ['pos', 'checkout', 'link'], true)) {
        $active = 'checkout';
    }
    $q = diparma_channel_query($extra);
    $channels = [
        'pos' => [
            'label' => 'POS',
            'icon' => 'fas fa-cash-register',
            'href' => $basePath . activity_pos_route($code) . $q,
        ],
        'checkout' => [
            'label' => 'CHECKOUT',
            'icon' => 'fas fa-shopping-cart',
            'href' => $basePath . activity_channel_route($code, 'checkout') . $q,
        ],
        'link' => [
            'label' => 'LINK',
            'icon' => 'fas fa-link',
            'href' => $basePath . activity_link_route($code) . $q,
        ],
    ];
    $html = '<div class="gw-ch-bar" style="display:flex;gap:8px;flex-wrap:wrap;margin:10px 0 16px">';
    foreach ($channels as $key => $ch) {
        $on = $key === $active;
        $bg = $on ? 'linear-gradient(135deg,#FFD700,#FFB700)' : 'rgba(255,255,255,.04)';
        $color = $on ? '#000' : '#edf0f7';
        $border = $on ? 'transparent' : 'rgba(255,215,0,.18)';
        $html .= '<a href="' . htmlspecialchars($ch['href']) . '" style="text-decoration:none;background:' . $bg . ';color:' . $color . ';border:1.5px solid ' . $border . ';border-radius:12px;padding:8px 14px;font-weight:800;font-size:.75rem;display:inline-flex;align-items:center;gap:7px">'
            . '<i class="' . htmlspecialchars($ch['icon']) . '"></i> ' . htmlspecialchars($ch['label'])
            . '</a>';
    }
    $html .= '</div>';
    return $html;
}
