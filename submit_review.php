<?php
/**
 * SokoHub Verified Reviews & Rating Recalculator (submit_review.php)
 * Role: Group 2 (Octavious & Ronny)
 *
 * Requirements:
 * 1. Verifies buyer actually purchased and received the item (deliveries.delivery_status = 'delivered').
 * 2. In a single PDO transaction, inserts the review and recalculates products.rating_avg and products.rating_count:
 *    UPDATE products SET
 *      rating_avg = (SELECT AVG(rating) FROM reviews WHERE product_id = ? AND status = 'published'),
 *      rating_count = (SELECT COUNT(*) FROM reviews WHERE product_id = ? AND status = 'published')
 *    WHERE id = ?;
 * 3. Handles both AJAX/JSON and traditional POST with flash alerts.
 */

require_once __DIR__ . '/includes/auth.php';
init_session();
$user = require_login();

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
    || (isset($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'application/json'));

$payload = [];
if ($isAjax && isset($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'application/json')) {
    $rawInput = file_get_contents('php://input');
    $payload = json_decode($rawInput, true) ?: [];
}

$productId = (int) ($_POST['product_id'] ?? $payload['product_id'] ?? 0);
$rating = (int) ($_POST['rating'] ?? $payload['rating'] ?? 0);
$comment = trim((string) ($_POST['comment'] ?? $payload['comment'] ?? ''));
$csrfToken = (string) ($_POST['csrf_token'] ?? $payload['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

function send_review_response(bool $success, string $message, int $productId): never
{
    global $isAjax;
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success'    => $success,
            'message'    => $message,
            'product_id' => $productId,
        ]);
        exit;
    }

    set_flash($success ? 'success' : 'error', $message);
    $redirectUrl = url('pages/public/product_details.php?id=' . $productId);
    header('Location: ' . $redirectUrl);
    exit;
}

if (!verify_csrf($csrfToken)) {
    send_review_response(false, 'Invalid or expired security token. Please try again.', $productId);
}

if ($productId <= 0) {
    send_review_response(false, 'Invalid product specified for review.', $productId);
}

if ($rating < 1 || $rating > 5) {
    send_review_response(false, 'Please provide a valid rating between 1 and 5 stars.', $productId);
}

$pdo = get_db_connection();

// 1. Verify Buyer Actually Purchased & Received the Item (deliveries.delivery_status = 'delivered')
$verifyStmt = $pdo->prepare(
    "SELECT oi.id AS order_item_id
     FROM order_items oi
     INNER JOIN orders o ON o.id = oi.order_id
     INNER JOIN deliveries d ON d.order_id = o.id
     WHERE o.buyer_id = :bid
       AND oi.product_id = :pid
       AND d.delivery_status = 'delivered'
     ORDER BY oi.id DESC
     LIMIT 1"
);
$verifyStmt->execute([
    ':bid' => (int) $user['id'],
    ':pid' => $productId,
]);
$orderItemId = $verifyStmt->fetchColumn();

if (!$orderItemId) {
    send_review_response(
        false,
        'Verified Purchase Required: You can only review products that have been delivered to you.',
        $productId
    );
}

// 2. Wrap Insert and Rating Recalculation inside a single PDO transaction
try {
    $pdo->beginTransaction();

    // Insert or update review record (UNIQUE on buyer_id, product_id)
    $reviewStmt = $pdo->prepare(
        "INSERT INTO reviews (product_id, buyer_id, order_item_id, rating, comment, status, created_at)
         VALUES (:pid, :bid, :oiid, :rating, :comment, 'published', NOW())
         ON DUPLICATE KEY UPDATE
            order_item_id = VALUES(order_item_id),
            rating = VALUES(rating),
            comment = VALUES(comment),
            status = 'published',
            created_at = NOW()"
    );
    $reviewStmt->execute([
        ':pid'     => $productId,
        ':bid'     => (int) $user['id'],
        ':oiid'    => (int) $orderItemId,
        ':rating'  => $rating,
        ':comment' => $comment !== '' ? $comment : null,
    ]);

    // Recalculate and update products.rating_avg and products.rating_count
    $updateStmt = $pdo->prepare(
        "UPDATE products SET
           rating_avg = COALESCE((SELECT AVG(rating) FROM reviews WHERE product_id = :p1 AND status = 'published'), 0.00),
           rating_count = (SELECT COUNT(*) FROM reviews WHERE product_id = :p2 AND status = 'published')
         WHERE id = :p3"
    );
    $updateStmt->execute([
        ':p1' => $productId,
        ':p2' => $productId,
        ':p3' => $productId,
    ]);

    $pdo->commit();

    send_review_response(true, 'Thank you! Your verified review and rating have been published.', $productId);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    send_review_response(false, 'Unable to submit review: ' . $e->getMessage(), $productId);
}
