<?php
/**
 * SokoHub Admin Seller Verification Queue
 * Administrative interface to review merchant credentials, inspect KYC documents, and approve/reject sellers.
 * Authored by Kennedy Kalawa.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/kyc.php';
require_once __DIR__ . '/../../includes/audit.php';

init_session();
$adminUser = require_role(['admin']);
$adminId = (int) $adminUser['id'];

$statusFilter = trim((string) ($_GET['status'] ?? ''));

// Handle Admin Action (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Security token mismatch. Please try again.');
        redirect('pages/dashboard/seller_verification.php');
    }

    $targetSellerId = (int) ($_POST['seller_user_id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');
    $reason = trim((string) ($_POST['rejection_reason'] ?? ''));

    if ($targetSellerId > 0) {
        if ($action === 'approve') {
            Kyc::updateSellerStatus($targetSellerId, 'approved', $adminId);
            set_flash('success', "Seller #{$targetSellerId} approved successfully! Their store is now verified.");
        } elseif ($action === 'reject') {
            if ($reason === '') {
                $reason = 'Documents submitted did not meet verification criteria.';
            }
            Kyc::updateSellerStatus($targetSellerId, 'rejected', $adminId, $reason);
            set_flash('info', "Seller #{$targetSellerId} marked as rejected with reason.");
        } elseif ($action === 'suspend') {
            Kyc::updateSellerStatus($targetSellerId, 'suspended', $adminId, $reason ?: 'Suspended by administration.');
            set_flash('warning', "Seller #{$targetSellerId} has been suspended.");
        }
    }

    redirect('pages/dashboard/seller_verification.php' . ($statusFilter !== '' ? '?status=' . urlencode($statusFilter) : ''));
}

$sellers = Kyc::getAdminSellerQueue($statusFilter);

// Statistics
$pdo = get_db_connection();
Kyc::ensureSchema();
$totalPending  = (int) $pdo->query("SELECT COUNT(*) FROM seller_profiles WHERE verification_status = 'pending'")->fetchColumn();
$totalApproved = (int) $pdo->query("SELECT COUNT(*) FROM seller_profiles WHERE verification_status = 'approved'")->fetchColumn();
$totalRejected = (int) $pdo->query("SELECT COUNT(*) FROM seller_profiles WHERE verification_status = 'rejected'")->fetchColumn();

$pageTitle = 'SokoHub | Seller Verification Queue';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="container section-space">
    <div class="page-head">
        <div>
            <h1>Seller KYC &amp; Verification Queue</h1>
            <p class="muted">Review uploaded merchant credentials, audit business permits, and grant marketplace selling privileges.</p>
        </div>
        <div class="header-actions">
            <a href="<?= e(url('pages/dashboard/dashboard.php')) ?>" class="btn btn-outline">&larr; Back to Dashboard</a>
        </div>
    </div>

    <!-- Metrics Cards -->
    <div class="stats-grid" style="margin-bottom: 2rem;">
        <div class="stat-card">
            <span class="stat-label">Pending Verification</span>
            <strong class="stat-value" style="color: #b45309;"><?= number_format($totalPending) ?></strong>
            <span class="badge badge-amber">Action Required</span>
        </div>
        <div class="stat-card">
            <span class="stat-label">Approved Merchants</span>
            <strong class="stat-value" style="color: #10b981;"><?= number_format($totalApproved) ?></strong>
            <span class="badge badge-success">Active Stores</span>
        </div>
        <div class="stat-card">
            <span class="stat-label">Rejected Applications</span>
            <strong class="stat-value" style="color: #ef4444;"><?= number_format($totalRejected) ?></strong>
            <span class="badge badge-danger">Needs Resubmission</span>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="card" style="margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
            <a href="<?= e(url('pages/dashboard/seller_verification.php')) ?>" class="btn <?= $statusFilter === '' ? 'btn-primary' : 'btn-outline' ?> btn-sm">All Sellers</a>
            <a href="<?= e(url('pages/dashboard/seller_verification.php?status=pending')) ?>" class="btn <?= $statusFilter === 'pending' ? 'btn-amber' : 'btn-outline' ?> btn-sm">
                Pending (<?= $totalPending ?>)
            </a>
            <a href="<?= e(url('pages/dashboard/seller_verification.php?status=approved')) ?>" class="btn <?= $statusFilter === 'approved' ? 'btn-primary' : 'btn-outline' ?> btn-sm">
                Approved (<?= $totalApproved ?>)
            </a>
            <a href="<?= e(url('pages/dashboard/seller_verification.php?status=rejected')) ?>" class="btn <?= $statusFilter === 'rejected' ? 'btn-outline' : 'btn-outline' ?> btn-sm">
                Rejected (<?= $totalRejected ?>)
            </a>
        </div>

        <span class="muted" style="font-size: 0.9rem;">Showing <?= count($sellers) ?> merchant profile(s)</span>
    </div>

    <!-- Queue Table -->
    <div class="card">
        <?php if (empty($sellers)): ?>
            <div style="text-align: center; padding: 3rem 1rem; color: #64748b;">
                <p>No seller profiles found for the selected filter.</p>
                <a href="<?= e(url('pages/dashboard/seller_verification.php')) ?>" class="btn btn-outline btn-sm">View All Sellers</a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table" style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="border-bottom: 2px solid #e2e8f0; text-align: left;">
                            <th style="padding: 0.75rem;">Merchant / Business</th>
                            <th style="padding: 0.75rem;">Contact Info</th>
                            <th style="padding: 0.75rem;">Town / County</th>
                            <th style="padding: 0.75rem;">Submitted Documents</th>
                            <th style="padding: 0.75rem;">Status</th>
                            <th style="padding: 0.75rem; text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sellers as $s): 
                            $docs = Kyc::getSellerDocuments((int) $s['user_id']);
                            $status = $s['verification_status'] ?? 'pending';
                        ?>
                            <tr style="border-bottom: 1px solid #edf2f7;">
                                <td style="padding: 0.75rem;">
                                    <strong style="display: block; font-size: 1rem; color: var(--color-primary);"><?= e($s['business_name']) ?></strong>
                                    <div style="font-size: 0.85rem; color: #64748b;">Owner: <?= e($s['seller_name']) ?></div>
                                    <small class="muted">Registered: <?= e(date('M d, Y', strtotime($s['registered_at']))) ?></small>
                                </td>
                                <td style="padding: 0.75rem; font-size: 0.85rem;">
                                    <div><?= e($s['seller_email']) ?></div>
                                    <div class="muted"><?= e($s['seller_phone']) ?></div>
                                </td>
                                <td style="padding: 0.75rem; font-size: 0.9rem;">
                                    <?= e($s['town']) ?>
                                    <div style="font-size: 0.75rem;" class="muted"><?= e(ucfirst($s['business_type'] ?? 'retail')) ?></div>
                                </td>
                                <td style="padding: 0.75rem;">
                                    <?php if (empty($docs)): ?>
                                        <span class="muted" style="font-size: 0.85rem; font-style: italic;">No docs uploaded</span>
                                    <?php else: ?>
                                        <div style="display: flex; flex-direction: column; gap: 0.35rem;">
                                            <?php foreach ($docs as $d): ?>
                                                <a href="<?= e(url($d['file_path'])) ?>" target="_blank" class="btn btn-outline btn-sm" style="font-size: 0.75rem; padding: 0.2rem 0.5rem; display: inline-flex; align-items: center; gap: 0.25rem;">
                                                    &boxbox; <?= e(ucwords(str_replace('_', ' ', $d['doc_type']))) ?>
                                                    <span class="badge <?= $d['review_status'] === 'accepted' ? 'badge-success' : ($d['review_status'] === 'rejected' ? 'badge-danger' : 'badge-amber') ?>" style="font-size: 0.65rem; padding: 0.1rem 0.3rem;">
                                                        <?= e($d['review_status']) ?>
                                                    </span>
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 0.75rem;">
                                    <?php if ($status === 'approved'): ?>
                                        <span class="badge badge-success">Approved &check;</span>
                                        <?php if ($s['verified_at']): ?>
                                            <div style="font-size: 0.7rem; color: #64748b; margin-top: 0.25rem;">
                                                By <?= e($s['reviewer_name'] ?: 'Admin') ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php elseif ($status === 'rejected'): ?>
                                        <span class="badge badge-danger">Rejected &cross;</span>
                                        <?php if (!empty($s['rejection_reason'])): ?>
                                            <div style="font-size: 0.75rem; color: #ef4444; margin-top: 0.25rem; max-width: 180px;">
                                                <?= e($s['rejection_reason']) ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php elseif ($status === 'suspended'): ?>
                                        <span class="badge badge-danger">Suspended</span>
                                    <?php else: ?>
                                        <span class="badge badge-amber">Pending Review</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 0.75rem; text-align: right;">
                                    <div style="display: flex; justify-content: flex-end; gap: 0.5rem; flex-wrap: wrap;">
                                        <?php if ($status !== 'approved'): ?>
                                            <form method="POST" action="<?= e(url('pages/dashboard/seller_verification.php')) ?>" style="margin: 0;">
                                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="seller_user_id" value="<?= (int) $s['user_id'] ?>">
                                                <input type="hidden" name="action" value="approve">
                                                <button type="submit" class="btn btn-primary btn-sm" onclick="return confirm('Approve this seller to start publishing live products?');">
                                                    &check; Approve
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if ($status !== 'rejected'): ?>
                                            <details style="position: relative; display: inline-block;">
                                                <summary class="btn btn-outline btn-sm" style="color: #ef4444; border-color: #ef4444; font-size: 0.8rem; cursor: pointer;">
                                                    Reject...
                                                </summary>
                                                <div style="position: absolute; right: 0; z-index: 100; background: white; border: 1px solid #cbd5e1; border-radius: 8px; padding: 1rem; box-shadow: 0 10px 25px rgba(0,0,0,0.15); width: 280px; text-align: left;">
                                                    <form method="POST" action="<?= e(url('pages/dashboard/seller_verification.php')) ?>">
                                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                        <input type="hidden" name="seller_user_id" value="<?= (int) $s['user_id'] ?>">
                                                        <input type="hidden" name="action" value="reject">
                                                        
                                                        <label class="form-label" style="font-size: 0.8rem; font-weight: 600;">Rejection Reason:</label>
                                                        <textarea name="rejection_reason" class="form-control" rows="2" placeholder="e.g. Blurry ID, expired permit" style="font-size: 0.85rem; margin-bottom: 0.75rem;" required></textarea>

                                                        <button type="submit" class="btn btn-primary btn-sm" style="background: #ef4444; width: 100%;">
                                                            Confirm Rejection
                                                        </button>
                                                    </form>
                                                </div>
                                            </details>
                                        <?php endif; ?>
                                    </div>
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
