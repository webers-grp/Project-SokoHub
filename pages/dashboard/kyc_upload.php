<?php
/**
 * SokoHub Seller KYC Document Upload
 * Allows registered sellers to submit National ID and Business Permit documents for merchant verification.
 * Authored by Kennedy Kalawa.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/kyc.php';

init_session();
$sellerUser = require_role(['seller']);
$userId = (int) $sellerUser['id'];

$sellerProfile = Kyc::getSellerProfile($userId);
$currentStatus = $sellerProfile['verification_status'] ?? 'pending';
$rejectionReason = $sellerProfile['rejection_reason'] ?? null;

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_doc') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Security token mismatch. Please refresh and try again.';
    } else {
        $docType = (string) ($_POST['doc_type'] ?? '');
        $file = $_FILES['document_file'] ?? null;

        if (!$file || empty($file['name'])) {
            $errors[] = 'Please select a document file to upload.';
        } else {
            $uploadResult = Kyc::uploadDocument($userId, $docType, $file);
            if ($uploadResult['ok']) {
                set_flash('success', 'Document submitted successfully! An administrator will review your credentials.');
                redirect('pages/dashboard/kyc_upload.php');
            } else {
                $errors[] = $uploadResult['error'] ?? 'Upload failed.';
            }
        }
    }
}

$documents = Kyc::getSellerDocuments($userId);

$pageTitle = 'SokoHub | Seller Verification & KYC';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="container section-space">
    <div class="page-head">
        <div>
            <h1>Seller Compliance &amp; Verification (KYC)</h1>
            <p class="muted">Upload your merchant credentials to verify your account and publish products on SokoHub.</p>
        </div>
        <div class="header-actions">
            <a href="<?= e(url('pages/dashboard/dashboard.php')) ?>" class="btn btn-outline">&larr; Back to Dashboard</a>
        </div>
    </div>

    <!-- Status Banner Card -->
    <div class="card" style="margin-bottom: 2rem; border-left: 6px solid <?= $currentStatus === 'approved' ? '#10b981' : ($currentStatus === 'rejected' ? '#ef4444' : '#f59e0b') ?>;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
            <div>
                <span class="muted" style="font-size: 0.85rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Current Verification Status</span>
                <div style="margin-top: 0.25rem; display: flex; align-items: center; gap: 0.75rem;">
                    <?php if ($currentStatus === 'approved'): ?>
                        <span class="badge badge-success" style="font-size: 1rem; padding: 0.4rem 0.8rem;">Verified Merchant &check;</span>
                        <span style="font-size: 0.95rem; color: #10b981; font-weight: 600;">Your store is active and approved to sell!</span>
                    <?php elseif ($currentStatus === 'rejected'): ?>
                        <span class="badge badge-danger" style="font-size: 1rem; padding: 0.4rem 0.8rem;">Verification Rejected &cross;</span>
                        <span style="font-size: 0.95rem; color: #ef4444; font-weight: 600;">Please review the administrator notes below and resubmit.</span>
                    <?php elseif ($currentStatus === 'suspended'): ?>
                        <span class="badge badge-danger" style="font-size: 1rem; padding: 0.4rem 0.8rem;">Account Suspended</span>
                        <span style="font-size: 0.95rem; color: #ef4444; font-weight: 600;">Contact platform administration for compliance review.</span>
                    <?php else: ?>
                        <span class="badge badge-amber" style="font-size: 1rem; padding: 0.4rem 0.8rem;">Pending Admin Review &bull;</span>
                        <span style="font-size: 0.95rem; color: #b45309; font-weight: 600;">Your submitted documents are currently in the review queue.</span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($currentStatus === 'approved'): ?>
                <a href="<?= e(url('pages/dashboard/manage_products.php')) ?>" class="btn btn-primary">Go to Product Catalog &rarr;</a>
            <?php endif; ?>
        </div>

        <?php if ($currentStatus === 'rejected' && !empty($rejectionReason)): ?>
            <div style="margin-top: 1rem; padding: 0.75rem 1rem; background: #fee2e2; border-radius: 6px; color: #991b1b; font-size: 0.9rem;">
                <strong>Administrator Rejection Reason:</strong> <?= e($rejectionReason) ?>
            </div>
        <?php endif; ?>
    </div>

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
        <!-- Left: Upload Form -->
        <div class="card">
            <h2 style="margin-bottom: 0.5rem;">Submit New Document</h2>
            <p class="muted" style="margin-bottom: 1.5rem; font-size: 0.9rem;">
                Please upload a clear copy of your National ID card, Passport, or County Business Permit (max 5 MB).
            </p>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error" style="margin-bottom: 1.25rem;">
                    <div>
                        <?php foreach ($errors as $err): ?>
                            <div><?= e($err) ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <form action="<?= e(url('pages/dashboard/kyc_upload.php')) ?>" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="upload_doc">

                <div class="form-group" style="margin-bottom: 1.25rem;">
                    <label for="doc_type" class="form-label" style="font-weight: 600;">Document Type *</label>
                    <select name="doc_type" id="doc_type" class="form-control" required>
                        <option value="national_id">National ID / Passport</option>
                        <option value="business_registration">County Business Permit / Registration</option>
                        <option value="other">KRA PIN / Tax Certificate / Other</option>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom: 1.5rem;">
                    <label for="document_file" class="form-label" style="font-weight: 600;">Select Document File *</label>
                    <input type="file" name="document_file" id="document_file" class="form-control" accept=".pdf,.png,.jpg,.jpeg" required>
                    <small class="muted" style="display: block; margin-top: 0.35rem;">Accepted formats: PDF, PNG, JPG, JPEG (Max: 5 MB)</small>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%;">Upload Document for Verification</button>
            </form>
        </div>

        <!-- Right: Requirements Guide -->
        <div class="card" style="background: #f8fafc;">
            <h2 style="margin-bottom: 0.75rem;">Verification Requirements</h2>
            <ul style="padding-left: 1.25rem; line-height: 1.7; color: #475569; font-size: 0.9rem;">
                <li><strong>Government ID:</strong> A scanned or well-lit smartphone photo of your Kenyan National ID (front &amp; back) or Passport.</li>
                <li><strong>Legibility:</strong> Ensure your full name, ID number, and photo are completely legible without flash glare or blur.</li>
                <li><strong>Business Merchants:</strong> If you are selling agricultural produce or packaged goods in Kirinyaga County, upload your single business permit to receive the "Verified Merchant" badge.</li>
                <li><strong>Fast Approval:</strong> Documents are reviewed by platform administrators within 24 hours.</li>
            </ul>
        </div>
    </div>

    <!-- Submitted Documents History -->
    <div class="card" style="margin-top: 2rem;">
        <div class="section-heading" style="margin-bottom: 1rem;">
            <h2>Submitted Documents History</h2>
            <span class="muted"><?= count($documents) ?> document(s) on file</span>
        </div>

        <?php if (empty($documents)): ?>
            <div style="text-align: center; padding: 2rem; color: #64748b;">
                <p>No documents uploaded yet. Use the form above to submit your credentials.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table" style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="border-bottom: 2px solid #e2e8f0; text-align: left;">
                            <th style="padding: 0.75rem;">Document Type</th>
                            <th style="padding: 0.75rem;">File Preview</th>
                            <th style="padding: 0.75rem;">Uploaded Date</th>
                            <th style="padding: 0.75rem;">Review Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($documents as $doc): ?>
                            <tr style="border-bottom: 1px solid #edf2f7;">
                                <td style="padding: 0.75rem; font-weight: 600;">
                                    <?php
                                    $typeLabels = [
                                        'national_id' => 'National ID / Passport',
                                        'business_registration' => 'Business Permit / Reg',
                                        'other' => 'Tax / Other Credential',
                                    ];
                                    echo e($typeLabels[$doc['doc_type']] ?? $doc['doc_type']);
                                    ?>
                                </td>
                                <td style="padding: 0.75rem;">
                                    <a href="<?= e(url($doc['file_path'])) ?>" target="_blank" class="btn btn-outline btn-sm" style="font-size: 0.8rem;">
                                        &boxbox; View File
                                    </a>
                                </td>
                                <td style="padding: 0.75rem; font-size: 0.85rem;" class="muted">
                                    <?= e(date('M d, Y H:i', strtotime($doc['uploaded_at']))) ?>
                                </td>
                                <td style="padding: 0.75rem;">
                                    <?php if ($doc['review_status'] === 'accepted'): ?>
                                        <span class="badge badge-success">Accepted &check;</span>
                                    <?php elseif ($doc['review_status'] === 'rejected'): ?>
                                        <span class="badge badge-danger">Rejected &cross;</span>
                                    <?php else: ?>
                                        <span class="badge badge-amber">Under Review &bull;</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
