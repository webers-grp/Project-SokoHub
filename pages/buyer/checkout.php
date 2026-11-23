<?php
/**
<<<<<<< HEAD
 * SokoHub Multi-Step Order Checkout
 * Role: Group 2 (Octavious & Ronny)
 *
 * Features:
 * 1. Multi-step checkout wizard (1. Delivery Address -> 2. Payment Method -> 3. Review & Place Order)
 * 2. Saved Delivery Addresses selector from addresses table
 * 3. Add-new-address inline support with optional "save for future" checkbox
 * 4. Interactive payment method selector (M-Pesa, Cash on Delivery, Bank Card)
 * 5. Submits to atomic checkout_process.php (PDO transaction + stock_movements ledger)
=======
 * SokoHub Order Checkout (Atomic MySQL Transaction)
 * Pure Digital Pay-on-Delivery (M-Pesa STK Prompt upon arrival). Strictly No Cash.
 * Creates order, order_items, delivery dispatch record, and decrements product stock.
 * Authored by Kennedy Kalawa.
>>>>>>> feature/seller-kyc-admin
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/inventory.php';
require_once __DIR__ . '/../../includes/kyc.php';
require_once __DIR__ . '/../../includes/payments.php';
init_session();
$user = require_login();
$pdo = get_db_connection();
Payments::ensureSchema();

if (empty($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    set_flash('warning', 'Your shopping cart is empty. Add items before checking out.');
    redirect('pages/public/products.php');
}

$deliveryFee = 150.00;
$ids = array_map('intval', array_keys($_SESSION['cart']));
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare(
    "SELECT id, seller_id, name, price, stock_qty, image_path FROM products WHERE id IN ({$placeholders}) AND status = 'active'"
);
$stmt->execute($ids);
$dbProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$items = [];
$itemsSubtotal = 0.0;
foreach ($dbProducts as $p) {
    $pid = (int) $p['id'];
    $qty = min((int) $p['stock_qty'], max(1, (int) ($_SESSION['cart'][$pid] ?? 0)));
    if ($qty <= 0) {
        continue;
    }
    $lineTotal = (float) $p['price'] * $qty;
    $itemsSubtotal += $lineTotal;
    $p['qty'] = $qty;
    $p['line_total'] = $lineTotal;
    $items[] = $p;
}

if (empty($items)) {
    $_SESSION['cart'] = [];
    set_flash('error', 'None of your cart items are currently available in stock.');
    redirect('pages/public/products.php');
}

<<<<<<< HEAD
// Fetch Buyer's Saved Addresses from addresses table
$savedAddresses = [];
try {
    $addrStmt = $pdo->prepare('SELECT * FROM addresses WHERE user_id = :uid ORDER BY is_default DESC, id DESC');
    $addrStmt->execute([':uid' => (int) $user['id']]);
    $savedAddresses = $addrStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable) {
    $savedAddresses = [];
=======
$errors = [];
$old = [
    'delivery_address' => '',
    'delivery_town'    => 'Kutus',
    'mpesa_phone'      => $user['phone'] ?? '',
    'payment_method'   => 'Digital Pay-on-Delivery (M-Pesa)',
    'notes'            => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid security token. Please refresh and try again.';
    } else {
        $old['delivery_address'] = trim((string) ($_POST['delivery_address'] ?? ''));
        $old['delivery_town'] = trim((string) ($_POST['delivery_town'] ?? ''));
        $old['mpesa_phone'] = trim((string) ($_POST['mpesa_phone'] ?? ''));
        $old['payment_method'] = 'Digital Pay-on-Delivery (M-Pesa)';
        $old['notes'] = trim((string) ($_POST['notes'] ?? ''));

        if (mb_strlen($old['delivery_address']) < 5) {
            $errors[] = 'Please provide a clear delivery address (at least 5 characters).';
        }
        if (mb_strlen($old['delivery_town']) < 2) {
            $errors[] = 'Please provide your delivery town.';
        }

        $cleanMpesa = Kyc::cleanMpesaPhone($old['mpesa_phone']);
        if (!$cleanMpesa) {
            $errors[] = 'Please provide a valid Safaricom phone number (e.g. 0712345678 or 0112345678) to receive the M-Pesa STK payment prompt on delivery.';
        }

        if (empty($errors)) {
            $pdo->beginTransaction();
            try {
                $verifiedItems = [];
                $verifiedSubtotal = 0.0;

                $lockStmt = $pdo->prepare("SELECT id, seller_id, name, price, stock_qty FROM products WHERE id = :id AND status = 'active' FOR UPDATE");
                foreach ($items as $it) {
                    $lockStmt->execute([':id' => (int) $it['id']]);
                    $fresh = $lockStmt->fetch();
                    if (!$fresh || (int) $fresh['stock_qty'] < (int) $it['qty']) {
                        throw new RuntimeException('Insufficient stock for "' . $it['name'] . '".');
                    }
                    $line = (float) $fresh['price'] * (int) $it['qty'];
                    $verifiedSubtotal += $line;
                    $verifiedItems[] = [
                        'id'         => (int) $fresh['id'],
                        'seller_id'  => (int) $fresh['seller_id'],
                        'qty'        => (int) $it['qty'],
                        'unit_price' => (float) $fresh['price'],
                        'line_total' => $line,
                    ];
                }

                $grandTotal = $verifiedSubtotal;
                $orderCode = 'SKH-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));

                // Step 4: Pure Digital Pay-on-Delivery (Strictly zero upfront payment)
                $payStatus = 'unpaid';

                $ordStmt = $pdo->prepare(
                    "INSERT INTO orders
                     (order_code, buyer_id, total_amount, payment_method, payment_status, order_status, delivery_address, delivery_town, notes)
                     VALUES (:code, :buyer, :total, :pmethod, :pstatus, 'pending', :addr, :town, :notes)"
                );
                $ordStmt->execute([
                    ':code'    => $orderCode,
                    ':buyer'   => (int) $user['id'],
                    ':total'   => $grandTotal,
                    ':pmethod' => 'Digital Pay-on-Delivery (M-Pesa)',
                    ':pstatus' => $payStatus,
                    ':addr'    => $old['delivery_address'],
                    ':town'    => $old['delivery_town'],
                    ':notes'   => $old['notes'],
                ]);
                $orderId = (int) $pdo->lastInsertId();

                $itemStmt = $pdo->prepare(
                    'INSERT INTO order_items (order_id, product_id, seller_id, quantity, unit_price, line_total)
                     VALUES (:oid, :pid, :sid, :qty, :price, :line)'
                );
                $stockStmt = $pdo->prepare('UPDATE products SET stock_qty = stock_qty - :qty WHERE id = :pid');

                foreach ($verifiedItems as $vi) {
                    $itemStmt->execute([
                        ':oid'   => $orderId,
                        ':pid'   => $vi['id'],
                        ':sid'   => $vi['seller_id'],
                        ':qty'   => $vi['qty'],
                        ':price' => $vi['unit_price'],
                        ':line'  => $vi['line_total'],
                    ]);
                    $stockStmt->execute([
                        ':qty' => $vi['qty'],
                        ':pid' => $vi['id'],
                    ]);
                    Inventory::checkProductLowStock((int) $vi['id'], 'Order: ' . $orderCode);
                }

                // Initial delivery tracking note reflecting merchant-driven assignment
                $delStmt = $pdo->prepare(
                    "INSERT INTO deliveries (order_id, pickup_town, dropoff_address, delivery_fee, delivery_status, tracking_notes)
                     VALUES (:oid, 'Kutus Market', :dropoff, :fee, 'pending', 'Awaiting merchant rider assignment')"
                );
                $delStmt->execute([
                    ':oid'     => $orderId,
                    ':dropoff' => $old['delivery_town'] . ' - ' . $old['delivery_address'],
                    ':fee'     => $deliveryFee,
                ]);
                $delId = (int) $pdo->lastInsertId();

                // Create initial pending payment record for STK push dispatch
                $payStmt = $pdo->prepare("
                    INSERT INTO payments (order_id, delivery_id, buyer_phone, amount, payment_method, status)
                    VALUES (:oid, :did, :phone, :amt, 'Digital Pay-on-Delivery (M-Pesa)', 'pending')
                ");
                $payStmt->execute([
                    ':oid'   => $orderId,
                    ':did'   => $delId,
                    ':phone' => $cleanMpesa,
                    ':amt'   => $grandTotal + $deliveryFee,
                ]);

                // Update user's phone if missing or changed
                if (!empty($cleanMpesa) && empty($user['phone'])) {
                    $pdo->prepare('UPDATE users SET phone = :phone WHERE id = :uid')->execute([
                        ':phone' => $cleanMpesa,
                        ':uid'   => (int) $user['id'],
                    ]);
                    $_SESSION['user']['phone'] = $cleanMpesa;
                }

                $pdo->commit();
                $_SESSION['cart'] = [];

                // Step 5: Notify offline merchants via Telephony (SMS / Automated Voice Call)
                require_once __DIR__ . '/../../includes/telephony.php';
                Telephony::ensureSchema();
                $sellerIds = array_unique(array_map(fn($vi) => (int) $vi['seller_id'], $verifiedItems));
                foreach ($sellerIds as $sid) {
                    Telephony::notifyOfflineSellerOnNewOrder($sid, $orderCode, $grandTotal, $old['delivery_town']);
                }

                set_flash('success', 'Order ' . $orderCode . ' placed successfully! Pure Digital Pay-on-Delivery: Your assigned delivery rider will prompt phone ' . $cleanMpesa . ' via Safaricom M-Pesa STK push upon arrival.');
                redirect('pages/buyer/orders.php');
            } catch (Throwable $e) {
                $pdo->rollBack();
                $errors[] = $e->getMessage();
            }
        }
    }
>>>>>>> feature/seller-kyc-admin
}

$defaultAddressId = 0;
foreach ($savedAddresses as $a) {
    if ((int) $a['is_default'] === 1) {
        $defaultAddressId = (int) $a['id'];
        break;
    }
}
if ($defaultAddressId === 0 && !empty($savedAddresses)) {
    $defaultAddressId = (int) $savedAddresses[0]['id'];
}

$pageTitle = 'SokoHub | Multi-Step Checkout';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="container section-space">
    <div class="page-head">
        <div>
<<<<<<< HEAD
            <h1><?= icon('shopping-bag', 'lucide-md', 'emerald') ?> Order Checkout</h1>
            <p class="muted">Complete your order in 3 simple steps.</p>
=======
            <h1>Order Checkout</h1>
            <p class="muted">Confirm delivery location and Safaricom phone number for Pay-on-Delivery M-Pesa prompt.</p>
>>>>>>> feature/seller-kyc-admin
        </div>
        <a href="<?= e(url('pages/buyer/cart.php')) ?>" class="btn btn-outline">
            <?= icon('arrow-left', 'lucide-xs', 'blue') ?>
            <span>Back to Cart</span>
        </a>
    </div>

    <!-- Multi-Step Wizard Progress Bar (Group 2) -->
    <div class="checkout-stepper" id="checkoutStepper">
        <div class="step-item active" data-step="1">
            <div class="step-num"><?= icon('map-pin', 'lucide-xs') ?> 1</div>
            <div class="step-label">Delivery Address</div>
        </div>
        <div class="step-divider"></div>
        <div class="step-item" data-step="2">
            <div class="step-num"><?= icon('credit-card', 'lucide-xs') ?> 2</div>
            <div class="step-label">Payment Method</div>
        </div>
        <div class="step-divider"></div>
        <div class="step-item" data-step="3">
            <div class="step-num"><?= icon('check-circle-2', 'lucide-xs') ?> 3</div>
            <div class="step-label">Review &amp; Place Order</div>
        </div>
    </div>

    <div class="split-grid" style="align-items: start; margin-top: 1.5rem;">
        <!-- Left: Multi-Step Interactive Form -->
        <div class="card">
<<<<<<< HEAD
            <form action="<?= e(url('checkout_process.php')) ?>" method="POST" id="checkoutForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                <!-- ================= STEP 1: DELIVERY ADDRESS ================= -->
                <div class="checkout-step-pane active" id="stepPane1">
                    <h2 class="step-section-title">
                        <?= icon('map-pin', 'lucide-sm', 'amber') ?>
                        <span>Step 1: Choose Delivery Location</span>
                    </h2>

                    <?php if (!empty($savedAddresses)): ?>
                        <div class="saved-address-list" style="margin-bottom: 1.25rem;">
                            <label class="form-label" style="font-weight: 700;">Saved Delivery Addresses:</label>
                            <?php foreach ($savedAddresses as $addr): ?>
                                <?php $isSelected = ((int) $addr['id'] === $defaultAddressId); ?>
                                <label class="address-card-radio <?= $isSelected ? 'selected' : '' ?>">
                                    <input type="radio"
                                           name="address_id"
                                           value="<?= (int) $addr['id'] ?>"
                                           <?= $isSelected ? 'checked' : '' ?>
                                           data-recipient="<?= e($addr['recipient_name']) ?>"
                                           data-phone="<?= e($addr['phone']) ?>"
                                           data-town="<?= e($addr['town']) ?>"
                                           data-county="<?= e($addr['county'] ?: 'Kirinyaga') ?>"
                                           data-street="<?= e($addr['street_address']) ?>">
                                    <div class="address-card-body">
                                        <div class="address-title">
                                            <strong><?= e($addr['recipient_name']) ?></strong>
                                            <?php if (!empty($addr['is_default'])): ?>
                                                <span class="badge badge-success small">Default</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="address-text muted small">
                                            <?= icon('phone', 'lucide-xs') ?> <?= e($addr['phone']) ?> &bull;
                                            <?= icon('map-pin', 'lucide-xs') ?> <?= e($addr['street_address']) ?>, <?= e($addr['town']) ?> (<?= e($addr['county'] ?: 'Kirinyaga') ?>)
                                        </div>
                                    </div>
                                </label>
                            <?php endforeach; ?>

                            <label class="address-card-radio <?= $defaultAddressId === 0 ? 'selected' : '' ?>" style="margin-top: 0.5rem;">
                                <input type="radio" name="address_id" value="0" <?= $defaultAddressId === 0 ? 'checked' : '' ?> id="radioNewAddress">
                                <div class="address-card-body">
                                    <strong><?= icon('plus', 'lucide-xs', 'blue') ?> Deliver to a New Address</strong>
                                    <div class="muted small">Enter different recipient name, phone, or town</div>
                                </div>
                            </label>
                        </div>
                    <?php else: ?>
                        <input type="hidden" name="address_id" value="0">
                    <?php endif; ?>

                    <!-- Form Fields for New Address -->
                    <div id="newAddressFields" style="<?= !empty($savedAddresses) ? 'display:none;' : '' ?>">
                        <div class="form-grid-2">
                            <div class="form-group">
                                <label for="recipient_name">Recipient Name *</label>
                                <input type="text"
                                       id="recipient_name"
                                       name="recipient_name"
                                       class="form-control"
                                       value="<?= e($user['full_name']) ?>"
                                       placeholder="e.g. Kennedy Kalawa">
                            </div>
                            <div class="form-group">
                                <label for="phone">Recipient Phone Number *</label>
                                <input type="tel"
                                       id="phone"
                                       name="phone"
                                       class="form-control"
                                       value="<?= e($user['phone'] ?? '') ?>"
                                       placeholder="e.g. +254 712 345 678">
                            </div>
                        </div>

                        <div class="form-grid-2">
                            <div class="form-group">
                                <label for="county">County *</label>
                                <input type="text" id="county" name="county" class="form-control" value="Kirinyaga" placeholder="Kirinyaga">
                            </div>
                            <div class="form-group">
                                <label for="town">Town / Market Center *</label>
                                <select id="town" name="town" class="form-control">
                                    <option value="Kutus" selected>Kutus (Free / Base Delivery)</option>
                                    <option value="Kerugoya">Kerugoya</option>
                                    <option value="Kagio">Kagio</option>
                                    <option value="Sagana">Sagana</option>
                                    <option value="Embu">Embu</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="street_address">Specific Street Address / Landmark / Hostel *</label>
                            <input type="text"
                                   id="street_address"
                                   name="street_address"
                                   class="form-control"
                                   placeholder="e.g. Kirinyaga University Gate B, Hostel 4, Room 102">
                        </div>

                        <div class="checkbox-row" style="margin-bottom: 1rem;">
                            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                <input type="checkbox" name="save_address" value="1" checked>
                                <span>Save this address to my saved addresses book</span>
                            </label>
                        </div>
                    </div>

                    <div style="text-align: right; margin-top: 1.5rem;">
                        <button type="button" class="btn btn-primary btn-next-step" data-target="2">
                            <span>Continue to Payment</span>
                            <?= icon('arrow-right', 'lucide-xs', 'white') ?>
                        </button>
                    </div>
                </div>

                <!-- ================= STEP 2: PAYMENT METHOD ================= -->
                <div class="checkout-step-pane" id="stepPane2" style="display:none;">
                    <h2 class="step-section-title">
                        <?= icon('credit-card', 'lucide-sm', 'amber') ?>
                        <span>Step 2: Choose Payment Method</span>
                    </h2>

                    <div class="payment-selector-grid">
                        <label class="payment-method-card selected">
                            <input type="radio" name="payment_method" value="M-Pesa" checked>
                            <div class="payment-card-body">
                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                    <strong><?= icon('smartphone', 'lucide-sm', 'emerald') ?> M-Pesa Mobile Money</strong>
                                    <span class="badge badge-success">Instant</span>
                                </div>
                                <p class="muted small" style="margin-top:0.35rem;">
                                    Safe and automated STK Push to your Safaricom line upon placing order.
                                </p>
                            </div>
                        </label>

                        <label class="payment-method-card">
                            <input type="radio" name="payment_method" value="Cash on Delivery">
                            <div class="payment-card-body">
                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                    <strong><?= icon('truck', 'lucide-sm', 'amber') ?> Cash on Delivery</strong>
                                    <span class="badge badge-warning">Doorstep Pay</span>
                                </div>
                                <p class="muted small" style="margin-top:0.35rem;">
                                    Pay cash or local M-Pesa till to your delivery rider after inspecting your goods.
                                </p>
                            </div>
                        </label>

                        <label class="payment-method-card">
                            <input type="radio" name="payment_method" value="Bank Card">
                            <div class="payment-card-body">
                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                    <strong><?= icon('credit-card', 'lucide-sm', 'blue') ?> Debit / Credit Card</strong>
                                    <span class="badge badge-info">Visa / Mastercard</span>
                                </div>
                                <p class="muted small" style="margin-top:0.35rem;">
                                    Pay securely using Visa, Mastercard, or local bank card.
                                </p>
                            </div>
                        </label>
                    </div>

                    <div class="form-group" style="margin-top: 1.25rem;">
                        <label for="notes">Special Rider Instructions (Optional)</label>
                        <textarea id="notes" name="notes" class="form-control" rows="2" placeholder="e.g. Call when approaching Gate B, leave with caretaker, gate code 1234..."></textarea>
                    </div>

                    <div style="display:flex; justify-content:space-between; margin-top: 1.5rem;">
                        <button type="button" class="btn btn-outline btn-prev-step" data-target="1">
                            <?= icon('arrow-left', 'lucide-xs', 'blue') ?>
                            <span>Back to Address</span>
                        </button>
                        <button type="button" class="btn btn-primary btn-next-step" data-target="3">
                            <span>Review Order</span>
                            <?= icon('arrow-right', 'lucide-xs', 'white') ?>
                        </button>
                    </div>
                </div>

                <!-- ================= STEP 3: REVIEW & PLACE ORDER ================= -->
                <div class="checkout-step-pane" id="stepPane3" style="display:none;">
                    <h2 class="step-section-title">
                        <?= icon('check-circle-2', 'lucide-sm', 'emerald') ?>
                        <span>Step 3: Review &amp; Confirm Order</span>
                    </h2>

                    <div class="card" style="background:#f8fafc; margin-bottom:1.25rem;">
                        <h3 style="font-size:1.05rem; margin-bottom:0.4rem; color:var(--text-dark);">
                            <?= icon('map-pin', 'lucide-xs', 'rose') ?> Delivery Destination
                        </h3>
                        <p id="reviewAddressSummary" class="small muted" style="line-height:1.5;">
                            <!-- Populated dynamically via JS -->
                        </p>
                    </div>

                    <div class="card" style="background:#f8fafc; margin-bottom:1.25rem;">
                        <h3 style="font-size:1.05rem; margin-bottom:0.4rem; color:var(--text-dark);">
                            <?= icon('credit-card', 'lucide-xs', 'blue') ?> Payment Preference
                        </h3>
                        <p id="reviewPaymentSummary" class="small muted">
                            <!-- Populated dynamically via JS -->
                        </p>
                    </div>

                    <div class="card" style="background:#ecfdf5; border:1px solid #a7f3d0; margin-bottom:1.5rem;">
                        <div style="display:flex; align-items:center; gap:0.6rem;">
                            <?= icon('shield-check', 'lucide-md', 'emerald') ?>
                            <div>
                                <strong style="color:#065f46;">Inventory Reserved on Confirmation</strong>
                                <p class="small" style="color:#047857; margin:0.15rem 0 0;">
                                    Stock quantities will be atomicity checked and decremented in real-time.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div style="display:flex; justify-content:space-between; margin-top: 1.5rem;">
                        <button type="button" class="btn btn-outline btn-prev-step" data-target="2">
                            <?= icon('arrow-left', 'lucide-xs', 'blue') ?>
                            <span>Back to Payment</span>
                        </button>
                        <button type="submit" class="btn btn-amber btn-lg" id="btnPlaceOrder">
                            <?= icon('check-circle', 'lucide-sm', 'white') ?>
                            <span>Confirm &amp; Place Order</span>
                        </button>
                    </div>
                </div>
=======
            <h2 style="color: var(--green-dark); margin-bottom: 1rem;">Delivery &amp; Payment Details</h2>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <div>
                        <?php foreach ($errors as $err): ?>
                            <div><?= e($err) ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Digital Pay-on-Delivery Notice -->
            <div style="background: rgba(16, 185, 129, 0.08); border-left: 5px solid #10b981; padding: 0.9rem 1.1rem; border-radius: 6px; margin-bottom: 1.5rem;">
                <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.35rem;">
                    <span class="badge badge-success" style="font-size: 0.78rem;">
                        <?= icon('shield-check', 'lucide-xs') ?> Strictly Cashless
                    </span>
                    <strong style="color: #065f46; font-size: 0.95rem;">Pure Digital Pay-on-Delivery</strong>
                </div>
                <p class="small muted" style="margin: 0; line-height: 1.5;">
                    <strong>Zero upfront payment.</strong> SokoHub operates on a strictly cashless digital delivery model. When your rider arrives with your package, they will trigger an automated Safaricom M-Pesa STK Push directly to your phone. Enter your PIN on arrival to release the goods. No cash will be accepted.
                </p>
            </div>

            <form action="<?= e(url('pages/buyer/checkout.php')) ?>" method="POST" data-validate="true">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                <div class="form-group">
                    <label for="delivery_town">Town / Delivery Area *</label>
                    <input type="text" id="delivery_town" name="delivery_town" class="form-control" value="<?= e($old['delivery_town']) ?>" required data-minlength="2" placeholder="e.g. Kutus, Kerugoya, Sagana">
                </div>

                <div class="form-group">
                    <label for="delivery_address">Specific Drop-Off Address *</label>
                    <input type="text" id="delivery_address" name="delivery_address" class="form-control" value="<?= e($old['delivery_address']) ?>" required data-minlength="5" placeholder="e.g. Kirinyaga University Gate B, Hostel 2, Room 14">
                </div>

                <div class="form-group">
                    <label for="mpesa_phone">Safaricom Phone for M-Pesa STK Push Prompt *</label>
                    <input type="tel" id="mpesa_phone" name="mpesa_phone" class="form-control" value="<?= e($old['mpesa_phone']) ?>" required placeholder="e.g. 0712345678 or 0112345678">
                    <small class="muted">Your phone will receive an automated STK Push prompt to enter your PIN when the rider arrives.</small>
                </div>

                <div class="form-group">
                    <label for="payment_method">Payment Method</label>
                    <div style="padding: 0.65rem 0.85rem; border: 1px solid var(--border-color); border-radius: 6px; background: var(--bg-surface); display: flex; align-items: center; justify-content: space-between;">
                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            <?= icon('smartphone', 'lucide-xs', 'emerald') ?>
                            <strong>Digital Pay-on-Delivery (Safaricom M-Pesa)</strong>
                        </div>
                        <span class="badge badge-success" style="font-size: 0.72rem;">On Arrival</span>
                    </div>
                </div>

                <div class="form-group">
                    <label for="notes">Rider Instructions (Optional)</label>
                    <textarea id="notes" name="notes" class="form-control" rows="2" placeholder="e.g. Call on arrival, landmark, gate colour..."><?= e($old['notes']) ?></textarea>
                </div>

                <button type="submit" class="btn btn-amber btn-block" style="font-size: 1.05rem; padding: 0.75rem;">
                    <?= icon('check-circle', 'lucide-xs') ?>
                    <span>Confirm Order (Pay on Delivery)</span>
                </button>
>>>>>>> feature/seller-kyc-admin
            </form>
        </div>

        <!-- Right: Order Summary Sidebar -->
        <div class="card">
            <h2 style="margin-bottom: 1rem;">
                <?= icon('receipt', 'lucide-sm', 'purple') ?>
                <span>Order Summary</span>
            </h2>
            <ul style="list-style:none;">
                <?php foreach ($items as $it): ?>
                    <li style="display:flex; justify-content:space-between; align-items:center; padding:0.65rem 0; border-bottom:1px solid var(--border-color);">
                        <div style="display:flex; align-items:center; gap:0.6rem;">
                            <img src="<?= e(url($it['image_path'])) ?>" alt="" class="table-thumb" style="width:38px; height:38px;">
                            <div>
                                <strong style="font-size:0.95rem;"><?= e($it['name']) ?></strong>
                                <div class="small muted">Qty: <?= (int) $it['qty'] ?> &times; <?= format_ksh($it['price']) ?></div>
                            </div>
                        </div>
                        <strong><?= format_ksh($it['line_total']) ?></strong>
                    </li>
                <?php endforeach; ?>
            </ul>
<<<<<<< HEAD

            <div style="margin-top:1rem;">
                <p style="display:flex; justify-content:space-between; margin-bottom:0.4rem;" class="muted">
                    <span>Items Subtotal</span>
                    <strong id="summarySubtotal"><?= format_ksh($itemsSubtotal) ?></strong>
                </p>
                <p style="display:flex; justify-content:space-between; margin-bottom:0.4rem;" class="muted">
                    <span>Local Delivery Fee</span>
                    <strong id="summaryDeliveryFee"><?= format_ksh($deliveryFee) ?></strong>
                </p>
                <hr style="border:0; border-top:1px solid var(--border-color); margin:0.75rem 0;">
                <p style="display:flex; justify-content:space-between; font-size:1.3rem; font-weight:800; color:var(--green-dark);">
                    <span>Total Amount</span>
                    <strong id="summaryGrandTotal"><?= format_ksh($itemsSubtotal + $deliveryFee) ?></strong>
                </p>
=======
            <p style="display:flex; justify-content:space-between; margin-top:0.85rem;" class="muted">
                <span>Products Subtotal</span>
                <span><?= format_ksh($itemsSubtotal) ?></span>
            </p>
            <p style="display:flex; justify-content:space-between; margin-top:0.4rem;" class="muted">
                <span>Delivery Fee (Kirinyaga Local Dispatch)</span>
                <span><?= format_ksh($deliveryFee) ?></span>
            </p>
            <p style="display:flex; justify-content:space-between; margin-top:0.75rem; font-size:1.25rem; font-weight:800; color:var(--green-dark);">
                <span>Total Payable on Delivery</span>
                <span><?= format_ksh($itemsSubtotal + $deliveryFee) ?></span>
            </p>
            <div class="small muted" style="margin-top: 0.5rem; text-align: center; border-top: 1px dashed var(--border-color); padding-top: 0.5rem;">
                No payment is deducted now. STK push will be initiated upon rider arrival.
>>>>>>> feature/seller-kyc-admin
            </div>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
