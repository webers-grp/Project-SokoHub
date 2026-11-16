<?php
/**
 * SokoHub Seller Monthly Payout Statements & Payroll Engine
 * Automated calculation of delivered gross sales, 10% platform commission, refunds,
 * and net disbursals with Safaricom M-Pesa tracking and printable official vouchers.
 * Authored by Kennedy Kalawa.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/payouts.php';
require_once __DIR__ . '/../../includes/kyc.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/notifications.php';

init_session();
$user = require_role(['admin', 'seller']);
$role = $user['role'];
$isAdmin = ($role === 'admin');
$userId = (int) $user['id'];

Payouts::ensureSchema();

// ---------------------------------------------------------
// 1. CSV EXPORT HANDLER
// ---------------------------------------------------------
if (($_GET['export'] ?? '') === 'csv') {
    $statusFilter = trim((string) ($_GET['status'] ?? ''));
    $periodFilter = trim((string) ($_GET['period'] ?? ''));
    $searchQuery  = trim((string) ($_GET['q'] ?? ''));

    if ($isAdmin) {
        $rows = Payouts::getAdminPayoutQueue(
            $statusFilter !== '' ? $statusFilter : null,
            $periodFilter !== '' ? $periodFilter : null,
            $searchQuery !== '' ? $searchQuery : null
        );
    } else {
        $rows = Payouts::getSellerPayouts($userId);
    }

    $filename = 'sokohub_payouts_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    // Output UTF-8 BOM for Microsoft Excel compatibility
    fputs($out, "\xEF\xBB\xBF");

    // CSV Header row
    fputcsv($out, [
        'Statement ID',
        'Period Start',
        'Period End',
        'Merchant Store',
        'Merchant Full Name',
        'Contact Phone',
        'M-Pesa Disbursal Phone',
        'KYC Status',
        'Delivered Orders',
        'Gross Sales (KES)',
        'Platform Fee (10%) (KES)',
        'Refunds (KES)',
        'Net Payout (KES)',
        'Status',
        'M-Pesa Transaction Ref',
        'Disbursal Date',
    ]);

    foreach ($rows as $r) {
        fputcsv($out, [
            'SKH-PAY-' . str_pad($r['id'], 5, '0', STR_PAD_LEFT),
            $r['period_start'],
            $r['period_end'],
            $r['business_name'] ?? 'N/A',
            $r['seller_name'] ?? ($user['full_name'] ?? 'N/A'),
            $r['seller_phone'] ?? 'N/A',
            $r['mpesa_phone'] ?? 'N/A',
            ucfirst($r['kyc_status'] ?? 'pending'),
            $r['order_count'] ?? 0,
            number_format((float) $r['gross_sales'], 2, '.', ''),
            number_format((float) $r['platform_fee'], 2, '.', ''),
            number_format((float) $r['refunds'], 2, '.', ''),
            number_format((float) $r['net_amount'], 2, '.', ''),
            strtoupper($r['status']),
            $r['mpesa_reference'] ?? 'PENDING',
            $r['paid_at'] ?? 'N/A',
        ]);
    }

    fclose($out);
    exit;
}

// ---------------------------------------------------------
// 2. POST ACTIONS (Admin Only)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) {
        set_flash('error', 'Unauthorized action.');
        redirect('pages/dashboard/payouts.php');
    }

    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Security token mismatch. Please try again.');
        redirect('pages/dashboard/payouts.php');
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'generate_statements') {
        $payoutMonth = trim((string) ($_POST['payout_month'] ?? ''));
        $res = Payouts::generateMonthlyPayouts($payoutMonth, $userId);

        if ($res['ok']) {
            $msg = "Generated {$res['created']} new statements, updated {$res['updated']} existing, and skipped {$res['skipped']} finalized records for billing cycle {$res['period_start']} to {$res['period_end']}. Total Net: KES " . number_format($res['total_net'], 2);
            set_flash('success', $msg);
            redirect('pages/dashboard/payouts.php?period=' . urlencode($res['year_month']));
        } else {
            set_flash('error', 'Statement generation failed: ' . ($res['error'] ?? 'Unknown error'));
            redirect('pages/dashboard/payouts.php');
        }
    } elseif ($action === 'approve') {
        $payoutId = (int) ($_POST['payout_id'] ?? 0);
        $res = Payouts::approvePayout($payoutId, $userId);

        if ($res['ok']) {
            set_flash('success', 'Payout statement #' . $payoutId . ' approved and queued for M-Pesa disbursal.');
        } else {
            set_flash('error', 'Approval failed: ' . ($res['error'] ?? 'Unknown error'));
        }
        redirect('pages/dashboard/payouts.php');
    } elseif ($action === 'mark_paid') {
        $payoutId = (int) ($_POST['payout_id'] ?? 0);
        $mpesaRef = trim((string) ($_POST['mpesa_reference'] ?? ''));

        $res = Payouts::markPayoutPaid($payoutId, $mpesaRef, $userId);

        if ($res['ok']) {
            set_flash('success', "Disbursal recorded successfully! Payout #{$payoutId} marked as PAID. M-Pesa Ref: {$res['mpesa_reference']}. Seller has been notified.");
        } else {
            set_flash('error', 'Disbursal record failed: ' . ($res['error'] ?? 'Invalid M-Pesa reference'));
        }
        redirect('pages/dashboard/payouts.php');
    }
}

// ---------------------------------------------------------
// 3. SINGLE STATEMENT / VOUCHER VIEW
// ---------------------------------------------------------
$viewAction = (string) ($_GET['action'] ?? '');
if (in_array($viewAction, ['statement', 'view'], true) && !empty($_GET['id'])) {
    $payoutId = (int) $_GET['id'];
    $payout = Payouts::getPayoutDetails($payoutId);

    if (!$payout) {
        set_flash('error', 'Payout statement not found.');
        redirect('pages/dashboard/payouts.php');
    }

    // Security check: sellers can only inspect their own statements
    if (!$isAdmin && (int) $payout['seller_user_id'] !== $userId) {
        set_flash('error', 'You do not have permission to view this statement.');
        redirect('pages/dashboard/payouts.php');
    }

    $pageTitle = 'SokoHub | Payout Voucher #' . str_pad($payout['id'], 5, '0', STR_PAD_LEFT);
    require_once __DIR__ . '/../../includes/header.php';
    ?>
    <style>
        .voucher-card {
            background: #fff;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 2.5rem;
            box-shadow: 0 10px 30px rgba(0,0,0,0.06);
            margin-bottom: 2rem;
            position: relative;
        }
        .voucher-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px dashed #cbd5e1;
            padding-bottom: 1.5rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1.5rem;
        }
        .voucher-title {
            font-size: 1.6rem;
            font-weight: 800;
            color: #1e293b;
            letter-spacing: -0.5px;
            margin: 0;
        }
        .voucher-sub {
            color: #64748b;
            font-size: 0.9rem;
            margin-top: 0.25rem;
        }
        .voucher-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .info-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1.25rem;
        }
        .info-box h4 {
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
            margin-bottom: 0.75rem;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.4rem;
            font-size: 0.9rem;
        }
        .info-row span:first-child {
            color: #64748b;
        }
        .info-row span:last-child {
            font-weight: 600;
            color: #1e293b;
        }
        .calculation-card {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        .calc-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid #dcfce7;
            font-size: 0.95rem;
        }
        .calc-row.total {
            border-bottom: none;
            border-top: 2px solid #22c55e;
            margin-top: 0.5rem;
            padding-top: 0.75rem;
            font-size: 1.25rem;
            font-weight: 800;
            color: #15803d;
        }
        @media print {
            body {
                background: #fff !important;
                color: #000 !important;
            }
            .navbar, .footer, .btn, .no-print {
                display: none !important;
            }
            .section-space {
                padding: 0 !important;
            }
            .voucher-card {
                border: 1px solid #000 !important;
                box-shadow: none !important;
                padding: 1rem !important;
            }
            .calculation-card {
                border: 1px solid #999 !important;
                background: #fdfdfd !important;
            }
        }
    </style>

    <main class="container section-space">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;" class="no-print">
            <a href="<?= e(url('pages/dashboard/payouts.php')) ?>" class="btn btn-outline btn-sm">&larr; Back to Payouts List</a>
            <div style="display: flex; gap: 0.5rem;">
                <button type="button" class="btn btn-primary btn-sm" onclick="window.print();">🖨️ Print Payout Voucher</button>
                <a href="<?= e(url('pages/dashboard/payouts.php?export=csv')) ?>" class="btn btn-amber btn-sm">Export Payroll CSV</a>
            </div>
        </div>

        <div class="voucher-card">
            <!-- Voucher Header -->
            <div class="voucher-header">
                <div>
                    <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.25rem;">
                        <span style="font-size: 1.5rem;">🌿</span>
                        <h1 class="voucher-title">SOKOHUB DIGITAL MARKETPLACE</h1>
                    </div>
                    <div class="voucher-sub">Kirinyaga County Merchant Settlement &amp; Monthly Payout Voucher</div>
                    <div style="font-size: 0.8rem; color: #94a3b8; margin-top: 0.25rem;">Regulated under Kirinyaga County E-Commerce Framework &bull; Kutus Main Hub</div>
                </div>
                <div style="text-align: right;">
                    <div style="font-size: 1.1rem; font-weight: 800; color: #1e293b;">
                        VOUCHER #SKH-PAY-<?= str_pad($payout['id'], 5, '0', STR_PAD_LEFT) ?>
                    </div>
                    <div style="margin-top: 0.35rem;">
                        <?php if ($payout['status'] === 'paid'): ?>
                            <span class="badge badge-success" style="font-size: 0.85rem; padding: 0.35rem 0.75rem;">DISBURSED / PAID &check;</span>
                        <?php elseif ($payout['status'] === 'approved'): ?>
                            <span class="badge badge-info" style="font-size: 0.85rem; padding: 0.35rem 0.75rem;">APPROVED FOR DISBURSAL</span>
                        <?php else: ?>
                            <span class="badge badge-neutral" style="font-size: 0.85rem; padding: 0.35rem 0.75rem;">DRAFT STATEMENT</span>
                        <?php endif; ?>
                    </div>
                    <div style="font-size: 0.8rem; color: #64748b; margin-top: 0.4rem;">
                        Billing Cycle: <strong><?= e($payout['period_start']) ?></strong> to <strong><?= e($payout['period_end']) ?></strong>
                    </div>
                </div>
            </div>

            <!-- Merchant & Disbursal Information Grid -->
            <div class="voucher-grid">
                <div class="info-box">
                    <h4>Merchant Profile</h4>
                    <div class="info-row">
                        <span>Business Name:</span>
                        <span><?= e($payout['business_name'] ?: 'Independent Merchant') ?></span>
                    </div>
                    <div class="info-row">
                        <span>Merchant Name:</span>
                        <span><?= e($payout['seller_name']) ?></span>
                    </div>
                    <div class="info-row">
                        <span>Email:</span>
                        <span><?= e($payout['seller_email']) ?></span>
                    </div>
                    <div class="info-row">
                        <span>Operating Town:</span>
                        <span><?= e($payout['town'] ?? 'Kutus') ?></span>
                    </div>
                    <div class="info-row">
                        <span>KYC Verification:</span>
                        <span>
                            <?php if (($payout['kyc_status'] ?? '') === 'approved'): ?>
                                <span style="color: #16a34a; font-weight: 700;">Verified Merchant &check;</span>
                            <?php else: ?>
                                <span style="color: #d97706; font-weight: 700;">Pending Verification</span>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>

                <div class="info-box">
                    <h4>M-Pesa Disbursal Details</h4>
                    <div class="info-row">
                        <span>Disbursal Phone:</span>
                        <span style="color: #047857; font-family: monospace; font-size: 0.95rem;">
                            📱 <?= e($payout['mpesa_phone'] ?: $payout['seller_phone']) ?>
                        </span>
                    </div>
                    <div class="info-row">
                        <span>National ID / Passport:</span>
                        <span><?= e($payout['national_id_no'] ?? 'On File') ?></span>
                    </div>
                    <div class="info-row">
                        <span>M-Pesa Reference:</span>
                        <span style="font-family: monospace; font-size: 0.95rem; color: #1e293b;">
                            <?= !empty($payout['mpesa_reference']) ? e($payout['mpesa_reference']) : '<em style="color:#94a3b8;">Pending Disbursal</em>' ?>
                        </span>
                    </div>
                    <div class="info-row">
                        <span>Disbursal Date:</span>
                        <span><?= !empty($payout['paid_at']) ? e($payout['paid_at']) : '<em style="color:#94a3b8;">Awaiting Payment</em>' ?></span>
                    </div>
                    <div class="info-row">
                        <span>Generated On:</span>
                        <span><?= e($payout['created_at']) ?></span>
                    </div>
                </div>
            </div>

            <!-- Financial Computation Summary -->
            <div class="calculation-card">
                <div style="font-size: 0.9rem; font-weight: 700; color: #166534; text-transform: uppercase; margin-bottom: 0.75rem; letter-spacing: 0.5px;">
                    Statement Financial Summary &amp; Platform Fee Breakdown
                </div>
                <div class="calc-row">
                    <span>Gross Delivered Sales (Orders Fulfilled &amp; Delivered):</span>
                    <span>KES <?= number_format((float) $payout['gross_sales'], 2) ?></span>
                </div>
                <div class="calc-row">
                    <span>SokoHub Marketplace Commission (10.00% Platform Fee):</span>
                    <span style="color: #dc2626;">- KES <?= number_format((float) $payout['platform_fee'], 2) ?></span>
                </div>
                <div class="calc-row">
                    <span>Customer Adjustments &amp; Verified Refunds:</span>
                    <span style="color: #dc2626;">- KES <?= number_format((float) $payout['refunds'], 2) ?></span>
                </div>
                <div class="calc-row total">
                    <span>Net Disbursable Payout Amount:</span>
                    <span>KES <?= number_format((float) $payout['net_amount'], 2) ?></span>
                </div>
            </div>

            <!-- Itemized Delivered Products in this Cycle -->
            <div style="margin-bottom: 2rem;">
                <h3 style="font-size: 1.1rem; color: #1e293b; margin-bottom: 0.75rem;">
                    Itemized Order Breakdown (<?= count($payout['line_items'] ?? []) ?> Items Fulfilled)
                </h3>
                <?php if (empty($payout['line_items'])): ?>
                    <p style="color: #64748b; font-size: 0.9rem;">No delivered orders were fulfilled in this billing cycle.</p>
                <?php else: ?>
                    <div style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
                            <thead>
                                <tr style="background: #f1f5f9; border-bottom: 2px solid #cbd5e1; text-align: left;">
                                    <th style="padding: 0.6rem;">Order Code</th>
                                    <th style="padding: 0.6rem;">Fulfilled Date</th>
                                    <th style="padding: 0.6rem;">Product Name</th>
                                    <th style="padding: 0.6rem;">Category</th>
                                    <th style="padding: 0.6rem; text-align: center;">Qty</th>
                                    <th style="padding: 0.6rem; text-align: right;">Unit Price (KES)</th>
                                    <th style="padding: 0.6rem; text-align: right;">Line Total (KES)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payout['line_items'] as $item): ?>
                                    <tr style="border-bottom: 1px solid #e2e8f0;">
                                        <td style="padding: 0.6rem; font-family: monospace; font-weight: 600;">
                                            <?= e($item['order_code']) ?>
                                        </td>
                                        <td style="padding: 0.6rem; color: #64748b;">
                                            <?= e(date('d M Y, H:i', strtotime($item['order_date']))) ?>
                                        </td>
                                        <td style="padding: 0.6rem; font-weight: 600;">
                                            <?= e($item['product_name']) ?>
                                        </td>
                                        <td style="padding: 0.6rem; color: #64748b;">
                                            <?= e($item['category_name'] ?? 'General') ?>
                                        </td>
                                        <td style="padding: 0.6rem; text-align: center;">
                                            <?= (int) $item['quantity'] ?>
                                        </td>
                                        <td style="padding: 0.6rem; text-align: right;">
                                            <?= number_format((float) $item['unit_price'], 2) ?>
                                        </td>
                                        <td style="padding: 0.6rem; text-align: right; font-weight: 600;">
                                            <?= number_format((float) $item['line_total'], 2) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Authorized Signoff -->
            <div style="border-top: 1px solid #e2e8f0; padding-top: 1.5rem; display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 1.5rem; font-size: 0.85rem; color: #64748b;">
                <div>
                    <div><strong>SokoHub Financial Clearance Department</strong></div>
                    <div>Kirinyaga University Incubation Hub &bull; P.O. Box 143-10300, Kerugoya</div>
                    <div>Queries: finance@sokohub.co.ke | +254 700 SOKOHUB</div>
                </div>
                <div style="text-align: right;">
                    <div style="border-bottom: 1px solid #94a3b8; width: 220px; margin-bottom: 0.25rem;"></div>
                    <div>Authorized Digital Signature &amp; Stamp</div>
                    <div style="font-size: 0.75rem; color: #94a3b8;">System generated voucher &bull; Tamper proof</div>
                </div>
            </div>

            <!-- Admin Inline Disbursement / Approval (Visible only on screen, hidden on print) -->
            <?php if ($isAdmin && $payout['status'] !== 'paid'): ?>
                <div class="no-print" style="margin-top: 2rem; padding: 1.5rem; background: #fffbeb; border: 1px solid #fef3c7; border-radius: 8px;">
                    <h4 style="margin-bottom: 0.5rem; color: #92400e;">Administrator Settlement Controls</h4>
                    <p style="font-size: 0.85rem; color: #b45309; margin-bottom: 1rem;">
                        Verify that the merchant's KYC is approved and initiate Safaricom M-Pesa B2C disbursal to <strong><?= e($payout['mpesa_phone'] ?: $payout['seller_phone']) ?></strong>.
                    </p>
                    <div style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
                        <?php if ($payout['status'] === 'draft'): ?>
                            <form method="POST" action="<?= e(url('pages/dashboard/payouts.php')) ?>" style="margin: 0;">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="payout_id" value="<?= (int) $payout['id'] ?>">
                                <button type="submit" class="btn btn-primary btn-sm">&check; Approve Statement</button>
                            </form>
                        <?php endif; ?>

                        <form method="POST" action="<?= e(url('pages/dashboard/payouts.php')) ?>" style="display: flex; gap: 0.5rem; align-items: center; margin: 0; flex-wrap: wrap;">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="mark_paid">
                            <input type="hidden" name="payout_id" value="<?= (int) $payout['id'] ?>">
                            <input type="text" name="mpesa_reference" placeholder="e.g. QJD782394A" class="form-control" style="font-family: monospace; font-size: 0.85rem; text-transform: uppercase; width: 180px; padding: 0.35rem 0.6rem;" required>
                            <button type="submit" class="btn btn-amber btn-sm" onclick="return confirm('Confirm disbursement of KES <?= number_format((float) $payout['net_amount'], 2) ?> via Safaricom M-Pesa?');">
                                💸 Confirm M-Pesa Disbursal
                            </button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <?php
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

// ---------------------------------------------------------
// 4. MAIN PAYOUTS LIST VIEW
// ---------------------------------------------------------
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$periodFilter = trim((string) ($_GET['period'] ?? ''));
$searchQuery  = trim((string) ($_GET['q'] ?? ''));

if ($isAdmin) {
    $payouts = Payouts::getAdminPayoutQueue(
        $statusFilter !== '' ? $statusFilter : null,
        $periodFilter !== '' ? $periodFilter : null,
        $searchQuery !== '' ? $searchQuery : null
    );
    $summary = Payouts::getPayoutSummary();
} else {
    $payouts = Payouts::getSellerPayouts($userId);
    $summary = Payouts::getPayoutSummary($userId);
}

// Default generation month (previous month)
$defaultGenMonth = date('Y-m', strtotime('first day of last month'));

$pageTitle = $isAdmin ? 'SokoHub | Seller Payouts & Payroll' : 'SokoHub | My Earnings & Payouts';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="container section-space">
    <!-- Page Header & Action Bar -->
    <div class="page-head" style="margin-bottom: 2rem;">
        <div>
            <h1 style="font-size: 1.8rem; font-weight: 800; color: #1e293b; margin: 0;">
                <?= $isAdmin ? 'Seller Monthly Payouts &amp; Payroll' : 'My Earnings &amp; Monthly Statements' ?>
            </h1>
            <p style="color: #64748b; font-size: 0.95rem; margin-top: 0.25rem;">
                <?= $isAdmin 
                    ? 'Automated monthly statement generator, 10% platform fee calculations, and Safaricom M-Pesa disbursal tracking.' 
                    : 'Track your delivered order revenue, SokoHub 10% marketplace fees, and direct M-Pesa monthly settlements.' 
                ?>
            </p>
        </div>
        <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
            <?php if ($isAdmin): ?>
                <!-- Monthly Statement Generator Popover -->
                <details style="position: relative; display: inline-block;">
                    <summary class="btn btn-primary" style="cursor: pointer;">
                        ⚡ Generate Monthly Statements
                    </summary>
                    <div style="position: absolute; right: 0; z-index: 100; background: white; border: 1px solid #cbd5e1; border-radius: 10px; padding: 1.25rem; box-shadow: 0 10px 25px rgba(0,0,0,0.15); width: 320px; text-align: left;">
                        <h4 style="font-size: 0.95rem; margin-bottom: 0.5rem; color: #1e293b;">Generate Monthly Payouts</h4>
                        <p style="font-size: 0.8rem; color: #64748b; margin-bottom: 1rem;">
                            Runs the calculator for all delivered vendor orders in the selected billing cycle, computing gross sales, 10% platform fee, and net amounts.
                        </p>
                        <form method="POST" action="<?= e(url('pages/dashboard/payouts.php')) ?>">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="generate_statements">
                            
                            <label class="form-label" style="font-size: 0.8rem; font-weight: 600; display: block; margin-bottom: 0.25rem;">
                                Select Billing Month:
                            </label>
                            <input type="month" name="payout_month" value="<?= e($defaultGenMonth) ?>" class="form-control" style="font-size: 0.85rem; margin-bottom: 1rem; width: 100%;" required>

                            <button type="submit" class="btn btn-primary btn-sm" style="width: 100%;">
                                Calculate &amp; Generate Drafts &rarr;
                            </button>
                        </form>
                    </div>
                </details>
            <?php endif; ?>

            <a href="<?= e(url('pages/dashboard/payouts.php?' . http_build_query(array_merge($_GET, ['export' => 'csv'])))) ?>" class="btn btn-amber">
                📥 Export CSV Payroll
            </a>
        </div>
    </div>

    <!-- Summary Metrics Cards -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.25rem; margin-bottom: 2rem;">
        <div class="card" style="padding: 1.25rem; border-left: 5px solid #27b327;">
            <div style="font-size: 0.8rem; font-weight: 700; color: #64748b; text-transform: uppercase;">
                Gross Delivered Sales
            </div>
            <div style="font-size: 1.6rem; font-weight: 800; color: #1e293b; margin-top: 0.35rem;">
                KES <?= number_format($summary['total_gross'], 2) ?>
            </div>
            <div style="font-size: 0.75rem; color: #64748b; margin-top: 0.25rem;">
                From <?= $summary['total_count'] ?> statements
            </div>
        </div>

        <?php if ($isAdmin): ?>
            <div class="card" style="padding: 1.25rem; border-left: 5px solid #6366f1;">
                <div style="font-size: 0.8rem; font-weight: 700; color: #64748b; text-transform: uppercase;">
                    10% Platform Commission
                </div>
                <div style="font-size: 1.6rem; font-weight: 800; color: #4338ca; margin-top: 0.35rem;">
                    KES <?= number_format($summary['total_fees'], 2) ?>
                </div>
                <div style="font-size: 0.75rem; color: #64748b; margin-top: 0.25rem;">
                    Retained SokoHub marketplace revenue
                </div>
            </div>
        <?php else: ?>
            <div class="card" style="padding: 1.25rem; border-left: 5px solid #f59e0b;">
                <div style="font-size: 0.8rem; font-weight: 700; color: #64748b; text-transform: uppercase;">
                    Platform Fees (10%)
                </div>
                <div style="font-size: 1.6rem; font-weight: 800; color: #b45309; margin-top: 0.35rem;">
                    KES <?= number_format($summary['total_fees'], 2) ?>
                </div>
                <div style="font-size: 0.75rem; color: #64748b; margin-top: 0.25rem;">
                    SokoHub service commission
                </div>
            </div>
        <?php endif; ?>

        <div class="card" style="padding: 1.25rem; border-left: 5px solid #10b981;">
            <div style="font-size: 0.8rem; font-weight: 700; color: #64748b; text-transform: uppercase;">
                Total Disbursed (Paid)
            </div>
            <div style="font-size: 1.6rem; font-weight: 800; color: #047857; margin-top: 0.35rem;">
                KES <?= number_format($summary['total_paid'], 2) ?>
            </div>
            <div style="font-size: 0.75rem; color: #64748b; margin-top: 0.25rem;">
                <?= $summary['count_paid'] ?> statements settled via M-Pesa
            </div>
        </div>

        <div class="card" style="padding: 1.25rem; border-left: 5px solid #eab308;">
            <div style="font-size: 0.8rem; font-weight: 700; color: #64748b; text-transform: uppercase;">
                Pending Settlement
            </div>
            <div style="font-size: 1.6rem; font-weight: 800; color: #854d0e; margin-top: 0.35rem;">
                KES <?= number_format($summary['total_pending'], 2) ?>
            </div>
            <div style="font-size: 0.75rem; color: #64748b; margin-top: 0.25rem;">
                <?= $summary['count_draft'] ?> Drafts &bull; <?= $summary['count_approved'] ?> Approved
            </div>
        </div>
    </div>

    <!-- Filter & Search Controls -->
    <div class="card" style="padding: 1rem 1.25rem; margin-bottom: 1.75rem;">
        <form method="GET" action="<?= e(url('pages/dashboard/payouts.php')) ?>" style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin: 0;">
            <div style="display: flex; align-items: center; gap: 0.35rem;">
                <label style="font-size: 0.85rem; font-weight: 600; color: #64748b;">Status:</label>
                <select name="status" class="form-control" style="font-size: 0.85rem; padding: 0.35rem 0.6rem; width: auto;" onchange="this.form.submit();">
                    <option value="">All Statuses</option>
                    <option value="draft" <?= $statusFilter === 'draft' ? 'selected' : '' ?>>Draft</option>
                    <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>Approved</option>
                    <option value="paid" <?= $statusFilter === 'paid' ? 'selected' : '' ?>>Paid (Disbursed)</option>
                </select>
            </div>

            <div style="display: flex; align-items: center; gap: 0.35rem;">
                <label style="font-size: 0.85rem; font-weight: 600; color: #64748b;">Month:</label>
                <input type="month" name="period" value="<?= e($periodFilter) ?>" class="form-control" style="font-size: 0.85rem; padding: 0.35rem 0.6rem; width: auto;" onchange="this.form.submit();">
            </div>

            <?php if ($isAdmin): ?>
                <div style="flex: 1; min-width: 200px;">
                    <input type="text" name="q" value="<?= e($searchQuery) ?>" placeholder="Search store, seller, or M-Pesa ref..." class="form-control" style="font-size: 0.85rem; padding: 0.35rem 0.6rem; width: 100%;">
                </div>
            <?php endif; ?>

            <button type="submit" class="btn btn-outline btn-sm">Filter</button>
            <?php if ($statusFilter !== '' || $periodFilter !== '' || $searchQuery !== ''): ?>
                <a href="<?= e(url('pages/dashboard/payouts.php')) ?>" class="btn btn-light btn-sm" style="color: #64748b;">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Payout Statements Table -->
    <div class="card" style="padding: 1.5rem; overflow-x: auto;">
        <?php if (empty($payouts)): ?>
            <div style="text-align: center; padding: 3rem 1rem;">
                <span style="font-size: 2.5rem;">📊</span>
                <h3 style="font-size: 1.2rem; color: #1e293b; margin: 0.75rem 0 0.25rem 0;">No payout statements found</h3>
                <p style="color: #64748b; font-size: 0.9rem; max-width: 480px; margin: 0 auto 1.5rem auto;">
                    <?= $isAdmin 
                        ? 'Click the "Generate Monthly Statements" button above to run the sales and commission calculator for delivered orders.' 
                        : 'Your monthly statements will appear here once delivered sales are verified for the billing cycle.' 
                    ?>
                </p>
            </div>
        <?php else: ?>
            <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
                <thead>
                    <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0; text-align: left;">
                        <th style="padding: 0.75rem;">Statement</th>
                        <th style="padding: 0.75rem;">Billing Period</th>
                        <?php if ($isAdmin): ?>
                            <th style="padding: 0.75rem;">Merchant / Store</th>
                        <?php endif; ?>
                        <th style="padding: 0.75rem;">M-Pesa Disbursal Phone</th>
                        <th style="padding: 0.75rem; text-align: right;">Gross Sales</th>
                        <th style="padding: 0.75rem; text-align: right;">Fee (10%)</th>
                        <th style="padding: 0.75rem; text-align: right;">Net Payout</th>
                        <th style="padding: 0.75rem;">Status</th>
                        <th style="padding: 0.75rem;">M-Pesa Ref</th>
                        <th style="padding: 0.75rem; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payouts as $p): 
                        $status = $p['status'];
                    ?>
                        <tr style="border-bottom: 1px solid #e2e8f0;">
                            <!-- Statement ID -->
                            <td style="padding: 0.75rem; font-family: monospace; font-weight: 600;">
                                <a href="<?= e(url('pages/dashboard/payouts.php?action=statement&id=' . (int) $p['id'])) ?>" style="color: #27b327; text-decoration: underline;">
                                    #SKH-PAY-<?= str_pad($p['id'], 5, '0', STR_PAD_LEFT) ?>
                                </a>
                            </td>

                            <!-- Period -->
                            <td style="padding: 0.75rem; color: #475569; font-size: 0.85rem;">
                                <div><strong><?= e(date('M Y', strtotime($p['period_start']))) ?></strong></div>
                                <div style="font-size: 0.75rem; color: #94a3b8;">
                                    <?= e($p['period_start']) ?> &rarr; <?= e($p['period_end']) ?>
                                </div>
                            </td>

                            <!-- Merchant / Store (Admin only) -->
                            <?php if ($isAdmin): ?>
                                <td style="padding: 0.75rem;">
                                    <div style="font-weight: 600; color: #1e293b;">
                                        <?= e($p['business_name'] ?: $p['seller_name']) ?>
                                    </div>
                                    <div style="font-size: 0.75rem; color: #64748b;">
                                        <?= e($p['seller_name']) ?>
                                        <?php if (($p['kyc_status'] ?? '') === 'approved'): ?>
                                            <span style="color: #16a34a; font-weight: 600;">&check; KYC</span>
                                        <?php else: ?>
                                            <span style="color: #d97706; font-weight: 600;">⚠️ Unverified</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            <?php endif; ?>

                            <!-- Disbursal Phone -->
                            <td style="padding: 0.75rem; font-family: monospace; color: #047857; font-size: 0.85rem;">
                                📱 <?= e($p['mpesa_phone'] ?? $p['seller_phone'] ?? 'N/A') ?>
                            </td>

                            <!-- Gross Sales -->
                            <td style="padding: 0.75rem; text-align: right; color: #475569;">
                                KES <?= number_format((float) $p['gross_sales'], 2) ?>
                            </td>

                            <!-- Platform Fee (10%) -->
                            <td style="padding: 0.75rem; text-align: right; color: #dc2626; font-size: 0.85rem;">
                                -KES <?= number_format((float) $p['platform_fee'], 2) ?>
                            </td>

                            <!-- Net Payout -->
                            <td style="padding: 0.75rem; text-align: right; font-weight: 800; color: #15803d; font-size: 0.95rem;">
                                KES <?= number_format((float) $p['net_amount'], 2) ?>
                            </td>

                            <!-- Status Badge -->
                            <td style="padding: 0.75rem;">
                                <?php if ($status === 'paid'): ?>
                                    <span class="badge badge-success" style="font-size: 0.75rem;">Paid &check;</span>
                                    <?php if (!empty($p['paid_at'])): ?>
                                        <div style="font-size: 0.7rem; color: #64748b; margin-top: 2px;">
                                            <?= e(date('d M, H:i', strtotime($p['paid_at']))) ?>
                                        </div>
                                    <?php endif; ?>
                                <?php elseif ($status === 'approved'): ?>
                                    <span class="badge badge-info" style="font-size: 0.75rem;">Approved</span>
                                <?php else: ?>
                                    <span class="badge badge-neutral" style="font-size: 0.75rem;">Draft</span>
                                <?php endif; ?>
                            </td>

                            <!-- M-Pesa Reference -->
                            <td style="padding: 0.75rem; font-family: monospace; font-size: 0.85rem;">
                                <?php if (!empty($p['mpesa_reference'])): ?>
                                    <span style="background: #e2e8f0; padding: 2px 6px; border-radius: 4px; font-weight: 600;">
                                        <?= e($p['mpesa_reference']) ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: #94a3b8; font-style: italic;">Pending</span>
                                <?php endif; ?>
                            </td>

                            <!-- Action Buttons -->
                            <td style="padding: 0.75rem; text-align: right;">
                                <div style="display: flex; gap: 0.35rem; justify-content: flex-end; align-items: center; flex-wrap: wrap;">
                                    <a href="<?= e(url('pages/dashboard/payouts.php?action=statement&id=' . (int) $p['id'])) ?>" class="btn btn-outline btn-sm" style="font-size: 0.75rem; padding: 0.25rem 0.5rem;" title="View official printable payout voucher">
                                        Voucher &rarr;
                                    </a>

                                    <?php if ($isAdmin && $status === 'draft'): ?>
                                        <form method="POST" action="<?= e(url('pages/dashboard/payouts.php')) ?>" style="margin: 0;">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="approve">
                                            <input type="hidden" name="payout_id" value="<?= (int) $p['id'] ?>">
                                            <button type="submit" class="btn btn-outline btn-sm" style="font-size: 0.75rem; padding: 0.25rem 0.5rem; color: #0284c7; border-color: #0284c7;">
                                                Approve
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($isAdmin && $status !== 'paid'): ?>
                                        <!-- Disburse Modal -->
                                        <details style="position: relative; display: inline-block;">
                                            <summary class="btn btn-amber btn-sm" style="font-size: 0.75rem; padding: 0.25rem 0.5rem; cursor: pointer;">
                                                💸 Disburse
                                            </summary>
                                            <div style="position: absolute; right: 0; z-index: 100; background: white; border: 1px solid #cbd5e1; border-radius: 8px; padding: 1rem; box-shadow: 0 10px 25px rgba(0,0,0,0.18); width: 280px; text-align: left;">
                                                <form method="POST" action="<?= e(url('pages/dashboard/payouts.php')) ?>">
                                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="mark_paid">
                                                    <input type="hidden" name="payout_id" value="<?= (int) $p['id'] ?>">

                                                    <div style="font-size: 0.8rem; font-weight: 700; color: #1e293b; margin-bottom: 0.25rem;">
                                                        Safaricom M-Pesa Disbursal
                                                    </div>
                                                    <div style="font-size: 0.75rem; color: #64748b; margin-bottom: 0.5rem;">
                                                        Disbursing <strong>KES <?= number_format((float) $p['net_amount'], 2) ?></strong> to <strong><?= e($p['mpesa_phone'] ?? $p['seller_phone'] ?? '') ?></strong>
                                                    </div>

                                                    <label style="font-size: 0.75rem; font-weight: 600; display: block; margin-bottom: 0.25rem;">
                                                        M-Pesa Transaction Code:
                                                    </label>
                                                    <input type="text" name="mpesa_reference" placeholder="e.g. QJD782394A" class="form-control" style="font-family: monospace; font-size: 0.85rem; text-transform: uppercase; margin-bottom: 0.75rem; width: 100%;" required>

                                                    <button type="submit" class="btn btn-primary btn-sm" style="width: 100%; font-size: 0.8rem;">
                                                        Confirm &amp; Record Disbursal
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
        <?php endif; ?>
    </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
