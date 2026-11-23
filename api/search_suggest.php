<?php
/**
 * SokoHub Instant Live Autocomplete Search Suggestion API
 * Role: Group 2 (Octavious & Ronny)
 *
 * Executes full-text prefix search in MySQL:
 * SELECT id, name, price, image_path AS image_url FROM products
 * WHERE MATCH(name, description) AGAINST (? IN BOOLEAN MODE) AND status = 'active' LIMIT 8;
 * Returns lightweight JSON in under 50ms.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=60');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$query = trim((string) ($_GET['q'] ?? ''));

if ($query === '' || mb_strlen($query) < 1) {
    echo json_encode([]);
    exit;
}

try {
    $pdo = get_db_connection();

    // Sanitize and prepare Boolean Mode tokens with wildcard prefix
    $cleanQuery = preg_replace('/[+\-><()~*\"@]+/', ' ', $query);
    $words = array_filter(explode(' ', trim((string) $cleanQuery)));

    $booleanTokens = [];
    foreach ($words as $w) {
        if ($w !== '') {
            $booleanTokens[] = '+' . $w . '*';
        }
    }
    $booleanSearch = implode(' ', $booleanTokens);

    $results = [];

    // 1. Primary Full-Text Search in BOOLEAN MODE
    if ($booleanSearch !== '') {
        try {
            $stmt = $pdo->prepare(
                "SELECT id, name, price, image_path, image_path AS image_url, rating_avg, rating_count
                 FROM products
                 WHERE MATCH(name, description) AGAINST (:ftQuery IN BOOLEAN MODE)
                   AND status = 'active'
                 LIMIT 8"
            );
            $stmt->execute([':ftQuery' => $booleanSearch]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            // If FULLTEXT index is not yet generated or query rejected, proceed to fallback
            $results = [];
        }
    }

    // 2. High-speed Fallback for short keywords (< 3 chars) or missing FULLTEXT rows
    if (empty($results)) {
        $likePattern = '%' . $cleanQuery . '%';
        $stmt = $pdo->prepare(
            "SELECT id, name, price, image_path, image_path AS image_url, rating_avg, rating_count
             FROM products
             WHERE (name LIKE :kw1 OR description LIKE :kw2)
               AND status = 'active'
             ORDER BY is_featured DESC, id ASC
             LIMIT 8"
        );
        $stmt->execute([':kw1' => $likePattern, ':kw2' => $likePattern]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $formatted = [];
    foreach ($results as $row) {
        $img = !empty($row['image_url']) ? $row['image_url'] : 'assets/img/cart-icon.jpg';
        $formatted[] = [
            'id'              => (int) $row['id'],
            'name'            => (string) $row['name'],
            'price'           => (float) $row['price'],
            'formatted_price' => format_ksh($row['price']),
            'image_url'       => url($img),
            'rating_avg'      => (float) ($row['rating_avg'] ?? 0.0),
            'rating_count'    => (int) ($row['rating_count'] ?? 0),
            'url'             => url('pages/public/product_details.php?id=' . (int) $row['id']),
        ];
    }

    echo json_encode($formatted, JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Autocomplete lookup failed.']);
}
