<?php
/**
 * SokoHub Bulk Product CSV Upload Engine
 * Allows merchants to import dozens of products via CSV spreadsheets with
 * row-level validation, background audit logging, and automated catalog publishing.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/kyc.php';
require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/notifications.php';

class BulkImport
{
    /**
     * Normalize column headers by stripping BOM, trimming, and mapping aliases.
     */
    public static function normalizeHeader(string $header): string
    {
        $h = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $header);
        $h = strtolower(trim($h));
        $h = str_replace([' ', '-'], '_', $h);

        if (in_array($h, ['name', 'product_name', 'item_name', 'title'], true)) {
            return 'name';
        }
        if (in_array($h, ['category', 'category_name', 'category_id', 'cat'], true)) {
            return 'category';
        }
        if (in_array($h, ['description', 'desc', 'details', 'about'], true)) {
            return 'description';
        }
        if (in_array($h, ['price', 'selling_price', 'unit_price', 'cost'], true)) {
            return 'price';
        }
        if (in_array($h, ['original_price', 'was_price', 'regular_price', 'list_price'], true)) {
            return 'original_price';
        }
        if (in_array($h, ['discount_percent', 'discount_pct', 'discount', 'discount_percentage', 'disc_pct', 'disc'], true)) {
            return 'discount_percent';
        }
        if (in_array($h, ['stock_qty', 'stock', 'quantity', 'qty', 'units', 'stock_quantity'], true)) {
            return 'stock_qty';
        }
        if (in_array($h, ['low_stock_threshold', 'threshold', 'alert_threshold', 'min_stock'], true)) {
            return 'low_stock_threshold';
        }
        if (in_array($h, ['condition', 'condition_type', 'item_condition'], true)) {
            return 'condition_type';
        }
        if (in_array($h, ['is_featured', 'featured'], true)) {
            return 'is_featured';
        }
        if (in_array($h, ['image_path', 'image', 'photo', 'preset_image'], true)) {
            return 'image_path';
        }

        return $h;
    }

    /**
     * Parse and validate CSV data rows against catalog constraints and categories.
     */
    public static function parseCsv(string $csvFilePath, int $sellerUserId): array
    {
        if (!file_exists($csvFilePath) || !is_readable($csvFilePath)) {
            return ['ok' => false, 'error' => 'CSV file does not exist or cannot be read.'];
        }

        $pdo = get_db_connection();

        // Build category lookup table
        $catStmt = $pdo->query('SELECT id, LOWER(name) AS low_name FROM categories');
        $catMap = [];
        $defaultCatId = 1;
        while ($row = $catStmt->fetch(PDO::FETCH_ASSOC)) {
            $catMap[$row['low_name']] = (int) $row['id'];
            if ($defaultCatId === 1) {
                $defaultCatId = (int) $row['id'];
            }
        }

        $handle = fopen($csvFilePath, 'r');
        if ($handle === false) {
            return ['ok' => false, 'error' => 'Unable to open CSV file.'];
        }

        $rawHeaders = fgetcsv($handle);
        if ($rawHeaders === false || empty($rawHeaders)) {
            fclose($handle);
            return ['ok' => false, 'error' => 'The uploaded CSV file is empty.'];
        }

        $headers = array_map([self::class, 'normalizeHeader'], $rawHeaders);

        // Required fields check
        $hasName  = in_array('name', $headers, true);
        $hasPrice = in_array('price', $headers, true);

        if (!$hasName || !$hasPrice) {
            fclose($handle);
            return [
                'ok'    => false,
                'error' => "CSV must contain at least 'name' and 'price' columns. Found: " . implode(', ', array_filter($headers)),
            ];
        }

        $validRows   = [];
        $invalidRows = [];
        $rowNumber   = 1;

        while (($data = fgetcsv($handle)) !== false) {
            $rowNumber++;

            if (empty(array_filter($data, fn($v) => trim($v) !== ''))) {
                continue;
            }

            $rowAssoc = [];
            foreach ($headers as $idx => $headerName) {
                $rowAssoc[$headerName] = trim($data[$idx] ?? '');
            }

            $errors = [];

            // Name
            $name = $rowAssoc['name'] ?? '';
            if (mb_strlen($name) < 3) {
                $errors[] = 'Product name must be at least 3 characters.';
            }

            // Price
            $priceRaw = $rowAssoc['price'] ?? '';
            $price = (float) preg_replace('/[^0-9.]/', '', $priceRaw);
            if ($price <= 0) {
                $errors[] = 'Price must be greater than zero.';
            }

            // Category
            $catInput = strtolower(trim($rowAssoc['category'] ?? ''));
            $catId = $catMap[$catInput] ?? null;
            if (!$catId && is_numeric($rowAssoc['category'] ?? null)) {
                $checkId = (int) $rowAssoc['category'];
                if (in_array($checkId, $catMap, true)) {
                    $catId = $checkId;
                }
            }
            if (!$catId) {
                $catId = $defaultCatId;
            }

            // Stock
            $stockRaw = $rowAssoc['stock_qty'] ?? '10';
            $stock = max(0, (int) preg_replace('/[^0-9]/', '', $stockRaw));

            // Low Stock Threshold
            $thresholdRaw = $rowAssoc['low_stock_threshold'] ?? '5';
            $threshold = max(1, (int) preg_replace('/[^0-9]/', '', $thresholdRaw));

            // Condition
            $condInput = strtolower(trim($rowAssoc['condition_type'] ?? 'new'));
            $condition = in_array($condInput, ['second hand', 'used', 'refurbished'], true) ? 'Second Hand' : 'New';

            // Image
            $imageInput = trim($rowAssoc['image_path'] ?? '');
            $image = !empty($imageInput) ? $imageInput : 'assets/img/fresh-produce.jpg';

            // Original Price
            $origPriceRaw = $rowAssoc['original_price'] ?? '';
            $origPrice = (float) preg_replace('/[^0-9.]/', '', $origPriceRaw);
            if ($origPrice <= 0) {
                $origPrice = $price;
            }

            // Discount percent
            $discPct = 0;
            if ($origPrice > $price) {
                $discPct = (int) round((($origPrice - $price) / $origPrice) * 100);
            }

            $featured = in_array(strtolower($rowAssoc['is_featured'] ?? '0'), ['1', 'true', 'yes'], true) ? 1 : 0;
            $description = $rowAssoc['description'] ?? "High quality {$name} available from local marketplace vendor.";

            $cleanRow = [
                'row_no'              => $rowNumber,
                'name'                => $name,
                'category_id'         => $catId,
                'description'         => $description,
                'price'               => $price,
                'original_price'      => $origPrice,
                'discount_percent'    => $discPct,
                'stock_qty'           => $stock,
                'low_stock_threshold' => $threshold,
                'condition_type'      => $condition,
                'is_featured'         => $featured,
                'image_path'          => $image,
                'raw'                 => $rowAssoc,
            ];

            if (empty($errors)) {
                $validRows[] = $cleanRow;
            } else {
                $cleanRow['errors'] = $errors;
                $invalidRows[] = $cleanRow;
            }
        }

        fclose($handle);

        return [
            'ok'           => true,
            'total_rows'   => count($validRows) + count($invalidRows),
            'valid_count'  => count($validRows),
            'error_count'  => count($invalidRows),
            'valid_rows'   => $validRows,
            'invalid_rows' => $invalidRows,
        ];
    }

    /**
     * Execute transactional insertion of validated rows into products table.
     */
    public static function commitImport(
        int $sellerUserId,
        string $originalFilename,
        string $storedFilePath,
        array $validRows,
        array $invalidRows,
        int $actorUserId
    ): array {
        $pdo = get_db_connection();

        // Check KYC gate
        $kycCheck = Kyc::canPublishProducts($sellerUserId);
        if (!$kycCheck['can_publish']) {
            return [
                'ok'    => false,
                'error' => 'Catalog publishing is locked: ' . $kycCheck['reason'],
            ];
        }

        $pdo->beginTransaction();
        try {
            // 1. Insert master bulk_imports record
            $impStmt = $pdo->prepare('
                INSERT INTO bulk_imports (
                    seller_user_id, original_filename, file_path, status,
                    total_rows, success_rows, error_rows, created_at
                ) VALUES (
                    :sid, :fname, :fpath, "processing",
                    :tot, 0, :errs, NOW()
                )
            ');
            $totCount = count($validRows) + count($invalidRows);
            $errCount = count($invalidRows);
            $impStmt->execute([
                ':sid'   => $sellerUserId,
                ':fname' => $originalFilename,
                ':fpath' => $storedFilePath,
                ':tot'   => $totCount,
                ':errs'  => $errCount,
            ]);
            $importId = (int) $pdo->lastInsertId();

            // 2. Prepare statement for products
            $prodStmt = $pdo->prepare('
                INSERT INTO products (
                    seller_id, category_id, name, description, price,
                    original_price, discount_percent, stock_qty, low_stock_threshold,
                    condition_type, is_featured, image_path, status, created_at
                ) VALUES (
                    :sid, :cid, :name, :desc, :price,
                    :orig_price, :disc, :stock, :thresh,
                    :cond, :feat, :img, "active", NOW()
                )
            ');

            // 3. Prepare statement for bulk_import_rows
            $rowLogStmt = $pdo->prepare('
                INSERT INTO bulk_import_rows (
                    import_id, row_no, raw_data, status, error_message, product_id
                ) VALUES (
                    :iid, :rno, :raw, :st, :err, :pid
                )
            ');

            $successCount = 0;
            $createdProducts = [];

            // Insert valid items
            foreach ($validRows as $r) {
                $prodStmt->execute([
                    ':sid'        => $sellerUserId,
                    ':cid'        => $r['category_id'],
                    ':name'       => $r['name'],
                    ':desc'       => $r['description'],
                    ':price'      => $r['price'],
                    ':orig_price' => $r['original_price'],
                    ':disc'       => $r['discount_percent'],
                    ':stock'      => $r['stock_qty'],
                    ':thresh'     => $r['low_stock_threshold'],
                    ':cond'       => $r['condition_type'],
                    ':feat'       => $r['is_featured'],
                    ':img'        => $r['image_path'],
                ]);

                $newProdId = (int) $pdo->lastInsertId();
                $createdProducts[] = [
                    'id'    => $newProdId,
                    'name'  => $r['name'],
                    'price' => $r['price'],
                ];

                // Stock movement initial record
                $pdo->prepare('
                    INSERT INTO stock_movements (product_id, change_qty, reason, balance_after, created_by, created_at)
                    VALUES (:pid, :qty, "import", :bal, :uid, NOW())
                ')->execute([
                    ':pid' => $newProdId,
                    ':qty' => $r['stock_qty'],
                    ':bal' => $r['stock_qty'],
                    ':uid' => $actorUserId,
                ]);

                $rowLogStmt->execute([
                    ':iid' => $importId,
                    ':rno' => $r['row_no'],
                    ':raw' => json_encode($r['raw'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    ':st'  => 'created',
                    ':err' => null,
                    ':pid' => $newProdId,
                ]);

                $successCount++;
            }

            // Log invalid rows
            foreach ($invalidRows as $ir) {
                $rowLogStmt->execute([
                    ':iid' => $importId,
                    ':rno' => $ir['row_no'],
                    ':raw' => json_encode($ir['raw'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    ':st'  => 'invalid',
                    ':err' => implode('; ', $ir['errors'] ?? []),
                    ':pid' => null,
                ]);
            }

            // Update bulk_imports summary status
            $finalStatus = ($errCount === 0) ? 'completed' : ($successCount > 0 ? 'completed' : 'failed');
            $updImp = $pdo->prepare('
                UPDATE bulk_imports 
                SET status = :st, success_rows = :succ, finished_at = NOW()
                WHERE id = :id
            ');
            $updImp->execute([
                ':st'   => $finalStatus,
                ':succ' => $successCount,
                ':id'   => $importId,
            ]);

            // Audit log
            Audit::record('product.bulk_imported', 'bulk_import', $importId, [
                'total_rows'   => $totCount,
                'success_rows' => $successCount,
                'error_rows'   => $errCount,
                'filename'     => $originalFilename,
            ], $actorUserId, 'seller');

            $pdo->commit();

            return [
                'ok'           => true,
                'import_id'    => $importId,
                'success_rows' => $successCount,
                'error_rows'   => $errCount,
                'total_rows'   => $totCount,
            ];
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('BulkImport::commitImport Error: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
