<?php
/**
 * SokoHub Bulk Product Management & CSV Upload Engine
 * 1. Interactive Spreadsheet Grid (Direct multi-product creation in the browser)
 * 2. Bulk Product Spreadsheet Editor (Update existing catalog in a grid)
 * 3. CSV File Upload Engine (Upload spreadsheets with automated validation)
 * Authored by Kennedy Kalawa.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/bulk_import.php';
require_once __DIR__ . '/../../includes/kyc.php';

init_session();
$user = require_role(['seller', 'admin']);
$role = $user['role'];
$isAdmin = ($role === 'admin');
$userId = (int) $user['id'];

BulkImport::ensureSchema();
$pdo = get_db_connection();
$categories = $pdo->query('SELECT id, name FROM categories ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);

// ---------------------------------------------------------
// 1. DOWNLOAD SAMPLE CSV TEMPLATE
// ---------------------------------------------------------
if (($_GET['action'] ?? '') === 'download_template') {
    $csvContent = BulkImport::generateSampleCsv();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="sokohub_bulk_products_template.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo $csvContent;
    exit;
}

// ---------------------------------------------------------
// 2. DETAILED IMPORT INSPECTION VIEW
// ---------------------------------------------------------
$viewAction = (string) ($_GET['action'] ?? '');
if ($viewAction === 'view' && !empty($_GET['id'])) {
    $importId = (int) $_GET['id'];
    $import = BulkImport::getImportDetails($importId);

    if (!$import) {
        set_flash('error', 'Bulk import job not found.');
        redirect('pages/dashboard/bulk_upload.php');
    }

    // Security check: sellers can only inspect their own imports
    if (!$isAdmin && (int) $import['seller_user_id'] !== $userId) {
        set_flash('error', 'You do not have permission to view this import job.');
        redirect('pages/dashboard/bulk_upload.php');
    }

    $pageTitle = 'SokoHub | Bulk Import Job #IMP-' . str_pad($import['id'], 4, '0', STR_PAD_LEFT);
    require_once __DIR__ . '/../../includes/header.php';
    ?>
    <main class="container section-space">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
            <div>
                <a href="<?= e(url('pages/dashboard/bulk_upload.php')) ?>" class="btn btn-outline btn-sm">&larr; Back to Bulk Operations</a>
            </div>
            <div style="display: flex; gap: 0.5rem;">
                <a href="<?= e(url('pages/dashboard/manage_products.php')) ?>" class="btn btn-primary btn-sm">View Active Catalog &rarr;</a>
            </div>
        </div>

        <!-- Import Summary Header Card -->
        <div class="card" style="padding: 1.75rem; margin-bottom: 2rem; border-left: 6px solid <?= $import['status'] === 'completed' ? '#10b981' : '#ef4444' ?>;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
                <div>
                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <span style="font-size: 1.4rem;">📦</span>
                        <h2 style="font-size: 1.4rem; color: #1e293b; margin: 0;">
                            Batch Job #IMP-<?= str_pad($import['id'], 4, '0', STR_PAD_LEFT) ?>
                        </h2>
                    </div>
                    <p style="color: #64748b; font-size: 0.9rem; margin: 0.25rem 0 0 0;">
                        Source: <strong><?= e($import['original_filename']) ?></strong> &bull; Processed on <?= e(date('d M Y, H:i', strtotime($import['created_at']))) ?>
                    </p>
                    <?php if ($isAdmin): ?>
                        <div style="font-size: 0.85rem; color: #4338ca; margin-top: 0.25rem;">
                            Merchant Store: <strong><?= e($import['business_name']) ?></strong> (<?= e($import['seller_name']) ?>)
                        </div>
                    <?php endif; ?>
                </div>

                <div style="text-align: right;">
                    <?php if ($import['status'] === 'completed'): ?>
                        <span class="badge badge-success" style="font-size: 0.85rem; padding: 0.35rem 0.75rem;">Completed &check;</span>
                    <?php elseif ($import['status'] === 'failed'): ?>
                        <span class="badge badge-danger" style="font-size: 0.85rem; padding: 0.35rem 0.75rem;">Failed &cross;</span>
                    <?php else: ?>
                        <span class="badge badge-info" style="font-size: 0.85rem; padding: 0.35rem 0.75rem;"><?= e(ucfirst($import['status'])) ?></span>
                    <?php endif; ?>
                    <div style="font-size: 0.75rem; color: #64748b; margin-top: 0.35rem;">
                        Finished: <?= !empty($import['finished_at']) ? e(date('d M Y, H:i:s', strtotime($import['finished_at']))) : 'In Progress' ?>
                    </div>
                </div>
            </div>

            <!-- Metric Badges Row -->
            <div style="display: flex; gap: 1.5rem; margin-top: 1.5rem; flex-wrap: wrap; border-top: 1px solid #e2e8f0; padding-top: 1.25rem;">
                <div>
                    <div style="font-size: 0.75rem; text-transform: uppercase; color: #64748b; font-weight: 700;">Total Rows</div>
                    <div style="font-size: 1.5rem; font-weight: 800; color: #1e293b;"><?= (int) $import['total_rows'] ?></div>
                </div>
                <div>
                    <div style="font-size: 0.75rem; text-transform: uppercase; color: #16a34a; font-weight: 700;">Successfully Created</div>
                    <div style="font-size: 1.5rem; font-weight: 800; color: #16a34a;"><?= (int) $import['success_rows'] ?></div>
                </div>
                <div>
                    <div style="font-size: 0.75rem; text-transform: uppercase; color: #ef4444; font-weight: 700;">Failed Rows</div>
                    <div style="font-size: 1.5rem; font-weight: 800; color: #ef4444;"><?= (int) $import['error_rows'] ?></div>
                </div>
            </div>
        </div>

        <!-- Row-by-Row Breakdown Table -->
        <div class="card" style="padding: 1.5rem; overflow-x: auto;">
            <h3 style="font-size: 1.15rem; color: #1e293b; margin-bottom: 1rem;">
                Row Processing Breakdown (<?= count($import['rows'] ?? []) ?> Rows)
            </h3>

            <?php if (empty($import['rows'])): ?>
                <p style="color: #64748b; font-size: 0.9rem;">No rows were recorded for this import.</p>
            <?php else: ?>
                <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
                    <thead>
                        <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0; text-align: left;">
                            <th style="padding: 0.65rem; width: 60px;">Row #</th>
                            <th style="padding: 0.65rem;">Product Name</th>
                            <th style="padding: 0.65rem;">Category</th>
                            <th style="padding: 0.65rem; text-align: right;">Price</th>
                            <th style="padding: 0.65rem; text-align: center;">Stock</th>
                            <th style="padding: 0.65rem; text-align: center;">Status</th>
                            <th style="padding: 0.65rem;">Details &amp; Error Messages</th>
                            <th style="padding: 0.65rem; text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($import['rows'] as $r): 
                            $raw = json_decode($r['raw_data'], true) ?: [];
                            $isSuccess = ($r['status'] === 'created');
                        ?>
                            <tr style="border-bottom: 1px solid #e2e8f0; background: <?= $isSuccess ? 'inherit' : '#fff5f5' ?>;">
                                <td style="padding: 0.65rem; font-family: monospace; font-weight: 700; color: #64748b;">
                                    <?= (int) $r['row_no'] ?>
                                </td>
                                <td style="padding: 0.65rem; font-weight: 600; color: #1e293b;">
                                    <?= e($raw['name'] ?? ($r['product_name'] ?? 'N/A')) ?>
                                </td>
                                <td style="padding: 0.65rem; color: #475569;">
                                    <?= e($raw['category'] ?? ($raw['category_id'] ?? 'N/A')) ?>
                                </td>
                                <td style="padding: 0.65rem; text-align: right; color: #1e293b;">
                                    <?= isset($raw['price']) && $raw['price'] !== '' ? 'KES ' . number_format((float) $raw['price'], 2) : '-' ?>
                                </td>
                                <td style="padding: 0.65rem; text-align: center; color: #475569;">
                                    <?= e($raw['stock_qty'] ?? '10') ?>
                                </td>
                                <td style="padding: 0.65rem; text-align: center;">
                                    <?php if ($isSuccess): ?>
                                        <span class="badge badge-success" style="font-size: 0.75rem;">Created &check;</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger" style="font-size: 0.75rem;">Invalid &cross;</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 0.65rem;">
                                    <?php if ($isSuccess): ?>
                                        <span style="color: #16a34a; font-size: 0.8rem;">
                                            Published &bull; Product ID #<?= (int) $r['product_id'] ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #dc2626; font-size: 0.8rem; font-weight: 500;">
                                            ⚠️ <?= e($r['error_message']) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 0.65rem; text-align: right;">
                                    <?php if ($isSuccess && !empty($r['product_id'])): ?>
                                        <a href="<?= e(url('pages/dashboard/manage_products.php?edit=' . (int) $r['product_id'])) ?>" class="btn btn-outline btn-sm" style="font-size: 0.75rem; padding: 0.2rem 0.5rem;" target="_blank">
                                            Edit &rarr;
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </main>
    <?php
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

// ---------------------------------------------------------
// 3. POST HANDLERS
// ---------------------------------------------------------
$isVerifiedSeller = Kyc::isSellerApproved($userId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Security token mismatch. Please try again.');
        redirect('pages/dashboard/bulk_upload.php');
    }

    if (!$isAdmin && !$isVerifiedSeller) {
        set_flash('error', 'KYC Compliance Required: You must complete verification and obtain admin approval before uploading products.');
        redirect('pages/dashboard/bulk_upload.php');
    }

    $action = (string) ($_POST['action'] ?? '');

    // Action A: CSV File Upload
    if ($action === 'upload_csv') {
        $targetSellerId = $userId;
        if ($isAdmin && !empty($_POST['target_seller_id'])) {
            $targetSellerId = (int) $_POST['target_seller_id'];
        }

        $uploadedFile = $_FILES['csv_file'] ?? [];
        $result = BulkImport::uploadAndProcessCsv($targetSellerId, $uploadedFile, $userId, $role);

        if ($result['ok']) {
            $msg = "Bulk CSV import completed! {$result['success_rows']} of {$result['total_rows']} products successfully imported and published.";
            if ($result['error_rows'] > 0) {
                $msg .= " ({$result['error_rows']} rows had validation errors).";
            }
            set_flash('success', $msg);
            redirect('pages/dashboard/bulk_upload.php?action=view&id=' . (int) $result['import_id']);
        } else {
            set_flash('error', 'Import failed: ' . ($result['error'] ?? 'Unknown error occurred.'));
            redirect('pages/dashboard/bulk_upload.php?tab=csv');
        }
    }

    // Action B: Manual Multi-Row Spreadsheet Grid Submission
    elseif ($action === 'manual_bulk_save') {
        $targetSellerId = $userId;
        if ($isAdmin && !empty($_POST['target_seller_id'])) {
            $targetSellerId = (int) $_POST['target_seller_id'];
        }

        $submittedRows = $_POST['products'] ?? [];
        $result = BulkImport::processManualBulkRows($targetSellerId, $submittedRows, $userId, $role);

        if ($result['ok']) {
            $msg = "Spreadsheet batch completed! {$result['success_rows']} of {$result['total_rows']} products successfully published to the live marketplace.";
            if ($result['error_rows'] > 0) {
                $msg .= " ({$result['error_rows']} rows had validation errors).";
            }
            set_flash('success', $msg);
            redirect('pages/dashboard/bulk_upload.php?action=view&id=' . (int) $result['import_id']);
        } else {
            set_flash('error', 'Batch submission error: ' . ($result['error'] ?? 'Please check your inputs.'));
            redirect('pages/dashboard/bulk_upload.php?tab=grid');
        }
    }

    // Action C: Bulk Update of Existing Products
    elseif ($action === 'bulk_update_existing') {
        $items = $_POST['items'] ?? [];
        $result = BulkImport::bulkUpdateProducts($userId, $items, $isAdmin, $userId);

        if ($result['ok']) {
            $msg = "Successfully updated {$result['updated_count']} products in your catalog!";
            if (!empty($result['errors'])) {
                $msg .= " Some warnings: " . implode('; ', $result['errors']);
            }
            set_flash('success', $msg);
        } else {
            set_flash('error', 'Bulk update failed: ' . ($result['error'] ?? 'Unknown error'));
        }
        redirect('pages/dashboard/bulk_upload.php?tab=editor');
    }
}

// ---------------------------------------------------------
// 4. MAIN BULK MANAGEMENT VIEW
// ---------------------------------------------------------
$activeTab = trim((string) ($_GET['tab'] ?? 'grid'));
if (!in_array($activeTab, ['grid', 'csv', 'editor'], true)) {
    $activeTab = 'grid';
}

if ($isAdmin) {
    $imports = BulkImport::getAdminImports();
    $sellers = $pdo->query("
        SELECT u.id, u.full_name, COALESCE(sp.business_name, u.full_name) AS business_name
        FROM users u
        LEFT JOIN seller_profiles sp ON sp.user_id = u.id
        WHERE u.role = 'seller'
        ORDER BY business_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    $existingProducts = BulkImport::getSellerProducts($userId, true, 40);
} else {
    $imports = BulkImport::getSellerImports($userId);
    $sellers = [];
    $existingProducts = BulkImport::getSellerProducts($userId, false, 40);
}

$pageTitle = 'SokoHub | Multi-Product Bulk Management';
require_once __DIR__ . '/../../includes/header.php';
?>

<style>
    .tab-nav {
        display: flex;
        gap: 0.5rem;
        border-bottom: 2px solid #e2e8f0;
        margin-bottom: 1.75rem;
        flex-wrap: wrap;
    }
    .tab-btn {
        padding: 0.75rem 1.25rem;
        font-weight: 700;
        font-size: 0.95rem;
        color: #64748b;
        background: none;
        border: none;
        border-bottom: 3px solid transparent;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        transition: all 0.2s ease;
        text-decoration: none;
    }
    .tab-btn:hover {
        color: #27b327;
        text-decoration: none;
    }
    .tab-btn.active {
        color: #27b327;
        border-bottom-color: #27b327;
    }
    .grid-table input, .grid-table select, .grid-table textarea {
        font-size: 0.85rem;
        padding: 0.35rem 0.5rem;
        width: 100%;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
    }
    .grid-table input:focus, .grid-table select:focus, .grid-table textarea:focus {
        border-color: #27b327;
        outline: none;
        box-shadow: 0 0 0 2px rgba(39, 179, 39, 0.2);
    }
</style>

<main class="container section-space">
    <!-- Header -->
    <div class="page-head" style="margin-bottom: 1.5rem;">
        <div>
            <h1 style="font-size: 1.8rem; font-weight: 800; color: #1e293b; margin: 0;">
                Multi-Product Management &amp; Bulk Engine
            </h1>
            <p style="color: #64748b; font-size: 0.95rem; margin-top: 0.25rem;">
                Add multiple products using the live spreadsheet grid, upload CSV files, or edit your existing catalog in bulk.
            </p>
        </div>
        <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
            <a href="<?= e(url('pages/dashboard/manage_products.php')) ?>" class="btn btn-outline btn-sm">
                Individual Product CRUD &rarr;
            </a>
        </div>
    </div>

    <!-- KYC Compliance Warning -->
    <?php if (!$isAdmin && !$isVerifiedSeller): ?>
        <div class="card" style="margin-bottom: 2rem; border-left: 6px solid #f59e0b; background: #fffbeb; padding: 1.25rem 1.5rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                <div>
                    <strong style="color: #92400e; font-size: 1rem;">⚠️ Merchant KYC Verification Required</strong>
                    <p style="margin: 0.25rem 0 0 0; color: #b45309; font-size: 0.9rem;">
                        Your seller account must have an approved National ID and verified Safaricom M-Pesa line before publishing bulk products to the live marketplace.
                    </p>
                </div>
                <a href="<?= e(url('pages/dashboard/kyc_upload.php')) ?>" class="btn btn-amber btn-sm">Complete KYC Verification &rarr;</a>
            </div>
        </div>
    <?php endif; ?>

    <!-- Navigation Tabs -->
    <div class="tab-nav">
        <a href="?tab=grid" class="tab-btn <?= $activeTab === 'grid' ? 'active' : '' ?>">
            <span>📋</span> Interactive Spreadsheet Grid (Add Multiple)
        </a>
        <a href="?tab=csv" class="tab-btn <?= $activeTab === 'csv' ? 'active' : '' ?>">
            <span>📤</span> Upload CSV File
        </a>
        <a href="?tab=editor" class="tab-btn <?= $activeTab === 'editor' ? 'active' : '' ?>">
            <span>✏️</span> Bulk Inventory Editor (<?= count($existingProducts) ?> Items)
        </a>
    </div>

    <!-- ============================================================= -->
    <!-- TAB 1: INTERACTIVE SPREADSHEET GRID (DIRECT MULTI-ROW ADD)     -->
    <!-- ============================================================= -->
    <?php if ($activeTab === 'grid'): ?>
        <div class="card" style="padding: 1.75rem; margin-bottom: 2rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; flex-wrap: wrap; gap: 1rem;">
                <div>
                    <h3 style="font-size: 1.2rem; color: #1e293b; margin: 0;">
                        Live Product Entry Grid
                    </h3>
                    <p style="color: #64748b; font-size: 0.85rem; margin: 0.25rem 0 0 0;">
                        Type your products directly in the spreadsheet rows below without needing a CSV file.
                    </p>
                </div>
                <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                    <button type="button" class="btn btn-outline btn-sm" onclick="addGridRow();">
                        + Add 1 Row
                    </button>
                    <button type="button" class="btn btn-outline btn-sm" onclick="addMultipleRows(5);">
                        + Add 5 Rows
                    </button>
                    <button type="button" class="btn btn-amber btn-sm" onclick="fillSampleData();">
                        ✨ Fill Sample Demo Products
                    </button>
                </div>
            </div>

            <form method="POST" action="<?= e(url('pages/dashboard/bulk_upload.php')) ?>" id="gridForm">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="manual_bulk_save">

                <?php if ($isAdmin && !empty($sellers)): ?>
                    <div class="form-group" style="margin-bottom: 1.25rem;">
                        <label style="font-weight: 600; font-size: 0.85rem; color: #1e293b; display: block; margin-bottom: 0.35rem;">
                            Assign Products to Merchant Store:
                        </label>
                        <select name="target_seller_id" class="form-control" style="max-width: 380px;">
                            <?php foreach ($sellers as $s): ?>
                                <option value="<?= (int) $s['id'] ?>">
                                    <?= e($s['business_name']) ?> (<?= e($s['full_name']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div style="overflow-x: auto; margin-bottom: 1.5rem;">
                    <table class="grid-table" style="width: 100%; border-collapse: collapse; min-width: 980px;">
                        <thead>
                            <tr style="background: #f8fafc; border-bottom: 2px solid #cbd5e1; text-align: left; font-size: 0.85rem;">
                                <th style="padding: 0.6rem; width: 40px; text-align: center;">#</th>
                                <th style="padding: 0.6rem; width: 220px;">Product Name *</th>
                                <th style="padding: 0.6rem; width: 140px;">Category *</th>
                                <th style="padding: 0.6rem; width: 110px;">Price (KES) *</th>
                                <th style="padding: 0.6rem; width: 110px;">Was Price</th>
                                <th style="padding: 0.6rem; width: 80px; text-align: center;">Stock</th>
                                <th style="padding: 0.6rem; width: 80px; text-align: center;">Alert &le;</th>
                                <th style="padding: 0.6rem; width: 120px;">Condition</th>
                                <th style="padding: 0.6rem;">Description (min 10 chars) *</th>
                                <th style="padding: 0.6rem; width: 45px; text-align: center;">Del</th>
                            </tr>
                        </thead>
                        <tbody id="gridBody">
                            <!-- Rows injected dynamically via JS or rendered baseline -->
                        </tbody>
                    </table>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; border-top: 1px solid #e2e8f0; padding-top: 1.25rem;">
                    <div style="font-size: 0.85rem; color: #64748b;">
                        Active Rows: <strong id="gridRowCount">0</strong> &bull; Blank rows are automatically skipped.
                    </div>
                    <div>
                        <button type="submit" class="btn btn-primary" <?= (!$isAdmin && !$isVerifiedSeller) ? 'disabled' : '' ?> onclick="return confirm('Save and publish all entered products to your live catalog?');">
                            💾 Save &amp; Publish All Products
                        </button>
                    </div>
                </div>
            </form>
        </div>

    <!-- ============================================================= -->
    <!-- TAB 2: UPLOAD CSV SPREADSHEET                                  -->
    <!-- ============================================================= -->
    <?php elseif ($activeTab === 'csv'): ?>
        <div class="card" style="padding: 2rem; margin-bottom: 2.5rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; flex-wrap: wrap; gap: 1rem;">
                <div>
                    <h3 style="font-size: 1.2rem; color: #1e293b; margin: 0;">
                        Upload Product Spreadsheet (.csv)
                    </h3>
                    <p style="color: #64748b; font-size: 0.85rem; margin: 0.25rem 0 0 0;">
                        Import products from Microsoft Excel, Google Sheets, or LibreOffice Calc.
                    </p>
                </div>
                <div>
                    <a href="<?= e(url('pages/dashboard/bulk_upload.php?action=download_template')) ?>" class="btn btn-amber btn-sm">
                        📥 Download Sample Template (.csv)
                    </a>
                </div>
            </div>

            <form method="POST" action="<?= e(url('pages/dashboard/bulk_upload.php')) ?>" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="upload_csv">

                <?php if ($isAdmin && !empty($sellers)): ?>
                    <div class="form-group" style="margin-bottom: 1.25rem;">
                        <label style="font-weight: 600; font-size: 0.85rem; color: #1e293b; display: block; margin-bottom: 0.35rem;">
                            Select Merchant Account to Import Into:
                        </label>
                        <select name="target_seller_id" class="form-control" style="max-width: 380px;">
                            <?php foreach ($sellers as $s): ?>
                                <option value="<?= (int) $s['id'] ?>">
                                    <?= e($s['business_name']) ?> (<?= e($s['full_name']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div style="display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap;">
                    <div style="flex: 1; min-width: 280px;">
                        <label style="font-weight: 600; font-size: 0.85rem; color: #1e293b; display: block; margin-bottom: 0.35rem;">
                            Choose .CSV Spreadsheet:
                        </label>
                        <input type="file" name="csv_file" class="form-control" accept=".csv" required <?= (!$isAdmin && !$isVerifiedSeller) ? 'disabled' : '' ?>>
                    </div>

                    <div>
                        <button type="submit" class="btn btn-primary" <?= (!$isAdmin && !$isVerifiedSeller) ? 'disabled' : '' ?> onclick="return confirm('Upload and process this CSV spreadsheet? Products will be validated and published immediately.');">
                            📤 Upload &amp; Process CSV
                        </button>
                    </div>
                </div>
            </form>

            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 1rem 1.25rem; margin-top: 1.5rem; font-size: 0.85rem;">
                <strong style="color: #1e293b; display: block; margin-bottom: 0.35rem;">💡 Format Requirements:</strong>
                <ul style="margin: 0; padding-left: 1.25rem; color: #475569; line-height: 1.5;">
                    <li><strong>Required Columns:</strong> <code>name</code>, <code>category</code>, <code>description</code>, <code>price</code></li>
                    <li><strong>Optional Columns:</strong> <code>original_price</code>, <code>stock_qty</code>, <code>low_stock_threshold</code>, <code>condition_type</code>, <code>is_featured</code></li>
                    <li><strong>Safe Fault-Tolerant Processing:</strong> Products with validation errors are logged row-by-row while valid products are published immediately.</li>
                </ul>
            </div>
        </div>

    <!-- ============================================================= -->
    <!-- TAB 3: BULK INVENTORY SPREADSHEET EDITOR                       -->
    <!-- ============================================================= -->
    <?php elseif ($activeTab === 'editor'): ?>
        <div class="card" style="padding: 1.75rem; margin-bottom: 2.5rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; flex-wrap: wrap; gap: 1rem;">
                <div>
                    <h3 style="font-size: 1.2rem; color: #1e293b; margin: 0;">
                        Bulk Inventory Spreadsheet Editor
                    </h3>
                    <p style="color: #64748b; font-size: 0.85rem; margin: 0.25rem 0 0 0;">
                        Quickly adjust selling prices, restock units, change low-stock alert thresholds, or archive products in a spreadsheet grid.
                    </p>
                </div>
            </div>

            <?php if (empty($existingProducts)): ?>
                <div style="text-align: center; padding: 3rem 1rem;">
                    <span style="font-size: 2.5rem;">🏷️</span>
                    <h4 style="font-size: 1.1rem; color: #1e293b; margin: 0.5rem 0 0.25rem 0;">No products in catalog</h4>
                    <p style="color: #64748b; font-size: 0.875rem;">
                        Use the "Interactive Spreadsheet Grid" or "Upload CSV" tab to add your first batch of products.
                    </p>
                </div>
            <?php else: ?>
                <form method="POST" action="<?= e(url('pages/dashboard/bulk_upload.php')) ?>">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="bulk_update_existing">

                    <div style="overflow-x: auto; margin-bottom: 1.5rem;">
                        <table class="grid-table" style="width: 100%; border-collapse: collapse; min-width: 950px;">
                            <thead>
                                <tr style="background: #f8fafc; border-bottom: 2px solid #cbd5e1; text-align: left; font-size: 0.85rem;">
                                    <th style="padding: 0.6rem; width: 60px;">ID</th>
                                    <th style="padding: 0.6rem; width: 250px;">Product Name</th>
                                    <th style="padding: 0.6rem; width: 140px;">Category</th>
                                    <th style="padding: 0.6rem; width: 120px;">Price (KES)</th>
                                    <th style="padding: 0.6rem; width: 100px;">Stock Units</th>
                                    <th style="padding: 0.6rem; width: 100px;">Alert &le;</th>
                                    <th style="padding: 0.6rem; width: 120px;">Condition</th>
                                    <th style="padding: 0.6rem; width: 110px;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($existingProducts as $idx => $p): 
                                    $pId = (int) $p['id'];
                                ?>
                                    <tr style="border-bottom: 1px solid #e2e8f0;">
                                        <td style="padding: 0.6rem; font-family: monospace; font-weight: 700; color: #64748b;">
                                            #<?= $pId ?>
                                            <input type="hidden" name="items[<?= $idx ?>][id]" value="<?= $pId ?>">
                                        </td>
                                        <td style="padding: 0.6rem;">
                                            <input type="text" name="items[<?= $idx ?>][name]" value="<?= e($p['name']) ?>" required>
                                        </td>
                                        <td style="padding: 0.6rem;">
                                            <select name="items[<?= $idx ?>][category_id]">
                                                <?php foreach ($categories as $cat): ?>
                                                    <option value="<?= (int) $cat['id'] ?>" <?= (int) $p['category_id'] === (int) $cat['id'] ? 'selected' : '' ?>>
                                                        <?= e($cat['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td style="padding: 0.6rem;">
                                            <input type="number" step="0.01" min="1" name="items[<?= $idx ?>][price]" value="<?= e($p['price']) ?>" required>
                                        </td>
                                        <td style="padding: 0.6rem;">
                                            <input type="number" min="0" name="items[<?= $idx ?>][stock_qty]" value="<?= e($p['stock_qty']) ?>" required>
                                        </td>
                                        <td style="padding: 0.6rem;">
                                            <input type="number" min="1" name="items[<?= $idx ?>][low_stock_threshold]" value="<?= e($p['low_stock_threshold']) ?>" required>
                                        </td>
                                        <td style="padding: 0.6rem;">
                                            <select name="items[<?= $idx ?>][condition_type]">
                                                <option value="New" <?= $p['condition_type'] === 'New' ? 'selected' : '' ?>>New</option>
                                                <option value="Second Hand" <?= $p['condition_type'] === 'Second Hand' ? 'selected' : '' ?>>Second Hand</option>
                                            </select>
                                        </td>
                                        <td style="padding: 0.6rem;">
                                            <select name="items[<?= $idx ?>][status]">
                                                <option value="active" <?= $p['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                                <option value="archived" <?= $p['status'] === 'archived' ? 'selected' : '' ?>>Archived</option>
                                            </select>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div style="display: flex; justify-content: flex-end; border-top: 1px solid #e2e8f0; padding-top: 1.25rem;">
                        <button type="submit" class="btn btn-primary" onclick="return confirm('Update all modified product values in your live catalog?');">
                            💾 Save All Spreadsheet Updates
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Past Import Job History -->
    <div class="card" style="padding: 1.5rem; overflow-x: auto;">
        <h3 style="font-size: 1.15rem; color: #1e293b; margin-bottom: 1rem;">
            <?= $isAdmin ? 'All Bulk Import &amp; Spreadsheet Jobs' : 'My Bulk Upload History' ?>
        </h3>

        <?php if (empty($imports)): ?>
            <div style="text-align: center; padding: 2.5rem 1rem;">
                <span style="font-size: 2.5rem;">📄</span>
                <h4 style="font-size: 1.1rem; color: #1e293b; margin: 0.5rem 0 0.25rem 0;">No bulk batches yet</h4>
                <p style="color: #64748b; font-size: 0.875rem;">
                    Use the Interactive Spreadsheet Grid above or upload a CSV file to add multiple products.
                </p>
            </div>
        <?php else: ?>
            <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
                <thead>
                    <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0; text-align: left;">
                        <th style="padding: 0.75rem;">Job ID</th>
                        <th style="padding: 0.75rem;">Date</th>
                        <th style="padding: 0.75rem;">Source / Name</th>
                        <?php if ($isAdmin): ?>
                            <th style="padding: 0.75rem;">Merchant Store</th>
                        <?php endif; ?>
                        <th style="padding: 0.75rem; text-align: center;">Total</th>
                        <th style="padding: 0.75rem; text-align: center;">Success</th>
                        <th style="padding: 0.75rem; text-align: center;">Errors</th>
                        <th style="padding: 0.75rem;">Status</th>
                        <th style="padding: 0.75rem; text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($imports as $imp): 
                        $status = $imp['status'];
                    ?>
                        <tr style="border-bottom: 1px solid #e2e8f0;">
                            <td style="padding: 0.75rem; font-family: monospace; font-weight: 700;">
                                <a href="<?= e(url('pages/dashboard/bulk_upload.php?action=view&id=' . (int) $imp['id'])) ?>" style="color: #27b327; text-decoration: underline;">
                                    #IMP-<?= str_pad($imp['id'], 4, '0', STR_PAD_LEFT) ?>
                                </a>
                            </td>
                            <td style="padding: 0.75rem; color: #475569;">
                                <?= e(date('d M Y, H:i', strtotime($imp['created_at']))) ?>
                            </td>
                            <td style="padding: 0.75rem; font-weight: 600; color: #1e293b;">
                                <?= str_starts_with($imp['file_path'], 'manual') ? '📋' : '📄' ?>
                                <?= e($imp['original_filename']) ?>
                            </td>
                            <?php if ($isAdmin): ?>
                                <td style="padding: 0.75rem; color: #4338ca;">
                                    <?= e($imp['business_name'] ?? $imp['seller_name'] ?? 'N/A') ?>
                                </td>
                            <?php endif; ?>
                            <td style="padding: 0.75rem; text-align: center; font-weight: 600;">
                                <?= (int) $imp['total_rows'] ?>
                            </td>
                            <td style="padding: 0.75rem; text-align: center;">
                                <span class="badge badge-success" style="font-size: 0.75rem;">
                                    <?= (int) $imp['success_rows'] ?> &check;
                                </span>
                            </td>
                            <td style="padding: 0.75rem; text-align: center;">
                                <?php if ((int) $imp['error_rows'] > 0): ?>
                                    <span class="badge badge-danger" style="font-size: 0.75rem;">
                                        <?= (int) $imp['error_rows'] ?> &cross;
                                    </span>
                                <?php else: ?>
                                    <span style="color: #94a3b8;">0</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 0.75rem;">
                                <?php if ($status === 'completed'): ?>
                                    <span class="badge badge-success" style="font-size: 0.75rem;">Completed</span>
                                <?php elseif ($status === 'failed'): ?>
                                    <span class="badge badge-danger" style="font-size: 0.75rem;">Failed</span>
                                <?php else: ?>
                                    <span class="badge badge-info" style="font-size: 0.75rem;"><?= e(ucfirst($status)) ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 0.75rem; text-align: right;">
                                <a href="<?= e(url('pages/dashboard/bulk_upload.php?action=view&id=' . (int) $imp['id'])) ?>" class="btn btn-outline btn-sm" style="font-size: 0.75rem; padding: 0.25rem 0.5rem;">
                                    Inspect Breakdown &rarr;
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</main>

<script>
const categoriesList = <?= json_encode($categories) ?>;
let rowCounter = 0;

function createRowHtml(index, data = {}) {
    let catOptions = '';
    categoriesList.forEach(c => {
        const isSel = (data.category_id && parseInt(data.category_id) === parseInt(c.id)) ? 'selected' : '';
        catOptions += `<option value="${c.id}" ${isSel}>${c.name}</option>`;
    });

    return `
        <tr id="gridRow_${index}" style="border-bottom: 1px solid #e2e8f0;">
            <td style="padding: 0.5rem; text-align: center; font-family: monospace; font-weight: 700; color: #64748b;" class="row-num">
                ${index + 1}
            </td>
            <td style="padding: 0.5rem;">
                <input type="text" name="products[${index}][name]" value="${data.name || ''}" placeholder="e.g. Kitengela Pure Cotton Bed Sheet" required>
            </td>
            <td style="padding: 0.5rem;">
                <select name="products[${index}][category_id]">
                    ${catOptions}
                </select>
            </td>
            <td style="padding: 0.5rem;">
                <input type="number" step="0.01" min="1" name="products[${index}][price]" value="${data.price || ''}" placeholder="1200.00" required>
            </td>
            <td style="padding: 0.5rem;">
                <input type="number" step="0.01" min="1" name="products[${index}][original_price]" value="${data.original_price || ''}" placeholder="1400.00">
            </td>
            <td style="padding: 0.5rem;">
                <input type="number" min="0" name="products[${index}][stock_qty]" value="${data.stock_qty || '10'}">
            </td>
            <td style="padding: 0.5rem;">
                <input type="number" min="1" name="products[${index}][low_stock_threshold]" value="${data.low_stock_threshold || '5'}">
            </td>
            <td style="padding: 0.5rem;">
                <select name="products[${index}][condition_type]">
                    <option value="New" ${data.condition === 'New' ? 'selected' : ''}>New</option>
                    <option value="Second Hand" ${data.condition === 'Second Hand' ? 'selected' : ''}>Second Hand</option>
                </select>
            </td>
            <td style="padding: 0.5rem;">
                <input type="text" name="products[${index}][description]" value="${data.description || ''}" placeholder="Quality, specifications, size, warranty..." required minlength="10">
            </td>
            <td style="padding: 0.5rem; text-align: center;">
                <button type="button" class="btn btn-outline btn-sm" style="color: #ef4444; border-color: #ef4444; padding: 0.2rem 0.45rem; font-size: 0.75rem;" onclick="removeGridRow(${index});" title="Delete Row">
                    &times;
                </button>
            </td>
        </tr>
    `;
}

function addGridRow(data = {}) {
    const tbody = document.getElementById('gridBody');
    if (!tbody) return;
    tbody.insertAdjacentHTML('beforeend', createRowHtml(rowCounter, data));
    rowCounter++;
    updateRowNumbers();
}

function addMultipleRows(count = 5) {
    for (let i = 0; i < count; i++) {
        addGridRow();
    }
}

function removeGridRow(index) {
    const row = document.getElementById(`gridRow_${index}`);
    if (row) {
        row.remove();
        updateRowNumbers();
    }
}

function updateRowNumbers() {
    const tbody = document.getElementById('gridBody');
    if (!tbody) return;
    const rows = tbody.querySelectorAll('tr');
    rows.forEach((r, idx) => {
        const numCell = r.querySelector('.row-num');
        if (numCell) numCell.textContent = idx + 1;
    });
    const countSpan = document.getElementById('gridRowCount');
    if (countSpan) countSpan.textContent = rows.length;
}

function fillSampleData() {
    const samples = [
        {
            name: 'Kirinyaga Highlands Pure Raw Honey 500g',
            category_id: 3,
            price: 850,
            original_price: 950,
            stock_qty: 30,
            low_stock_threshold: 5,
            condition: 'New',
            description: 'Freshly harvested unfiltered organic natural honey from Mount Kenya forest apiaries.'
        },
        {
            name: 'Pwani Gas 13kg Cylinder with Regulator',
            category_id: 2,
            price: 7800,
            original_price: 8500,
            stock_qty: 15,
            low_stock_threshold: 3,
            condition: 'New',
            description: 'Standard domestic 13kg cooking gas cylinder with safety relief valve and 2M reinforced hose.'
        },
        {
            name: 'Traditional Hand-Woven Kiondo Bag',
            category_id: 1,
            price: 1250,
            original_price: 1500,
            stock_qty: 20,
            low_stock_threshold: 4,
            condition: 'New',
            description: 'Durable Sisal and wool handcrafted Kirinyaga market bag with leather shoulder straps.'
        }
    ];

    const tbody = document.getElementById('gridBody');
    if (tbody) tbody.innerHTML = '';
    rowCounter = 0;
    samples.forEach(s => addGridRow(s));
}

// Initialize with 3 rows on page load
document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('gridBody')) {
        addMultipleRows(3);
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
