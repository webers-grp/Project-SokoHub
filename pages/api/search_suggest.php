<?php
/**
 * SokoHub Live Search Autocomplete & Typeahead Suggestions API
 *
 * Responds with JSON containing matched products and categories based on search term.
 * Parameterized queries only.
 */

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';

$query = trim((string) ($_GET['q'] ?? ''));

if (mb_strlen($query) < 2) {
    echo json_encode([
        'ok'          => true,
        'suggestions' => [],
        'categories'  => [],
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $pdo = get_db_connection();
    $term = '%' . $query . '%';

    // 1. Suggest matched categories
    $catStmt = $pdo->prepare('
        SELECT id, name
        FROM categories
        WHERE name LIKE :term
        ORDER BY name ASC
        LIMIT 4
    ');
    $catStmt->execute([':term' => $term]);
    $matchedCategories = [];
    while ($cat = $catStmt->fetch(PDO::FETCH_ASSOC)) {
        $matchedCategories[] = [
            'id'   => (int) $cat['id'],
            'name' => $cat['name'],
            'url'  => 'pages/public/products.php?category=' . (int) $cat['id'],
        ];
    }

    // 2. Suggest matched products
    $prodStmt = $pdo->prepare('
        SELECT 
            p.id,
            p.name,
            p.price,
            p.original_price,
            p.stock_qty,
            p.rating_avg,
            p.rating_count,
            p.image_path,
            c.name AS category_name,
            COALESCE(sp.business_name, u.full_name) AS seller_name
        FROM products p
        INNER JOIN categories c ON c.id = p.category_id
        INNER JOIN users u ON u.id = p.seller_id
        LEFT JOIN seller_profiles sp ON sp.user_id = u.id
        WHERE p.status = "active"
          AND (p.name LIKE :term OR p.description LIKE :term OR c.name LIKE :term)
        ORDER BY (p.name LIKE :exact) DESC, p.rating_avg DESC, p.id DESC
        LIMIT 6
    ');
    $prodStmt->execute([
        ':term'  => $term,
        ':exact' => $query . '%',
    ]);

    $suggestions = [];
    while ($p = $prodStmt->fetch(PDO::FETCH_ASSOC)) {
        $price = (float) $p['price'];
        $suggestions[] = [
            'id'              => (int) $p['id'],
            'name'            => $p['name'],
            'price'           => $price,
            'formatted_price' => format_ksh($price),
            'stock_qty'       => (int) $p['stock_qty'],
            'category_name'   => $p['category_name'],
            'seller_name'     => $p['seller_name'],
            'image_path'      => $p['image_path'],
            'rating_avg'      => (float) $p['rating_avg'],
            'rating_count'    => (int) $p['rating_count'],
            'url'             => 'pages/public/product_details.php?id=' . (int) $p['id'],
        ];
    }

    echo json_encode([
        'ok'          => true,
        'query'       => $query,
        'categories'  => $matchedCategories,
        'suggestions' => $suggestions,
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('search_suggest error: ' . $e->getMessage());
    echo json_encode([
        'ok'          => false,
        'error'       => 'Search suggestion service temporarily unavailable',
        'suggestions' => [],
        'categories'  => [],
    ]);
}
