<?php
/**
 * ============================================================
 * DI PARMA | 13 ظ†ظˆط¹ ط´ط±ط§ط، ط­ظ‚ظٹظ‚ظٹ ظ…ظ† ط§ظ„ط¨ط·ط§ظ‚ط© â†’ Ledger
 * ============================================================
 * 
 * ظ‡ط°ط§ ط§ظ„ظ…ظ„ظپ ظ‡ظˆ ط¬ظˆظ‡ط± ظ†ط¸ط§ظ… ط§ظ„ط¯ظپط¹ ظپظٹ DI PARMA
 * ظٹط¯ط¹ظ… 13 ظ†ظˆط¹ط§ظ‹ ظ…ط®طھظ„ظپط§ظ‹ ظ…ظ† ط¹ظ…ظ„ظٹط§طھ ط§ظ„ط´ط±ط§ط،
 * ط¬ظ…ظٹط¹ظ‡ط§ ط­ظ‚ظٹظ‚ظٹط© 100% ط¨ط¯ظˆظ† ط£ظٹ ظ…ط­ط§ظƒط§ط©
 * 
 * ============================================================
 * ط§ظ„ط£ظ†ظˆط§ط¹ ط§ظ„ظ…ط¯ط¹ظˆظ…ط© (13 ظ†ظˆط¹):
 * 
 * â”€â”€â”€ ظ…ط´طھط±ظٹط§طھ 2D / MOTO â”€â”€â”€
 * 1.  purchase_2d      â†’ ط´ط±ط§ط، 2D / MOTO ط¹ط§ظ…
 * 2.  purchase_advice  â†’ ط´ط±ط§ط، ط¥ط±ط´ط§ط¯ظٹ (Advice) - ISO 0220
 * 3.  purchase_offline â†’ ظ…ط¨ظٹط¹ط§طھ ط®ط§ط±ط¬ ط§ظ„ط®ط· (Offline MOTO)
 * 4.  purchase_online  â†’ ظ…ط¨ظٹط¹ط§طھ ط¹ط¨ط± ط§ظ„ط¥ظ†طھط±ظ†طھ (Online MOTO)
 * 
 * â”€â”€â”€ ظ…ط´طھط±ظٹط§طھ 3D Secure â”€â”€â”€
 * 5.  purchase_3d      â†’ ط´ط±ط§ط، ظ…ط¹ 3D Secure
 * 6.  auth_hold        â†’ طھط¬ظ…ظٹط¯ ظ…ط¨ظ„ط؛ (Authorization Hold)
 * 7.  auth_capture     â†’ طھط£ظƒظٹط¯ ط§ظ„طھط¬ظ…ظٹط¯ (Auth Capture)
 * 
 * â”€â”€â”€ ظ…ط´طھط±ظٹط§طھ ظ…طھط®طµطµط© â”€â”€â”€
 * 8.  recurring        â†’ ط´ط±ط§ط، ظ…طھظƒط±ط± (ط§ط´طھط±ط§ظƒ)
 * 9.  installment      â†’ ط´ط±ط§ط، ط¨ط§ظ„طھظ‚ط³ظٹط·
 * 10. crypto_purchase  â†’ ط´ط±ط§ط، ط¹ظ…ظ„ط§طھ ط±ظ‚ظ…ظٹط©
 * 11. gift_card        â†’ ط´ط±ط§ط، ط¨ط·ط§ظ‚ط© ظ‡ط¯ط§ظٹط§
 * 12. wire_transfer    â†’ طھط­ظˆظٹظ„ ط¨ظ†ظƒظٹ ظ…ط¨ط§ط´ط±
 * 13. quasi_cash       â†’ ط³ط­ط¨ ظ†ظ‚ط¯ظٹ ط´ط¨ظٹظ‡ (Quasi Cash)
 * 
 * ============================================================
 * ط§ظ„ط¹ظ…ظ„ظ‡ ط§ظ„ط£ط³ط§ط³ظٹط©: USD (ط¯ظˆظ„ط§ط± ط£ظ…ط±ظٹظƒظٹ)
 * ط¬ظ…ظٹط¹ ط§ظ„ظ…ط¹ط§ظ…ظ„ط§طھ ط­ظ‚ظٹظ‚ظٹط© 100% ط¨ط¯ظˆظ† ظ…ط­ط§ظƒط§ط©
 * طھطھظƒط§ظ…ظ„ ظ…ط¹ Ledger Nano X ط¹ط¨ط± TronGrid
 * ============================================================
 */

// ============================================================
// [1] ط¥ط¹ط¯ط§ط¯ط§طھ ط§ظ„ط±ط£ط³ ظˆط§ظ„ط£ظ…ط§ظ†
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Api-Key, X-Timestamp, X-Signature, X-Transaction-Type');

/**
 * ظ…ط¹ط§ظ„ط¬ط© ط·ظ„ط¨ط§طھ OPTIONS (CORS Preflight)
 * ط§ظ„ظ…طھطµظپط­ ظٹط±ط³ظ„ ط·ظ„ط¨ OPTIONS ظ‚ط¨ظ„ ط§ظ„ط·ظ„ط¨ ط§ظ„ظپط¹ظ„ظٹ ظ„ظ„طھط­ظ‚ظ‚ ظ…ظ† طµظ„ط§ط­ظٹط§طھ CORS
 */
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/**
 * ظ‚ط¨ظˆظ„ ط·ظ„ط¨ط§طھ POST ظپظ‚ط·
 * ط¬ظ…ظٹط¹ ط¹ظ…ظ„ظٹط§طھ ط§ظ„ط¯ظپط¹ طھطھظ… ط¹ط¨ط± POST ظ„ط£ط³ط¨ط§ط¨ ط£ظ…ظ†ظٹط©
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $query = http_build_query($_GET);
    header('Location: ../checkout_nuvei.php' . ($query !== '' ? '?' . $query : ''), true, 302);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method Not Allowed. Use POST.'
    ]);
    exit;
}

// ============================================================
// [2] ط§ط³طھظٹط±ط§ط¯ ط§ظ„ظ…ظ„ظپط§طھ ط§ظ„ظ…ط·ظ„ظˆط¨ط©
// ============================================================

require_once __DIR__ . '/../includes/config.php';      // ط¥ط¹ط¯ط§ط¯ط§طھ ط§ظ„ظ†ط¸ط§ظ…
require_once __DIR__ . '/../includes/database.php';    // ط§ظ„ط§طھطµط§ظ„ ط¨ظ‚ط§ط¹ط¯ط© ط§ظ„ط¨ظٹط§ظ†ط§طھ
require_once __DIR__ . '/../includes/functions.php';   // ط¯ظˆط§ظ„ ظ…ط³ط§ط¹ط¯ط© ط¹ط§ظ…ط©

// ============================================================
// [3] طھط¹ط±ظٹظپ ط£ظ†ظˆط§ط¹ ط§ظ„ط¹ظ…ظ„ظٹط§طھ ط§ظ„ظ€ 13
// ============================================================

/**
 * طھط¹ط±ظٹظپ ط¬ظ…ظٹط¹ ط£ظ†ظˆط§ط¹ ط§ظ„ط¹ظ…ظ„ظٹط§طھ ط§ظ„ظ…ط¯ط¹ظˆظ…ط©
 * ظƒظ„ ظ†ظˆط¹ ظ„ظ‡ ط®طµط§ط¦طµظ‡ ط§ظ„ط®ط§طµط©:
 * - id: ط±ظ‚ظ… طھط¹ط±ظٹظپ ط§ظ„ظ†ظˆط¹
 * - label: ط§ط³ظ… ط§ظ„ظ†ظˆط¹ (ط¹ط±ط¨ظٹ/ط¥ظ†ط¬ظ„ظٹط²ظٹ)
 * - iso: ظ†ظˆط¹ ط±ط³ط§ظ„ط© ISO (0200, 0100, 0220, 0400, 0420)
 * - security: ظ†ظˆط¹ ط§ظ„ط£ظ…ط§ظ† (3D ط£ظˆ 2D)
 * - category: طھطµظ†ظٹظپ ط§ظ„ط¹ظ…ظ„ظٹط©
 * - requires_original: ظ‡ظ„ ظٹطھط·ظ„ط¨ ظ…ط±ط¬ط¹ ط£طµظ„ظٹطں
 * - settlement_days: ط¹ط¯ط¯ ط£ظٹط§ظ… ط§ظ„طھط³ظˆظٹط©
 * - type: ظ†ظˆط¹ ط§ظ„ط¨ظˆط§ظ‚ط© (card, crypto, bank)
 * - moto_indicator: ظ…ط¤ط´ط± MOTO (M, T, F, E)
 * - advice: ظ‡ظ„ ظ‡ظٹ ظ…ط¹ط§ظ…ظ„ط© ط¥ط±ط´ط§ط¯ظٹط©طں
 * - offline: ظ‡ظ„ ظ‡ظٹ ط®ط§ط±ط¬ ط§ظ„ط®ط·طں
 * - description: ظˆطµظپ ط§ظ„ط¹ظ…ظ„ظٹط©
 * - risk_level: ظ…ط³طھظˆظ‰ ط§ظ„ظ…ط®ط§ط·ط±ط©
 */
$TRANSACTION_TYPES = [
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    // 1. PURCHASE 2D / MOTO - ط´ط±ط§ط، 2D ط¹ط§ظ…
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    'purchase_2d' => [
        'id' => '01',
        'label' => 'ط´ط±ط§ط، 2D / MOTO',
        'iso' => '0200',
        'security' => '2D',
        'category' => 'moto',
        'requires_original' => false,
        'settlement_days' => 1,
        'type' => 'card',
        'moto_indicator' => 'M',
        'advice' => false,
        'offline' => false,
        'description' => 'ط´ط±ط§ط، ط¹ط§ظ… ط¨ط¯ظˆظ† 3D Secure (MOTO)',
        'risk_level' => 'medium',
        'icon' => 'fa-credit-card',
        'color' => '#3B82F6'
    ],
    
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    // 2. PURCHASE ADVICE - ط´ط±ط§ط، ط¥ط±ط´ط§ط¯ظٹ
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    'purchase_advice' => [
        'id' => '02',
        'label' => 'ط´ط±ط§ط، ط¥ط±ط´ط§ط¯ظٹ (Advice)',
        'iso' => '0220',  // ISO 0220 = Advice Message
        'security' => '2D',
        'category' => 'advice',
        'requires_original' => true,  // ظٹطھط·ظ„ط¨ ظ…ط±ط¬ط¹ ط£طµظ„ظٹ
        'settlement_days' => 1,
        'type' => 'card',
        'moto_indicator' => null,
        'advice' => true,
        'offline' => false,
        'description' => 'ظ…ط¹ط§ظ…ظ„ط© ط¥ط±ط´ط§ط¯ظٹط© ط¨ط¹ط¯ ظ…ظˆط§ظپظ‚ط© ظ…ط³ط¨ظ‚ط© ظ…ظ† ط§ظ„ط¨ظ†ظƒ',
        'risk_level' => 'low',
        'icon' => 'fa-bell',
        'color' => '#F59E0B'
    ],
    
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    // 3. PURCHASE OFFLINE - ظ…ط¨ظٹط¹ط§طھ ط®ط§ط±ط¬ ط§ظ„ط®ط· (Offline MOTO)
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    'purchase_offline' => [
        'id' => '03',
        'label' => 'ظ…ط¨ظٹط¹ط§طھ ط®ط§ط±ط¬ ط§ظ„ط®ط· (Offline MOTO)',
        'iso' => '0200',
        'security' => '2D',
        'category' => 'offline',
        'requires_original' => false,
        'settlement_days' => 1,
        'type' => 'card',
        'moto_indicator' => 'M',
        'advice' => false,
        'offline' => true,
        'description' => 'ظ…ط¨ظٹط¹ط§طھ ط¹ط¨ط± ط§ظ„ظ‡ط§طھظپ/ط§ظ„ط¨ط±ظٹط¯/ظپط§ظƒط³ - MOTO',
        'risk_level' => 'medium',
        'icon' => 'fa-phone',
        'color' => '#8B5CF6',
        'offline_channels' => ['phone', 'mail', 'fax', 'other']
    ],
    
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    // 4. PURCHASE ONLINE - ظ…ط¨ظٹط¹ط§طھ ط¹ط¨ط± ط§ظ„ط¥ظ†طھط±ظ†طھ (Online MOTO)
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    'purchase_online' => [
        'id' => '04',
        'label' => 'ظ…ط¨ظٹط¹ط§طھ ط¹ط¨ط± ط§ظ„ط¥ظ†طھط±ظ†طھ (Online MOTO)',
        'iso' => '0200',
        'security' => '2D',
        'category' => 'online',
        'requires_original' => false,
        'settlement_days' => 1,
        'type' => 'card',
        'moto_indicator' => 'E',
        'advice' => false,
        'offline' => false,
        'description' => 'ظ…ط¨ظٹط¹ط§طھ ط¹ط¨ط± ط§ظ„ط¥ظ†طھط±ظ†طھ ظ…ط¹ طھطµظ†ظٹظپ MOTO',
        'risk_level' => 'low',
        'icon' => 'fa-globe',
        'color' => '#06B6D4'
    ],
    
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    // 5. PURCHASE 3D SECURE - ط´ط±ط§ط، ظ…ط¹ 3D Secure
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    'purchase_3d' => [
        'id' => '05',
        'label' => 'ط´ط±ط§ط، 3D Secure',
        'iso' => '0200',
        'security' => '3D',
        'category' => 'online',
        'requires_original' => false,
        'settlement_days' => 2,
        'type' => 'card',
        'moto_indicator' => null,
        'advice' => false,
        'offline' => false,
        'description' => 'ط´ط±ط§ط، ظ…ط¹ طھط­ظ‚ظ‚ 3D Secure ظ…ظ† ط§ظ„ط¨ظ†ظƒ ط§ظ„ظ…طµط¯ط±',
        'risk_level' => 'low',
        'icon' => 'fa-shield-alt',
        'color' => '#10B981'
    ],
    
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    // 6. AUTH HOLD - طھط¬ظ…ظٹط¯ ظ…ط¨ظ„ط؛
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    'auth_hold' => [
        'id' => '06',
        'label' => 'طھط¬ظ…ظٹط¯ ظ…ط¨ظ„ط؛ (Authorization Hold)',
        'iso' => '0100',  // ISO 0100 = Authorization Request
        'security' => '3D',
        'category' => 'auth',
        'requires_original' => false,
        'settlement_days' => 3,
        'type' => 'card',
        'moto_indicator' => null,
        'advice' => false,
        'offline' => false,
        'description' => 'طھط¬ظ…ظٹط¯ ط§ظ„ظ…ط¨ظ„ط؛ ظ…ط¤ظ‚طھط§ظ‹ ظ„ط­ظٹظ† طھط£ظƒظٹط¯ ط§ظ„ط¹ظ…ظ„ظٹط©',
        'risk_level' => 'low',
        'icon' => 'fa-lock',
        'color' => '#6366F1',
        'hold_days' => 7
    ],
    
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    // 7. AUTH CAPTURE - طھط£ظƒظٹط¯ ط§ظ„طھط¬ظ…ظٹط¯
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    'auth_capture' => [
        'id' => '07',
        'label' => 'طھط£ظƒظٹط¯ ط§ظ„طھط¬ظ…ظٹط¯ (Auth Capture)',
        'iso' => '0200',
        'security' => '3D',
        'category' => 'auth',
        'requires_original' => true,  // ظٹطھط·ظ„ط¨ ط§ظ„ظ…ط±ط¬ط¹ ط§ظ„ط£طµظ„ظٹ ظ„ظ„طھط¬ظ…ظٹط¯
        'settlement_days' => 1,
        'type' => 'card',
        'moto_indicator' => null,
        'advice' => false,
        'offline' => false,
        'description' => 'طھط£ظƒظٹط¯ ط§ظ„طھط¬ظ…ظٹط¯ ظˆطھط­ظˆظٹظ„ظ‡ ط¥ظ„ظ‰ ط´ط±ط§ط، ظƒط§ظ…ظ„',
        'risk_level' => 'low',
        'icon' => 'fa-check-double',
        'color' => '#8B5CF6'
    ],
    
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    // 8. RECURRING - ط´ط±ط§ط، ظ…طھظƒط±ط± (ط§ط´طھط±ط§ظƒ)
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    'recurring' => [
        'id' => '08',
        'label' => 'ط´ط±ط§ط، ظ…طھظƒط±ط± (ط§ط´طھط±ط§ظƒ)',
        'iso' => '0200',
        'security' => '3D',
        'category' => 'recurring',
        'requires_original' => false,
        'settlement_days' => 1,
        'type' => 'card',
        'moto_indicator' => null,
        'advice' => false,
        'offline' => false,
        'description' => 'ط¯ظپط¹ ظ…طھظƒط±ط± ط´ظ‡ط±ظٹ/ط³ظ†ظˆظٹ ظ„ظ„ط§ط´طھط±ط§ظƒط§طھ',
        'risk_level' => 'low',
        'icon' => 'fa-repeat',
        'color' => '#14B8A6',
        'recurring_indicator' => 'R',
        'frequencies' => ['monthly', 'quarterly', 'yearly']
    ],
    
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    // 9. INSTALLMENT - ط´ط±ط§ط، ط¨ط§ظ„طھظ‚ط³ظٹط·
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    'installment' => [
        'id' => '09',
        'label' => 'ط´ط±ط§ط، ط¨ط§ظ„طھظ‚ط³ظٹط·',
        'iso' => '0200',
        'security' => '3D',
        'category' => 'installment',
        'requires_original' => false,
        'settlement_days' => 1,
        'type' => 'card',
        'moto_indicator' => null,
        'advice' => false,
        'offline' => false,
        'description' => 'ط´ط±ط§ط، ظˆطھظ‚ط³ظٹظ… ط§ظ„ظ…ط¨ظ„ط؛ ط¹ظ„ظ‰ ط¹ط¯ط© ط¯ظپط¹ط§طھ',
        'risk_level' => 'low',
        'icon' => 'fa-calculator',
        'color' => '#F97316',
        'installment_indicator' => 'I',
        'min_installments' => 2,
        'max_installments' => 12
    ],
    
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    // 10. CRYPTO PURCHASE - ط´ط±ط§ط، ط¹ظ…ظ„ط§طھ ط±ظ‚ظ…ظٹط©
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    'crypto_purchase' => [
        'id' => '10',
        'label' => 'ط´ط±ط§ط، ط¹ظ…ظ„ط§طھ ط±ظ‚ظ…ظٹط©',
        'iso' => '0200',
        'security' => '2D',
        'category' => 'crypto',
        'requires_original' => false,
        'settlement_days' => 1,
        'type' => 'crypto',
        'moto_indicator' => null,
        'advice' => false,
        'offline' => false,
        'description' => 'ط´ط±ط§ط، USDT/BTC/ETH ط¨ط§ط³طھط®ط¯ط§ظ… ط§ظ„ط¨ط·ط§ظ‚ط©',
        'risk_level' => 'medium',
        'icon' => 'fab fa-bitcoin',
        'color' => '#F7931A',
        'crypto_currencies' => ['USDT', 'BTC', 'ETH', 'BNB', 'SOL', 'XRP', 'ADA', 'DOGE']
    ],
    
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    // 11. GIFT CARD - ط¨ط·ط§ظ‚ط© ظ‡ط¯ط§ظٹط§
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    'gift_card' => [
        'id' => '11',
        'label' => 'ط¨ط·ط§ظ‚ط© ظ‡ط¯ط§ظٹط§',
        'iso' => '0200',
        'security' => '2D',
        'category' => 'gift',
        'requires_original' => false,
        'settlement_days' => 1,
        'type' => 'card',
        'moto_indicator' => null,
        'advice' => false,
        'offline' => false,
        'description' => 'ط´ط±ط§ط، ط¨ط·ط§ظ‚ط© ظ‡ط¯ط§ظٹط§ ط±ظ‚ظ…ظٹط©',
        'risk_level' => 'low',
        'icon' => 'fa-gift',
        'color' => '#EC4899',
        'min_amount' => 5,
        'max_amount' => 500
    ],
    
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    // 12. WIRE TRANSFER - طھط­ظˆظٹظ„ ط¨ظ†ظƒظٹ ظ…ط¨ط§ط´ط±
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    'wire_transfer' => [
        'id' => '12',
        'label' => 'طھط­ظˆظٹظ„ ط¨ظ†ظƒظٹ ظ…ط¨ط§ط´ط±',
        'iso' => '0200',
        'security' => '2D',
        'category' => 'bank',
        'requires_original' => false,
        'settlement_days' => 3,
        'type' => 'bank',
        'moto_indicator' => null,
        'advice' => false,
        'offline' => false,
        'description' => 'طھط­ظˆظٹظ„ ظ…ط¨ظ„ط؛ ظ…ظ† ط§ظ„ط¨ط·ط§ظ‚ط© ط¥ظ„ظ‰ ط­ط³ط§ط¨ ط¨ظ†ظƒظٹ',
        'risk_level' => 'low',
        'icon' => 'fa-university',
        'color' => '#1E40AF'
    ],
    
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    // 13. QUASI CASH - ط³ط­ط¨ ظ†ظ‚ط¯ظٹ ط´ط¨ظٹظ‡
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    'quasi_cash' => [
        'id' => '13',
        'label' => 'ط³ط­ط¨ ظ†ظ‚ط¯ظٹ ط´ط¨ظٹظ‡ (Quasi Cash)',
        'iso' => '0200',
        'security' => '3D',
        'category' => 'cash',
        'requires_original' => false,
        'settlement_days' => 2,
        'type' => 'card',
        'moto_indicator' => null,
        'advice' => false,
        'offline' => false,
        'description' => 'ط³ط­ط¨ ظ†ظ‚ط¯ظٹ ط¹ط¨ط± ط§ظ„ط¨ط·ط§ظ‚ط© (ظƒط§ط²ظٹظ†ظˆظ‡ط§طھ/ظ…ط±ط§ظ‡ظ†ط§طھ)',
        'risk_level' => 'high',
        'icon' => 'fa-coins',
        'color' => '#FFD700',
        'max_amount' => 10000
    ],
];

// ============================================================
// [4] ظ‚ط±ط§ط،ط© ط¨ظٹط§ظ†ط§طھ ط§ظ„ط·ظ„ط¨
// ============================================================

/**
 * ظ‚ط±ط§ط،ط© ط§ظ„ط¨ظٹط§ظ†ط§طھ ط§ظ„ظ…ط±ط³ظ„ط© ظ…ظ† ط§ظ„ط¹ظ…ظٹظ„
 * طھط¯ط¹ظ… JSON ظˆ Form Data
 */
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

// ط¥ط°ط§ ظ„ظ… طھظƒظ† JSONطŒ ط­ط§ظˆظ„ ظ‚ط±ط§ط،ط© ظ…ظ† POST
if (!is_array($data) && !empty($_POST)) {
    $data = $_POST;
}

// ط¥ط°ط§ ظ„ظ… طھظƒظ† ظ‡ظ†ط§ظƒ ط¨ظٹط§ظ†ط§طھطŒ ط±ظپط¶ ط§ظ„ط·ظ„ط¨
if (!is_array($data) || empty($data)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid or missing request data'
    ]);
    exit;
}

// ============================================================
// [5] ط§ط³طھط®ط±ط§ط¬ ظ†ظˆط¹ ط§ظ„ط¹ظ…ظ„ظٹط© ظˆط§ظ„ط¨ظٹط§ظ†ط§طھ
// ============================================================

/**
 * ط§ط³طھط®ط±ط§ط¬ ط§ظ„ط¨ظٹط§ظ†ط§طھ ظ…ظ† ط§ظ„ط·ظ„ط¨
 * ط¬ظ…ظٹط¹ ط§ظ„ظ‚ظٹظ… ظٹطھظ… طھظ†ط¸ظٹظپظ‡ط§ ظˆطھط£ظƒظٹط¯ظ‡ط§
 */
$transactionType = trim($data['txn_type'] ?? $data['transaction_type'] ?? 'purchase_3d');
$amount = floatval($data['amount'] ?? 0);
$currency = strtoupper(trim($data['currency'] ?? 'USD'));
$cardNumber = preg_replace('/\D/', '', $data['card_number'] ?? $data['cardNumber'] ?? '');
$cardExpiry = trim($data['card_expiry'] ?? $data['cardExpiry'] ?? '');
$cardCvv = trim($data['card_cvv'] ?? $data['cardCvv'] ?? '');
$cardHolder = trim($data['card_holder'] ?? $data['cardName'] ?? $data['card_name'] ?? 'CARDHOLDER');
$email = trim($data['email'] ?? $data['customer_email'] ?? '');
$phone = trim($data['phone'] ?? $data['customer_phone'] ?? '');
$reference = trim($data['reference'] ?? '');
$ledgerAddress = trim($data['ledger_address'] ?? $data['ledgerAddr'] ?? '');
$originalReference = trim($data['original_reference'] ?? $data['orig_ref'] ?? '');
$originalAuthCode = trim($data['original_auth_code'] ?? '');
$motoIndicator = strtoupper(trim($data['moto_indicator'] ?? $data['motoIndicator'] ?? ''));
$installmentCount = intval($data['installment_count'] ?? $data['installments'] ?? 0);
$recurringFrequency = trim($data['recurring_frequency'] ?? $data['frequency'] ?? 'monthly');
$cryptoCurrency = strtoupper(trim($data['crypto_currency'] ?? $data['cryptoCurrency'] ?? 'USDT'));
$giftCardAmount = floatval($data['gift_card_amount'] ?? $data['giftAmount'] ?? 0);
$offlineChannel = trim($data['offline_channel'] ?? $data['channel'] ?? 'phone');
$purpose = trim($data['purpose'] ?? 'Gaming/Entertainment');
$bankAccount = $data['bank_account'] ?? $data['bankAccount'] ?? [];
$billingAddress = $data['billing_address'] ?? $data['billingAddress'] ?? [];
$returnUrl = trim($data['return_url'] ?? $data['returnUrl'] ?? 'https://diparmas.com/receipt.php');
$autoTransfer = isset($data['auto_transfer']) ? (bool)$data['auto_transfer'] : true;

/**
 * طھظˆظ„ظٹط¯ ظ…ط±ط¬ط¹ ظپط±ظٹط¯ ط¥ط°ط§ ظ„ظ… ظٹطھظ… ط¥ط±ط³ط§ظ„ظ‡
 * ط§ظ„طµظٹط؛ط©: DP + ظ†ظˆط¹ ط§ظ„ط¹ظ…ظ„ظٹط© + ط§ظ„طھط§ط±ظٹط® + ط¹ط´ظˆط§ط¦ظٹ
 */
if (empty($reference)) {
    $prefix = 'DP';
    $typePrefix = strtoupper(substr($transactionType, 0, 3));
    $date = date('Ymd');
    $random = strtoupper(bin2hex(random_bytes(4)));
    $reference = $prefix . '_' . $typePrefix . '_' . $date . '_' . $random;
}

// ============================================================
// [6] ط§ظ„طھط­ظ‚ظ‚ ظ…ظ† ظ†ظˆط¹ ط§ظ„ط¹ظ…ظ„ظٹط©
// ============================================================

/**
 * ط§ظ„طھط£ظƒط¯ ظ…ظ† ط£ظ† ظ†ظˆط¹ ط§ظ„ط¹ظ…ظ„ظٹط© ظ…ط¯ط¹ظˆظ…
 * ط¥ط°ط§ ظ„ظ… ظٹظƒظ† ظ…ط¯ط¹ظˆظ…ط§ظ‹طŒ ظ†ط¹ط±ط¶ ظ‚ط§ط¦ظ…ط© ط§ظ„ط£ظ†ظˆط§ط¹ ط§ظ„ظ…ط¯ط¹ظˆظ…ط©
 */
if (!isset($TRANSACTION_TYPES[$transactionType])) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Unsupported transaction type',
        'supported_types' => array_keys($TRANSACTION_TYPES),
        'supported_types_labels' => array_column($TRANSACTION_TYPES, 'label'),
    ]);
    exit;
}

$txnDef = $TRANSACTION_TYPES[$transactionType];

// ============================================================
// [7] ط§ظ„طھط­ظ‚ظ‚ ظ…ظ† طµط­ط© ط§ظ„ط¨ظٹط§ظ†ط§طھ ط­ط³ط¨ ظ†ظˆط¹ ط§ظ„ط¹ظ…ظ„ظٹط©
// ============================================================

$errors = [];

/**
 * 7.1 ط§ظ„طھط­ظ‚ظ‚ ظ…ظ† ط§ظ„ظ…ط¨ظ„ط؛
 */
if ($amount <= 0) {
    $errors[] = 'Amount must be greater than 0';
}

/**
 * 7.2 ط§ظ„ط­ط¯ ط§ظ„ط£ظ‚طµظ‰ ظ„ظ„ظ…ط¨ظ„ط؛ ط­ط³ط¨ ظ†ظˆط¹ ط§ظ„ط¹ظ…ظ„ظٹط©
 */
$maxAmounts = [
    'purchase_2d' => 25000,
    'purchase_advice' => 100000,
    'purchase_offline' => 25000,
    'purchase_online' => 25000,
    'purchase_3d' => 50000,
    'auth_hold' => 100000,
    'auth_capture' => 100000,
    'recurring' => 10000,
    'installment' => 50000,
    'crypto_purchase' => 25000,
    'gift_card' => 500,
    'wire_transfer' => 100000,
    'quasi_cash' => 10000,
];

if (isset($maxAmounts[$transactionType]) && $amount > $maxAmounts[$transactionType]) {
    $errors[] = 'Amount exceeds maximum allowed (' . number_format($maxAmounts[$transactionType], 2) . ' USD)';
}

/**
 * 7.3 ط§ظ„طھط­ظ‚ظ‚ ظ…ظ† ط§ظ„ط¹ظ…ظ„ط©
 */
$allowedCurrencies = ['USD', 'EUR', 'GBP', 'AED', 'SAR', 'EGP', 'KWD', 'QAR', 'OMR', 'BHD'];
if (!in_array($currency, $allowedCurrencies)) {
    $errors[] = 'Unsupported currency: ' . $currency . '. Supported: ' . implode(', ', $allowedCurrencies);
}

/**
 * 7.4 ط§ظ„طھط­ظ‚ظ‚ ظ…ظ† ط§ظ„ط¨ط·ط§ظ‚ط© (ظ„ط£ظ†ظˆط§ط¹ ط§ظ„ط¨ط·ط§ظ‚ط§طھ)
 */
$cardTypes = ['purchase_2d', 'purchase_advice', 'purchase_offline', 'purchase_online', 'purchase_3d', 'auth_hold', 'auth_capture', 'recurring', 'installment', 'gift_card', 'quasi_cash'];

if (in_array($txnDef['type'], ['card', 'crypto'])) {
    
    // 7.4.1 ط±ظ‚ظ… ط§ظ„ط¨ط·ط§ظ‚ط©
    if (empty($cardNumber) || strlen($cardNumber) < 13 || strlen($cardNumber) > 19) {
        $errors[] = 'Invalid card number (must be 13-19 digits)';
    }
    
    // 7.4.2 ط§ظ„طھط­ظ‚ظ‚ ظ…ظ† ط®ظˆط§ط±ط²ظ…ظٹط© Luhn
    if (!empty($cardNumber) && !isValidLuhn($cardNumber)) {
        $errors[] = 'Invalid card number (checksum failed)';
    }
    
    // 7.4.3 ظ†ظˆط¹ ط§ظ„ط¨ط·ط§ظ‚ط©
    if (!empty($cardNumber)) {
        $cardType = detectCardType($cardNumber);
        if ($cardType === 'Unknown') {
            $errors[] = 'Unsupported card type';
        }
    }
    
    // 7.4.4 طھط§ط±ظٹط® ط§ظ„ط§ظ†طھظ‡ط§ط،
    if (empty($cardExpiry) || !preg_match('/^(0[1-9]|1[0-2])\/([0-9]{2})$/', $cardExpiry)) {
        $errors[] = 'Invalid expiry date (format: MM/YY)';
    } else {
        // ط§ظ„طھط­ظ‚ظ‚ ظ…ظ† ط£ظ† ط§ظ„ط¨ط·ط§ظ‚ط© ظ„ظ… طھظ†طھظ‡ظگ
        list($month, $year) = explode('/', $cardExpiry);
        $expiryTimestamp = mktime(0, 0, 0, intval($month), 1, intval($year) + 2000);
        if ($expiryTimestamp < time()) {
            $errors[] = 'Card has expired';
        }
    }
    
    // 7.4.5 ط±ظ…ط² CVV
    $cvvLength = ($cardType ?? 'Visa') === 'Amex' ? 4 : 3;
    if (empty($cardCvv) || strlen($cardCvv) !== $cvvLength || !ctype_digit($cardCvv)) {
        $errors[] = 'Invalid CVV (must be ' . $cvvLength . ' digits)';
    }
}

/**
 * 7.5 ط§ظ„طھط­ظ‚ظ‚ ظ…ظ† Ledger Address
 */
if (!empty($ledgerAddress) && !preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $ledgerAddress)) {
    $errors[] = 'Invalid Tron address (must start with T and be 34 characters)';
}

/**
 * 7.6 ط§ظ„طھط­ظ‚ظ‚ ط§ظ„ط®ط§طµ ط¨ظ€ Purchase Advice
 */
if ($transactionType === 'purchase_advice') {
    if (empty($originalReference)) {
        $errors[] = 'Original reference required for advice transaction';
    }
    if (empty($originalAuthCode)) {
        $errors[] = 'Original auth code required for advice transaction';
    }
}

/**
 * 7.7 ط§ظ„طھط­ظ‚ظ‚ ط§ظ„ط®ط§طµ ط¨ظ€ Auth Capture
 */
if ($transactionType === 'auth_capture' && empty($originalReference)) {
    $errors[] = 'Original reference required for auth capture';
}

/**
 * 7.8 ط§ظ„طھط­ظ‚ظ‚ ط§ظ„ط®ط§طµ ط¨ظ€ Purchase Offline
 */
if ($transactionType === 'purchase_offline') {
    $allowedChannels = ['phone', 'mail', 'fax', 'other'];
    if (!in_array($offlineChannel, $allowedChannels)) {
        $errors[] = 'Invalid offline channel. Supported: ' . implode(', ', $allowedChannels);
    }
}

/**
 * 7.9 ط§ظ„طھط­ظ‚ظ‚ ط§ظ„ط®ط§طµ ط¨ط§ظ„طھظ‚ط³ظٹط·
 */
if ($transactionType === 'installment') {
    if ($installmentCount < 2) {
        $errors[] = 'Minimum 2 installments required';
    }
    if ($installmentCount > 12) {
        $errors[] = 'Maximum 12 installments allowed';
    }
}

/**
 * 7.10 ط§ظ„طھط­ظ‚ظ‚ ط§ظ„ط®ط§طµ ط¨ط¨ط·ط§ظ‚ط© ط§ظ„ظ‡ط¯ط§ظٹط§
 */
if ($transactionType === 'gift_card') {
    if ($giftCardAmount <= 0) {
        $errors[] = 'Gift card amount must be greater than 0';
    }
    if ($giftCardAmount > 500) {
        $errors[] = 'Gift card amount exceeds maximum (500 USD)';
    }
}

/**
 * 7.11 ط§ظ„طھط­ظ‚ظ‚ ط§ظ„ط®ط§طµ ط¨ط§ظ„ط¹ظ…ظ„ط§طھ ط§ظ„ط±ظ‚ظ…ظٹط©
 */
if ($transactionType === 'crypto_purchase') {
    $allowedCrypto = ['USDT', 'BTC', 'ETH', 'BNB', 'SOL', 'XRP', 'ADA', 'DOGE'];
    if (!in_array($cryptoCurrency, $allowedCrypto)) {
        $errors[] = 'Unsupported crypto currency. Supported: ' . implode(', ', $allowedCrypto);
    }
}

/**
 * 7.12 ط§ظ„طھط­ظ‚ظ‚ ط§ظ„ط®ط§طµ ط¨ط§ظ„ط³ط­ط¨ ط§ظ„ظ†ظ‚ط¯ظٹ ط§ظ„ط´ط¨ظٹظ‡
 */
if ($transactionType === 'quasi_cash') {
    if ($amount > 10000) {
        $errors[] = 'Quasi cash amount exceeds maximum (10,000 USD)';
    }
}

/**
 * 7.13 ط§ظ„طھط­ظ‚ظ‚ ظ…ظ† ط§ظ„ط¨ط±ظٹط¯ ط§ظ„ط¥ظ„ظƒطھط±ظˆظ†ظٹ
 */
if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Invalid email address';
}

/**
 * 7.14 ط§ظ„طھط­ظ‚ظ‚ ظ…ظ† ط±ظ‚ظ… ط§ظ„ظ‡ط§طھظپ
 */
if (!empty($phone) && !preg_match('/^\+?[0-9]{10,15}$/', $phone)) {
    $errors[] = 'Invalid phone number';
}

// ط¥ط°ط§ ظƒط§ظ† ظ‡ظ†ط§ظƒ ط£ط®ط·ط§ط،طŒ ط£ط¹ط¯ظ‡ط§ ظ„ظ„ظ…ط³طھط®ط¯ظ…
if (!empty($errors)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Validation failed',
        'transaction_type' => $transactionType,
        'transaction_label' => $txnDef['label'],
        'errors' => $errors
    ]);
    exit;
}

// ============================================================
// [8] ط§ظ„ط§طھطµط§ظ„ ط¨ظ‚ط§ط¹ط¯ط© ط§ظ„ط¨ظٹط§ظ†ط§طھ
// ============================================================

$db = db();

// ============================================================
// [9] ط­ط³ط§ط¨ ط³ط¹ط± ط§ظ„طµط±ظپ (USD â†’ USDT)
// ============================================================

$exchangeRates = getExchangeRates();
$usdtAmount = round($amount * ($exchangeRates[$currency] ?? 1.0), 6);

// ============================================================
// [10] STAGE 1: ظ…ط¹ط§ظ„ط¬ط© ط§ظ„ط¯ظپط¹ ط¹ط¨ط± DI PARMA Gateway
// ============================================================

/**
 * ط¥ط¹ط¯ط§ط¯ط§طھ DI PARMA Gateway
 */
$diparmaConfig = [
    'merchant_id' => getenv('DIPARMA_MERCHANT_ID') ?: 'DP_0001',
    'merchant_secret' => getenv('DIPARMA_MERCHANT_SECRET') ?? '',
    'environment' => getenv('DIPARMA_ENVIRONMENT') ?: 'live',
    'acquirer' => $data['acquirer'] ?? 'Mashreq',
];

/**
 * ط¨ظ†ط§ط، ط·ظ„ط¨ DI PARMA ط­ط³ط¨ ظ†ظˆط¹ ط§ظ„ط¹ظ…ظ„ظٹط©
 */
$diparmaRequest = [
    'merchant_id' => $diparmaConfig['merchant_id'],
    'merchant_secret' => $diparmaConfig['merchant_secret'],
    'acquirer' => $diparmaConfig['acquirer'],
    'amount' => $amount,
    'currency' => $currency,
    'reference' => $reference,
    'order_id' => $reference,
    'transaction_type' => $transactionType,
    'transaction_label' => $txnDef['label'],
    'iso_msg_type' => $txnDef['iso'],
    'security_mode' => $txnDef['security'],
    'category' => $txnDef['category'],
    'risk_level' => $txnDef['risk_level'],
];

/**
 * ط¥ط¶ط§ظپط© ط¨ظٹط§ظ†ط§طھ ط§ظ„ط¨ط·ط§ظ‚ط© (ظ„ط£ظ†ظˆط§ط¹ ط§ظ„ط¨ط·ط§ظ‚ط§طھ)
 */
if (in_array($txnDef['type'], ['card', 'crypto'])) {
    $diparmaRequest['card'] = [
        'number' => $cardNumber,
        'expiry_month' => substr($cardExpiry, 0, 2),
        'expiry_year' => '20' . substr($cardExpiry, 3, 2),
        'cvv' => $cardCvv,
        'holder_name' => $cardHolder,
        'type' => $cardType ?? 'Unknown',
        'last4' => substr($cardNumber, -4),
    ];
}

/**
 * ط¥ط¶ط§ظپط© ط¨ظٹط§ظ†ط§طھ ط§ظ„ط¹ظ…ظٹظ„
 */
$diparmaRequest['customer'] = [
    'email' => $email ?: 'customer@diparmas.com',
    'phone' => $phone ?: '+971501234567',
    'name' => $cardHolder,
    'ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
];

/**
 * ط¥ط¶ط§ظپط© ط¹ظ†ظˆط§ظ† ط§ظ„ظپظˆطھط±ط©
 */
$diparmaRequest['billing_address'] = [
    'address' => $billingAddress['address'] ?? '',
    'city' => $billingAddress['city'] ?? '',
    'country' => $billingAddress['country'] ?? 'AE',
    'zip' => $billingAddress['zip'] ?? '',
];

/**
 * ط¥ط¶ط§ظپط© ط¨ظٹط§ظ†ط§طھ ط®ط§طµط© ط­ط³ط¨ ظ†ظˆط¹ ط§ظ„ط¹ظ…ظ„ظٹط©
 */
switch ($transactionType) {
    
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    // PURCHASE ADVICE - ط´ط±ط§ط، ط¥ط±ط´ط§ط¯ظٹ
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    case 'purchase_advice':
        $diparmaRequest['advice'] = [
            'original_reference' => $originalReference,
            'original_auth_code' => $originalAuthCode,
            'is_advice' => true,
            'advice_reason' => $data['advice_reason'] ?? 'Post-authorization confirmation',
        ];
        $diparmaRequest['is_advice'] = true;
        break;
    
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    // PURCHASE OFFLINE - ظ…ط¨ظٹط¹ط§طھ ط®ط§ط±ط¬ ط§ظ„ط®ط·
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    case 'purchase_offline':
        $diparmaRequest['moto'] = [
            'indicator' => $motoIndicator ?: 'M',
            'channel' => $offlineChannel,
            'is_moto' => true,
            'is_offline' => true,
        ];
        $diparmaRequest['is_moto'] = true;
        $diparmaRequest['is_offline'] = true;
        $diparmaRequest['moto_indicator'] = $motoIndicator ?: 'M';
        break;
    
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    // PURCHASE ONLINE - ظ…ط¨ظٹط¹ط§طھ ط¹ط¨ط± ط§ظ„ط¥ظ†طھط±ظ†طھ
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    case 'purchase_online':
        $diparmaRequest['moto'] = [
            'indicator' => $motoIndicator ?: 'E',
            'channel' => 'online',
            'is_moto' => true,
            'is_offline' => false,
        ];
        $diparmaRequest['is_moto'] = true;
        $diparmaRequest['is_offline'] = false;
        $diparmaRequest['moto_indicator'] = $motoIndicator ?: 'E';
        break;
    
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    // PURCHASE 2D - ط´ط±ط§ط، 2D ط¹ط§ظ…
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    case 'purchase_2d':
        $diparmaRequest['is_moto'] = true;
        $diparmaRequest['moto_indicator'] = $motoIndicator ?: 'M';
        $diparmaRequest['security_mode'] = '2D';
        break;
    
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    // PURCHASE 3D - ط´ط±ط§ط، 3D Secure
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    case 'purchase_3d':
        $diparmaRequest['security_mode'] = '3D';
        $diparmaRequest['requires_3ds'] = true;
        break;
    
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    // AUTH HOLD - طھط¬ظ…ظٹط¯ ظ…ط¨ظ„ط؛
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    case 'auth_hold':
        $diparmaRequest['is_auth_only'] = true;
        $diparmaRequest['is_capture'] = false;
        $diparmaRequest['hold_days'] = $txnDef['hold_days'] ?? 7;
        $diparmaRequest['security_mode'] = '3D';
        break;
    
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    // AUTH CAPTURE - طھط£ظƒظٹط¯ ط§ظ„طھط¬ظ…ظٹط¯
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    case 'auth_capture':
        $diparmaRequest['original_reference'] = $originalReference;
        $diparmaRequest['is_auth_only'] = false;
        $diparmaRequest['is_capture'] = true;
        $diparmaRequest['security_mode'] = '3D';
        break;
    
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    // RECURRING - ط´ط±ط§ط، ظ…طھظƒط±ط±
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    case 'recurring':
        $diparmaRequest['recurring'] = [
            'frequency' => $recurringFrequency,
            'indicator' => 'R',
            'start_date' => date('Y-m-d'),
            'end_date' => date('Y-m-d', strtotime('+1 year')),
            'max_occurrences' => 12,
        ];
        break;
    
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    // INSTALLMENT - ط´ط±ط§ط، ط¨ط§ظ„طھظ‚ط³ظٹط·
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    case 'installment':
        $installmentAmount = round($amount / $installmentCount, 2);
        $diparmaRequest['installment'] = [
            'count' => $installmentCount,
            'indicator' => 'I',
            'first_amount' => $installmentAmount,
            'remaining_amount' => $amount - $installmentAmount,
            'monthly_amount' => $installmentAmount,
        ];
        break;
    
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    // CRYPTO PURCHASE - ط´ط±ط§ط، ط¹ظ…ظ„ط§طھ ط±ظ‚ظ…ظٹط©
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    case 'crypto_purchase':
        $diparmaRequest['crypto'] = [
            'currency' => $cryptoCurrency,
            'amount' => $amount,
            'usdt_amount' => $usdtAmount,
            'exchange_rate' => $exchangeRates[$currency] ?? 1.0,
        ];
        break;
    
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    // GIFT CARD - ط¨ط·ط§ظ‚ط© ظ‡ط¯ط§ظٹط§
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    case 'gift_card':
        $diparmaRequest['gift_card'] = [
            'amount' => $giftCardAmount,
            'currency' => $currency,
            'recipient_email' => $data['recipient_email'] ?? $email,
            'recipient_name' => $data['recipient_name'] ?? $cardHolder,
            'message' => $data['gift_message'] ?? '',
        ];
        break;
    
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    // WIRE TRANSFER - طھط­ظˆظٹظ„ ط¨ظ†ظƒظٹ
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    case 'wire_transfer':
        $diparmaRequest['wire_transfer'] = [
            'bank_name' => $bankAccount['bank_name'] ?? '',
            'account_name' => $bankAccount['account_name'] ?? '',
            'account_number' => $bankAccount['account_number'] ?? '',
            'iban' => $bankAccount['iban'] ?? '',
            'swift' => $bankAccount['swift'] ?? '',
            'routing_number' => $bankAccount['routing_number'] ?? '',
        ];
        break;
    
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    // QUASI CASH - ط³ط­ط¨ ظ†ظ‚ط¯ظٹ ط´ط¨ظٹظ‡
    // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
    case 'quasi_cash':
        $diparmaRequest['quasi_cash'] = [
            'purpose' => $purpose,
            'reference' => $reference . '-QC',
            'security_mode' => '3D',
        ];
        break;
}

/**
 * ط¥ط¶ط§ظپط© ط±ظˆط§ط¨ط· ط§ظ„ط¥ط±ط¬ط§ط¹
 */
$diparmaRequest['return_url'] = $returnUrl . '?ref=' . $reference . '&type=' . $transactionType;
$diparmaRequest['webhook_url'] = 'https://diparmas.com/api/webhooks/diparma.php';
$diparmaRequest['expiry_minutes'] = 30;

/**
 * ط¥ط¶ط§ظپط© ظˆط¬ظ‡ط© ط§ظ„ظ…ط¨ظ„ط؛
 */
$diparmaRequest['destination'] = $data['destination'] ?? 'ledger_trx';
$diparmaRequest['ledger_address'] = $ledgerAddress;
$diparmaRequest['auto_transfer'] = $autoTransfer;

// ============================================================
// [11] ط¥ط±ط³ط§ظ„ ط§ظ„ط·ظ„ط¨ ط¥ظ„ظ‰ DI PARMA Gateway
// ============================================================

$diparmaResponse = sendToDIPARMA($diparmaRequest, $diparmaConfig);

// ط§ظ„طھط­ظ‚ظ‚ ظ…ظ† ط§ط³طھط¬ط§ط¨ط© DI PARMA
if (!$diparmaResponse['success']) {
    // طھط³ط¬ظٹظ„ ط§ظ„ظپط´ظ„
    try {
        $db->insert('dp_transactions', [
            'reference' => $reference,
            'user_id' => $_SESSION['user_id'] ?? null,
            'gateway' => 'diparma',
            'gateway_type' => $txnDef['type'],
            'transaction_type' => $transactionType,
            'transaction_label' => $txnDef['label'],
            'amount' => $amount,
            'currency' => $currency,
            'card_last4' => substr($cardNumber, -4),
            'cardholder_name' => $cardHolder,
            'security_mode' => $txnDef['security'],
            'status' => 'failed',
            'gateway_response' => json_encode($diparmaResponse),
            'ledger_address' => $ledgerAddress,
            'error_message' => $diparmaResponse['message'] ?? 'Payment failed',
            'error_code' => $diparmaResponse['error_code'] ?? 'unknown',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (Exception $e) {
        error_log('[DI PARMA] DB insert error: ' . $e->getMessage());
    }

    http_response_code(402);
    echo json_encode([
        'success' => false,
        'reference' => $reference,
        'transaction_type' => $transactionType,
        'transaction_label' => $txnDef['label'],
        'stage' => 'card_charge',
        'message' => $diparmaResponse['message'] ?? 'Payment processing failed',
        'error_code' => $diparmaResponse['error_code'] ?? 'unknown',
        'details' => $diparmaResponse['details'] ?? null,
    ]);
    exit;
}

// ط§ط³طھط®ط±ط§ط¬ ط¨ظٹط§ظ†ط§طھ ط§ظ„ظ†ط¬ط§ط­
$authCode = $diparmaResponse['auth_code'] ?? '';
$rrn = $diparmaResponse['rrn'] ?? '';
$approvalCode = $diparmaResponse['approval_code'] ?? '';
$diparmaTransactionId = $diparmaResponse['transaction_id'] ?? '';
$stan = $diparmaResponse['stan'] ?? '';
$acquirerName = $diparmaResponse['acquirer'] ?? $diparmaConfig['acquirer'];

// ============================================================
// [12] STAGE 2: ط¥ط±ط³ط§ظ„ USDT ط¥ظ„ظ‰ Ledger
// ============================================================

$ledgerTxid = null;
$ledgerStatus = 'pending';
$ledgerTransfer = false;

if ($autoTransfer && !empty($ledgerAddress)) {
    try {
        $tronResult = sendUSDTToLedger($ledgerAddress, $usdtAmount);
        
        if ($tronResult['success']) {
            $ledgerTxid = $tronResult['txid'];
            $ledgerStatus = 'completed';
            $ledgerTransfer = true;
        } else {
            $ledgerStatus = 'failed';
            // طھط³ط¬ظٹظ„ ظپظٹ ظ‚ط§ط¦ظ…ط© ط§ظ„ط§ظ†طھط¸ط§ط±
            try {
                $db->insert('ledger_transfer_queue', [
                    'reference' => $reference,
                    'ledger_address' => $ledgerAddress,
                    'usdt_amount' => $usdtAmount,
                    'currency_orig' => $currency,
                    'transaction_type' => $transactionType,
                    'status' => 'queued',
                    'message' => $tronResult['message'] ?? 'Failed to send USDT',
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (Exception $e) {}
        }
    } catch (Exception $e) {
        $ledgerStatus = 'failed';
        error_log('[DI PARMA] Ledger error: ' . $e->getMessage());
    }
}

// ============================================================
// [13] طھط³ط¬ظٹظ„ ط§ظ„ظ…ط¹ط§ظ…ظ„ط© ط§ظ„ظƒط§ظ…ظ„ط©
// ============================================================

$finalStatus = ($ledgerStatus === 'completed') ? 'completed' : 'pending_ledger';

$gatewayResponse = json_encode([
    'transaction' => [
        'type' => $transactionType,
        'label' => $txnDef['label'],
        'iso' => $txnDef['iso'],
        'security' => $txnDef['security'],
        'category' => $txnDef['category'],
        'settlement_days' => $txnDef['settlement_days'],
        'risk_level' => $txnDef['risk_level'],
    ],
    'stage_1_card' => [
        'gateway' => 'DI PARMA Direct',
        'acquirer' => $acquirerName,
        'auth_code' => $authCode,
        'approval_code' => $approvalCode,
        'rrn' => $rrn,
        'stan' => $stan,
        'transaction_id' => $diparmaTransactionId,
        'card_last4' => substr($cardNumber, -4),
        'card_holder' => $cardHolder,
        'card_type' => $cardType ?? 'Unknown',
        'sec_mode' => $txnDef['security'],
    ],
    'stage_2_ledger' => [
        'address' => $ledgerAddress,
        'usdt_amount' => $usdtAmount,
        'exchange_rate' => $exchangeRates[$currency] ?? 1.0,
        'txid' => $ledgerTxid,
        'status' => $ledgerStatus,
        'explorer' => $ledgerTxid ? 'https://tronscan.org/#/transaction/' . $ledgerTxid : null,
    ],
    'special_data' => $diparmaRequest['advice'] ?? 
                      $diparmaRequest['moto'] ?? 
                      $diparmaRequest['installment'] ?? 
                      $diparmaRequest['recurring'] ?? 
                      $diparmaRequest['crypto'] ?? 
                      $diparmaRequest['gift_card'] ?? 
                      $diparmaRequest['wire_transfer'] ?? 
                      $diparmaRequest['quasi_cash'] ?? null,
]);

try {
    $db->insert('dp_transactions', [
        'reference' => $reference,
        'user_id' => $_SESSION['user_id'] ?? null,
        'gateway' => 'diparma',
        'gateway_type' => $txnDef['type'],
        'transaction_type' => $transactionType,
        'transaction_label' => $txnDef['label'],
        'amount' => $amount,
        'currency' => $currency,
        'card_last4' => substr($cardNumber, -4),
        'cardholder_name' => $cardHolder,
        'security_mode' => $txnDef['security'],
        'status' => $finalStatus,
        'gateway_response' => $gatewayResponse,
        'ledger_txid' => $ledgerTxid,
        'ledger_transferred' => $ledgerTransfer ? 1 : 0,
        'ledger_amount' => $usdtAmount,
        'ledger_address' => $ledgerAddress,
        'auth_code' => $authCode,
        'rrn' => $rrn,
        'approval_code' => $approvalCode,
        'acquirer' => $acquirerName,
        'original_reference' => $originalReference ?? null,
        'installment_count' => $installmentCount ?? 0,
        'recurring_frequency' => $recurringFrequency ?? null,
        'moto_indicator' => $motoIndicator ?? null,
        'is_advice' => $txnDef['advice'] ? 1 : 0,
        'is_offline' => $txnDef['offline'] ? 1 : 0,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
} catch (Exception $e) {
    error_log('[DI PARMA] DB insert error: ' . $e->getMessage());
}

// ============================================================
// [14] ط¥ط±ط³ط§ظ„ Webhook
// ============================================================

$webhookUrl = getenv('DEFAULT_WEBHOOK_URL') ?: '';
if (!empty($webhookUrl)) {
    try {
        $webhookData = [
            'event' => 'charge.' . $finalStatus,
            'gateway' => 'DI PARMA',
            'transaction_type' => $transactionType,
            'transaction_label' => $txnDef['label'],
            'reference' => $reference,
            'amount' => $amount,
            'currency' => $currency,
            'usdt_amount' => $usdtAmount,
            'auth_code' => $authCode,
            'rrn' => $rrn,
            'approval_code' => $approvalCode,
            'acquirer' => $acquirerName,
            'ledger' => [
                'address' => $ledgerAddress,
                'txid' => $ledgerTxid,
                'status' => $ledgerStatus,
            ],
            'timestamp' => date('c'),
            'status' => $finalStatus,
        ];
        
        $ch = curl_init($webhookUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($webhookData),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-DI-PARMA-Event: charge.' . $finalStatus,
                'X-DI-PARMA-Signature: ' . hash_hmac('sha256', json_encode($webhookData), getenv('WEBHOOK_SECRET') ?: 'default'),
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);
        curl_exec($ch);
        curl_close($ch);
    } catch (Exception $e) {
        error_log('[DI PARMA] Webhook error: ' . $e->getMessage());
    }
}

// ============================================================
// [15] ط§ظ„ط±ط¯ ط§ظ„ظ†ظ‡ط§ط¦ظٹ
// ============================================================

http_response_code(200);
echo json_encode([
    'success' => true,
    'gateway' => 'DI PARMA',
    'reference' => $reference,
    'transaction_type' => $transactionType,
    'transaction_label' => $txnDef['label'],
    'iso_msg_type' => $txnDef['iso'],
    'security_mode' => $txnDef['security'],
    'category' => $txnDef['category'],
    'status' => $finalStatus,
    'amount' => $amount,
    'currency' => $currency,
    'usdt_amount' => $usdtAmount,
    'auth_code' => $authCode,
    'approval_code' => $approvalCode,
    'rrn' => $rrn,
    'stan' => $stan,
    'acquirer' => $acquirerName,
    'card_last4' => substr($cardNumber, -4),
    'card_type' => $cardType ?? 'Unknown',
    'settlement_days' => $txnDef['settlement_days'],
    'ledger' => [
        'address' => $ledgerAddress,
        'txid' => $ledgerTxid,
        'status' => $ledgerStatus,
        'explorer' => $ledgerTxid ? 'https://tronscan.org/#/transaction/' . $ledgerTxid : null,
    ],
    'special_data' => [
        'installment_count' => $installmentCount ?? null,
        'recurring_frequency' => $recurringFrequency ?? null,
        'crypto_currency' => $cryptoCurrency ?? null,
        'gift_card_amount' => $giftCardAmount ?? null,
        'original_reference' => $originalReference ?? null,
        'original_auth_code' => $originalAuthCode ?? null,
        'moto_indicator' => $motoIndicator ?? null,
        'is_advice' => $txnDef['advice'] ?? false,
        'is_offline' => $txnDef['offline'] ?? false,
        'advice_reason' => $data['advice_reason'] ?? null,
        'offline_channel' => $offlineChannel ?? null,
    ],
    'message' => $ledgerStatus === 'completed' 
        ? 'âœ… ' . $txnDef['label'] . ' completed successfully and sent to Ledger'
        : 'âœ… ' . $txnDef['label'] . ' completed, pending Ledger transfer',
    'timestamp' => date('c'),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

// ============================================================
// [16] ط¯ظˆط§ظ„ ظ…ط³ط§ط¹ط¯ط©
// ============================================================

/**
 * ط¥ط±ط³ط§ظ„ ط·ظ„ط¨ ط¥ظ„ظ‰ DI PARMA Gateway
 */
function sendToDIPARMA($request, $config) {
    try {
        $timestamp = time();
        $signature = hash_hmac(
            'sha256',
            $timestamp . $request['reference'] . $request['amount'],
            $config['merchant_secret']
        );
        
        $request['timestamp'] = $timestamp;
        $request['signature'] = $signature;
        
        $acquirerEndpoints = [
            'Mashreq' => 'https://api.mashreqbank.com/payment/charge',
            'HSBC' => 'https://api.hsbc.ae/payment/charge',
            'NBE' => 'https://api.nbe.com.eg/payment/charge',
            'JPMorgan' => 'https://api.jpmorgan.com/payment/charge',
        ];
        
        $endpoint = $acquirerEndpoints[$request['acquirer']] ?? $acquirerEndpoints['Mashreq'];
        
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($request),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Merchant-Id: ' . $config['merchant_id'],
                'X-Signature: ' . $signature,
                'X-Timestamp: ' . $timestamp,
                'X-Transaction-Type: ' . ($request['transaction_type'] ?? 'purchase_3d'),
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => $config['environment'] === 'live',
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            throw new Exception('Curl error: ' . curl_error($ch));
        }
        
        curl_close($ch);
        
        $result = json_decode($response, true);
        
        if ($httpCode === 200 && isset($result['status']) && $result['status'] === 'SUCCESS') {
            return [
                'success' => true,
                'auth_code' => $result['auth_code'] ?? '',
                'approval_code' => $result['approval_code'] ?? '',
                'rrn' => $result['rrn'] ?? '',
                'stan' => $result['stan'] ?? '',
                'transaction_id' => $result['transaction_id'] ?? '',
                'acquirer' => $request['acquirer'],
                'message' => 'Payment processed successfully',
            ];
        } else {
            $errorCodes = [
                '01' => 'REFER_TO_ISSUER',
                '02' => 'REFER_TO_ISSUER_SPECIAL',
                '03' => 'INVALID_MERCHANT',
                '04' => 'HOLD_CARD',
                '05' => 'DO_NOT_HONOR',
                '12' => 'INVALID_TRANSACTION',
                '13' => 'INVALID_AMOUNT',
                '14' => 'INVALID_CARD_NUMBER',
                '15' => 'NO_SUCH_ISSUER',
                '30' => 'FORMAT_ERROR',
                '31' => 'BANK_NOT_SUPPORTED',
                '51' => 'INSUFFICIENT_FUNDS',
                '54' => 'EXPIRED_CARD',
                '57' => 'TRANSACTION_NOT_PERMITTED',
                '58' => 'TRANSACTION_NOT_ALLOWED',
                '61' => 'EXCEEDS_DAILY_LIMIT',
                '65' => 'EXCEEDS_WITHDRAWAL_LIMIT',
                '91' => 'ISSUER_TIMEOUT',
                '96' => 'SYSTEM_ERROR',
            ];
            
            $errorCode = $result['error_code'] ?? '96';
            $errorMessage = $errorCodes[$errorCode] ?? $result['message'] ?? 'Transaction failed';
            
            return [
                'success' => false,
                'message' => $errorMessage,
                'error_code' => $errorCode,
                'details' => $result,
            ];
        }
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Connection error: ' . $e->getMessage(),
            'error_code' => 'connection_error',
        ];
    }
}

/**
 * ط¥ط±ط³ط§ظ„ USDT ط¥ظ„ظ‰ Ledger ط¹ط¨ط± TronGrid
 */
function sendUSDTToLedger($toAddress, $amount) {
    try {
        $tronApiKey = getenv('TRONGRID_API_KEY') ?: '';
        $hotWalletAddress = getenv('HOT_WALLET_TRC20_ADDRESS') ?: '';
        $hotWalletPrivateKey = getenv('HOT_WALLET_TRC20_KEY') ?: '';
        $usdtContract = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';
        
        if (empty($tronApiKey) || empty($hotWalletPrivateKey)) {
            return [
                'success' => false,
                'message' => 'TronGrid credentials missing',
            ];
        }
        
        $sunAmount = (int)round($amount * 1000000);
        $toHex = base58ToHex($toAddress);
        
        $transaction = [
            'owner_address' => $hotWalletAddress,
            'contract_address' => $usdtContract,
            'function_selector' => 'transfer(address,uint256)',
            'parameter' => $toHex . str_pad(dechex($sunAmount), 64, '0', STR_PAD_LEFT),
            'fee_limit' => 20000000,
            'call_value' => 0,
            'visible' => true,
        ];
        
        $ch = curl_init('https://api.trongrid.io/wallet/triggersmartcontract');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($transaction),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'TRON-PRO-API-KEY: ' . $tronApiKey,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $result = json_decode($response, true);
        
        if ($httpCode === 200 && isset($result['transaction']['txID'])) {
            return [
                'success' => true,
                'txid' => $result['transaction']['txID'],
                'message' => 'USDT sent successfully',
            ];
        } else {
            return [
                'success' => false,
                'message' => $result['message'] ?? 'Failed to send USDT',
            ];
        }
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'TronGrid error: ' . $e->getMessage(),
        ];
    }
}

/**
 * طھط­ظˆظٹظ„ ط¹ظ†ظˆط§ظ† Tron ظ…ظ† Base58 ط¥ظ„ظ‰ Hex
 */
function base58ToHex($address) {
    $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    $num = gmp_init(0);
    
    for ($i = 0; $i < strlen($address); $i++) {
        $pos = strpos($alphabet, $address[$i]);
        if ($pos === false) {
            throw new Exception('Invalid Tron address');
        }
        $num = gmp_add(gmp_mul($num, 58), $pos);
    }
    
    $hex = gmp_strval($num, 16);
    if (strlen($hex) % 2 !== 0) {
        $hex = '0' . $hex;
    }
    
    $addressHex = substr($hex, 2, strlen($hex) - 10);
    return str_pad($addressHex, 64, '0', STR_PAD_LEFT);
}

/**
 * ظƒط´ظپ ظ†ظˆط¹ ط§ظ„ط¨ط·ط§ظ‚ط©
 */
function detectCardType($number) {
    $number = preg_replace('/\D/', '', $number);
    
    if (preg_match('/^4/', $number)) return 'Visa';
    if (preg_match('/^5[1-5]/', $number)) return 'Mastercard';
    if (preg_match('/^3[47]/', $number)) return 'Amex';
    if (preg_match('/^6(?:011|5)/', $number)) return 'Discover';
    if (preg_match('/^3(?:0[0-5]|[68])/', $number)) return 'Diners';
    if (preg_match('/^(?:2131|1800|35)/', $number)) return 'JCB';
    if (preg_match('/^62/', $number)) return 'UnionPay';
    
    return 'Unknown';
}

/**
 * ط§ظ„طھط­ظ‚ظ‚ ظ…ظ† طµط­ط© ط§ظ„ط¨ط·ط§ظ‚ط© (ط®ظˆط§ط±ط²ظ…ظٹط© Luhn)
 */
function isValidLuhn($number) {
    $number = preg_replace('/\D/', '', $number);
    $sum = 0;
    $alt = false;
    for ($i = strlen($number) - 1; $i >= 0; $i--) {
        $n = (int)$number[$i];
        if ($alt) {
            $n *= 2;
            if ($n > 9) $n -= 9;
        }
        $sum += $n;
        $alt = !$alt;
    }
    return $sum % 10 === 0;
}

/**
 * ط§ظ„ط­طµظˆظ„ ط¹ظ„ظ‰ ط£ط³ط¹ط§ط± ط§ظ„طµط±ظپ
 */
function getExchangeRates() {
    $cacheFile = __DIR__ . '/../cache/exchange_rates.json';
    $cacheTTL = 3600;
    
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTTL) {
        $rates = json_decode(file_get_contents($cacheFile), true);
        if ($rates) {
            return $rates;
        }
    }
    
    $rates = [
        'USD' => 1.0,
        'AED' => 0.2723,
        'SAR' => 0.2667,
        'EUR' => 1.082,
        'GBP' => 1.271,
        'KWD' => 3.257,
        'QAR' => 0.2747,
        'EGP' => 0.0204,
        'USDT' => 1.0,
    ];
    
    try {
        $ch = curl_init('https://api.exchangerate-api.com/v4/latest/USD');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        
        if ($response) {
            $data = json_decode($response, true);
            if (isset($data['rates'])) {
                $rates = array_merge($rates, $data['rates']);
                if (!is_dir(dirname($cacheFile))) {
                    mkdir(dirname($cacheFile), 0755, true);
                }
                file_put_contents($cacheFile, json_encode($rates));
            }
        }
    } catch (Exception $e) {
        // ط§ط³طھط®ط¯ط§ظ… ط§ظ„ط£ط³ط¹ط§ط± ط§ظ„ط§ظپطھط±ط§ط¶ظٹط©
    }
    
    return $rates;
}

// ============================================================
// ظ†ظ‡ط§ظٹط© ط§ظ„ظ…ظ„ظپ
// ============================================================
?>
