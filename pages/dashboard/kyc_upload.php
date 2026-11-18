<?php
/**
 * SokoHub Seller & Delivery Rider KYC Verification
 * Allows merchants and riders to submit their National ID, M-Pesa payment line, and credentials.
 * Authored by Kennedy Kalawa.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/kyc.php';

init_session();
$user = require_role(['seller', 'delivery']);
$userId = (int) $user['id'];
$role = (string) $user['role'];

$profile = Kyc::getUserKycProfile($userId, $role);
$currentStatus = $profile['verification_status'] ?? 'pending';
$rejectionReason = $profile['rejection_reason'] ?? null;
$savedMpesa = $profile['mpesa_phone'] ?? '';
$savedNid = $profile['national_id_no'] ?? '';
$savedTransport = $profile['transport_method'] ?? 'motorbike';
$savedPlate = $profile['vehicle_plate_number'] ?? '';
$savedModel = $profile['vehicle_model'] ?? '';

$errors = [];
$successMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Security token mismatch. Please refresh and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        // 1. Update Profile Credentials & Vehicle Data
        if ($action === 'save_credentials' || $action === 'upload_doc') {
            $nidInput = trim((string) ($_POST['national_id_no'] ?? $savedNid));
            $mpesaInput = trim((string) ($_POST['mpesa_phone'] ?? $savedMpesa));
            $transportInput = trim((string) ($_POST['transport_method'] ?? $savedTransport));
            $plateInput = trim((string) ($_POST['vehicle_plate_number'] ?? $savedPlate));
            $modelInput = trim((string) ($_POST['vehicle_model'] ?? $savedModel));

            $credResult = Kyc::updateKycDetails(
                $userId,
                $role,
                $nidInput,
                $mpesaInput,
                $role === 'delivery' ? $plateInput : null,
                $role === 'delivery' ? $transportInput : null,
                $role === 'delivery' ? $modelInput : null
            );
            if (!$credResult['ok']) {
                $errors[] = $credResult['error'];
            } else {
                $savedNid = $credResult['national_id_no'];
                $savedMpesa = $credResult['mpesa_phone'];
                if ($role === 'delivery') {
                    $savedPlate = $credResult['vehicle_plate_number'] ?? $savedPlate;
                    $savedTransport = $credResult['transport_method'] ?? $savedTransport;
                    $savedModel = $credResult['vehicle_model'] ?? $savedModel;
                }
            }
        }

        // 2. Upload Document
        if ($action === 'upload_doc' && empty($errors)) {
            $docType = (string) ($_POST['doc_type'] ?? '');
            $file = $_FILES['document_file'] ?? null;

            if (!$file || empty($file['name'])) {
                $errors[] = 'Please select a document file to upload.';
            } else {
                $uploadResult = Kyc::uploadDocument($userId, $role, $docType, $file);
                if ($uploadResult['ok']) {
                    set_flash('success', 'Document "' . htmlspecialchars($uploadResult['file_name']) . '" uploaded successfully! Your verification credentials are in review.');
                    redirect('pages/dashboard/kyc_upload.php');
                } else {
                    $errors[] = $uploadResult['error'];
                }
            }
        }
    }
}

$documents = Kyc::getUserDocuments($userId);

$pageTitle = 'SokoHub | Identity & KYC Verification';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="container section-space">
    <div class="page-head">
        <div>
            <h1><?= $role === 'delivery' ? 'Rider Compliance &amp; Verification' : 'Merchant Compliance &amp; Verification (KYC)' ?></h1>
            <p class="muted">Upload your official National ID and M-Pesa payment details to verify your account.</p>
        </div>
        <div class="header-actions">
            <a href="<?= e(url('pages/dashboard/dashboard.php')) ?>" class="btn btn-outline">
                <?= icon('arrow-left', 'lucide-xs') ?>
                <span>Back to Dashboard</span>
            </a>
        </div>
    </div>

    <!-- Status Banner Card -->
    <div class="card" style="margin-bottom: 2rem; border-left: 6px solid <?= $currentStatus === 'approved' ? '#10b981' : ($currentStatus === 'rejected' ? '#ef4444' : '#f59e0b') ?>;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
            <div>
                <span class="muted" style="font-size: 0.85rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Account Verification Status</span>
                <div style="margin-top: 0.25rem; display: flex; align-items: center; gap: 0.75rem;">
                    <?php if ($currentStatus === 'approved'): ?>
                        <span class="badge badge-success" style="font-size: 0.95rem; padding: 0.35rem 0.75rem;">
                            <?= icon('shield-check', 'lucide-xs', 'white') ?>
                            <span><?= $role === 'delivery' ? 'Verified Delivery Rider' : 'Verified Merchant' ?></span>
                        </span>
                        <span style="font-size: 0.95rem; color: #10b981; font-weight: 600;">
                            Your account is fully verified! You can publish products and process orders.
                        </span>
                    <?php elseif ($currentStatus === 'rejected'): ?>
                        <span class="badge badge-danger" style="font-size: 0.95rem; padding: 0.35rem 0.75rem;">
                            <?= icon('alert-circle', 'lucide-xs', 'white') ?>
                            <span>Verification Rejected</span>
                        </span>
                        <span style="font-size: 0.95rem; color: #ef4444; font-weight: 600;">Please review the administrator notes below and resubmit clear documents.</span>
                    <?php elseif ($currentStatus === 'suspended'): ?>
                        <span class="badge badge-danger" style="font-size: 0.95rem; padding: 0.35rem 0.75rem;">
                            <?= icon('x-circle', 'lucide-xs', 'white') ?>
                            <span>Account Suspended</span>
                        </span>
                        <span style="font-size: 0.95rem; color: #ef4444; font-weight: 600;">Contact platform administration for compliance reactivation.</span>
                    <?php else: ?>
                        <span class="badge badge-amber" style="font-size: 0.95rem; padding: 0.35rem 0.75rem;">
                            <?= icon('clock', 'lucide-xs', 'white') ?>
                            <span>Pending Admin Review</span>
                        </span>
                        <span style="font-size: 0.95rem; color: #b45309; font-weight: 600;">Your credentials and documents are currently in the administrator review queue.</span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($currentStatus === 'approved'): ?>
                <a href="<?= e(url($role === 'delivery' ? 'pages/dashboard/deliveries.php' : 'pages/dashboard/manage_products.php')) ?>" class="btn btn-primary">
                    <?= $role === 'delivery' ? icon('truck', 'lucide-xs') : icon('package', 'lucide-xs') ?>
                    <span><?= $role === 'delivery' ? 'Go to Delivery Queue' : 'Go to Product Catalog' ?></span>
                </a>
            <?php endif; ?>
        </div>

        <?php if ($currentStatus === 'rejected' && !empty($rejectionReason)): ?>
            <div style="margin-top: 1rem; padding: 0.75rem 1rem; background: #fee2e2; border-radius: 6px; color: #991b1b; font-size: 0.9rem;">
                <strong>Administrator Rejection Reason:</strong> <?= e($rejectionReason) ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error" style="margin-bottom: 2rem;">
            <div>
                <?php foreach ($errors as $err): ?>
                    <div><?= e($err) ?></div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
        <!-- Left: Upload & Credentials Form -->
        <div class="card">
            <h2 style="margin-bottom: 0.5rem;">Mandatory Identity Details</h2>
            <p class="muted" style="margin-bottom: 1.5rem; font-size: 0.9rem;">
                All <?= $role === 'delivery' ? 'delivery riders' : 'sellers' ?> must provide an official National ID and a registered Safaricom M-Pesa line for payout dispatches.
            </p>

            <form action="<?= e(url('pages/dashboard/kyc_upload.php')) ?>" method="POST" enctype="multipart/form-data" id="kycForm">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="upload_doc">

                <div class="form-group" style="margin-bottom: 1.25rem;">
                    <label for="national_id_no" class="form-label" style="font-weight: 600;">Kenyan National ID / Passport Number *</label>
                    <input type="text" name="national_id_no" id="national_id_no" class="form-control" placeholder="e.g. 12345678" value="<?= e($savedNid) ?>" required>
                    <small class="muted">Enter your 7 or 8-digit National ID number as printed on your ID card.</small>
                </div>

                <div class="form-group" style="margin-bottom: 1.25rem;">
                    <label for="mpesa_phone" class="form-label" style="font-weight: 600;">Safaricom M-Pesa Phone Number *</label>
                    <input type="tel" name="mpesa_phone" id="mpesa_phone" class="form-control" placeholder="e.g. 0712345678 or 0112345678" value="<?= e($savedMpesa) ?>" required>
                    <small class="muted">Payouts will be sent directly to this M-Pesa number. Must be a valid Kenyan mobile line.</small>
                </div>

                <?php if ($role === 'delivery'): ?>
                    <div class="form-group" style="margin-bottom: 1.25rem;">
                        <label for="transport_method" class="form-label" style="font-weight: 600;">Delivery Transport Category *</label>
                        <select name="transport_method" id="transport_method" class="form-control" required>
                            <option value="motorbike" <?= $savedTransport === 'motorbike' ? 'selected' : '' ?>>Motorcycle / Boda-Boda</option>
                            <option value="tuk_tuk" <?= $savedTransport === 'tuk_tuk' ? 'selected' : '' ?>>Tuk-Tuk (Auto Rickshaw)</option>
                            <option value="pickup_truck" <?= $savedTransport === 'pickup_truck' ? 'selected' : '' ?>>Pickup Truck / Van</option>
                            <option value="car" <?= $savedTransport === 'car' ? 'selected' : '' ?>>Car / Station Wagon</option>
                            <option value="bicycle" <?= $savedTransport === 'bicycle' ? 'selected' : '' ?>>Bicycle (Plate exempt)</option>
                        </select>
                        <small class="muted">Your active mode of transport for marketplace dispatches.</small>
                    </div>

                    <div id="vehiclePlateContainer" style="<?= $savedTransport === 'bicycle' ? 'display: none;' : '' ?>">
                        <div class="form-group" style="margin-bottom: 1.25rem;">
                            <label for="vehicle_plate_number" class="form-label" style="font-weight: 600;">Kenyan Vehicle Number Plate *</label>
                            <input type="text" name="vehicle_plate_number" id="vehicle_plate_number" class="form-control" placeholder="e.g. KMDF 450A or KDA 567Z" value="<?= e($savedPlate) ?>" style="text-transform: uppercase; font-family: monospace; font-weight: 700; letter-spacing: 0.5px;">
                            <small class="muted">Official Kenyan registration plate format: <code>KMDF 123A</code> (motorcycle) or <code>KDA 456B</code> (vehicle).</small>
                        </div>

                        <div class="form-group" style="margin-bottom: 1.25rem;">
                            <label for="vehicle_model" class="form-label" style="font-weight: 600;">Vehicle Make / Model (Optional)</label>
                            <input type="text" name="vehicle_model" id="vehicle_model" class="form-control" placeholder="e.g. Bajaj Boxer BM150, TVS Star HLX, Toyota Probox" value="<?= e($savedModel) ?>">
                            <small class="muted">Vehicle make and model to help merchants and buyers identify you.</small>
                        </div>
                    </div>
                <?php endif; ?>

                <hr style="border: 0; border-top: 1px solid #e2e8f0; margin: 1.5rem 0;">

                <h3 style="margin-bottom: 0.5rem; font-size: 1.1rem;">Upload Verification Document</h3>

                <div class="form-group" style="margin-bottom: 1.25rem;">
                    <label for="doc_type" class="form-label" style="font-weight: 600;">Document Category *</label>
                    <select name="doc_type" id="doc_type" class="form-control" required>
                        <option value="national_id">National ID Card (Front or Both Sides)</option>
                        <?php if ($role === 'delivery'): ?>
                            <option value="rider_license">Driving License / Transport Permit</option>
                        <?php else: ?>
                            <option value="business_registration">County Business Permit / Store Registration</option>
                        <?php endif; ?>
                        <option value="other">KRA Tax Certificate / Proof of Address</option>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom: 1.5rem;">
                    <label for="document_file" class="form-label" style="font-weight: 600;">Select Document File *</label>
                    <input type="file" name="document_file" id="document_file" class="form-control" accept=".pdf,.png,.jpg,.jpeg" required>
                    <small class="muted" style="display: block; margin-top: 0.35rem;">
                        <strong>Supported Formats:</strong> PDF documents (<code>.pdf</code>) or Images (<code>.png</code>, <code>.jpg</code>, <code>.jpeg</code>). Max: 5 MB.
                    </small>
                    <div id="fileTypeWarning" style="display: none; color: #ef4444; font-size: 0.85rem; margin-top: 0.35rem; font-weight: 600;"></div>
                </div>

                <button type="submit" class="btn btn-primary" id="submitBtn" style="width: 100%;">
                    <?= icon('upload', 'lucide-xs') ?>
                    <span>Save Details &amp; Upload Document</span>
                </button>
            </form>
        </div>

        <!-- Right: Requirements Guide & Benefits -->
        <div class="card" style="background: #f8fafc; height: fit-content;">
            <h2 style="margin-bottom: 0.75rem;">
                <?= icon('shield-check', 'lucide-sm', 'emerald') ?>
                <span>Verification Guidelines</span>
            </h2>
            <ul style="padding-left: 1.25rem; line-height: 1.8; color: #475569; font-size: 0.9rem;">
                <li><strong>Why KYC is Mandatory:</strong> SokoHub enforces strict trust and safety. Both sellers and delivery riders must have a verified government identity to prevent scams and ensure accountability.</li>
                <li><strong>M-Pesa Verification:</strong> Double-check your phone number. All customer order earnings and delivery commissions are disbursed to your registered M-Pesa line.</li>
                <li><strong>Clear Image Quality:</strong> Ensure your document photo has no blur, glare, or cut-off corners. Your full name, photo, and ID number must match your account.</li>
                <li><strong>Accepted Formats Only:</strong> Upload only <code>.pdf</code>, <code>.png</code>, or <code>.jpg</code> files. Word documents (<code>.docx</code>) and text files are not accepted.</li>
            </ul>
        </div>
    </div>

    <!-- Submitted Documents History -->
    <div class="card" style="margin-top: 2rem;">
        <div class="section-heading" style="margin-bottom: 1rem;">
            <h2>
                <?= icon('file-text', 'lucide-sm', 'blue') ?>
                <span>Submitted Documents History</span>
            </h2>
            <span class="muted"><?= count($documents) ?> document(s) uploaded</span>
        </div>

        <?php if (empty($documents)): ?>
            <div style="text-align: center; padding: 2.5rem; color: #64748b;">
                <p>No documents uploaded yet. Submit your National ID and business credentials above.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table" style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="border-bottom: 2px solid #e2e8f0; text-align: left;">
                            <th style="padding: 0.75rem;">Document Type</th>
                            <th style="padding: 0.75rem;">Original File Name</th>
                            <th style="padding: 0.75rem;">File Size</th>
                            <th style="padding: 0.75rem;">Preview</th>
                            <th style="padding: 0.75rem;">Uploaded Date</th>
                            <th style="padding: 0.75rem;">Review Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($documents as $doc): 
                            $sizeKB = $doc['file_size'] ? round($doc['file_size'] / 1024, 1) . ' KB' : 'N/A';
                            $isPdf = strtolower(pathinfo($doc['file_path'], PATHINFO_EXTENSION)) === 'pdf';
                        ?>
                            <tr style="border-bottom: 1px solid #edf2f7;">
                                <td style="padding: 0.75rem; font-weight: 600;">
                                    <?php
                                    $typeLabels = [
                                        'national_id' => 'National ID Card',
                                        'business_registration' => 'Business Permit / Registration',
                                        'rider_license' => 'Rider Driving License',
                                        'other' => 'Tax / Other Credential',
                                    ];
                                    echo e($typeLabels[$doc['doc_type']] ?? $doc['doc_type']);
                                    ?>
                                </td>
                                <td style="padding: 0.75rem; font-size: 0.85rem;" class="muted">
                                    <?= e($doc['file_name'] ?: basename($doc['file_path'])) ?>
                                </td>
                                <td style="padding: 0.75rem; font-size: 0.85rem;" class="muted">
                                    <?= e($sizeKB) ?>
                                </td>
                                <td style="padding: 0.75rem;">
                                    <a href="<?= e(url($doc['file_path'])) ?>" target="_blank" class="btn btn-outline btn-sm" style="font-size: 0.8rem; display: inline-flex; align-items: center; gap: 0.25rem;">
                                        <?= icon('eye', 'lucide-xs', 'blue') ?>
                                        <span>View <?= $isPdf ? 'PDF' : 'Image' ?></span>
                                    </a>
                                </td>
                                <td style="padding: 0.75rem; font-size: 0.85rem;" class="muted">
                                    <?= e(date('M d, Y H:i', strtotime($doc['uploaded_at']))) ?>
                                </td>
                                <td style="padding: 0.75rem;">
                                    <?php if ($doc['review_status'] === 'accepted'): ?>
                                        <span class="badge badge-success">
                                            <?= icon('check-circle', 'lucide-xs') ?>
                                            <span>Accepted</span>
                                        </span>
                                    <?php elseif ($doc['review_status'] === 'rejected'): ?>
                                        <span class="badge badge-danger">
                                            <?= icon('x-circle', 'lucide-xs') ?>
                                            <span>Rejected</span>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-amber">
                                            <?= icon('clock', 'lucide-xs') ?>
                                            <span>Under Review</span>
                                        </span>
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

<script>
// Toggle vehicle number plate requirement based on transport category
var transportSelect = document.getElementById('transport_method');
var plateContainer = document.getElementById('vehiclePlateContainer');
var plateInput = document.getElementById('vehicle_plate_number');

if (transportSelect && plateContainer) {
    transportSelect.addEventListener('change', function() {
        if (this.value === 'bicycle') {
            plateContainer.style.display = 'none';
            if (plateInput) plateInput.removeAttribute('required');
        } else {
            plateContainer.style.display = 'block';
            if (plateInput) plateInput.setAttribute('required', 'required');
        }
    });
}

// Client-side file extension check to immediately notify if wrong format is picked
document.getElementById('document_file').addEventListener('change', function(e) {
    var file = this.files[0];
    var warning = document.getElementById('fileTypeWarning');
    var submitBtn = document.getElementById('submitBtn');
    if (!file) return;

    var validExts = ['pdf', 'png', 'jpg', 'jpeg'];
    var ext = file.name.split('.').pop().toLowerCase();

    if (validExts.indexOf(ext) === -1) {
        warning.innerHTML = '⚠️ Unsupported file format (.' + ext + '). SokoHub only accepts PDF documents (.pdf) or image files (.png, .jpg, .jpeg). Please upload a supported format.';
        warning.style.display = 'block';
        submitBtn.disabled = true;
    } else if (file.size > 5 * 1024 * 1024) {
        warning.innerHTML = '⚠️ File size (' + (file.size / (1024*1024)).toFixed(1) + ' MB) exceeds the 5 MB limit.';
        warning.style.display = 'block';
        submitBtn.disabled = true;
    } else {
        warning.style.display = 'none';
        submitBtn.disabled = false;
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
