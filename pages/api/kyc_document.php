<?php
/**
 * SokoHub Authenticated KYC Document Download & Inspection Endpoint
 *
 * Enforces strict role-based access control:
 * - Only the document owner (seller / rider) or an administrator can view or download the document.
 * - Unauthorized requests receive HTTP 403 Forbidden.
 * - Streams file safely with appropriate Content-Type, X-Content-Type-Options: nosniff, and Cache-Control: private.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/audit.php';

init_session();
$user = require_login();
$userId = (int) $user['id'];
$role = $user['role'];
$isAdmin = ($role === 'admin');

$docId = (int) ($_GET['id'] ?? 0);
if ($docId <= 0) {
    http_response_code(400);
    exit('Invalid document ID.');
}

$pdo = get_db_connection();
$stmt = $pdo->prepare('SELECT * FROM kyc_documents WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $docId]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doc) {
    http_response_code(404);
    exit('Document not found.');
}

// Strict Authorization Check: Owner or Admin only
if (!$isAdmin && (int) $doc['user_id'] !== $userId) {
    Audit::record('kyc.unauthorized_access_attempt', 'kyc_document', $docId, [
        'owner_id' => $doc['user_id'],
        'attempted_by' => $userId,
    ], $userId, $role);
    http_response_code(403);
    exit('Access denied: You do not have permission to inspect this compliance document.');
}

// Resolve real filesystem path
$fullPath = APP_ROOT . '/' . ltrim($doc['file_path'], '/');
if (!is_file($fullPath) || !is_readable($fullPath)) {
    http_response_code(404);
    exit('Document file is missing on storage.');
}

// Real MIME detection
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($fullPath) ?: 'application/octet-stream';

// If inspected by admin, record audit log entry
if ($isAdmin && (int) $doc['user_id'] !== $userId) {
    Audit::record('kyc.admin_inspected', 'kyc_document', $docId, [
        'document_owner' => $doc['user_id'],
        'doc_type'       => $doc['doc_type'],
        'file_name'      => $doc['file_name'],
    ], $userId, 'admin');
}

// Send hardened streaming headers
Security::sendHeaders();
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($fullPath));
header('Content-Disposition: inline; filename="' . basename($doc['file_name'] ?: $fullPath) . '"');
header('Cache-Control: private, no-transform, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

readfile($fullPath);
exit;
