<?php
/**
 * SokoHub Seller KYC & Verification Service
 * Handles merchant onboarding compliance, document uploads, and administrative approvals.
 * Authored by Kennedy Kalawa.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/audit.php';

class Kyc
{
    /**
     * Ensure seller_documents table and seller_profiles columns exist.
     */
    public static function ensureSchema(): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }

        try {
            $pdo = get_db_connection();

            // 1. Ensure verification columns exist on seller_profiles
            $colCheck = $pdo->query("SHOW COLUMNS FROM seller_profiles LIKE 'verification_status'")->fetch();
            if (!$colCheck) {
                $pdo->exec("
                    ALTER TABLE seller_profiles
                    ADD COLUMN verification_status ENUM('pending', 'approved', 'rejected', 'suspended') NOT NULL DEFAULT 'pending',
                    ADD COLUMN verified_at DATETIME NULL,
                    ADD COLUMN verified_by INT UNSIGNED NULL,
                    ADD COLUMN rejection_reason VARCHAR(255) NULL,
                    ADD COLUMN mpesa_phone VARCHAR(20) NULL
                ");
            }

            // 2. Ensure seller_documents table exists
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS seller_documents (
                    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    seller_user_id   INT UNSIGNED NOT NULL,
                    doc_type         ENUM('business_registration', 'national_id', 'other') NOT NULL,
                    file_path        VARCHAR(255) NOT NULL,
                    review_status    ENUM('pending', 'accepted', 'rejected') NOT NULL DEFAULT 'pending',
                    reviewed_by      INT UNSIGNED NULL,
                    reviewed_at      DATETIME NULL,
                    uploaded_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_docs_seller (seller_user_id),
                    CONSTRAINT fk_docs_seller FOREIGN KEY (seller_user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            // Ensure upload directory exists
            $uploadDir = __DIR__ . '/../assets/img/uploads/kyc';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0755, true);
            }

            $checked = true;
        } catch (Throwable $e) {
            error_log('SokoHub Kyc::ensureSchema Error: ' . $e->getMessage());
        }
    }

    /**
     * Get seller profile with verification status.
     */
    public static function getSellerProfile(int $userId): ?array
    {
        self::ensureSchema();
        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare('
                SELECT sp.*, u.full_name, u.email, u.phone
                FROM seller_profiles sp
                INNER JOIN users u ON sp.user_id = u.id
                WHERE sp.user_id = :uid
                LIMIT 1
            ');
            $stmt->execute([':uid' => $userId]);
            $res = $stmt->fetch(PDO::FETCH_ASSOC);
            return $res ?: null;
        } catch (Throwable $e) {
            error_log('SokoHub Kyc::getSellerProfile Error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if seller is approved to publish active marketplace products.
     */
    public static function isSellerApproved(int $userId): bool
    {
        $profile = self::getSellerProfile($userId);
        if (!$profile) {
            return false;
        }
        return ($profile['verification_status'] ?? 'pending') === 'approved';
    }

    /**
     * Get all documents submitted by a specific seller.
     */
    public static function getSellerDocuments(int $sellerUserId): array
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
            $stmt->execute([':uid' => $sellerUserId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('SokoHub Kyc::getSellerDocuments Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Handle document upload from a seller.
     */
    public static function uploadDocument(int $sellerUserId, string $docType, array $file): array
    {
        self::ensureSchema();

        $allowedTypes = ['business_registration', 'national_id', 'other'];
        if (!in_array($docType, $allowedTypes, true)) {
            return ['ok' => false, 'error' => 'Invalid document type selected.'];
        }

        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'Upload failed. Please choose a valid file.'];
        }

        // Limit file size to 5 MB
        $maxBytes = 5 * 1024 * 1024;
        if ($file['size'] > $maxBytes) {
            return ['ok' => false, 'error' => 'File size exceeds the 5 MB limit.'];
        }

        // Validate extension & MIME type
        $allowedExts = ['pdf', 'png', 'jpg', 'jpeg'];
        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts, true)) {
            return ['ok' => false, 'error' => 'Invalid file format. Only PDF, PNG, JPG files are accepted.'];
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $allowedMimes = ['application/pdf', 'image/png', 'image/jpeg', 'image/jpg'];
        if (!in_array($mime, $allowedMimes, true)) {
            return ['ok' => false, 'error' => 'File content failed security validation.'];
        }

        // Generate safe unique filename
        $safeName = sprintf('kyc_%d_%s_%s.%s', $sellerUserId, $docType, bin2hex(random_bytes(6)), $ext);
        $targetDir = realpath(__DIR__ . '/../assets/img/uploads/kyc') ?: (__DIR__ . '/../assets/img/uploads/kyc');
        $targetPath = $targetDir . '/' . $safeName;
        $relPath = 'assets/img/uploads/kyc/' . $safeName;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            return ['ok' => false, 'error' => 'Failed to save uploaded document to server.'];
        }

        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare('
                INSERT INTO seller_documents (seller_user_id, doc_type, file_path, review_status)
                VALUES (:uid, :type, :path, "pending")
            ');
            $stmt->execute([
                ':uid'  => $sellerUserId,
                ':type' => $docType,
                ':path' => $relPath,
            ]);
            $docId = (int) $pdo->lastInsertId();

            // Set seller status to pending verification
            $pdo->prepare("
                UPDATE seller_profiles
                SET verification_status = 'pending', rejection_reason = NULL
                WHERE user_id = :uid AND verification_status = 'rejected'
            ")->execute([':uid' => $sellerUserId]);

            // Record audit trail
            Audit::record('seller.kyc_uploaded', 'seller_document', $docId, [
                'doc_type'  => $docType,
                'file_name' => $file['name'],
                'file_path' => $relPath,
            ], $sellerUserId, 'seller');

            return ['ok' => true, 'doc_id' => $docId, 'path' => $relPath];
        } catch (Throwable $e) {
            error_log('SokoHub Kyc::uploadDocument DB Error: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Database error while saving document metadata.'];
        }
    }

    /**
     * Get all sellers for admin review queue with optional status filter.
     */
    public static function getAdminSellerQueue(string $statusFilter = ''): array
    {
        self::ensureSchema();
        try {
            $pdo = get_db_connection();
            $sql = '
                SELECT 
                    sp.*,
                    u.full_name AS seller_name,
                    u.email AS seller_email,
                    u.phone AS seller_phone,
                    u.created_at AS registered_at,
                    COUNT(sd.id) AS document_count,
                    v.full_name AS reviewer_name
                FROM seller_profiles sp
                INNER JOIN users u ON sp.user_id = u.id
                LEFT JOIN seller_documents sd ON sp.user_id = sd.seller_user_id
                LEFT JOIN users v ON sp.verified_by = v.id
                WHERE 1=1
            ';
            $params = [];

            if ($statusFilter !== '') {
                $sql .= ' AND sp.verification_status = :status';
                $params[':status'] = $statusFilter;
            }

            $sql .= ' GROUP BY sp.id, u.id ORDER BY (sp.verification_status = "pending") DESC, sp.created_at DESC';

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('SokoHub Kyc::getAdminSellerQueue Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Admin action: Approve, reject, or suspend a seller.
     */
    public static function updateSellerStatus(int $sellerUserId, string $newStatus, int $adminId, ?string $reason = null): bool
    {
        self::ensureSchema();

        $validStatuses = ['approved', 'rejected', 'suspended', 'pending'];
        if (!in_array($newStatus, $validStatuses, true)) {
            return false;
        }

        try {
            $pdo = get_db_connection();

            $stmt = $pdo->prepare('
                UPDATE seller_profiles
                SET verification_status = :status,
                    verified_at = :at,
                    verified_by = :by,
                    rejection_reason = :reason
                WHERE user_id = :uid
            ');

            $verifiedAt = ($newStatus === 'approved') ? date('Y-m-d H:i:s') : null;

            $ok = $stmt->execute([
                ':status' => $newStatus,
                ':at'     => $verifiedAt,
                ':by'     => $adminId,
                ':reason' => ($newStatus === 'rejected') ? $reason : null,
                ':uid'    => $sellerUserId,
            ]);

            if ($ok) {
                // If seller approved, mark documents as accepted
                if ($newStatus === 'approved') {
                    $pdo->prepare('
                        UPDATE seller_documents
                        SET review_status = "accepted", reviewed_by = :by, reviewed_at = NOW()
                        WHERE seller_user_id = :uid AND review_status = "pending"
                    ')->execute([':by' => $adminId, ':uid' => $sellerUserId]);
                }

                // If rejected, mark pending docs as rejected
                if ($newStatus === 'rejected') {
                    $pdo->prepare('
                        UPDATE seller_documents
                        SET review_status = "rejected", reviewed_by = :by, reviewed_at = NOW()
                        WHERE seller_user_id = :uid AND review_status = "pending"
                    ')->execute([':by' => $adminId, ':uid' => $sellerUserId]);
                }

                // Log audit trail
                Audit::record('seller.' . $newStatus, 'seller_profile', $sellerUserId, [
                    'previous_status' => self::getSellerProfile($sellerUserId)['verification_status'] ?? 'unknown',
                    'new_status'      => $newStatus,
                    'reason'          => $reason,
                ], $adminId, 'admin');
            }

            return $ok;
        } catch (Throwable $e) {
            error_log('SokoHub Kyc::updateSellerStatus Error: ' . $e->getMessage());
            return false;
        }
    }
}
