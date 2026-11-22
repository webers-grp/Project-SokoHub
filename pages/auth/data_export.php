<?php
/**
 * SokoHub KDPA Personal Data Export Endpoint
 *
 * Implements Kenya Data Protection Act (KDPA) Right of Data Portability:
 * Allows authenticated customers, sellers, and delivery personnel to download
 * a complete, structured JSON export of their personal account data, addresses,
 * orders, line items, and reviews.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/audit.php';

init_session();
$user = require_login();
$userId = (int) $user['id'];
$pdo = get_db_connection();

// 1. User Profile
$pStmt = $pdo->prepare('SELECT id, full_name, email, phone, role, status, created_at, updated_at FROM users WHERE id = :uid LIMIT 1');
$pStmt->execute([':uid' => $userId]);
$profile = $pStmt->fetch(PDO::FETCH_ASSOC);

// 2. Saved Addresses
$aStmt = $pdo->prepare('SELECT id, label, town, address_line, landmark, phone, is_default, created_at FROM user_addresses WHERE user_id = :uid');
$aStmt->execute([':uid' => $userId]);
$addresses = $aStmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Orders & Items
$oStmt = $pdo->prepare('SELECT id, order_code, total_amount, payment_method, payment_status, order_status, delivery_address, delivery_town, delivery_fee, created_at FROM orders WHERE buyer_id = :uid ORDER BY id DESC');
$oStmt->execute([':uid' => $userId]);
$orders = $oStmt->fetchAll(PDO::FETCH_ASSOC);

$ordersWithItems = [];
foreach ($orders as $ord) {
    $iStmt = $pdo->prepare('SELECT oi.product_id, p.name AS product_name, oi.quantity, oi.unit_price, oi.line_total FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id WHERE oi.order_id = :oid');
    $iStmt->execute([':oid' => (int) $ord['id']]);
    $ord['items'] = $iStmt->fetchAll(PDO::FETCH_ASSOC);
    $ordersWithItems[] = $ord;
}

// 4. Reviews Submitted
$rStmt = $pdo->prepare('SELECT r.id, r.product_id, p.name AS product_name, r.rating, r.comment, r.created_at FROM reviews r LEFT JOIN products p ON p.id = r.product_id WHERE r.buyer_id = :uid');
$rStmt->execute([':uid' => $userId]);
$reviews = $rStmt->fetchAll(PDO::FETCH_ASSOC);

// 5. Wishlist items
$wStmt = $pdo->prepare('SELECT w.product_id, p.name AS product_name, w.created_at FROM wishlists w LEFT JOIN products p ON p.id = w.product_id WHERE w.user_id = :uid');
$wStmt->execute([':uid' => $userId]);
$wishlist = $wStmt->fetchAll(PDO::FETCH_ASSOC);

$exportData = [
    'application'     => 'SokoHub Marketplace',
    'kdpa_compliance' => 'Kenya Data Protection Act, 2019 - Personal Data Portability Export',
    'exported_at'     => date('Y-m-d H:i:s T'),
    'user_profile'    => $profile,
    'saved_addresses' => $addresses,
    'order_history'   => $ordersWithItems,
    'product_reviews' => $reviews,
    'saved_wishlist'  => $wishlist,
];

Audit::record('kdpa.data_exported', 'user', $userId, [
    'order_count'   => count($orders),
    'address_count' => count($addresses),
], $userId, $user['role']);

$filename = 'sokohub_data_export_' . $userId . '_' . date('Ymd_His') . '.json';
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

echo json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit;
