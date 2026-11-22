<?php
/**
 * SokoHub Admin KYC Verification Queue (Sellers & Delivery Riders)
 * Allows administrators to inspect uploaded National IDs, Business Permits, and Licenses
 * with an interactive document previewer, cross-checking ID numbers and M-Pesa lines.
 * Authored by Kennedy Kalawa.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/kyc.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/security.php';

init_session();
$adminUser = require_role(['admin']);
$adminId = (int) $adminUser['id'];

$typeFilter   = trim((string) ($_GET['type'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? ''));

// Handle Admin Action (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Security token mismatch. Please try again.');
        redirect('pages/dashboard/seller_verification.php');
    }

    $targetUserId = (int) ($_POST['user_id'] ?? 0);
    $targetRole   = (string) ($_POST['role'] ?? 'seller');
    $action       = (string) ($_POST['action'] ?? '');
    $reason       = trim((string) ($_POST['rejection_reason'] ?? ''));

    if ($targetUserId > 0 && in_array($targetRole, ['seller', 'delivery'], true)) {
        if ($action === 'approve') {
            Kyc::updateStatus($targetUserId, $targetRole, 'approved', $adminId);
            set_flash('success', "Account #{$targetUserId} ({$targetRole}) approved! They are now verified to operate on SokoHub.");
        } elseif ($action === 'reject') {
            if ($reason === '') {
                $reason = 'Documents submitted did not meet verification criteria or were unreadable.';
            }
            Kyc::updateStatus($targetUserId, $targetRole, 'rejected', $adminId, $reason);
            set_flash('info', "Account #{$targetUserId} rejected. Rejection reason was recorded.");
        } elseif ($action === 'suspend') {
            Kyc::updateStatus($targetUserId, $targetRole, 'suspended', $adminId, $reason ?: 'Suspended by administration.');
            set_flash('warning', "Account #{$targetUserId} has been suspended.");
        }
    }

    $redirectUrl = 'pages/dashboard/seller_verification.php?' . http_build_query([
        'type'   => $typeFilter,
        'status' => $statusFilter,
    ]);
    redirect($redirectUrl);
}

$accounts = Kyc::getAdminQueue($typeFilter, $statusFilter);

// Statistics
$pdo = get_db_connection();
Kyc::ensureSchema();
$totalPendingSellers = (int) $pdo->query("SELECT COUNT(*) FROM seller_profiles WHERE verification_status = 'pending'")->fetchColumn();
$totalPendingRiders  = (int) $pdo->query("SELECT COUNT(*) FROM delivery_profiles WHERE verification_status = 'pending'")->fetchColumn();
$totalPending = $totalPendingSellers + $totalPendingRiders;

$totalApproved = (int) $pdo->query("
    SELECT (SELECT COUNT(*) FROM seller_profiles WHERE verification_status = 'approved') +
           (SELECT COUNT(*) FROM delivery_profiles WHERE verification_status = 'approved')
")->fetchColumn();

$totalRejected = (int) $pdo->query("
    SELECT (SELECT COUNT(*) FROM seller_profiles WHERE verification_status = 'rejected') +
           (SELECT COUNT(*) FROM delivery_profiles WHERE verification_status = 'rejected')
")->fetchColumn();

$pageTitle = 'SokoHub | KYC & Compliance Queue';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="container section-space">
    <div class="page-head">
        <div>
            <h1><?= icon('shield-alert', 'lucide-md', 'amber') ?> <span>Merchant &amp; Delivery KYC Verification Queue</span></h1>
            <p class="muted">Review uploaded National IDs, Licenses, and Safaricom M-Pesa lines before granting marketplace selling or dispatch privileges.</p>
        </div>
        <div class="header-actions">
            <a href="<?= e(url('pages/dashboard/dashboard.php')) ?>" class="btn btn-outline">
                <?= icon('arrow-left', 'lucide-xs') ?>
                <span>Back to Dashboard</span>
            </a>
        </div>
    </div>

    <!-- Metrics Cards -->
    <div class="stats-grid" style="margin-bottom: 2rem;">
        <div class="stat-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
                <span class="stat-label">Pending Reviews</span>
                <span class="stat-icon-wrap stat-icon-amber"><?= icon('clock', 'lucide-xs') ?></span>
            </div>
            <strong class="stat-value" style="color: #b45309;"><?= number_format($totalPending) ?></strong>
            <span class="badge badge-amber"><?= $totalPendingSellers ?> Sellers, <?= $totalPendingRiders ?> Riders</span>
        </div>
        <div class="stat-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
                <span class="stat-label">Verified Accounts</span>
                <span class="stat-icon-wrap stat-icon-emerald"><?= icon('shield-check', 'lucide-xs') ?></span>
            </div>
            <strong class="stat-value" style="color: #10b981;"><?= number_format($totalApproved) ?></strong>
            <span class="badge badge-success">Approved to Operate</span>
        </div>
        <div class="stat-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
                <span class="stat-label">Rejected / Needs Resubmit</span>
                <span class="stat-icon-wrap stat-icon-rose"><?= icon('alert-triangle', 'lucide-xs') ?></span>
            </div>
            <strong class="stat-value" style="color: #ef4444;"><?= number_format($totalRejected) ?></strong>
            <span class="badge badge-danger">Action Required</span>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="card" style="margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
            <a href="<?= e(url('pages/dashboard/seller_verification.php')) ?>" class="btn <?= ($typeFilter === '' && $statusFilter === '') ? 'btn-primary' : 'btn-outline' ?> btn-sm">All Accounts</a>
            <a href="<?= e(url('pages/dashboard/seller_verification.php?status=pending')) ?>" class="btn <?= $statusFilter === 'pending' ? 'btn-amber' : 'btn-outline' ?> btn-sm">
                Pending Verification (<?= $totalPending ?>)
            </a>
            <a href="<?= e(url('pages/dashboard/seller_verification.php?type=seller')) ?>" class="btn <?= $typeFilter === 'seller' ? 'btn-primary' : 'btn-outline' ?> btn-sm">Sellers Only</a>
            <a href="<?= e(url('pages/dashboard/seller_verification.php?type=delivery')) ?>" class="btn <?= $typeFilter === 'delivery' ? 'btn-primary' : 'btn-outline' ?> btn-sm">Riders Only</a>
            <a href="<?= e(url('pages/dashboard/seller_verification.php?status=approved')) ?>" class="btn <?= $statusFilter === 'approved' ? 'btn-primary' : 'btn-outline' ?> btn-sm">Approved (<?= $totalApproved ?>)</a>
        </div>

        <span class="muted" style="font-size: 0.9rem;">Showing <?= count($accounts) ?> account(s)</span>
    </div>

    <!-- Queue Table -->
    <div class="card">
        <?php if (empty($accounts)): ?>
            <div style="text-align: center; padding: 3rem 1rem; color: #64748b;">
                <p>No merchant or rider profiles found matching the selected filter.</p>
                <a href="<?= e(url('pages/dashboard/seller_verification.php')) ?>" class="btn btn-outline btn-sm">View All Profiles</a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table" style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="border-bottom: 2px solid #e2e8f0; text-align: left;">
                            <th style="padding: 0.75rem;">Account / Role</th>
                            <th style="padding: 0.75rem;">KYC Credentials (ID &amp; M-Pesa)</th>
                            <th style="padding: 0.75rem;">Location / Town</th>
                            <th style="padding: 0.75rem;">Uploaded Verification Files</th>
                            <th style="padding: 0.75rem;">Status</th>
                            <th style="padding: 0.75rem; text-align: right;">Review Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($accounts as $acc): 
                            $userId = (int) $acc['user_id'];
                            $role   = (string) $acc['account_type'];
                            $status = $acc['verification_status'] ?? 'pending';
                            $docs   = Kyc::getUserDocuments($userId);
                        ?>
                            <tr style="border-bottom: 1px solid #edf2f7;">
                                <td style="padding: 0.75rem;">
                                    <strong style="display: block; font-size: 1rem; color: var(--color-primary);">
                                        <?= e($acc['business_name']) ?>
                                    </strong>
                                    <div style="font-size: 0.85rem; color: #475569;">
                                        Owner: <strong><?= e($acc['owner_name']) ?></strong>
                                    </div>
                                    <div style="margin-top: 0.25rem;">
                                        <span class="badge <?= $role === 'delivery' ? 'badge-primary' : 'badge-amber' ?>" style="font-size: 0.7rem; text-transform: uppercase;">
                                            <?= $role === 'delivery' ? 'Delivery Rider' : 'Merchant / Seller' ?>
                                        </span>
                                        <span class="muted" style="font-size: 0.75rem; margin-left: 0.35rem;">
                                            Joined <?= e(date('M d, Y', strtotime($acc['registered_at']))) ?>
                                        </span>
                                    </div>
                                </td>

                                <td style="padding: 0.75rem; font-size: 0.85rem;">
                                    <div>
                                        <span class="muted">National ID:</span> 
                                        <?php if (!empty($acc['national_id_no'])): ?>
                                            <strong style="color: #0f172a; font-family: monospace; font-size: 0.95rem;"><?= e(Security::maskNationalId($acc['national_id_no'])) ?></strong>
                                        <?php else: ?>
                                            <span style="color: #ef4444; font-weight: 600;">Not Provided</span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="margin-top: 0.25rem;">
                                        <span class="muted">M-Pesa Line:</span> 
                                        <?php if (!empty($acc['mpesa_phone'])): ?>
                                            <strong style="color: #10b981; font-family: monospace; font-size: 0.95rem;"><?= e(Security::maskPhone($acc['mpesa_phone'])) ?></strong>
                                        <?php else: ?>
                                            <span style="color: #ef4444; font-weight: 600;">Not Provided</span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="margin-top: 0.2rem; font-size: 0.75rem;" class="muted">
                                        Email: <?= e($acc['email']) ?>
                                    </div>
                                    <?php if ($role === 'delivery'): ?>
                                        <div style="margin-top: 0.35rem; padding: 0.25rem 0.5rem; background: var(--info-bg); border: 1px solid rgba(2, 132, 199, 0.25); border-radius: 6px; font-size: 0.78rem;">
                                            <span style="color: var(--info); font-weight: 700;">Vehicle:</span> 
                                            <strong><?= e(ucfirst(str_replace('_', ' ', $acc['business_type'] ?? 'motorbike'))) ?></strong>
                                            <?php if (!empty($acc['vehicle_plate_number'])): ?>
                                                &bull; Plate: <strong style="font-family: monospace; color: var(--color-primary);"><?= e($acc['vehicle_plate_number']) ?></strong>
                                            <?php else: ?>
                                                &bull; <span style="color: var(--danger); font-weight: 700;">No Plate Registered</span>
                                            <?php endif; ?>
                                            <?php if (!empty($acc['vehicle_model'])): ?>
                                                <span class="muted">(<?= e($acc['vehicle_model']) ?>)</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td style="padding: 0.75rem; font-size: 0.9rem;">
                                    <strong><?= e($acc['town']) ?></strong>
                                </td>

                                <!-- Uploaded Files Column with Detail Cards -->
                                <td style="padding: 0.75rem;">
                                    <?php if (empty($docs)): ?>
                                        <span style="color: #ef4444; font-size: 0.85rem; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;"><?= icon('alert-circle', 'lucide-xs') ?> <span>Zero documents uploaded</span></span>
                                    <?php else: ?>
                                        <div style="display: flex; flex-direction: column; gap: 0.4rem;">
                                            <?php foreach ($docs as $d): 
                                                $sizeKB = $d['file_size'] ? round($d['file_size'] / 1024, 1) . ' KB' : '';
                                                $isPdf = strtolower(pathinfo($d['file_path'], PATHINFO_EXTENSION)) === 'pdf';
                                                $typeLabel = ucwords(str_replace('_', ' ', $d['doc_type']));
                                                $viewUrl = url('pages/api/kyc_document.php?id=' . (int) $d['id']);
                                            ?>
                                                <div style="border: 1px solid #e2e8f0; border-radius: 6px; padding: 0.35rem 0.5rem; background: #f8fafc; font-size: 0.8rem;">
                                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.2rem;">
                                                        <strong>&bull; <?= e($typeLabel) ?></strong>
                                                        <span class="badge <?= $d['review_status'] === 'accepted' ? 'badge-success' : ($d['review_status'] === 'rejected' ? 'badge-danger' : 'badge-amber') ?>" style="font-size: 0.65rem; padding: 0.1rem 0.3rem;">
                                                            <?= e($d['review_status']) ?>
                                                        </span>
                                                    </div>
                                                    <div style="font-size: 0.75rem; color: #64748b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 220px;">
                                                        File: <?= e($d['file_name'] ?: basename($d['file_path'])) ?> (<?= e($sizeKB) ?>)
                                                    </div>
                                                    <div style="margin-top: 0.25rem;">
                                                        <a href="<?= e($viewUrl) ?>" target="_blank" class="btn btn-outline btn-sm" style="font-size: 0.7rem; padding: 0.15rem 0.4rem; display: inline-flex; align-items: center; gap: 0.25rem;">
                                                            <?= icon('eye', 'lucide-xs', 'blue') ?>
                                                            <span>Inspect <?= $isPdf ? 'PDF' : 'Photo' ?></span>
                                                        </a>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <!-- Status Column -->
                                <td style="padding: 0.75rem;">
                                    <?php if ($status === 'approved'): ?>
                                        <span class="badge badge-success" style="font-size: 0.85rem;">
                                            <?= icon('check-circle', 'lucide-xs') ?>
                                            <span>Approved</span>
                                        </span>
                                        <?php if ($acc['verified_at']): ?>
                                            <div style="font-size: 0.7rem; color: #64748b; margin-top: 0.25rem;">
                                                Verified by <?= e($acc['reviewer_name'] ?: 'Admin') ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php elseif ($status === 'rejected'): ?>
                                        <span class="badge badge-danger" style="font-size: 0.85rem;">
                                            <?= icon('x-circle', 'lucide-xs') ?>
                                            <span>Rejected</span>
                                        </span>
                                        <?php if (!empty($acc['rejection_reason'])): ?>
                                            <div style="font-size: 0.75rem; color: #ef4444; margin-top: 0.25rem; max-width: 180px;">
                                                <?= e($acc['rejection_reason']) ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php elseif ($status === 'suspended'): ?>
                                        <span class="badge badge-danger" style="font-size: 0.85rem;">
                                            <?= icon('alert-circle', 'lucide-xs') ?>
                                            <span>Suspended</span>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-amber" style="font-size: 0.85rem;">
                                            <?= icon('clock', 'lucide-xs') ?>
                                            <span>Pending Review</span>
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- Actions Column -->
                                <td style="padding: 0.75rem; text-align: right;">
                                    <div style="display: flex; justify-content: flex-end; gap: 0.4rem; flex-wrap: wrap;">
                                        <?php if ($status !== 'approved'): ?>
                                            <form method="POST" action="<?= e(url('pages/dashboard/seller_verification.php')) ?>" style="margin: 0;">
                                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="user_id" value="<?= $userId ?>">
                                                <input type="hidden" name="role" value="<?= e($role) ?>">
                                                <input type="hidden" name="action" value="approve">
                                                <button type="submit" class="btn btn-primary btn-sm" onclick="return confirm('Approve this <?= $role ?> account to start operations?');">
                                                    <?= icon('check', 'lucide-xs') ?>
                                                    <span>Approve</span>
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if ($status !== 'rejected'): ?>
                                            <details style="position: relative; display: inline-block;">
                                                <summary class="btn btn-outline btn-sm" style="color: #ef4444; border-color: #ef4444; font-size: 0.75rem; cursor: pointer;">
                                                    <?= icon('x', 'lucide-xs', 'rose') ?>
                                                    <span>Reject...</span>
                                                </summary>
                                                <div style="position: absolute; right: 0; z-index: 100; background: white; border: 1px solid #cbd5e1; border-radius: 8px; padding: 1rem; box-shadow: 0 10px 25px rgba(0,0,0,0.15); width: 280px; text-align: left;">
                                                    <form method="POST" action="<?= e(url('pages/dashboard/seller_verification.php')) ?>">
                                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                        <input type="hidden" name="user_id" value="<?= $userId ?>">
                                                        <input type="hidden" name="role" value="<?= e($role) ?>">
                                                        <input type="hidden" name="action" value="reject">
                                                        
                                                        <label class="form-label" style="font-size: 0.8rem; font-weight: 600;">Specify Rejection Reason:</label>
                                                        <textarea name="rejection_reason" class="form-control" rows="2" placeholder="e.g. Blurry ID, expired permit, ID does not match name" style="font-size: 0.85rem; margin-bottom: 0.75rem;" required></textarea>

                                                        <button type="submit" class="btn btn-primary btn-sm" style="background: #ef4444; width: 100%;">
                                                            <?= icon('x-circle', 'lucide-xs') ?>
                                                            <span>Confirm Rejection</span>
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
