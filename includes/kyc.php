<?php
/**
 * SokoHub KYC & Merchant/Rider Verification Service
 * Handles KYC compliance for both Sellers and Delivery Personnel.
 * Requires National ID + M-Pesa phone number before marketplace publishing or delivery claiming.
 * Authored by Kennedy Kalawa.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/notifications.php';

class Kyc
{
    /**
     * Ensure database tables and columns exist for both sellers and riders.
     */
    public static function ensureSchema(): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }

        try {
            $pdo = get_db_connection();

            // 1. Ensure seller_profiles has KYC columns
            $colCheck = $pdo->query("SHOW COLUMNS FROM seller_profiles LIKE 'verification_status'")->fetch();
            if (!$colCheck) {
                $pdo->exec("
                    ALTER TABLE seller_profiles
                    ADD COLUMN verification_status ENUM('pending', 'approved', 'rejected', 'suspended') NOT NULL DEFAULT 'pending',
                    ADD COLUMN verified_at DATETIME NULL,
                    ADD COLUMN verified_by INT UNSIGNED NULL,
                    ADD COLUMN rejection_reason VARCHAR(255) NULL,
                    ADD COLUMN mpesa_phone VARCHAR(20) NULL,
                    ADD COLUMN national_id_no VARCHAR(30) NULL
                ");
            } else {
                $idCheck = $pdo->query("SHOW COLUMNS FROM seller_profiles LIKE 'national_id_no'")->fetch();
                if (!$idCheck) {
                    $pdo->exec("ALTER TABLE seller_profiles ADD COLUMN national_id_no VARCHAR(30) NULL");
                }
                $mpCheck = $pdo->query("SHOW COLUMNS FROM seller_profiles LIKE 'mpesa_phone'")->fetch();
                if (!$mpCheck) {
                    $pdo->exec("ALTER TABLE seller_profiles ADD COLUMN mpesa_phone VARCHAR(20) NULL");
                }
            }

            // 2. Ensure delivery_profiles has KYC columns
            $delColCheck = $pdo->query("SHOW COLUMNS FROM delivery_profiles LIKE 'verification_status'")->fetch();
            if (!$delColCheck) {
                $pdo->exec("
                    ALTER TABLE delivery_profiles
                    ADD COLUMN verification_status ENUM('pending', 'approved', 'rejected', 'suspended') NOT NULL DEFAULT 'pending',
                    ADD COLUMN verified_at DATETIME NULL,
                    ADD COLUMN verified_by INT UNSIGNED NULL,
                    ADD COLUMN rejection_reason VARCHAR(255) NULL,
                    ADD COLUMN mpesa_phone VARCHAR(20) NULL,
                    ADD COLUMN national_id_no VARCHAR(30) NULL
                ");
            } else {
                $delIdCheck = $pdo->query("SHOW COLUMNS FROM delivery_profiles LIKE 'national_id_no'")->fetch();
                if (!$delIdCheck) {
                    $pdo->exec("ALTER TABLE delivery_profiles ADD COLUMN national_id_no VARCHAR(30) NULL");
                }
                $delMpCheck = $pdo->query("SHOW COLUMNS FROM delivery_profiles LIKE 'mpesa_phone'")->fetch();
                if (!$delMpCheck) {
                    $pdo->exec("ALTER TABLE delivery_profiles ADD COLUMN mpesa_phone VARCHAR(20) NULL");
                }
            }

            // Ensure vehicle_plate_number and vehicle_model exist on delivery_profiles
            $delPlateCheck = $pdo->query("SHOW COLUMNS FROM delivery_profiles LIKE 'vehicle_plate_number'")->fetch();
            if (!$delPlateCheck) {
                $pdo->exec("
                    ALTER TABLE delivery_profiles 
                    ADD COLUMN vehicle_plate_number VARCHAR(20) NULL,
                    ADD COLUMN vehicle_model VARCHAR(80) NULL
                ");
            }

            // Update transport_method ENUM to support tuk_tuk, pickup_truck, etc.
            try {
                $pdo->exec("
                    ALTER TABLE delivery_profiles 
                    MODIFY COLUMN transport_method ENUM('motorbike', 'tuk_tuk', 'pickup_truck', 'car', 'bicycle') NOT NULL DEFAULT 'motorbike'
                ");
            } catch (Throwable $_) {}

            // Ensure assigned_by_seller_id exists on deliveries
            $delAssignCheck = $pdo->query("SHOW COLUMNS FROM deliveries LIKE 'assigned_by_seller_id'")->fetch();
            if (!$delAssignCheck) {
                $pdo->exec("ALTER TABLE deliveries ADD COLUMN assigned_by_seller_id INT UNSIGNED NULL");
            }

            // Ensure is_available column exists on delivery_profiles
            $delAvailCheck = $pdo->query("SHOW COLUMNS FROM delivery_profiles LIKE 'is_available'")->fetch();
            if (!$delAvailCheck) {
                $pdo->exec("ALTER TABLE delivery_profiles ADD COLUMN is_available TINYINT(1) NOT NULL DEFAULT 1");
            }

            // 3. Ensure seller_documents table exists and has file details
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS seller_documents (
                    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    seller_user_id   INT UNSIGNED NOT NULL,
                    user_role        ENUM('seller', 'delivery') NOT NULL DEFAULT 'seller',
                    doc_type         ENUM('national_id', 'business_registration', 'rider_license', 'other') NOT NULL,
                    file_name        VARCHAR(255) NULL,
                    file_path        VARCHAR(255) NOT NULL,
                    file_size        INT UNSIGNED NULL,
                    mime_type        VARCHAR(100) NULL,
                    review_status    ENUM('pending', 'accepted', 'rejected') NOT NULL DEFAULT 'pending',
                    reviewed_by      INT UNSIGNED NULL,
                    reviewed_at      DATETIME NULL,
                    uploaded_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_docs_seller (seller_user_id),
                    CONSTRAINT fk_docs_seller FOREIGN KEY (seller_user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            // Check extra columns in seller_documents
            $fnCheck = $pdo->query("SHOW COLUMNS FROM seller_documents LIKE 'file_name'")->fetch();
            if (!$fnCheck) {
                $pdo->exec("ALTER TABLE seller_documents ADD COLUMN file_name VARCHAR(255) NULL, ADD COLUMN file_size INT UNSIGNED NULL, ADD COLUMN mime_type VARCHAR(100) NULL");
            }
            $urCheck = $pdo->query("SHOW COLUMNS FROM seller_documents LIKE 'user_role'")->fetch();
            if (!$urCheck) {
                $pdo->exec("ALTER TABLE seller_documents ADD COLUMN user_role ENUM('seller', 'delivery') NOT NULL DEFAULT 'seller'");
            }
            $pdo->exec("ALTER TABLE seller_documents MODIFY COLUMN doc_type ENUM('national_id', 'business_registration', 'rider_license', 'other') NOT NULL");

            // Ensure upload directories exist and are fully writable (0777)
            $uploadBase = __DIR__ . '/../assets/img/uploads';
            $uploadKyc  = $uploadBase . '/kyc';

            foreach ([$uploadBase, $uploadKyc] as $dir) {
                if (!is_dir($dir)) {
                    @mkdir($dir, 0777, true);
                }
                @chmod($dir, 0777);
            }

            $checked = true;
        } catch (Throwable $e) {
            error_log('SokoHub Kyc::ensureSchema Error: ' . $e->getMessage());
        }
    }

    /**
     * Clean and validate a Kenyan M-Pesa phone number.
     * Accepts: 07XXXXXXXX, 01XXXXXXXX, +2547XXXXXXXX, 2547XXXXXXXX.
     * Returns standard format 254XXXXXXXXX, or null if invalid.
     */
    public static function cleanMpesaPhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }
        $p = preg_replace('/[^0-9]/', '', $phone);
        if (str_starts_with($p, '254') && strlen($p) === 12) {
            return $p;
        }
        if (str_starts_with($p, '0') && strlen($p) === 10) {
            return '254' . substr($p, 1);
        }
        if (strlen($p) === 9 && in_array($p[0], ['7', '1'], true)) {
            return '254' . $p;
        }
        return null;
    }

    /**
     * Clean and normalize a Kenyan vehicle or motorcycle registration number plate.
     * E.g. "kmdf 123a", "kmdf-123a", "kmdf123a" -> "KMDF 123A".
     * E.g. "kda 456b", "kda456b" -> "KDA 456B".
     */
    public static function cleanPlateNumber(?string $plate): ?string
    {
        if ($plate === null) {
            return null;
        }

        $p = strtoupper(trim($plate));
        // Remove hyphens, dots, underscores, and extra spaces
        $p = preg_replace('/[.\-_]+/', '', $p);
        $p = preg_replace('/\s+/', '', $p);

        if ($p === '') {
            return null;
        }

        // Standard Kenyan pattern: (K + 2 or 3 letters) + (3 digits) + (1 letter)
        // e.g. KMDF 123A, KDA 456B, KMC 789X
        if (preg_match('/^(K[A-Z]{2,3})([0-9]{3})([A-Z])$/', $p, $matches)) {
            return $matches[1] . ' ' . $matches[2] . $matches[3];
        }

        return $p;
    }

    /**
     * Validate Kenyan registration number plate based on transport method.
     * Bicycles are exempt. Motorized vehicles (motorbike, car, tuk-tuk, pickup) require a valid plate.
     * Returns null if valid, or a descriptive error message string if invalid.
     */
    public static function validatePlateNumber(?string $plate, string $transportMethod = 'motorbike'): ?string
    {
        if ($transportMethod === 'bicycle') {
            return null; // Bicycles do not require number plates
        }

        $clean = self::cleanPlateNumber($plate);
        if ($clean === null || $clean === '') {
            return 'Vehicle registration number plate is mandatory for motorized delivery vehicles (motorcycles, cars, tuk-tuks, and pickups).';
        }

        // Match standard Kenyan plate format: K[A-Z]{2,3} [0-9]{3}[A-Z]
        if (!preg_match('/^K[A-Z]{2,3}\s[0-9]{3}[A-Z]$/', $clean)) {
            return 'Invalid Kenyan number plate format. Expected format: KMDF 123A (motorcycles) or KDA 456B (vehicles).';
        }

        return null;
    }

    /**
     * Get profile with KYC details for either a seller or a delivery rider.
     */
    public static function getUserKycProfile(int $userId, string $role): ?array
    {
        self::ensureSchema();
        try {
            $pdo = get_db_connection();
            if ($role === 'seller') {
                $stmt = $pdo->prepare('
                    SELECT sp.*, u.full_name, u.email, u.phone AS user_phone, "seller" AS role_title
                    FROM seller_profiles sp
                    INNER JOIN users u ON sp.user_id = u.id
                    WHERE sp.user_id = :uid LIMIT 1
                ');
            } elseif ($role === 'delivery') {
                $stmt = $pdo->prepare('
                    SELECT dp.*, u.full_name, u.email, u.phone AS user_phone, "delivery" AS role_title,
                           dp.transport_method AS business_name, dp.service_area AS town
                    FROM delivery_profiles dp
                    INNER JOIN users u ON dp.user_id = u.id
                    WHERE dp.user_id = :uid LIMIT 1
                ');
            } else {
                return null;
            }

            $stmt->execute([':uid' => $userId]);
            $res = $stmt->fetch(PDO::FETCH_ASSOC);
            return $res ?: null;
        } catch (Throwable $e) {
            error_log('SokoHub Kyc::getUserKycProfile Error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if a user (seller or rider) is approved to sell or deliver.
     */
    public static function isUserApproved(int $userId, string $role): bool
    {
        $profile = self::getUserKycProfile($userId, $role);
        if (!$profile) {
            return false;
        }
        return ($profile['verification_status'] ?? 'pending') === 'approved';
    }

    /**
     * Backwards-compatible check for sellers.
     */
    public static function isSellerApproved(int $userId): bool
    {
        return self::isUserApproved($userId, 'seller');
    }

    /**
     * Update M-Pesa phone number and National ID number on profile.
     * For delivery riders, also validates and records transport method, vehicle number plate, and model.
     */
    public static function updateKycDetails(
        int $userId,
        string $role,
        string $nationalId,
        string $mpesaPhone,
        ?string $plateNumber = null,
        ?string $transportMethod = null,
        ?string $vehicleModel = null
    ): array
    {
        self::ensureSchema();

        $cleanPhone = self::cleanMpesaPhone($mpesaPhone);
        if ($cleanPhone === null) {
            return ['ok' => false, 'error' => 'Invalid M-Pesa phone number. Enter a valid Kenyan number (e.g. 0712345678 or 0112345678).'];
        }

        $cleanId = trim($nationalId);
        if (strlen($cleanId) < 6 || strlen($cleanId) > 20) {
            return ['ok' => false, 'error' => 'National ID / Passport number must be between 6 and 20 characters.'];
        }

        $cleanPlate = null;
        if ($role === 'delivery') {
            $validMethods = ['motorbike', 'tuk_tuk', 'pickup_truck', 'car', 'bicycle'];
            $method = ($transportMethod !== null && in_array($transportMethod, $validMethods, true)) ? $transportMethod : 'motorbike';

            // Validate number plate
            $plateErr = self::validatePlateNumber($plateNumber, $method);
            if ($plateErr !== null) {
                return ['ok' => false, 'error' => $plateErr];
            }
            $cleanPlate = self::cleanPlateNumber($plateNumber);
        }

        try {
            $pdo = get_db_connection();
            if ($role === 'seller') {
                $stmt = $pdo->prepare("
                    UPDATE seller_profiles
                    SET national_id_no = :nid, mpesa_phone = :mpesa
                    WHERE user_id = :uid
                ");
                $stmt->execute([
                    ':nid'   => $cleanId,
                    ':mpesa' => $cleanPhone,
                    ':uid'   => $userId,
                ]);
            } else {
                $validMethods = ['motorbike', 'tuk_tuk', 'pickup_truck', 'car', 'bicycle'];
                $method = ($transportMethod !== null && in_array($transportMethod, $validMethods, true)) ? $transportMethod : 'motorbike';
                $cleanModel = $vehicleModel !== null ? trim($vehicleModel) : null;

                $stmt = $pdo->prepare("
                    UPDATE delivery_profiles
                    SET national_id_no = :nid,
                        mpesa_phone = :mpesa,
                        vehicle_plate_number = :plate,
                        vehicle_model = :model,
                        transport_method = :method
                    WHERE user_id = :uid
                ");
                $stmt->execute([
                    ':nid'    => $cleanId,
                    ':mpesa'  => $cleanPhone,
                    ':plate'  => $cleanPlate,
                    ':model'  => $cleanModel ?: null,
                    ':method' => $method,
                    ':uid'    => $userId,
                ]);
            }

            return [
                'ok'                   => true,
                'mpesa_phone'          => $cleanPhone,
                'national_id_no'       => $cleanId,
                'vehicle_plate_number' => $cleanPlate,
                'transport_method'     => $transportMethod,
                'vehicle_model'        => $vehicleModel,
            ];
        } catch (Throwable $e) {
            error_log('SokoHub Kyc::updateKycDetails Error: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Database error while saving profile credentials.'];
        }
    }

    /**
     * Get all documents submitted by a user.
     */
    public static function getUserDocuments(int $userId): array
    {
        self::ensureSchema();
        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare('
                SELECT sd.*, u.full_name AS reviewer_name
                FROM seller_documents sd
                LEFT JOIN users u ON sd.reviewed_by = u.id
                WHERE sd.seller_user_id = :uid
                ORDER BY sd.uploaded_at DESC
            ');
            $stmt->execute([':uid' => $userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('SokoHub Kyc::getUserDocuments Error: ' . $e->getMessage());
            return [];
        }
    }

    public static function getSellerDocuments(int $sellerUserId): array
    {
        return self::getUserDocuments($sellerUserId);
    }

    /**
     * Backwards-compatible profile getter for sellers.
     */
    public static function getSellerProfile(int $sellerUserId): ?array
    {
        return self::getUserKycProfile($sellerUserId, 'seller');
    }

    /**
     * Profile getter for delivery riders.
     */
    public static function getDeliveryProfile(int $riderUserId): ?array
    {
        return self::getUserKycProfile($riderUserId, 'delivery');
    }

    /**
     * Retrieve all active, KYC-verified delivery riders who are available for dispatch.
     */
    public static function getAvailableRiders(?string $serviceArea = null): array
    {
        self::ensureSchema();
        try {
            $pdo = get_db_connection();
            $sql = "
                SELECT 
                    u.id AS rider_id,
                    u.full_name AS rider_name,
                    u.phone AS rider_phone,
                    dp.transport_method,
                    dp.vehicle_plate_number,
                    dp.vehicle_model,
                    dp.service_area,
                    dp.is_available,
                    dp.verification_status,
                    (SELECT COUNT(*) FROM deliveries d WHERE d.delivery_person_id = u.id AND d.delivery_status IN ('assigned', 'picked_up', 'in_transit')) AS active_deliveries
                FROM users u
                INNER JOIN delivery_profiles dp ON dp.user_id = u.id
                WHERE u.role = 'delivery'
                  AND dp.verification_status = 'approved'
                  AND dp.is_available = 1
            ";
            if ($serviceArea !== null && $serviceArea !== '') {
                $sql .= " AND (dp.service_area LIKE :area OR dp.service_area = 'All Kirinyaga' OR dp.service_area = 'Kirinyaga County & Embu')";
            }
            $sql .= " ORDER BY active_deliveries ASC, u.full_name ASC";
            
            $stmt = $pdo->prepare($sql);
            if ($serviceArea !== null && $serviceArea !== '') {
                $stmt->execute([':area' => '%' . trim($serviceArea) . '%']);
            } else {
                $stmt->execute();
            }
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('SokoHub Kyc::getAvailableRiders Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Seller or Admin assigns a delivery order directly to a verified delivery rider.
     */
    public static function assignRiderToDelivery(int $deliveryId, int $riderId, int $assignedByUserId, string $assignedByRole = 'seller'): array
    {
        self::ensureSchema();
        try {
            $pdo = get_db_connection();

            // 1. Verify delivery exists
            $dStmt = $pdo->prepare('
                SELECT d.*, o.order_code
                FROM deliveries d
                INNER JOIN orders o ON o.id = d.order_id
                WHERE d.id = :id LIMIT 1
            ');
            $dStmt->execute([':id' => $deliveryId]);
            $del = $dStmt->fetch(PDO::FETCH_ASSOC);
            if (!$del) {
                return ['ok' => false, 'error' => 'Delivery record not found.'];
            }

            // 2. If seller, verify seller has products in this order
            if ($assignedByRole === 'seller') {
                $sellerCheck = $pdo->prepare('
                    SELECT COUNT(*) FROM order_items
                    WHERE order_id = :oid AND seller_id = :sid
                ');
                $sellerCheck->execute([':oid' => $del['order_id'], ':sid' => $assignedByUserId]);
                if ((int) $sellerCheck->fetchColumn() === 0) {
                    return ['ok' => false, 'error' => 'You do not have permission to assign riders to an order that contains none of your products.'];
                }
            }

            // 3. Verify rider is approved
            $rStmt = $pdo->prepare('
                SELECT u.id, u.full_name, u.phone, dp.transport_method, dp.vehicle_plate_number, dp.vehicle_model, dp.verification_status
                FROM users u
                INNER JOIN delivery_profiles dp ON dp.user_id = u.id
                WHERE u.id = :rid AND u.role = "delivery" LIMIT 1
            ');
            $rStmt->execute([':rid' => $riderId]);
            $rider = $rStmt->fetch(PDO::FETCH_ASSOC);
            if (!$rider || $rider['verification_status'] !== 'approved') {
                return ['ok' => false, 'error' => 'The selected delivery person is not approved or verified for marketplace dispatch.'];
            }

            $riderDesc = $rider['full_name'];
            if (!empty($rider['vehicle_plate_number'])) {
                $riderDesc .= ' (' . $rider['vehicle_plate_number'] . ')';
            } elseif (!empty($rider['transport_method'])) {
                $riderDesc .= ' (' . ucfirst($rider['transport_method']) . ')';
            }

            $trackingNote = 'Assigned by ' . ($assignedByRole === 'admin' ? 'Administration' : 'Merchant') . ' to rider ' . $riderDesc;

            $pdo->beginTransaction();
            $upd = $pdo->prepare('
                UPDATE deliveries
                SET delivery_person_id = :rid,
                    assigned_by_seller_id = :sid,
                    delivery_status = "assigned",
                    assigned_at = NOW(),
                    tracking_notes = :notes
                WHERE id = :did
            ');
            $upd->execute([
                ':rid'   => $riderId,
                ':sid'   => $assignedByUserId,
                ':notes' => $trackingNote,
                ':did'   => $deliveryId,
            ]);

            // Update order status to processing/confirmed if pending
            $pdo->prepare('
                UPDATE orders
                SET order_status = "confirmed"
                WHERE id = :oid AND order_status = "pending"
            ')->execute([':oid' => $del['order_id']]);

            Audit::record('delivery.rider_assigned', 'delivery', $deliveryId, [
                'order_code' => $del['order_code'],
                'rider_id'   => $riderId,
                'rider_name' => $rider['full_name'],
                'plate'      => $rider['vehicle_plate_number'],
                'assigned_by'=> $assignedByUserId,
                'role'       => $assignedByRole,
            ], $assignedByUserId, $assignedByRole);

            $pdo->commit();

            return [
                'ok'         => true,
                'order_code' => $del['order_code'],
                'rider_name' => $rider['full_name'],
                'plate'      => $rider['vehicle_plate_number'],
                'rider_desc' => $riderDesc,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('SokoHub Kyc::assignRiderToDelivery Error: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Database error during rider assignment: ' . $e->getMessage()];
        }
    }

    /**
     * Toggle or set rider availability status (On Duty vs Off Duty).
     */
    public static function toggleRiderAvailability(int $riderUserId, ?bool $explicitStatus = null): bool
    {
        self::ensureSchema();
        try {
            $pdo = get_db_connection();
            if ($explicitStatus !== null) {
                $newStatus = $explicitStatus ? 1 : 0;
            } else {
                $cur = (int) $pdo->query("SELECT is_available FROM delivery_profiles WHERE user_id = {$riderUserId}")->fetchColumn();
                $newStatus = ($cur === 1) ? 0 : 1;
            }

            $stmt = $pdo->prepare('UPDATE delivery_profiles SET is_available = :st WHERE user_id = :uid');
            $stmt->execute([':st' => $newStatus, ':uid' => $riderUserId]);

            Audit::record('delivery.availability_updated', 'delivery_profile', $riderUserId, [
                'is_available' => $newStatus,
            ], $riderUserId, 'delivery');

            return $newStatus === 1;
        } catch (Throwable $e) {
            error_log('SokoHub Kyc::toggleRiderAvailability Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Handle document upload with explicit validation and friendly error messages.
     */
    public static function uploadDocument(int $userId, string $role, string $docType, array $file): array
    {
        self::ensureSchema();

        $allowedTypes = ['national_id', 'business_registration', 'rider_license', 'other'];
        if (!in_array($docType, $allowedTypes, true)) {
            return ['ok' => false, 'error' => 'Invalid document category selected. Please choose National ID, Business Permit, or License.'];
        }

        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            $uploadErrors = [
                UPLOAD_ERR_INI_SIZE   => 'The uploaded file exceeds the server upload_max_filesize limit.',
                UPLOAD_ERR_FORM_SIZE  => 'The uploaded file exceeds the form limit.',
                UPLOAD_ERR_PARTIAL    => 'The file was only partially uploaded. Please try again.',
                UPLOAD_ERR_NO_FILE    => 'No document file was selected. Please choose a file to upload.',
                UPLOAD_ERR_NO_TMP_DIR => 'Server configuration error: missing temporary directory.',
                UPLOAD_ERR_CANT_WRITE => 'Server error: failed to write file to disk. Check directory permissions.',
                UPLOAD_ERR_EXTENSION  => 'A server extension blocked the file upload.',
            ];
            $msg = $uploadErrors[$file['error']] ?? 'Upload failed with system code ' . $file['error'];
            return ['ok' => false, 'error' => $msg];
        }

        // Limit file size to 5 MB
        $maxBytes = 5 * 1024 * 1024;
        if ($file['size'] > $maxBytes) {
            $sizeMB = round($file['size'] / (1024 * 1024), 2);
            return [
                'ok' => false,
                'error' => "File size ({$sizeMB} MB) exceeds the maximum allowed limit of 5 MB. Please upload a smaller document."
            ];
        }

        // Validate extension
        $rawExt = pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION);
        $ext = strtolower($rawExt);
        $allowedExts = ['pdf', 'png', 'jpg', 'jpeg'];
        if (!in_array($ext, $allowedExts, true)) {
            $uploadedExt = $ext !== '' ? '.' . htmlspecialchars($ext) : 'unknown format';
            return [
                'ok' => false,
                'error' => "Unsupported file type ({$uploadedExt}). SokoHub only accepts PDF documents (.pdf) or image files (.png, .jpg, .jpeg). Please convert and upload a supported format."
            ];
        }

        // Validate MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $allowedMimes = ['application/pdf', 'image/png', 'image/jpeg', 'image/jpg'];
        if (!in_array($mime, $allowedMimes, true)) {
            return [
                'ok' => false,
                'error' => "Security validation failed: the file content does not match a valid PDF or image. Please upload a genuine PDF, PNG, or JPG document."
            ];
        }

        // Target directory setup
        $targetDir = __DIR__ . '/../assets/img/uploads/kyc';
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0777, true);
        }
        @chmod($targetDir, 0777);

        $safeName = sprintf('kyc_%s_%d_%s_%s.%s', $role, $userId, $docType, bin2hex(random_bytes(6)), $ext);
        $targetPath = $targetDir . '/' . $safeName;
        $relPath = 'assets/img/uploads/kyc/' . $safeName;

        // Save file
        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            if (!copy($file['tmp_name'], $targetPath)) {
                return [
                    'ok' => false,
                    'error' => 'Failed to write the uploaded file to server storage. Please ensure assets/img/uploads/kyc is writable.'
                ];
            }
        }
        @chmod($targetPath, 0666);

        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare('
                INSERT INTO seller_documents (seller_user_id, user_role, doc_type, file_name, file_path, file_size, mime_type, review_status)
                VALUES (:uid, :role, :type, :fname, :fpath, :fsize, :mime, "pending")
            ');
            $stmt->execute([
                ':uid'   => $userId,
                ':role'  => $role,
                ':type'  => $docType,
                ':fname' => $file['name'],
                ':fpath' => $relPath,
                ':fsize' => (int) $file['size'],
                ':mime'  => $mime,
            ]);
            $docId = (int) $pdo->lastInsertId();

            // Set profile status back to pending if it was previously rejected
            $table = ($role === 'seller') ? 'seller_profiles' : 'delivery_profiles';
            $pdo->prepare("
                UPDATE {$table}
                SET verification_status = 'pending', rejection_reason = NULL
                WHERE user_id = :uid AND verification_status = 'rejected'
            ")->execute([':uid' => $userId]);

            // Record audit trail
            Audit::record('kyc.document_uploaded', 'seller_document', $docId, [
                'role'      => $role,
                'doc_type'  => $docType,
                'file_name' => $file['name'],
                'file_size' => (int) $file['size'],
                'file_path' => $relPath,
            ], $userId, $role);

            return [
                'ok'        => true,
                'doc_id'    => $docId,
                'file_name' => $file['name'],
                'file_size' => (int) $file['size'],
                'path'      => $relPath,
            ];
        } catch (Throwable $e) {
            error_log('SokoHub Kyc::uploadDocument DB Error: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Database error saving document record: ' . $e->getMessage()];
        }
    }

    /**
     * Get review queue for both sellers and delivery riders.
     */
    public static function getAdminQueue(string $typeFilter = '', string $statusFilter = ''): array
    {
        self::ensureSchema();
        try {
            $pdo = get_db_connection();
            $results = [];

            // Fetch sellers
            if ($typeFilter === '' || $typeFilter === 'seller') {
                $sqlSellers = '
                    SELECT 
                        sp.user_id,
                        sp.business_name,
                        sp.business_type,
                        NULL AS vehicle_plate_number,
                        NULL AS vehicle_model,
                        NULL AS license_number,
                        sp.town,
                        sp.verification_status,
                        sp.verified_at,
                        sp.rejection_reason,
                        sp.mpesa_phone,
                        sp.national_id_no,
                        u.full_name AS owner_name,
                        u.email,
                        u.phone AS user_phone,
                        u.created_at AS registered_at,
                        "seller" AS account_type,
                        v.full_name AS reviewer_name,
                        COUNT(sd.id) AS document_count
                    FROM seller_profiles sp
                    INNER JOIN users u ON sp.user_id = u.id
                    LEFT JOIN seller_documents sd ON sp.user_id = sd.seller_user_id
                    LEFT JOIN users v ON sp.verified_by = v.id
                    WHERE 1=1
                ';
                if ($statusFilter !== '') {
                    $sqlSellers .= ' AND sp.verification_status = ' . $pdo->quote($statusFilter);
                }
                $sqlSellers .= ' GROUP BY sp.id, u.id';
                $stmt = $pdo->query($sqlSellers);
                $results = array_merge($results, $stmt->fetchAll(PDO::FETCH_ASSOC));
            }

            // Fetch delivery riders
            if ($typeFilter === '' || $typeFilter === 'delivery') {
                $sqlRiders = '
                    SELECT 
                        dp.user_id,
                        CONCAT(UCASE(LEFT(dp.transport_method, 1)), SUBSTRING(dp.transport_method, 2), " Rider (", COALESCE(dp.vehicle_plate_number, dp.license_number, "No Plate"), ")") AS business_name,
                        dp.transport_method AS business_type,
                        dp.vehicle_plate_number,
                        dp.vehicle_model,
                        dp.license_number,
                        dp.service_area AS town,
                        dp.verification_status,
                        dp.verified_at,
                        dp.rejection_reason,
                        dp.mpesa_phone,
                        dp.national_id_no,
                        u.full_name AS owner_name,
                        u.email,
                        u.phone AS user_phone,
                        u.created_at AS registered_at,
                        "delivery" AS account_type,
                        v.full_name AS reviewer_name,
                        COUNT(sd.id) AS document_count
                    FROM delivery_profiles dp
                    INNER JOIN users u ON dp.user_id = u.id
                    LEFT JOIN seller_documents sd ON dp.user_id = sd.seller_user_id
                    LEFT JOIN users v ON dp.verified_by = v.id
                    WHERE 1=1
                ';
                if ($statusFilter !== '') {
                    $sqlRiders .= ' AND dp.verification_status = ' . $pdo->quote($statusFilter);
                }
                $sqlRiders .= ' GROUP BY dp.id, u.id';
                $stmt = $pdo->query($sqlRiders);
                $results = array_merge($results, $stmt->fetchAll(PDO::FETCH_ASSOC));
            }

            // Sort: pending first, then newest
            usort($results, function ($a, $b) {
                if ($a['verification_status'] === 'pending' && $b['verification_status'] !== 'pending') return -1;
                if ($b['verification_status'] === 'pending' && $a['verification_status'] !== 'pending') return 1;
                return strcmp($b['registered_at'], $a['registered_at']);
            });

            return $results;
        } catch (Throwable $e) {
            error_log('SokoHub Kyc::getAdminQueue Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Backwards-compatible status updater for sellers.
     */
    public static function updateSellerStatus(int $sellerUserId, string $newStatus, int $adminId, ?string $reason = null): bool
    {
        return self::updateStatus($sellerUserId, 'seller', $newStatus, $adminId, $reason);
    }

    /**
     * Admin: Approve, Reject, or Suspend a user.
     */
    public static function updateStatus(int $targetUserId, string $role, string $newStatus, int $adminId, ?string $reason = null): bool
    {
        self::ensureSchema();

        $validStatuses = ['approved', 'rejected', 'suspended', 'pending'];
        if (!in_array($newStatus, $validStatuses, true)) {
            return false;
        }

        try {
            $pdo = get_db_connection();
            $table = ($role === 'seller') ? 'seller_profiles' : 'delivery_profiles';

            $verifiedAt = ($newStatus === 'approved') ? date('Y-m-d H:i:s') : null;

            $stmt = $pdo->prepare("
                UPDATE {$table}
                SET verification_status = :status,
                    verified_at = :at,
                    verified_by = :by,
                    rejection_reason = :reason
                WHERE user_id = :uid
            ");
            $ok = $stmt->execute([
                ':status' => $newStatus,
                ':at'     => $verifiedAt,
                ':by'     => $adminId,
                ':reason' => ($newStatus === 'rejected') ? $reason : null,
                ':uid'    => $targetUserId,
            ]);

            if ($ok) {
                if ($newStatus === 'approved') {
                    $pdo->prepare('
                        UPDATE seller_documents
                        SET review_status = "accepted", reviewed_by = :by, reviewed_at = NOW()
                        WHERE seller_user_id = :uid AND review_status = "pending"
                    ')->execute([':by' => $adminId, ':uid' => $targetUserId]);

                    // Broadcast notification to buyers when a new seller is approved
                    if ($role === 'seller') {
                        $prof = self::getSellerProfile($targetUserId);
                        $bName = $prof['business_name'] ?? 'Local Merchant';
                        $bTown = $prof['town'] ?? 'Kirinyaga';
                        Notifications::notifyNewSeller($targetUserId, $bName, $bTown);
                    }
                } elseif ($newStatus === 'rejected') {
                    $pdo->prepare('
                        UPDATE seller_documents
                        SET review_status = "rejected", reviewed_by = :by, reviewed_at = NOW()
                        WHERE seller_user_id = :uid AND review_status = "pending"
                    ')->execute([':by' => $adminId, ':uid' => $targetUserId]);
                }

                // Log audit trail
                Audit::record('kyc.' . $role . '_' . $newStatus, $table, $targetUserId, [
                    'account_type' => $role,
                    'new_status'   => $newStatus,
                    'reason'       => $reason,
                ], $adminId, 'admin');
            }

            return $ok;
        } catch (Throwable $e) {
            error_log('SokoHub Kyc::updateStatus Error: ' . $e->getMessage());
            return false;
        }
    }
}
