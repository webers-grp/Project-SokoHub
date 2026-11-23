<?php
/**
 * SokoHub Cart Engine (cart_actions.php)
 * Handles session-backed shopping cart actions:
 * - add: Adds item, caps at products.stock_qty
 * - update: Updates quantity with stock ceiling, removes if 0
 * - remove: Removes specific product
 * - clear: Empties shopping cart
 * - get: Returns JSON summary of active cart
 * Supports both JSON/AJAX responses and standard POST redirects with flash messages.
 */

require_once __DIR__ . '/includes/auth.php';
init_session();

$pdo = get_db_connection();

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
    || isset($_GET['ajax'])
    || (isset($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'application/json'));

$payload = [];
if ($isAjax && isset($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'application/json')) {
    $rawInput = file_get_contents('php://input');
    $payload = json_decode($rawInput, true) ?: [];
}

$action = (string) ($_POST['action'] ?? $payload['action'] ?? $_GET['action'] ?? '');
$productId = (int) ($_POST['product_id'] ?? $payload['product_id'] ?? $_GET['product_id'] ?? 0);
$quantity = (int) ($_POST['quantity'] ?? $payload['quantity'] ?? 1);
$csrfToken = (string) ($_POST['csrf_token'] ?? $payload['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

function send_cart_response(bool $success, string $message, int $productId = 0, float $deliveryFee = 150.00, ?PDO $pdo = null): void
{
    global $isAjax;

    if ($pdo === null) {
        $pdo = get_db_connection();
    }

    $subtotal = 0.0;
    $itemsCount = 0;
    $itemLineTotal = 0.0;
    $currentQty = 0;
    $stockQty = 0;

    if (!empty($_SESSION['cart'])) {
        $ids = array_map('intval', array_keys($_SESSION['cart']));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT id, name, price, stock_qty FROM products WHERE id IN ({$placeholders}) AND status = 'active'");
        $stmt->execute($ids);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($products as $p) {
            $pid = (int) $p['id'];
            $qty = (int) ($_SESSION['cart'][$pid] ?? 0);
            if ($qty > 0) {
                $line = (float) $p['price'] * $qty;
                $subtotal += $line;
                $itemsCount += $qty;
                if ($pid === $productId) {
                    $itemLineTotal = $line;
                    $currentQty = $qty;
                    $stockQty = (int) $p['stock_qty'];
                }
            }
        }
    }

    $total = $itemsCount > 0 ? $subtotal + $deliveryFee : 0.0;

    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success'               => $success,
            'message'               => $message,
            'cart_count'            => $itemsCount,
            'product_id'            => $productId,
            'quantity'              => $currentQty,
            'stock_qty'             => $stockQty,
            'line_total'            => round($itemLineTotal, 2),
            'formatted_line_total'  => format_ksh($itemLineTotal),
            'subtotal'              => round($subtotal, 2),
            'formatted_subtotal'    => format_ksh($subtotal),
            'delivery_fee'          => round($deliveryFee, 2),
            'formatted_delivery_fee'=> format_ksh($deliveryFee),
            'total'                 => round($total, 2),
            'formatted_total'       => format_ksh($total),
            'items'                 => $_SESSION['cart'],
        ]);
        exit;
    }

    set_flash($success ? 'success' : 'error', $message);
    $returnUrl = $_SERVER['HTTP_REFERER'] ?? url('pages/buyer/cart.php');
    header('Location: ' . $returnUrl);
    exit;
}

// Read-only cart fetch (for AJAX status sync)
if ($action === 'get') {
    send_cart_response(true, 'Cart retrieved successfully.', 0, 150.00, $pdo);
}

// CSRF Verification for all modifying actions
if (!verify_csrf($csrfToken)) {
    send_cart_response(false, 'Invalid or expired security token. Please refresh the page.', $productId, 150.00, $pdo);
}

switch ($action) {
    case 'add':
        if ($productId <= 0) {
            send_cart_response(false, 'Invalid product selected.', $productId, 150.00, $pdo);
        }

        $stmt = $pdo->prepare("SELECT id, name, price, stock_qty FROM products WHERE id = :id AND status = 'active' LIMIT 1");
        $stmt->execute([':id' => $productId]);
        $prod = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$prod || (int) $prod['stock_qty'] <= 0) {
            send_cart_response(false, 'Sorry, this product is currently out of stock.', $productId, 150.00, $pdo);
        }

        $availableStock = (int) $prod['stock_qty'];
        $existingQty = (int) ($_SESSION['cart'][$productId] ?? 0);
        $addQty = max(1, $quantity);

        if ($existingQty >= $availableStock) {
            send_cart_response(false, 'Maximum available stock (' . $availableStock . ') reached for ' . $prod['name'] . '.', $productId, 150.00, $pdo);
        }

        $newQty = min($availableStock, $existingQty + $addQty);
        $_SESSION['cart'][$productId] = $newQty;

        $msg = ($newQty === $availableStock && ($existingQty + $addQty) > $availableStock)
            ? 'Added ' . ($newQty - $existingQty) . ' items (capped at available stock: ' . $availableStock . ').'
            : 'Added "' . $prod['name'] . '" to your cart.';

        send_cart_response(true, $msg, $productId, 150.00, $pdo);
        break;

    case 'update':
        if ($productId <= 0) {
            send_cart_response(false, 'Invalid product selected.', $productId, 150.00, $pdo);
        }

        if ($quantity <= 0) {
            unset($_SESSION['cart'][$productId]);
            send_cart_response(true, 'Item removed from your cart.', $productId, 150.00, $pdo);
        }

        $stmt = $pdo->prepare("SELECT id, name, price, stock_qty FROM products WHERE id = :id AND status = 'active' LIMIT 1");
        $stmt->execute([':id' => $productId]);
        $prod = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$prod) {
            unset($_SESSION['cart'][$productId]);
            send_cart_response(false, 'Product no longer available.', $productId, 150.00, $pdo);
        }

        $stock = (int) $prod['stock_qty'];
        $cappedQty = min(max(1, $stock), $quantity);
        $_SESSION['cart'][$productId] = $cappedQty;

        $msg = ($quantity > $stock)
            ? 'Quantity capped to maximum available stock (' . $stock . ').'
            : 'Cart updated successfully.';

        send_cart_response(true, $msg, $productId, 150.00, $pdo);
        break;

    case 'remove':
        if ($productId > 0) {
            unset($_SESSION['cart'][$productId]);
            send_cart_response(true, 'Item removed from your shopping cart.', $productId, 150.00, $pdo);
        }
        send_cart_response(false, 'Invalid product to remove.', 0, 150.00, $pdo);
        break;

    case 'clear':
        $_SESSION['cart'] = [];
        send_cart_response(true, 'Your shopping cart has been cleared.', 0, 150.00, $pdo);
        break;

    default:
        send_cart_response(false, 'Unrecognized cart action requested.', 0, 150.00, $pdo);
        break;
}
