<?php
/**
 * SokoHub Query Performance & EXPLAIN Analysis Runner
 */

require_once __DIR__ . '/../config/db.php';

$pdo = get_db_connection();

$queries = [
    'Catalog Filter Query' => "
        SELECT p.id, p.name, p.price, p.stock_qty
        FROM products p
        WHERE p.category_id = 1 AND p.status = 'active'
        ORDER BY p.price ASC
        LIMIT 20
    ",
    'Customer Orders Lookup' => "
        SELECT o.id, o.order_code, o.total_amount, o.order_status
        FROM orders o
        WHERE o.buyer_id = 1
        ORDER BY o.created_at DESC
        LIMIT 10
    ",
    'Payment Checkout ID Lookup' => "
        SELECT id, amount, status
        FROM payments
        WHERE checkout_request_id = 'ws_CO_TEST_12345'
        LIMIT 1
    ",
    'Seller Settlement Payouts' => "
        SELECT p.id, p.gross_amount, p.net_amount, p.status
        FROM payouts p
        WHERE p.user_id = 2 AND p.status = 'pending'
    ",
    'Rate Limit Lookup' => "
        SELECT hits, reset_at
        FROM rate_limits
        WHERE rate_key = 'some_hash_key'
        LIMIT 1
    ",
];

echo "=== SokoHub SQL EXPLAIN Plan Analysis ===\n\n";

foreach ($queries as $label => $sql) {
    echo "--- {$label} ---\n";
    $stmt = $pdo->query("EXPLAIN " . trim($sql));
    $plan = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($plan as $row) {
        $tbl = $row['table'] ?? '';
        $type = $row['type'] ?? '';
        $key = $row['key'] ?? 'NONE';
        $rows = $row['rows'] ?? 0;
        $extra = $row['Extra'] ?? '';
        echo "  Table: {$tbl} | Access: {$type} | Key Used: {$key} | Rows examined: {$rows} | {$extra}\n";
    }
    echo "\n";
}
