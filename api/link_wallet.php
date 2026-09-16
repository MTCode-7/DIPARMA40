<?php
/**
 * DI PARMA | Link external wallets (Ledger / TronLink / MetaMask)
 */
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => dp_t('Method not allowed', 'طريقة غير مسموحة')]);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
if (!verifyCsrfToken($payload['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => dp_t('Invalid CSRF token', 'رمز CSRF غير صالح')]);
    exit;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$action = strtolower(trim((string) ($payload['action'] ?? 'link')));
$provider = strtolower(trim((string) ($payload['provider'] ?? '')));
$address = trim((string) ($payload['address'] ?? ''));
$network = strtoupper(trim((string) ($payload['network'] ?? '')));

$allowed = ['ledger', 'tron', 'tronlink', 'metamask'];
if (!in_array($provider, $allowed, true)) {
    echo json_encode(['success' => false, 'message' => dp_t('Unknown provider', 'مزود غير معروف')]);
    exit;
}
if ($provider === 'tronlink') {
    $provider = 'tron';
}

$db = db();
$table = DB_PREFIX . 'linked_wallets';

// Ensure table
try {
    $db->execute(
        "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED NOT NULL,
            `provider` VARCHAR(32) NOT NULL,
            `network` VARCHAR(32) NOT NULL DEFAULT '',
            `address` VARCHAR(128) NOT NULL,
            `label` VARCHAR(120) DEFAULT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'active',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL DEFAULT NULL,
            UNIQUE KEY `uniq_user_provider` (`user_id`, `provider`),
            KEY `idx_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
} catch (Throwable $e) {
    // continue — table may already exist
}

try {
    if ($action === 'unlink') {
        $db->execute("DELETE FROM `{$table}` WHERE user_id = ? AND provider = ?", [$userId, $provider]);
        echo json_encode(['success' => true, 'message' => dp_t('Wallet unlinked', 'تم فك الربط')]);
        exit;
    }

    if ($action === 'list') {
        $rows = $db->query("SELECT provider, network, address, status, updated_at FROM `{$table}` WHERE user_id = ?", [$userId]) ?: [];
        echo json_encode(['success' => true, 'wallets' => $rows]);
        exit;
    }

    // link / save
    if ($provider === 'ledger' || $provider === 'tron') {
        if (!preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $address)) {
            throw new InvalidArgumentException(dp_t('Invalid Tron address', 'عنوان Tron غير صالح'));
        }
        $network = $network !== '' ? $network : 'TRC20';
    } elseif ($provider === 'metamask') {
        if (!preg_match('/^0x[a-fA-F0-9]{40}$/', $address)) {
            throw new InvalidArgumentException(dp_t('Invalid Ethereum address', 'عنوان Ethereum غير صالح'));
        }
        $network = $network !== '' ? $network : 'ERC20';
    }

    $existing = $db->query(
        "SELECT id FROM `{$table}` WHERE user_id = ? AND provider = ? LIMIT 1",
        [$userId, $provider]
    );

    if (!empty($existing[0]['id'])) {
        $db->execute(
            "UPDATE `{$table}` SET network=?, address=?, status='active', updated_at=NOW() WHERE id=?",
            [$network, $address, (int) $existing[0]['id']]
        );
    } else {
        $db->execute(
            "INSERT INTO `{$table}` (user_id, provider, network, address, status, created_at, updated_at)
             VALUES (?,?,?,?, 'active', NOW(), NOW())",
            [$userId, $provider, $network, $address]
        );
    }

    // Also mirror into user_wallets for crypto flows when possible
    try {
        $coin = ($provider === 'metamask') ? 'ETH' : 'USDT';
        $uw = $db->query(
            "SELECT id FROM " . DB_PREFIX . "user_wallets WHERE user_id=? AND network=? AND coin=? LIMIT 1",
            [$userId, $network, $coin]
        );
        if (!empty($uw[0]['id'])) {
            $db->execute(
                "UPDATE " . DB_PREFIX . "user_wallets SET address=?, status='active', updated_at=NOW() WHERE id=?",
                [$address, (int) $uw[0]['id']]
            );
        } else {
            $db->execute(
                "INSERT INTO " . DB_PREFIX . "user_wallets (user_id, network, coin, address, status, created_at)
                 VALUES (?,?,?,?, 'active', NOW())",
                [$userId, $network, $coin, $address]
            );
        }
    } catch (Throwable $e) {
        // optional mirror
    }

    echo json_encode([
        'success'  => true,
        'message'  => dp_t('Wallet linked successfully', 'تم ربط المحفظة بنجاح'),
        'provider' => $provider,
        'network'  => $network,
        'address'  => $address,
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
