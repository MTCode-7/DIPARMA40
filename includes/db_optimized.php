<?php
/**
 * DI PARMA | Optimized DB Queries — استعلامات محسّنة مع Cache
 */

// ── إنشاء الفهارس تلقائياً عند أول تشغيل ───────────────────
function dp_ensure_indexes(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $db  = db();
    $pfx = DB_PREFIX;

    // خريطة: اسم الفهرس => SQL الإنشاء
    $indexes = [
        'idx_txn_status'      => "CREATE INDEX idx_txn_status      ON {$pfx}transactions(status)",
        'idx_txn_gateway'     => "CREATE INDEX idx_txn_gateway     ON {$pfx}transactions(gateway)",
        'idx_txn_created'     => "CREATE INDEX idx_txn_created     ON {$pfx}transactions(created_at)",
        'idx_txn_reference'   => "CREATE INDEX idx_txn_reference   ON {$pfx}transactions(reference)",
        'idx_txn_user'        => "CREATE INDEX idx_txn_user        ON {$pfx}transactions(user_id)",
        'idx_txn_status_date' => "CREATE INDEX idx_txn_status_date ON {$pfx}transactions(status, created_at)",
        'idx_gw_status'       => "CREATE INDEX idx_gw_status       ON {$pfx}payment_gateways(status)",
        'idx_gw_code'         => "CREATE INDEX idx_gw_code         ON {$pfx}payment_gateways(code)",
        'idx_users_username'  => "CREATE INDEX idx_users_username  ON {$pfx}users(username)",
        'idx_users_status'    => "CREATE INDEX idx_users_status    ON {$pfx}users(status)",
        'idx_links_status'    => "CREATE INDEX idx_links_status    ON {$pfx}payment_links(status)",
        'idx_links_user'      => "CREATE INDEX idx_links_user      ON {$pfx}payment_links(user_id)",
    ];

    // جلب الفهارس الموجودة مرة واحدة
    try {
        $existing = [];
        $rows = $db->query(
            "SELECT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND INDEX_NAME IN ('" . implode("','", array_keys($indexes)) . "')"
        );
        foreach ($rows as $r) {
            $existing[$r['INDEX_NAME']] = true;
        }

        foreach ($indexes as $name => $sql) {
            if (!isset($existing[$name])) {
                try { $db->execute($sql); } catch (Exception $e) { /* ignore */ }
            }
        }
    } catch (Exception $e) { /* ignore */ }
}

// ── Dashboard Stats مع Cache ─────────────────────────────────
function dp_get_dashboard_stats(int $days = 30): array {
    $cacheKey = "dashboard_stats_{$days}";
    return DPCache::remember($cacheKey, 120, function () use ($days) {
        $db  = db();
        $pfx = DB_PREFIX;

        $rows = $db->query("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status='completed'  THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN status='pending'    THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN status='failed'     THEN 1 ELSE 0 END) AS failed,
                SUM(CASE WHEN status='refunded'   THEN 1 ELSE 0 END) AS refunded,
                COALESCE(SUM(amount),0)                               AS total_amount,
                COALESCE(SUM(CASE WHEN status='completed' THEN amount ELSE 0 END),0) AS completed_amount,
                COALESCE(AVG(amount),0)                               AS avg_amount
            FROM {$pfx}transactions
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ", [$days]);

        return $rows[0] ?? [];
    });
}

// ── Gateway Stats مع Cache ───────────────────────────────────
function dp_get_gateway_stats(int $days = 30): array {
    $cacheKey = "gateway_stats_{$days}";
    return DPCache::remember($cacheKey, 120, function () use ($days) {
        return db()->query("
            SELECT gateway,
                   COUNT(*) AS total,
                   SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) AS successful,
                   COALESCE(SUM(amount),0) AS total_amount
            FROM " . DB_PREFIX . "transactions
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY gateway
            ORDER BY total DESC
            LIMIT 10
        ", [$days]);
    });
}

// ── Recent Transactions مع Cache قصير ───────────────────────
function dp_get_recent_transactions(int $limit = 10): array {
    return DPCache::remember("recent_txn_{$limit}", 30, function () use ($limit) {
        return db()->query("
            SELECT id, reference, gateway, amount, currency, status, customer_name, created_at
            FROM " . DB_PREFIX . "transactions
            ORDER BY created_at DESC
            LIMIT ?
        ", [$limit]);
    });
}

// ── Active Gateways Count مع Cache ──────────────────────────
function dp_get_active_gateways_count(): int {
    return (int) DPCache::remember('active_gw_count', 300, function () {
        $r = db()->query("SELECT COUNT(*) AS c FROM " . DB_PREFIX . "payment_gateways WHERE status='active'");
        return $r[0]['c'] ?? 0;
    });
}

// ── Unread Notifications مع Cache ───────────────────────────
function dp_get_unread_count(int $userId): int {
    return (int) DPCache::remember("unread_{$userId}", 60, function () use ($userId) {
        $r = db()->query(
            "SELECT COUNT(*) AS c FROM " . DB_PREFIX . "notifications WHERE user_id=? AND `read`=0",
            [$userId]
        );
        return $r[0]['c'] ?? 0;
    });
}

// ── Paginate مع Cache ────────────────────────────────────────
function dp_paginate(string $table, array $where = [], string $order = 'id DESC', int $page = 1, int $perPage = 25): array {
    $db     = db();
    $pfx    = DB_PREFIX;
    $offset = ($page - 1) * $perPage;

    $whereStr    = '';
    $whereParams = [];
    if (!empty($where)) {
        $parts = [];
        foreach ($where as $col => $val) {
            $parts[]       = "`{$col}` = ?";
            $whereParams[] = $val;
        }
        $whereStr = ' WHERE ' . implode(' AND ', $parts);
    }

    $countSql = "SELECT COUNT(*) AS c FROM {$pfx}{$table}{$whereStr}";
    $total    = (int)($db->query($countSql, $whereParams)[0]['c'] ?? 0);

    $rows = $db->query(
        "SELECT * FROM {$pfx}{$table}{$whereStr} ORDER BY {$order} LIMIT ? OFFSET ?",
        array_merge($whereParams, [$perPage, $offset])
    );

    return [
        'data'        => $rows,
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $perPage,
        'last_page'   => (int) ceil($total / $perPage),
        'from'        => $offset + 1,
        'to'          => min($offset + $perPage, $total),
    ];
}
