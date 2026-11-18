<?php
/**
 * SokoHub Bulk Product CSV Upload Engine
 * Allows merchants to import dozens of products via CSV spreadsheets with
 * row-level validation, background audit logging, and automated catalog publishing.
 * Authored by Kennedy Kalawa.
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
     * Ensure bulk_imports and bulk_import_rows tables exist with required constraints.
     */
    public static function ensureSchema(): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }

        try {
            $pdo = get_db_connection();

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS bulk_imports (
                    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    seller_user_id    INT UNSIGNED NOT NULL,
                    original_filename VARCHAR(200) NOT NULL,
                    file_path         VARCHAR(255) NOT NULL,
                    status            ENUM('uploaded', 'validating', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'uploaded',
                    total_rows        INT UNSIGNED NOT NULL DEFAULT 0,
                    success_rows      INT UNSIGNED NOT NULL DEFAULT 0,
                    error_rows        INT UNSIGNED NOT NULL DEFAULT 0,
                    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    finished_at       DATETIME NULL,
                    INDEX idx_imports_seller (seller_user_id, created_at),
                    CONSTRAINT fk_import_seller FOREIGN KEY (seller_user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS bulk_import_rows (
                    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    import_id     INT UNSIGNED NOT NULL,
                    row_no        INT UNSIGNED NOT NULL,
                    raw_data      JSON NOT NULL,
                    status        ENUM('valid', 'invalid', 'created') NOT NULL DEFAULT 'valid',
                    error_message VARCHAR(500) NULL,
                    product_id    INT UNSIGNED NULL,
                    INDEX idx_import_rows (import_id, row_no),
                    CONSTRAINT fk_importrow_import FOREIGN KEY (import_id) REFERENCES bulk_imports(id) ON DELETE CASCADE,
                    CONSTRAINT fk_importrow_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            $checked = true;
        } catch (Throwable $e) {
            error_log('SokoHub BulkImport::ensureSchema Error: ' . $e->getMessage());
        }
    }

    /**
     * Normalize column headers by stripping BOM, trimming, and mapping aliases.
     */
    public static function normalizeHeader(string $header): string
    {
        // Strip BOM and non-ASCII invisible characters
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
     * Resolve category ID by numeric ID, category name, or category slug.
     */
    public static function resolveCategory(PDO $pdo, string $val): ?int
    {
        $clean = trim($val);
        if ($clean === '') {
            return null;
        }

        if (is_numeric($clean)) {
            $stmt = $pdo->prepare('SELECT id FROM categories WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => (int) $clean]);
            $found = $stmt->fetchColumn();
            return $found ? (int) $found : null;
        }

        $stmt = $pdo->prepare('
            SELECT id FROM categories 
            WHERE LOWER(name) = LOWER(:val_name) OR LOWER(slug) = LOWER(:val_slug) 
            LIMIT 1
        ');
        $stmt->execute([
            ':val_name' => $clean,
            ':val_slug' => $clean,
        ]);
        $found = $stmt->fetchColumn();
        return $found ? (int) $found : null;
    }

    /**
     * Map category ID to default preset image.
     */
    public static function getDefaultImageForCategory(int $categoryId): string
    {
        return match ($categoryId) {
            1 => 'assets/img/clothes.jpg',
            2 => 'assets/img/gas-tanks.jpg',
            3 => 'assets/img/fresh-produce.jpg',
            4 => 'assets/img/electronics.jpg',
            default => 'assets/img/cart-icon.jpg',
        };
    }

    /**
     * Upload and process a bulk CSV file for a seller.
     */
    public static function uploadAndProcessCsv(
        int $sellerUserId,
        array $file,
        ?int $actorUserId = null,
        string $actorRole = 'seller'
    ): array {
        self::ensureSchema();

        // 1. Merchant KYC check (Admins can import on behalf of any merchant)
        if ($actorRole !== 'admin' && !Kyc::isSellerApproved($sellerUserId)) {
            return [
                'ok'    => false,
                'error' => 'KYC Compliance Required: You must be an approved merchant with verified National ID and M-Pesa line before bulk importing products.',
            ];
        }

        // 2. Validate file upload
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            $msg = match ($file['error'] ?? UPLOAD_ERR_NO_FILE) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds maximum upload size (max 5 MB).',
                UPLOAD_ERR_NO_FILE => 'No CSV file was selected.',
                default => 'File upload error occurred. Please try again.',
            };
            return ['ok' => false, 'error' => $msg];
        }

        $origName = basename($file['name'] ?? 'products.csv');
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            return ['ok' => false, 'error' => 'Unsupported file format. Please upload a standard comma-separated (.csv) file.'];
        }

        // Check size (max 5 MB)
        if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
            return ['ok' => false, 'error' => 'File size exceeds 5 MB limit.'];
        }

        // Target storage path
        $uploadDir = __DIR__ . '/../assets/img/uploads/imports';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0777, true);
        }

        $safeName = 'import_' . $sellerUserId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.csv';
        $destPath = $uploadDir . '/' . $safeName;
        $relPath  = 'assets/img/uploads/imports/' . $safeName;

        $tmpPath = $file['tmp_name'] ?? '';
        if (is_uploaded_file($tmpPath)) {
            if (!move_uploaded_file($tmpPath, $destPath)) {
                return ['ok' => false, 'error' => 'Failed to save uploaded CSV file to server.'];
            }
        } else {
            // For CLI testing & direct file paths
            if (!@copy($tmpPath, $destPath)) {
                return ['ok' => false, 'error' => 'Failed to copy CSV file to server storage.'];
            }
        }
        @chmod($destPath, 0666);

        try {
            $pdo = get_db_connection();

            $stmt = $pdo->prepare('
                INSERT INTO bulk_imports (seller_user_id, original_filename, file_path, status, created_at)
                VALUES (:sid, :orig, :fpath, "validating", NOW())
            ');
            $stmt->execute([
                ':sid'   => $sellerUserId,
                ':orig'  => $origName,
                ':fpath' => $relPath,
            ]);
            $importId = (int) $pdo->lastInsertId();

            Audit::record('bulk_import.uploaded', 'bulk_imports', $importId, [
                'original_filename' => $origName,
                'seller_id'         => $sellerUserId,
            ], $actorUserId ?: $sellerUserId, $actorRole);

            // 3. Process CSV lines immediately
            return self::processCsvFile($importId, $actorUserId, $actorRole);
        } catch (Throwable $e) {
            error_log('SokoHub BulkImport::uploadAndProcessCsv Error: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Process an existing bulk import CSV file row-by-row with transactional reliability.
     */
    public static function processCsvFile(int $importId, ?int $actorUserId = null, string $actorRole = 'seller'): array
    {
        self::ensureSchema();

        try {
            $pdo = get_db_connection();

            $stmt = $pdo->prepare('SELECT * FROM bulk_imports WHERE id = :id');
            $stmt->execute([':id' => $importId]);
            $import = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$import) {
                return ['ok' => false, 'error' => 'Import job not found.'];
            }

            $sellerUserId = (int) $import['seller_user_id'];
            $fullPath = __DIR__ . '/../' . ltrim($import['file_path'], '/');

            if (!file_exists($fullPath) || !is_readable($fullPath)) {
                $pdo->prepare("UPDATE bulk_imports SET status = 'failed' WHERE id = :id")->execute([':id' => $importId]);
                return ['ok' => false, 'error' => 'Stored CSV file could not be read from disk.'];
            }

            $handle = fopen($fullPath, 'r');
            if ($handle === false) {
                $pdo->prepare("UPDATE bulk_imports SET status = 'failed' WHERE id = :id")->execute([':id' => $importId]);
                return ['ok' => false, 'error' => 'Could not open CSV file stream.'];
            }

            // Read header row
            $headerRow = fgetcsv($handle);
            if ($headerRow === false || empty($headerRow)) {
                fclose($handle);
                $pdo->prepare("UPDATE bulk_imports SET status = 'failed' WHERE id = :id")->execute([':id' => $importId]);
                return ['ok' => false, 'error' => 'The uploaded CSV file is empty.'];
            }

            $normalizedHeaders = [];
            foreach ($headerRow as $h) {
                $normalizedHeaders[] = self::normalizeHeader((string) $h);
            }

            // Verify mandatory columns
            $required = ['name', 'category', 'description', 'price'];
            $missing = [];
            foreach ($required as $r) {
                if (!in_array($r, $normalizedHeaders, true)) {
                    $missing[] = $r;
                }
            }

            if (!empty($missing)) {
                fclose($handle);
                $pdo->prepare("UPDATE bulk_imports SET status = 'failed' WHERE id = :id")->execute([':id' => $importId]);
                return [
                    'ok'    => false,
                    'error' => 'CSV is missing required columns: ' . implode(', ', $missing) . '. Please download the sample template for the required structure.',
                ];
            }

            // Get seller store name for notifications
            $sellerStoreName = 'SokoHub Merchant';
            $sellerStmt = $pdo->prepare('
                SELECT COALESCE(sp.business_name, u.full_name) AS store_name
                FROM users u
                LEFT JOIN seller_profiles sp ON sp.user_id = u.id
                WHERE u.id = :uid
            ');
            $sellerStmt->execute([':uid' => $sellerUserId]);
            if ($sName = $sellerStmt->fetchColumn()) {
                $sellerStoreName = (string) $sName;
            }

            // Update status to processing
            $pdo->prepare("UPDATE bulk_imports SET status = 'processing' WHERE id = :id")->execute([':id' => $importId]);

            $rowNo        = 0;
            $successCount = 0;
            $errorCount   = 0;
            $results      = [];

            $insertRowStmt = $pdo->prepare('
                INSERT INTO bulk_import_rows (import_id, row_no, raw_data, status, error_message, product_id)
                VALUES (:iid, :rno, :raw, :st, :err, :pid)
            ');

            $insertProductStmt = $pdo->prepare('
                INSERT INTO products (
                    seller_id, category_id, name, description, price, original_price,
                    stock_qty, low_stock_threshold, condition_type, image_path, is_featured, status
                ) VALUES (
                    :seller_id, :cat_id, :name, :description, :price, :original_price,
                    :stock_qty, :low_stock_threshold, :condition_type, :image_path, :is_featured, "active"
                )
            ');

            while (($data = fgetcsv($handle)) !== false) {
                // Skip empty lines
                if (empty($data) || (count($data) === 1 && trim($data[0]) === '')) {
                    continue;
                }

                $rowNo++;
                $rowMap = [];
                foreach ($normalizedHeaders as $idx => $key) {
                    $rowMap[$key] = isset($data[$idx]) ? trim($data[$idx]) : '';
                }

                $rowErrors = [];

                // 1. Extract values
                $name        = $rowMap['name'] ?? '';
                $catVal      = $rowMap['category'] ?? '';
                $description = $rowMap['description'] ?? '';
                $price       = $rowMap['price'] ?? '';
                $origPrice   = $rowMap['original_price'] ?? '';
                $discPercent = trim((string) ($rowMap['discount_percent'] ?? ($rowMap['discount_pct'] ?? ($rowMap['discount'] ?? ''))));
                if ($discPercent !== '') {
                    if (!is_numeric($discPercent) || (float) $discPercent < 0 || (float) $discPercent >= 100) {
                        $rowErrors[] = 'Discount percentage must be a number between 0% and 99%.';
                    } elseif (is_numeric($price) && (float) $price > 0) {
                        $pricing = calculate_discount_pricing((float) $price, (float) $discPercent);
                        $price = (string) $pricing['price'];
                        $origPrice = $pricing['original_price'] !== null ? (string) $pricing['original_price'] : '';
                    }
                }
                $stockQty    = isset($rowMap['stock_qty']) && $rowMap['stock_qty'] !== '' ? $rowMap['stock_qty'] : '10';
                $threshold   = isset($rowMap['low_stock_threshold']) && $rowMap['low_stock_threshold'] !== '' ? $rowMap['low_stock_threshold'] : '5';
                $condition   = $rowMap['condition_type'] ?? 'New';
                $isFeatured  = (!empty($rowMap['is_featured']) && in_array(strtolower($rowMap['is_featured']), ['1', 'yes', 'true'], true)) ? 1 : 0;
                $imagePath   = $rowMap['image_path'] ?? '';

                // Normalize condition
                if (strcasecmp($condition, 'Second Hand') === 0 || strcasecmp($condition, 'used') === 0 || strcasecmp($condition, 'second_hand') === 0) {
                    $condition = 'Second Hand';
                } else {
                    $condition = 'New';
                }

                // 2. Resolve category
                $categoryId = self::resolveCategory($pdo, $catVal);
                if ($categoryId === null) {
                    $rowErrors[] = "Unknown category '{$catVal}'. Choose from Clothes, Gas, Food, Electronics, Others.";
                }

                // 3. Validate product fields
                $validationInput = [
                    'name'                => $name,
                    'category_id'         => $categoryId ?: 0,
                    'description'         => $description,
                    'price'               => $price,
                    'original_price'      => $origPrice,
                    'stock_qty'           => $stockQty,
                    'low_stock_threshold' => $threshold,
                    'condition_type'      => $condition,
                ];
                $fieldErrors = validate_product_fields($validationInput);
                $rowErrors = array_merge($rowErrors, $fieldErrors);

                // 4. Handle Row Outcome
                if (!empty($rowErrors)) {
                    $errorCount++;
                    $errText = implode(' | ', $rowErrors);
                    $insertRowStmt->execute([
                        ':iid' => $importId,
                        ':rno' => $rowNo,
                        ':raw' => json_encode($rowMap, JSON_UNESCAPED_UNICODE),
                        ':st'  => 'invalid',
                        ':err' => $errText,
                        ':pid' => null,
                    ]);

                    $results[] = [
                        'row_no'  => $rowNo,
                        'name'    => $name ?: '(Unnamed Row)',
                        'status'  => 'invalid',
                        'error'   => $errText,
                    ];
                } else {
                    // Choose image path
                    $chosenImage = $imagePath;
                    if ($chosenImage === '' || (!file_exists(__DIR__ . '/../' . ltrim($chosenImage, '/')) && !str_starts_with($chosenImage, 'assets/img/'))) {
                        $chosenImage = self::getDefaultImageForCategory((int) $categoryId);
                    }

                    $insertProductStmt->execute([
                        ':seller_id'           => $sellerUserId,
                        ':cat_id'              => $categoryId,
                        ':name'                => $name,
                        ':description'         => $description,
                        ':price'               => (float) $price,
                        ':original_price'      => $origPrice !== '' ? (float) $origPrice : null,
                        ':stock_qty'           => (int) $stockQty,
                        ':low_stock_threshold' => (int) $threshold,
                        ':condition_type'      => $condition,
                        ':image_path'          => $chosenImage,
                        ':is_featured'         => $isFeatured,
                    ]);
                    $newProductId = (int) $pdo->lastInsertId();

                    // Check low-stock status
                    Inventory::checkProductLowStock($newProductId, (int) $stockQty, (int) $threshold, $name, $sellerUserId);

                    // Notify buyers of new stock arrival
                    Notifications::notifyNewStock($newProductId, $name, (float) $price, $sellerStoreName);

                    $insertRowStmt->execute([
                        ':iid' => $importId,
                        ':rno' => $rowNo,
                        ':raw' => json_encode($rowMap, JSON_UNESCAPED_UNICODE),
                        ':st'  => 'created',
                        ':err' => null,
                        ':pid' => $newProductId,
                    ]);

                    $successCount++;
                    $results[] = [
                        'row_no'     => $rowNo,
                        'name'       => $name,
                        'status'     => 'created',
                        'product_id' => $newProductId,
                    ];
                }
            }
            fclose($handle);

            $finalStatus = ($rowNo === 0) ? 'failed' : (($successCount > 0) ? 'completed' : 'failed');

            // Finalize bulk_imports record
            $upFinal = $pdo->prepare('
                UPDATE bulk_imports
                SET total_rows   = :total,
                    success_rows = :succ,
                    error_rows   = :err,
                    status       = :st,
                    finished_at  = NOW()
                WHERE id = :id
            ');
            $upFinal->execute([
                ':total' => $rowNo,
                ':succ'  => $successCount,
                ':err'   => $errorCount,
                ':st'    => $finalStatus,
                ':id'    => $importId,
            ]);

            Audit::record('bulk_import.processed', 'bulk_imports', $importId, [
                'total_rows'   => $rowNo,
                'success_rows' => $successCount,
                'error_rows'   => $errorCount,
                'status'       => $finalStatus,
                'seller_id'    => $sellerUserId,
            ], $actorUserId ?: $sellerUserId, $actorRole);

            return [
                'ok'           => true,
                'import_id'    => $importId,
                'total_rows'   => $rowNo,
                'success_rows' => $successCount,
                'error_rows'   => $errorCount,
                'status'       => $finalStatus,
                'rows'         => $results,
            ];
        } catch (Throwable $e) {
            error_log('SokoHub BulkImport::processCsvFile Error: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get details of a bulk import job including all processed rows and errors.
     */
    public static function getImportDetails(int $importId): ?array
    {
        self::ensureSchema();
        try {
            $pdo = get_db_connection();

            $stmt = $pdo->prepare('
                SELECT 
                    bi.*,
                    u.full_name AS seller_name,
                    u.email AS seller_email,
                    COALESCE(sp.business_name, u.full_name) AS business_name
                FROM bulk_imports bi
                INNER JOIN users u ON u.id = bi.seller_user_id
                LEFT JOIN seller_profiles sp ON sp.user_id = u.id
                WHERE bi.id = :id
            ');
            $stmt->execute([':id' => $importId]);
            $import = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$import) {
                return null;
            }

            $rowStmt = $pdo->prepare('
                SELECT 
                    bir.*,
                    p.name AS product_name,
                    p.price AS product_price
                FROM bulk_import_rows bir
                LEFT JOIN products p ON p.id = bir.product_id
                WHERE bir.import_id = :iid
                ORDER BY bir.row_no ASC
            ');
            $rowStmt->execute([':iid' => $importId]);
            $import['rows'] = $rowStmt->fetchAll(PDO::FETCH_ASSOC);

            return $import;
        } catch (Throwable $e) {
            error_log('SokoHub BulkImport::getImportDetails Error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get all bulk imports for a specific seller.
     */
    public static function getSellerImports(int $sellerUserId): array
    {
        self::ensureSchema();
        try {
            $pdo = get_db_connection();

            $stmt = $pdo->prepare('
                SELECT * FROM bulk_imports
                WHERE seller_user_id = :sid
                ORDER BY created_at DESC, id DESC
            ');
            $stmt->execute([':sid' => $sellerUserId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('SokoHub BulkImport::getSellerImports Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get all bulk imports across all sellers (Admin view).
     */
    public static function getAdminImports(): array
    {
        self::ensureSchema();
        try {
            $pdo = get_db_connection();

            $stmt = $pdo->query('
                SELECT 
                    bi.*,
                    u.full_name AS seller_name,
                    u.email AS seller_email,
                    COALESCE(sp.business_name, u.full_name) AS business_name
                FROM bulk_imports bi
                INNER JOIN users u ON u.id = bi.seller_user_id
                LEFT JOIN seller_profiles sp ON sp.user_id = u.id
                ORDER BY bi.created_at DESC, bi.id DESC
            ');
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('SokoHub BulkImport::getAdminImports Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Generate sample CSV template content for merchant download.
     */
    public static function generateSampleCsv(): string
    {
        $headers = [
            'name',
            'category',
            'description',
            'price',
            'original_price',
            'stock_qty',
            'low_stock_threshold',
            'condition_type',
            'is_featured',
        ];

        $sampleData = [
            [
                'Kirinyaga Organic Raw Honey 500g',
                'Food',
                'Freshly harvested pure natural honey from Mount Kenya organic forest apiaries.',
                '850.00',
                '950.00',
                '25',
                '5',
                'New',
                '1',
            ],
            [
                'Pwani Gas 13kg Complete Cylinder',
                'Gas',
                'Standard 13kg cooking gas cylinder with safety valve, hose, and regulator.',
                '7800.00',
                '8500.00',
                '12',
                '3',
                'New',
                '1',
            ],
            [
                'Authentic African Kitenge Casual Shirt',
                'Clothes',
                'Tailored African wax print cotton casual shirt with short sleeves and collar.',
                '1800.00',
                '2200.00',
                '15',
                '4',
                'New',
                '0',
            ],
            [
                'Solar Rechargeable LED Study Lamp',
                'Electronics',
                'Energy efficient desk reading lamp with 5W solar charging panel and USB phone charger.',
                '1450.00',
                '1700.00',
                '30',
                '5',
                'New',
                '0',
            ],
            [
                'Kutus Handcrafted Earthen Cooking Pot',
                'Others',
                'Traditional artisan clay pot perfect for simmering stews, soups, and traditional dishes.',
                '650.00',
                '',
                '20',
                '4',
                'New',
                '0',
            ],
        ];

        $output = fopen('php://temp', 'r+');
        // Add UTF-8 BOM
        fputs($output, "\xEF\xBB\xBF");
        fputcsv($output, $headers);
        foreach ($sampleData as $row) {
            fputcsv($output, $row);
        }
        rewind($output);
        $csvString = stream_get_contents($output);
        fclose($output);

        return $csvString;
    }

    /**
     * Process multiple products entered directly via the interactive spreadsheet grid.
     */
    public static function processManualBulkRows(
        int $sellerUserId,
        array $rows,
        ?int $actorUserId = null,
        string $actorRole = 'seller'
    ): array {
        self::ensureSchema();

        // 1. KYC check (Admins can create on behalf of any merchant)
        if ($actorRole !== 'admin' && !Kyc::isSellerApproved($sellerUserId)) {
            return [
                'ok'    => false,
                'error' => 'KYC Compliance Required: You must be an approved merchant with verified National ID and M-Pesa line before adding products.',
            ];
        }

        // 2. Filter out completely blank rows
        $filteredRows = [];
        foreach ($rows as $r) {
            $name  = trim($r['name'] ?? '');
            $price = trim((string) ($r['price'] ?? ''));
            $desc  = trim($r['description'] ?? '');
            if ($name !== '' || $price !== '' || $desc !== '') {
                $filteredRows[] = $r;
            }
        }

        if (empty($filteredRows)) {
            return [
                'ok'    => false,
                'error' => 'No products provided. Please fill in at least one product row in the spreadsheet grid.',
            ];
        }

        try {
            $pdo = get_db_connection();

            // Create tracking job in bulk_imports
            $origName = 'Spreadsheet Grid Entry (' . count($filteredRows) . ' items)';
            $stmt = $pdo->prepare('
                INSERT INTO bulk_imports (seller_user_id, original_filename, file_path, status, created_at)
                VALUES (:sid, :orig, "manual_spreadsheet_grid", "processing", NOW())
            ');
            $stmt->execute([
                ':sid'  => $sellerUserId,
                ':orig' => $origName,
            ]);
            $importId = (int) $pdo->lastInsertId();

            // Get store name for notifications
            $sellerStoreName = 'SokoHub Merchant';
            $sellerStmt = $pdo->prepare('
                SELECT COALESCE(sp.business_name, u.full_name) AS store_name
                FROM users u
                LEFT JOIN seller_profiles sp ON sp.user_id = u.id
                WHERE u.id = :uid
            ');
            $sellerStmt->execute([':uid' => $sellerUserId]);
            if ($sName = $sellerStmt->fetchColumn()) {
                $sellerStoreName = (string) $sName;
            }

            $rowNo        = 0;
            $successCount = 0;
            $errorCount   = 0;
            $results      = [];

            $insertRowStmt = $pdo->prepare('
                INSERT INTO bulk_import_rows (import_id, row_no, raw_data, status, error_message, product_id)
                VALUES (:iid, :rno, :raw, :st, :err, :pid)
            ');

            $insertProductStmt = $pdo->prepare('
                INSERT INTO products (
                    seller_id, category_id, name, description, price, original_price,
                    stock_qty, low_stock_threshold, condition_type, image_path, is_featured, status
                ) VALUES (
                    :seller_id, :cat_id, :name, :description, :price, :original_price,
                    :stock_qty, :low_stock_threshold, :condition_type, :image_path, :is_featured, "active"
                )
            ');

            foreach ($filteredRows as $row) {
                $rowNo++;
                $rowErrors = [];

                $name        = trim($row['name'] ?? '');
                $catId       = (int) ($row['category_id'] ?? 0);
                $description = trim($row['description'] ?? '');
                $price       = trim((string) ($row['price'] ?? ''));
                $origPrice   = trim((string) ($row['original_price'] ?? ''));
                $discPercent = trim((string) ($row['discount_percent'] ?? ($row['discount_pct'] ?? ($row['discount'] ?? ''))));
                if ($discPercent !== '') {
                    if (!is_numeric($discPercent) || (float) $discPercent < 0 || (float) $discPercent >= 100) {
                        $rowErrors[] = 'Discount percentage must be a number between 0% and 99%.';
                    } elseif (is_numeric($price) && (float) $price > 0) {
                        $pricing = calculate_discount_pricing((float) $price, (float) $discPercent);
                        $price = (string) $pricing['price'];
                        $origPrice = $pricing['original_price'] !== null ? (string) $pricing['original_price'] : '';
                    }
                }
                $stockQty    = isset($row['stock_qty']) && $row['stock_qty'] !== '' ? $row['stock_qty'] : '10';
                $threshold   = isset($row['low_stock_threshold']) && $row['low_stock_threshold'] !== '' ? $row['low_stock_threshold'] : '5';
                $condition   = trim($row['condition_type'] ?? 'New');
                $isFeatured  = (!empty($row['is_featured']) && in_array(strtolower((string) $row['is_featured']), ['1', 'yes', 'true'], true)) ? 1 : 0;

                if (!in_array($condition, ['New', 'Second Hand'], true)) {
                    $condition = 'New';
                }

                $validationInput = [
                    'name'                => $name,
                    'category_id'         => $catId,
                    'description'         => $description,
                    'price'               => $price,
                    'original_price'      => $origPrice,
                    'stock_qty'           => $stockQty,
                    'low_stock_threshold' => $threshold,
                    'condition_type'      => $condition,
                ];

                $fieldErrors = validate_product_fields($validationInput);
                $rowErrors = array_merge($rowErrors, $fieldErrors);

                if (!empty($rowErrors)) {
                    $errorCount++;
                    $errText = implode(' | ', $rowErrors);
                    $insertRowStmt->execute([
                        ':iid' => $importId,
                        ':rno' => $rowNo,
                        ':raw' => json_encode($row, JSON_UNESCAPED_UNICODE),
                        ':st'  => 'invalid',
                        ':err' => $errText,
                        ':pid' => null,
                    ]);

                    $results[] = [
                        'row_no' => $rowNo,
                        'name'   => $name ?: '(Unnamed Row)',
                        'status' => 'invalid',
                        'error'  => $errText,
                    ];
                } else {
                    $chosenImage = self::getDefaultImageForCategory($catId);

                    $insertProductStmt->execute([
                        ':seller_id'           => $sellerUserId,
                        ':cat_id'              => $catId,
                        ':name'                => $name,
                        ':description'         => $description,
                        ':price'               => (float) $price,
                        ':original_price'      => $origPrice !== '' ? (float) $origPrice : null,
                        ':stock_qty'           => (int) $stockQty,
                        ':low_stock_threshold' => (int) $threshold,
                        ':condition_type'      => $condition,
                        ':image_path'          => $chosenImage,
                        ':is_featured'         => $isFeatured,
                    ]);
                    $newProductId = (int) $pdo->lastInsertId();

                    Inventory::checkProductLowStock($newProductId, (int) $stockQty, (int) $threshold, $name, $sellerUserId);
                    Notifications::notifyNewStock($newProductId, $name, (float) $price, $sellerStoreName);

                    $insertRowStmt->execute([
                        ':iid' => $importId,
                        ':rno' => $rowNo,
                        ':raw' => json_encode($row, JSON_UNESCAPED_UNICODE),
                        ':st'  => 'created',
                        ':err' => null,
                        ':pid' => $newProductId,
                    ]);

                    $successCount++;
                    $results[] = [
                        'row_no'     => $rowNo,
                        'name'       => $name,
                        'status'     => 'created',
                        'product_id' => $newProductId,
                    ];
                }
            }

            $finalStatus = ($rowNo === 0) ? 'failed' : (($successCount > 0) ? 'completed' : 'failed');

            $upFinal = $pdo->prepare('
                UPDATE bulk_imports
                SET total_rows   = :total,
                    success_rows = :succ,
                    error_rows   = :err,
                    status       = :st,
                    finished_at  = NOW()
                WHERE id = :id
            ');
            $upFinal->execute([
                ':total' => $rowNo,
                ':succ'  => $successCount,
                ':err'   => $errorCount,
                ':st'    => $finalStatus,
                ':id'    => $importId,
            ]);

            Audit::record('bulk_import.manual_grid', 'bulk_imports', $importId, [
                'total_rows'   => $rowNo,
                'success_rows' => $successCount,
                'error_rows'   => $errorCount,
                'status'       => $finalStatus,
                'seller_id'    => $sellerUserId,
            ], $actorUserId ?: $sellerUserId, $actorRole);

            return [
                'ok'           => true,
                'import_id'    => $importId,
                'total_rows'   => $rowNo,
                'success_rows' => $successCount,
                'error_rows'   => $errorCount,
                'status'       => $finalStatus,
                'rows'         => $results,
            ];
        } catch (Throwable $e) {
            error_log('SokoHub BulkImport::processManualBulkRows Error: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Bulk update existing products in a spreadsheet-like editor.
     */
    public static function bulkUpdateProducts(
        int $sellerUserId,
        array $items,
        bool $isAdmin = false,
        ?int $actorUserId = null
    ): array {
        self::ensureSchema();

        if (empty($items)) {
            return ['ok' => false, 'error' => 'No items submitted for update.'];
        }

        try {
            $pdo = get_db_connection();
            $updatedCount = 0;
            $errors = [];

            $checkStmt = $pdo->prepare('SELECT id, seller_id, name, price, stock_qty, low_stock_threshold, status FROM products WHERE id = :id LIMIT 1');
            $upStmt = $pdo->prepare('
                UPDATE products 
                SET name = :name,
                    category_id = :cat_id,
                    price = :price,
                    stock_qty = :stock,
                    low_stock_threshold = :thresh,
                    condition_type = :cond,
                    status = :st
                WHERE id = :id
            ');

            foreach ($items as $item) {
                $id = (int) ($item['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }

                $checkStmt->execute([':id' => $id]);
                $current = $checkStmt->fetch(PDO::FETCH_ASSOC);

                if (!$current) {
                    $errors[] = "Product #{$id} not found.";
                    continue;
                }

                if (!$isAdmin && (int) $current['seller_id'] !== $sellerUserId) {
                    $errors[] = "Permission denied for product #{$id}.";
                    continue;
                }

                $name      = trim($item['name'] ?? $current['name']);
                $catId     = (int) ($item['category_id'] ?? 1);
                $price     = (float) ($item['price'] ?? $current['price']);
                $stock     = (int) ($item['stock_qty'] ?? $current['stock_qty']);
                $threshold = max(1, (int) ($item['low_stock_threshold'] ?? $current['low_stock_threshold']));
                $condition = in_array($item['condition_type'] ?? '', ['New', 'Second Hand'], true) ? $item['condition_type'] : 'New';
                $status    = in_array($item['status'] ?? '', ['active', 'archived'], true) ? $item['status'] : 'active';

                if (mb_strlen($name) < 3) {
                    $errors[] = "Product #{$id}: Name must be at least 3 characters.";
                    continue;
                }
                if ($price <= 0) {
                    $errors[] = "Product #{$id}: Price must be positive.";
                    continue;
                }
                if ($stock < 0) {
                    $errors[] = "Product #{$id}: Stock cannot be negative.";
                    continue;
                }

                $upStmt->execute([
                    ':name'   => $name,
                    ':cat_id' => $catId,
                    ':price'  => $price,
                    ':stock'  => $stock,
                    ':thresh' => $threshold,
                    ':cond'   => $condition,
                    ':st'     => $status,
                    ':id'     => $id,
                ]);

                // Update inventory alerts if stock was modified
                Inventory::checkProductLowStock($id, $stock, $threshold, $name, (int) $current['seller_id']);

                $updatedCount++;
            }

            if ($updatedCount > 0) {
                Audit::record('product.bulk_inline_updated', 'products', null, [
                    'updated_items' => $updatedCount,
                    'seller_id'     => $sellerUserId,
                ], $actorUserId ?: $sellerUserId, $isAdmin ? 'admin' : 'seller');
            }

            return [
                'ok'            => true,
                'updated_count' => $updatedCount,
                'errors'        => $errors,
            ];
        } catch (Throwable $e) {
            error_log('SokoHub BulkImport::bulkUpdateProducts Error: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get products for a seller formatted for the spreadsheet grid editor.
     */
    public static function getSellerProducts(int $sellerUserId, bool $isAdmin = false, int $limit = 50): array
    {
        try {
            $pdo = get_db_connection();

            $sql = '
                SELECT p.*, c.name AS category_name
                FROM products p
                LEFT JOIN categories c ON c.id = p.category_id
            ';
            $params = [];

            if (!$isAdmin) {
                $sql .= ' WHERE p.seller_id = :sid';
                $params[':sid'] = $sellerUserId;
            }

            $sql .= ' ORDER BY p.updated_at DESC, p.id DESC LIMIT ' . (int) $limit;

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('SokoHub BulkImport::getSellerProducts Error: ' . $e->getMessage());
            return [];
        }
    }
}
