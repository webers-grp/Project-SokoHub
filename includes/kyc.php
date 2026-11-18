<?php
/**
 * SokoHub KYC & Merchant/Rider Verification Service
 * Handles KYC compliance for both Sellers and Delivery Personnel.
 * Requires National ID + M-Pesa phone number before marketplace publishing or delivery claiming.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/notifications.php';

class Kyc
{
    public static function ensureSchema(): void
    {
        // No-op: schema is guaranteed by migrations
    }

    /**
     * Retrieve KYC profile for a seller or delivery rider.
     */
    public static function getUserKycProfile(int $userId, string $role): array
    {
        try {
            $pdo = get_db_connection();
            $table = ($role === 'seller') ? 'seller_profiles' : 'delivery_profiles';

            $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE user_id = :uid LIMIT 1");
            $stmt->execute([':uid' => $userId]);
            $prof = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$prof) {
                if ($role === 'seller') {
                    $pdo->prepare('INSERT IGNORE INTO seller_profiles (user_id, business_name, verification_status) VALUES (:uid, "Local Merchant", "pending")')
                        ->execute([':uid' => $userId]);
                } else {
                    $pdo->prepare('INSERT IGNORE INTO delivery_profiles (user_id, transport_method, verification_status) VALUES (:uid, "motorbike", "pending")')
                        ->execute([':uid' => $userId]);
                }
                $stmt->execute([':uid' => $userId]);
                $prof = $stmt->fetch(PDO::FETCH_ASSOC);
            }

            return $prof ?: [];
        } catch (Throwable $e) {
            error_log('Kyc::getUserKycProfile Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Check if a seller is verified and approved.
     */
    public static function isSellerApproved(int $sellerUserId): bool
    {
        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare('SELECT verification_status FROM seller_profiles WHERE user_id = :uid LIMIT 1');
            $stmt->execute([':uid' => $sellerUserId]);
            $status = $stmt->fetchColumn();
            return $status === 'approved';
        } catch (Throwable $e) {
            error_log('Kyc::isSellerApproved Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if a seller can publish products (must be approved, or configured to allow sandbox).
     */
    public static function canPublishProducts(int $sellerUserId): array
    {
        $approved = self::isSellerApproved($sellerUserId);
        if ($approved) {
            return ['can_publish' => true, 'reason' => 'Account is KYC verified and approved.'];
        }

        // Check if seller has completed payout profile
        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare('SELECT payout_destination_type, payout_account, verification_status FROM seller_profiles WHERE user_id = :uid LIMIT 1');
            $stmt->execute([':uid' => $sellerUserId]);
            $prof = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($prof && $prof['verification_status'] === 'approved') {
                return ['can_publish' => true, 'reason' => 'KYC approved.'];
            }

            return [
                'can_publish' => false,
                'status'      => $prof['verification_status'] ?? 'pending',
                'reason'      => 'Merchant account KYC verification is pending administrative review.',
            ];
        } catch (Throwable $e) {
            return ['can_publish' => false, 'reason' => 'Error verifying seller status.'];
        }
    }

    /**
     * Check if a rider is verified and approved.
     */
    public static function isRiderApproved(int $riderUserId): bool
    {
        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare('SELECT verification_status FROM delivery_profiles WHERE user_id = :uid LIMIT 1');
            $stmt->execute([':uid' => $riderUserId]);
            $status = $stmt->fetchColumn();
            return $status === 'approved';
        } catch (Throwable $e) {
            error_log('Kyc::isRiderApproved Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Upload and attach a KYC document.
     */
    public static function uploadDocument(int $userId, string $role, string $docType, array $file): array
    {
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return ['ok' => false, 'error' => 'No valid document file uploaded.'];
        }

        $validTypes = ['national_id', 'business_permit', 'driving_license', 'other'];
        if (!in_array($docType, $validTypes, true)) {
            $docType = 'national_id';
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedExts = ['pdf', 'png', 'jpg', 'jpeg'];
        if (!in_array($ext, $allowedExts, true)) {
            return ['ok' => false, 'error' => 'Only PDF, JPG, and PNG files are accepted.'];
        }

        if ($file['size'] > 5 * 1024 * 1024) {
            return ['ok' => false, 'error' => 'Document file must not exceed 5 MB.'];
        }

        $uploadDir = dirname(__DIR__) . '/assets/img/uploads/kyc';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $safeName = 'kyc_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $destPath = $uploadDir . '/' . $safeName;
        $relPath  = 'assets/img/uploads/kyc/' . $safeName;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            return ['ok' => false, 'error' => 'Failed to save uploaded document to server.'];
        }

        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare('
                INSERT INTO kyc_documents (user_id, role, doc_type, file_name, file_path, file_size_kb, status, uploaded_at)
                VALUES (:uid, :role, :type, :fname, :fpath, :fsize, "pending", NOW())
            ');
            $sizeKb = (int) ceil($file['size'] / 1024);
            $stmt->execute([
                ':uid'   => $userId,
                ':role'  => $role,
                ':type'  => $docType,
                ':fname' => $file['name'],
                ':fpath' => $relPath,
                ':fsize' => $sizeKb,
            ]);

            $docId = (int) $pdo->lastInsertId();

            Audit::record('kyc.document_uploaded', 'kyc_document', $docId, [
                'doc_type'  => $docType,
                'file_name' => $file['name'],
            ], $userId, $role);

            return [
                'ok'        => true,
                'doc_id'    => $docId,
                'file_name' => $file['name'],
                'file_path' => $relPath,
            ];
        } catch (Throwable $e) {
            error_log('Kyc::uploadDocument Error: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Database error recording document: ' . $e->getMessage()];
        }
    }

    /**
     * Get documents uploaded by a user.
     */
    public static function getUserDocuments(int $userId): array
    {
        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare('SELECT * FROM kyc_documents WHERE user_id = :uid ORDER BY uploaded_at DESC');
            $stmt->execute([':uid' => $userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('Kyc::getUserDocuments Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Update credentials / vehicle data for a seller or rider.
     */
    public static function updateKycDetails(
        int $userId,
        string $role,
        string $nationalIdNo,
        string $mpesaPhone,
        ?string $plate = null,
        ?string $transport = null,
        ?string $model = null
    ): array {
        try {
            $pdo = get_db_connection();

            if ($role === 'seller') {
                $stmt = $pdo->prepare('
                    UPDATE seller_profiles 
                    SET national_id_no = :nid, mpesa_phone = :mpesa, updated_at = NOW()
                    WHERE user_id = :uid
                ');
                $stmt->execute([
                    ':nid'   => $nationalIdNo,
                    ':mpesa' => $mpesaPhone,
                    ':uid'   => $userId,
                ]);
            } else {
                $stmt = $pdo->prepare('
                    UPDATE delivery_profiles 
                    SET national_id_no = :nid, mpesa_phone = :mpesa, vehicle_plate_number = :plate,
                        transport_method = :transport, vehicle_model = :model, updated_at = NOW()
                    WHERE user_id = :uid
                ');
                $stmt->execute([
                    ':nid'       => $nationalIdNo,
                    ':mpesa'     => $mpesaPhone,
                    ':plate'     => $plate,
                    ':transport' => $transport ?: 'motorbike',
                    ':model'     => $model,
                    ':uid'       => $userId,
                ]);
            }

            return [
                'ok'                   => true,
                'national_id_no'       => $nationalIdNo,
                'mpesa_phone'          => $mpesaPhone,
                'vehicle_plate_number' => $plate,
                'transport_method'     => $transport,
                'vehicle_model'        => $model,
            ];
        } catch (Throwable $e) {
            error_log('Kyc::updateKycDetails Error: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get pending KYC queue for administrators.
     */
    public static function getAdminQueue(?string $typeFilter = null, ?string $statusFilter = null): array
    {
        try {
            $pdo = get_db_connection();
            $results = [];

            // 1. Sellers
            if (!$typeFilter || $typeFilter === 'seller') {
                $sqlSellers = '
                    SELECT 
                        sp.id AS profile_id,
                        sp.user_id,
                        sp.business_name,
                        sp.verification_status,
                        sp.rejection_reason,
                        sp.mpesa_phone,
                        sp.national_id_no,
                        u.full_name AS owner_name,
                        u.email,
                        u.phone AS user_phone,
                        u.created_at AS registered_at,
                        "seller" AS account_type,
                        COUNT(kd.id) AS document_count
                    FROM seller_profiles sp
                    INNER JOIN users u ON sp.user_id = u.id
                    LEFT JOIN kyc_documents kd ON kd.user_id = sp.user_id
                    WHERE 1=1
                ';
                if ($statusFilter !== '' && $statusFilter !== null) {
                    $sqlSellers .= ' AND sp.verification_status = ' . $pdo->quote($statusFilter);
                }
                $sqlSellers .= ' GROUP BY sp.id, u.id';
                $stmt = $pdo->query($sqlSellers);
                $results = array_merge($results, $stmt->fetchAll(PDO::FETCH_ASSOC));
            }

            // 2. Riders
            if (!$typeFilter || $typeFilter === 'delivery') {
                $sqlRiders = '
                    SELECT 
                        dp.id AS profile_id,
                        dp.user_id,
                        dp.transport_method,
                        dp.vehicle_plate_number,
                        dp.vehicle_model,
                        dp.verification_status,
                        dp.rejection_reason,
                        dp.mpesa_phone,
                        dp.national_id_no,
                        u.full_name AS owner_name,
                        u.email,
                        u.phone AS user_phone,
                        u.created_at AS registered_at,
                        "delivery" AS account_type,
                        COUNT(kd.id) AS document_count
                    FROM delivery_profiles dp
                    INNER JOIN users u ON dp.user_id = u.id
                    LEFT JOIN kyc_documents kd ON kd.user_id = dp.user_id
                    WHERE 1=1
                ';
                if ($statusFilter !== '' && $statusFilter !== null) {
                    $sqlRiders .= ' AND dp.verification_status = ' . $pdo->quote($statusFilter);
                }
                $sqlRiders .= ' GROUP BY dp.id, u.id';
                $stmt = $pdo->query($sqlRiders);
                $results = array_merge($results, $stmt->fetchAll(PDO::FETCH_ASSOC));
            }

            return $results;
        } catch (Throwable $e) {
            error_log('Kyc::getAdminQueue Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Admin: Approve, Reject, or Suspend a user.
     */
    public static function updateStatus(int $targetUserId, string $role, string $newStatus, int $adminId, ?string $reason = null): bool
    {
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
                    rejection_reason = :reason,
                    updated_at = NOW()
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
                $docStatus = ($newStatus === 'approved') ? 'approved' : (($newStatus === 'rejected') ? 'rejected' : 'pending');
                $pdo->prepare('
                    UPDATE kyc_documents
                    SET status = :st, reviewed_by = :by, reviewed_at = NOW()
                    WHERE user_id = :uid AND status = "pending"
                ')->execute([':st' => $docStatus, ':by' => $adminId, ':uid' => $targetUserId]);

                if ($newStatus === 'approved' && $role === 'seller') {
                    $prof = self::getUserKycProfile($targetUserId, 'seller');
                    $bName = $prof['business_name'] ?? 'Local Merchant';
                    $bTown = $prof['town'] ?? 'Kirinyaga';
                    Notifications::notifyNewSeller($targetUserId, $bName, $bTown);
                }

                Audit::record('kyc.' . $role . '_' . $newStatus, $table, $targetUserId, [
                    'account_type' => $role,
                    'new_status'   => $newStatus,
                    'reason'       => $reason,
                ], $adminId, 'admin');
            }

            return $ok;
        } catch (Throwable $e) {
            error_log('Kyc::updateStatus Error: ' . $e->getMessage());
            return false;
        }
    }
}
