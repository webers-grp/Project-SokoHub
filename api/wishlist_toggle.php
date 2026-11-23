<?php
/**
 * SokoHub Wishlist Toggle API (api/wishlist_toggle.php)
 * Role: Group 2 (Octavious & Ronny)
 *
 * Adds or removes a product for a logged-in buyer.
 * Returns JSON with updated wishlist status and badge count.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';
init_session();

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode([
        'success'       => false,
        'require_login' => true,
        'message'       => 'Please sign in to save items to your wishlist.',
        'login_url'     => url('pages/auth/login.php'),
    ]);
    exit;
}

$rawInput = file_get_contents('php://input');
$jsonPayload = json_decode($rawInput, true) ?: [];

$productId = (int) ($_POST['product_id'] ?? $jsonPayload['product_id'] ?? $_GET['product_id'] ?? 0);
$csrfToken = (string) ($_POST['csrf_token'] ?? $jsonPayload['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

if (!verify_csrf($csrfToken)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid or expired security token.',
    ]);
    exit;
}

if ($productId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Please select a valid product.',
    ]);
    exit;
}

try {
    $pdo = get_db_connection();

    // Verify product exists and is active
    $prodStmt = $pdo->prepare('SELECT id, name FROM products WHERE id = :pid AND status = :status LIMIT 1');
    $prodStmt->execute([':pid' => $productId, ':status' => 'active']);
    $prod = $prodStmt->fetch(PDO::FETCH_ASSOC);

    if (!$prod) {
        echo json_encode([
            'success' => false,
            'message' => 'Product not found or unavailable.',
        ]);
        exit;
    }

    $userId = (int) $user['id'];

    // Check existing wishlist entry
    $checkStmt = $pdo->prepare('SELECT id FROM wishlists WHERE user_id = :uid AND product_id = :pid LIMIT 1');
    $checkStmt->execute([':uid' => $userId, ':pid' => $productId]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $delStmt = $pdo->prepare('DELETE FROM wishlists WHERE user_id = :uid AND product_id = :pid');
        $delStmt->execute([':uid' => $userId, ':pid' => $productId]);
        $inWishlist = false;
        $message = 'Removed "' . $prod['name'] . '" from your wishlist.';
    } else {
        $insStmt = $pdo->prepare('INSERT INTO wishlists (user_id, product_id) VALUES (:uid, :pid)');
        $insStmt->execute([':uid' => $userId, ':pid' => $productId]);
        $inWishlist = true;
        $message = 'Saved "' . $prod['name'] . '" to your wishlist!';
    }

    $cntStmt = $pdo->prepare('SELECT COUNT(*) FROM wishlists WHERE user_id = :uid');
    $cntStmt->execute([':uid' => $userId]);
    $wishlistCount = (int) $cntStmt->fetchColumn();

    echo json_encode([
        'success'        => true,
        'in_wishlist'    => $inWishlist,
        'message'        => $message,
        'product_id'     => $productId,
        'wishlist_count' => $wishlistCount,
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Could not update wishlist: ' . $e->getMessage(),
    ]);
}
