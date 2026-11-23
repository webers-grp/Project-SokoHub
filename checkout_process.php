<?php
/**
 * SokoHub Checkout & Inventory Reservation Processor (checkout_process.php)
 * Role: Group 2 (Octavious & Ronny)
 *
 * Implements:
 * 1. Multi-step order placement inside an ACID PDO transaction
 * 2. Atomic stock decrement:
 *    UPDATE products SET stock_qty = stock_qty - ? WHERE id = ? AND stock_qty >= ?;
 *    Rolls back and throws out-of-stock error if affected rows === 0
 * 3. Writes historical order line snapshot into order_items.product_name_snapshot
 * 4. Records negative audit ledger row in stock_movements (change_qty = -quantity)
 * 5. Saves new delivery address to addresses table when requested
 * 6. Generates delivery dispatch record and clears session cart
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/inventory.php';
require_once __DIR__ . '/includes/kyc.php';
require_once __DIR__ . '/includes/payments.php';
require_once __DIR__ . '/includes/telephony.php';
init_session();
$user = require_login();

Payments::ensureSchema();
Telephony::ensureSchema();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('pages/buyer/checkout.php');
}

if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    set_flash('error', 'Invalid or expired security token. Please try again.');
    redirect('pages/buyer/checkout.php');
}

if (empty($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    set_flash('warning', 'Your shopping cart is empty. Add items before checking out.');
    redirect('pages/public/products.php');
}

$pdo = get_db_connection();

$addressId = (int) ($_POST['address_id'] ?? 0);
$recipientName = trim((string) ($_POST['recipient_name'] ?? ''));
$phone = trim((string) ($_POST['phone'] ?? ''));
$county = trim((string) ($_POST['county'] ?? 'Kirinyaga'));
$town = trim((string) ($_POST['town'] ?? ''));
$streetAddress = trim((string) ($_POST['street_address'] ?? ''));
$saveAddress = !empty($_POST['save_address']);
$paymentMethod = (string) ($_POST['payment_method'] ?? 'M-Pesa');
$notes = trim((string) ($_POST['notes'] ?? ''));

// Resolve delivery details from saved address or manual input
if ($addressId > 0) {
    $addrStmt = $pdo->prepare('SELECT * FROM addresses WHERE id = :id AND user_id = :uid LIMIT 1');
    $addrStmt->execute([':id' => $addressId, ':uid' => (int) $user['id']]);
    $savedAddr = $addrStmt->fetch(PDO::FETCH_ASSOC);

    if ($savedAddr) {
        $recipientName = $savedAddr['recipient_name'];
        $phone = $savedAddr['phone'];
        $county = $savedAddr['county'] ?: 'Kirinyaga';
        $town = $savedAddr['town'];
        $streetAddress = $savedAddr['street_address'];
    }
}

// Fallback to user session info if empty
if ($recipientName === '') {
    $recipientName = $user['full_name'];
}
if ($phone === '') {
    $phone = $user['phone'] ?? '';
}

// Basic field validation
$errors = [];
if (mb_strlen($recipientName) < 2) {
    $errors[] = 'Please provide the recipient name.';
}
if (mb_strlen($town) < 2) {
    $errors[] = 'Please provide the delivery town or area.';
}
if (mb_strlen($streetAddress) < 5) {
    $errors[] = 'Please provide a specific street address or landmark (at least 5 characters).';
}
if (!in_array($paymentMethod, ['M-Pesa', 'Cash on Delivery', 'Bank Card'], true)) {
    $errors[] = 'Please select a valid payment method.';
}

if (!empty($errors)) {
    set_flash('error', implode(' ', $errors));
    redirect('pages/buyer/checkout.php');
}

// Compute delivery fee
$deliveryFee = 150.00;
try {
    $zoneStmt = $pdo->prepare('SELECT fee FROM delivery_zones WHERE LOWER(town) = LOWER(:town) AND is_active = 1 LIMIT 1');
    $zoneStmt->execute([':town' => $town]);
    $fee = $zoneStmt->fetchColumn();
    if ($fee !== false && is_numeric($fee)) {
        $deliveryFee = (float) $fee;
    }
} catch (Throwable) {
    // Delivery zones table optional fallback
}

$cartItemIds = array_map('intval', array_keys($_SESSION['cart']));
if (empty($cartItemIds)) {
    set_flash('warning', 'Your shopping cart is empty.');
    redirect('pages/public/products.php');
}

try {
    $pdo->beginTransaction();

    // Optionally persist newly entered address into addresses table
    if ($saveAddress && $addressId <= 0) {
        $insAddr = $pdo->prepare(
            'INSERT INTO addresses (user_id, recipient_name, phone, county, town, street_address, is_default)
             VALUES (:uid, :name, :phone, :county, :town, :street, 0)'
        );
        $insAddr->execute([
            ':uid'    => (int) $user['id'],
            ':name'   => $recipientName,
            ':phone'  => $phone,
            ':county' => $county,
            ':town'   => $town,
            ':street' => $streetAddress,
        ]);
    }

    // Lock and inspect product rows for order line generation
    $placeholders = implode(',', array_fill(0, count($cartItemIds), '?'));
    $prodQuery = $pdo->prepare(
        "SELECT id, seller_id, name, price, stock_qty FROM products WHERE id IN ({$placeholders}) AND status = 'active' FOR UPDATE"
    );
    $prodQuery->execute($cartItemIds);
    $productsById = [];
    foreach ($prodQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $productsById[(int) $row['id']] = $row;
    }

    $orderItemsData = [];
    $productsSubtotal = 0.0;

    foreach ($_SESSION['cart'] as $pid => $reqQty) {
        $pid = (int) $pid;
        $reqQty = (int) $reqQty;
        if ($reqQty <= 0) {
            continue;
        }

        if (!isset($productsById[$pid])) {
            throw new RuntimeException("Product #{$pid} is no longer available in the marketplace.");
        }

        $prod = $productsById[$pid];
        $lineTotal = (float) $prod['price'] * $reqQty;
        $productsSubtotal += $lineTotal;

        $orderItemsData[] = [
            'product_id'            => $pid,
            'seller_id'             => (int) $prod['seller_id'],
            'product_name_snapshot' => (string) $prod['name'],
            'quantity'              => $reqQty,
            'unit_price'            => (float) $prod['price'],
            'line_total'            => $lineTotal,
        ];
    }

    if (empty($orderItemsData)) {
        throw new RuntimeException('None of the items in your cart could be processed.');
    }

    $grandTotal = $productsSubtotal;
    $orderCode = 'SKH-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));
    $paymentStatus = ($paymentMethod === 'Cash on Delivery') ? 'unpaid' : 'paid';
    $fullDeliveryAddress = $streetAddress . ', ' . $town . ', ' . $county . ' (' . $recipientName . ' - ' . $phone . ')';

    // 1. Insert Master Order Record
    $ordStmt = $pdo->prepare(
        'INSERT INTO orders
         (order_code, buyer_id, total_amount, payment_method, payment_status, order_status, delivery_address, delivery_town, notes, expires_at, version)
         VALUES (:code, :buyer, :total, :pmethod, :pstatus, :ostatus, :addr, :town, :notes, DATE_ADD(NOW(), INTERVAL 30 MINUTE), 0)'
    );
    $ordStmt->execute([
        ':code'    => $orderCode,
        ':buyer'   => (int) $user['id'],
        ':total'   => $grandTotal,
        ':pmethod' => $paymentMethod,
        ':pstatus' => $paymentStatus,
        ':ostatus' => 'pending',
        ':addr'    => $fullDeliveryAddress,
        ':town'    => $town,
        ':notes'   => $notes !== '' ? $notes : null,
    ]);
    $orderId = (int) $pdo->lastInsertId();

    // 2. Process Line Items: Atomic Stock Decrement & Stock Movements Ledger
    $decStockStmt = $pdo->prepare(
        'UPDATE products SET stock_qty = stock_qty - ? WHERE id = ? AND stock_qty >= ?'
    );
    $balanceStmt = $pdo->prepare('SELECT stock_qty FROM products WHERE id = ?');
    $movementStmt = $pdo->prepare(
        "INSERT INTO stock_movements (product_id, order_id, change_qty, reason, balance_after, created_by)
         VALUES (?, ?, ?, 'reserve', ?, ?)"
    );
    $orderItemStmt = $pdo->prepare(
        'INSERT INTO order_items (order_id, product_id, seller_id, quantity, unit_price, line_total, product_name_snapshot)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($orderItemsData as $item) {
        $pid = $item['product_id'];
        $qty = $item['quantity'];

        // Strict Atomic Inventory Decrement with concurrency guard
        $decStockStmt->execute([$qty, $pid, $qty]);
        if ($decStockStmt->rowCount() === 0) {
            throw new RuntimeException("Insufficient stock for '{$item['product_name_snapshot']}'. Order was safely rolled back.");
        }

        // Query new balance for stock ledger
        $balanceStmt->execute([$pid]);
        $balanceAfter = (int) $balanceStmt->fetchColumn();

        // Write negative ledger movement
        $movementStmt->execute([
            $pid,
            $orderId,
            -$qty,
            $balanceAfter,
            (int) $user['id'],
        ]);

        // Insert Order Line Item with historical name snapshot
        $orderItemStmt->execute([
            $orderId,
            $pid,
            $item['seller_id'],
            $qty,
            $item['unit_price'],
            $item['line_total'],
            $item['product_name_snapshot'],
        ]);
    }

    // 3. Create Delivery Record
    $delStmt = $pdo->prepare(
        "INSERT INTO deliveries (order_id, pickup_town, dropoff_address, delivery_fee, delivery_status, tracking_notes)
         VALUES (:oid, 'Kutus Market', :dropoff, :fee, 'pending', 'Awaiting rider dispatch claim')"
    );
    $delStmt->execute([
        ':oid'     => $orderId,
        ':dropoff' => $fullDeliveryAddress,
        ':fee'     => $deliveryFee,
    ]);
    $deliveryId = (int) $pdo->lastInsertId();

    // 4. Create initial pending payment record for Digital Pay-on-Delivery / STK push
    $cleanMpesa = Kyc::cleanMpesaPhone($phone) ?: $phone;
    $payStmt = $pdo->prepare("
        INSERT INTO payments (order_id, delivery_id, buyer_phone, amount, payment_method, status)
        VALUES (:oid, :did, :phone, :amt, :pmethod, :pstatus)
    ");
    $payStmt->execute([
        ':oid'     => $orderId,
        ':did'     => $deliveryId,
        ':phone'   => $cleanMpesa,
        ':amt'     => $grandTotal + $deliveryFee,
        ':pmethod' => $paymentMethod,
        ':pstatus' => ($paymentMethod === 'Cash on Delivery' || $paymentMethod === 'M-Pesa') ? 'pending' : 'completed',
    ]);
    $paymentId = (int) $pdo->lastInsertId();

    // Link digital_payment_id in deliveries if column exists
    try {
        $pdo->prepare("UPDATE deliveries SET digital_payment_id = :dpid WHERE id = :did")->execute([
            ':dpid' => $paymentId,
            ':did'  => $deliveryId,
        ]);
    } catch (Throwable $_) {}

    // 5. Trigger low stock notifications where threshold is reached
    foreach ($orderItemsData as $item) {
        try {
            Inventory::checkProductLowStock((int) $item['product_id'], 'Order: ' . $orderCode);
        } catch (Throwable $_) {}
    }

    // Commit All Changes Atomically
    $pdo->commit();

    // Clear Session Cart
    $_SESSION['cart'] = [];

    // 6. Notify offline merchants via Telephony (SMS / automated voice call)
    try {
        $sellerIds = array_unique(array_map(fn($it) => (int) $it['seller_id'], $orderItemsData));
        foreach ($sellerIds as $sid) {
            Telephony::notifyOfflineSellerOnNewOrder($sid, $orderCode, $grandTotal, $town);
        }
    } catch (Throwable $e) {
        error_log('Telephony dispatch error: ' . $e->getMessage());
    }

    set_flash('success', "Order #{$orderCode} confirmed successfully! Inventory reserved and delivery dispatch initiated.");
    redirect('pages/buyer/orders.php');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    set_flash('error', 'Checkout failed: ' . $e->getMessage());
    redirect('pages/buyer/checkout.php');
}
