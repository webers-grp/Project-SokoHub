<?php
/**
 * SokoHub Customer & Merchant Profile Management
 *
 * Allows users to:
 * - Update personal contact information (Full name, phone, town)
 * - Manage saved delivery addresses (with default selection)
 * - Change password with secure re-authentication
 * - Configure offline telephony notification preferences
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/telephony.php';

init_session();
$user = require_auth();
$userId = (int) $user['id'];
$pdo = get_db_connection();

$activeTab = trim((string) ($_GET['tab'] ?? 'general'));

// -------------------------------------------------------------
// POST HANDLERS
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Security token mismatch. Please try again.');
        redirect('pages/auth/profile.php?tab=' . urlencode($activeTab));
    }

    $action = trim((string) ($_POST['action'] ?? ''));

    // 1. Update Personal Info
    if ($action === 'update_profile') {
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $phone    = trim((string) ($_POST['phone'] ?? ''));
        $town     = trim((string) ($_POST['town'] ?? ''));
        $alertPref = trim((string) ($_POST['offline_alert_preference'] ?? 'both'));

        if (mb_strlen($fullName) < 3) {
            set_flash('error', 'Full name must be at least 3 characters.');
            redirect('pages/auth/profile.php?tab=general');
        }

        $cleanPhone = Telephony::normalizePhone($phone);
        if (!$cleanPhone) {
            set_flash('error', 'Please provide a valid Kenyan phone number (e.g. 0712345678).');
            redirect('pages/auth/profile.php?tab=general');
        }

        if (!in_array($alertPref, ['both', 'sms', 'call', 'none'], true)) {
            $alertPref = 'both';
        }

        $upd = $pdo->prepare('
            UPDATE users 
            SET full_name = :name, phone = :phone, town = :town, offline_alert_preference = :pref, updated_at = NOW()
            WHERE id = :id
        ');
        $upd->execute([
            ':name'  => $fullName,
            ':phone' => $cleanPhone,
            ':town'  => $town,
            ':pref'  => $alertPref,
            ':id'    => $userId,
        ]);

        // Refresh session
        $_SESSION['user']['full_name'] = $fullName;
        $_SESSION['user']['phone']     = $cleanPhone;

        Audit::record('user.profile_updated', 'user', $userId, [
            'full_name' => $fullName,
            'phone'     => $cleanPhone,
            'town'      => $town,
        ], $userId, $user['role']);

        set_flash('success', 'Profile information updated successfully.');
        redirect('pages/auth/profile.php?tab=general');
    }

    // 2. Add New Saved Address
    if ($action === 'add_address') {
        $label       = trim((string) ($_POST['label'] ?? 'Home'));
        $town        = trim((string) ($_POST['town'] ?? ''));
        $addressLine = trim((string) ($_POST['address_line'] ?? ''));
        $landmark    = trim((string) ($_POST['landmark'] ?? ''));
        $phone       = trim((string) ($_POST['phone'] ?? ''));
        $isDefault   = !empty($_POST['is_default']) ? 1 : 0;

        if (empty($town) || empty($addressLine)) {
            set_flash('error', 'Town and specific delivery address line are required.');
            redirect('pages/auth/profile.php?tab=addresses');
        }

        if ($isDefault) {
            $pdo->prepare('UPDATE user_addresses SET is_default = 0 WHERE user_id = :uid')->execute([':uid' => $userId]);
        } else {
            // If this is the user's first address, make it default automatically
            $chk = $pdo->prepare('SELECT COUNT(*) FROM user_addresses WHERE user_id = :uid');
            $chk->execute([':uid' => $userId]);
            if ((int) $chk->fetchColumn() === 0) {
                $isDefault = 1;
            }
        }

        $ins = $pdo->prepare('
            INSERT INTO user_addresses (user_id, label, town, address_line, landmark, phone, is_default, created_at)
            VALUES (:uid, :label, :town, :addr, :land, :phone, :def, NOW())
        ');
        $ins->execute([
            ':uid'   => $userId,
            ':label' => $label,
            ':town'  => $town,
            ':addr'  => $addressLine,
            ':land'  => $landmark ?: null,
            ':phone' => $phone ?: null,
            ':def'   => $isDefault,
        ]);

        Audit::record('user.address_added', 'user_address', (int) $pdo->lastInsertId(), [
            'town'         => $town,
            'address_line' => $addressLine,
        ], $userId, $user['role']);

        set_flash('success', 'Delivery address saved successfully.');
        redirect('pages/auth/profile.php?tab=addresses');
    }

    // 3. Set Default Address
    if ($action === 'set_default_address') {
        $addrId = (int) ($_POST['address_id'] ?? 0);
        $pdo->prepare('UPDATE user_addresses SET is_default = 0 WHERE user_id = :uid')->execute([':uid' => $userId]);
        $pdo->prepare('UPDATE user_addresses SET is_default = 1 WHERE id = :id AND user_id = :uid')
            ->execute([':id' => $addrId, ':uid' => $userId]);

        set_flash('success', 'Default delivery address updated.');
        redirect('pages/auth/profile.php?tab=addresses');
    }

    // 4. Delete Address
    if ($action === 'delete_address') {
        $addrId = (int) ($_POST['address_id'] ?? 0);
        $pdo->prepare('DELETE FROM user_addresses WHERE id = :id AND user_id = :uid')
            ->execute([':id' => $addrId, ':uid' => $userId]);

        set_flash('success', 'Address removed from your profile.');
        redirect('pages/auth/profile.php?tab=addresses');
    }

    // 5. Change Password with Re-authentication
    if ($action === 'change_password') {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword     = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        // Fetch user password hash
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $hash = $stmt->fetchColumn();

        if (!password_verify($currentPassword, $hash)) {
            set_flash('error', 'Your current password was incorrect. Please re-enter.');
            redirect('pages/auth/profile.php?tab=security');
        }

        if (strlen($newPassword) < 8) {
            set_flash('error', 'New password must be at least 8 characters long.');
            redirect('pages/auth/profile.php?tab=security');
        }

        if ($newPassword !== $confirmPassword) {
            set_flash('error', 'New password and confirmation do not match.');
            redirect('pages/auth/profile.php?tab=security');
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $pdo->prepare('UPDATE users SET password_hash = :hash, updated_at = NOW() WHERE id = :id')
            ->execute([':hash' => $newHash, ':id' => $userId]);

        Audit::record('user.password_changed', 'user', $userId, [], $userId, $user['role']);

        set_flash('success', 'Password successfully updated.');
        redirect('pages/auth/profile.php?tab=security');
    }
}

// Fetch current user details
$uStmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
$uStmt->execute([':id' => $userId]);
$profile = $uStmt->fetch(PDO::FETCH_ASSOC);

// Fetch saved addresses
$aStmt = $pdo->prepare('SELECT * FROM user_addresses WHERE user_id = :uid ORDER BY is_default DESC, id DESC');
$aStmt->execute([':uid' => $userId]);
$savedAddresses = $aStmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'My Account Profile - SokoHub';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="page-container" style="max-width: 960px; margin: 2rem auto; padding: 0 1rem;">
    <!-- Breadcrumb & Header -->
    <div style="margin-bottom: 2rem;">
        <nav style="font-size: 0.85rem; color: #64748b; margin-bottom: 0.5rem;">
            <a href="<?= e(url('index.php')) ?>" style="color: #64748b; text-decoration: none;">Home</a> &rsaquo;
            <span style="color: #0f172a; font-weight: 600;">My Account</span>
        </nav>
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
            <div>
                <h1 style="font-size: 1.75rem; font-weight: 800; color: #0f172a; margin: 0;">Account Settings</h1>
                <p style="color: #64748b; margin: 0.25rem 0 0; font-size: 0.9rem;">
                    Manage your personal profile, delivery locations, and security settings.
                </p>
            </div>
            <span class="badge" style="background: #e2e8f0; color: #334155; padding: 0.4rem 0.8rem; border-radius: 9999px; font-weight: 700; text-transform: uppercase; font-size: 0.75rem;">
                Role: <?= e(ucfirst($profile['role'])) ?>
            </span>
        </div>
    </div>

    <!-- Tab Navigation -->
    <div style="display: flex; gap: 0.5rem; border-bottom: 2px solid #e2e8f0; margin-bottom: 2rem; overflow-x: auto;">
        <a href="?tab=general" style="padding: 0.75rem 1.25rem; font-weight: 600; text-decoration: none; border-bottom: 3px solid <?= $activeTab === 'general' ? '#16a34a' : 'transparent' ?>; color: <?= $activeTab === 'general' ? '#16a34a' : '#64748b' ?>;">
            👤 Personal Details
        </a>
        <a href="?tab=addresses" style="padding: 0.75rem 1.25rem; font-weight: 600; text-decoration: none; border-bottom: 3px solid <?= $activeTab === 'addresses' ? '#16a34a' : 'transparent' ?>; color: <?= $activeTab === 'addresses' ? '#16a34a' : '#64748b' ?>;">
            📍 Saved Addresses (<?= count($savedAddresses) ?>)
        </a>
        <a href="?tab=security" style="padding: 0.75rem 1.25rem; font-weight: 600; text-decoration: none; border-bottom: 3px solid <?= $activeTab === 'security' ? '#16a34a' : 'transparent' ?>; color: <?= $activeTab === 'security' ? '#16a34a' : '#64748b' ?>;">
            🔒 Security &amp; Password
        </a>
    </div>

    <!-- TAB 1: GENERAL -->
    <?php if ($activeTab === 'general'): ?>
        <div style="background: white; border: 1px solid #e2e8f0; border-radius: 12px; padding: 2rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <h2 style="font-size: 1.25rem; font-weight: 700; color: #0f172a; margin-top: 0; margin-bottom: 1.5rem;">
                Personal Information
            </h2>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="update_profile">

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.25rem; margin-bottom: 1.5rem;">
                    <div>
                        <label style="display: block; font-weight: 600; font-size: 0.85rem; color: #334155; margin-bottom: 0.35rem;">
                            Full Name
                        </label>
                        <input type="text" name="full_name" value="<?= e($profile['full_name']) ?>" class="form-control" required style="width: 100%;">
                    </div>
                    <div>
                        <label style="display: block; font-weight: 600; font-size: 0.85rem; color: #334155; margin-bottom: 0.35rem;">
                            Email Address (Read-only)
                        </label>
                        <input type="email" value="<?= e($profile['email']) ?>" class="form-control" disabled style="width: 100%; background: #f8fafc; color: #94a3b8;">
                    </div>
                    <div>
                        <label style="display: block; font-weight: 600; font-size: 0.85rem; color: #334155; margin-bottom: 0.35rem;">
                            Phone Number (M-Pesa format)
                        </label>
                        <input type="text" name="phone" value="<?= e($profile['phone']) ?>" class="form-control" required style="width: 100%;">
                    </div>
                    <div>
                        <label style="display: block; font-weight: 600; font-size: 0.85rem; color: #334155; margin-bottom: 0.35rem;">
                            Primary County / Town
                        </label>
                        <input type="text" name="town" value="<?= e($profile['town'] ?? 'Kutus') ?>" class="form-control" required style="width: 100%;">
                    </div>
                </div>

                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 1.25rem; margin-bottom: 1.5rem;">
                    <label style="display: block; font-weight: 700; font-size: 0.9rem; color: #0f172a; margin-bottom: 0.25rem;">
                        Offline Telephony Notification Preference
                    </label>
                    <p style="font-size: 0.8rem; color: #64748b; margin: 0 0 0.75rem;">
                        How would you like to be notified if you are offline when new order updates or delivery tasks arrive?
                    </p>
                    <div style="display: flex; gap: 1.5rem; flex-wrap: wrap;">
                        <label style="font-size: 0.85rem; display: flex; align-items: center; gap: 0.4rem; cursor: pointer;">
                            <input type="radio" name="offline_alert_preference" value="both" <?= ($profile['offline_alert_preference'] ?? 'both') === 'both' ? 'checked' : '' ?>>
                            SMS &amp; Voice Call
                        </label>
                        <label style="font-size: 0.85rem; display: flex; align-items: center; gap: 0.4rem; cursor: pointer;">
                            <input type="radio" name="offline_alert_preference" value="sms" <?= ($profile['offline_alert_preference'] ?? '') === 'sms' ? 'checked' : '' ?>>
                            SMS Only
                        </label>
                        <label style="font-size: 0.85rem; display: flex; align-items: center; gap: 0.4rem; cursor: pointer;">
                            <input type="radio" name="offline_alert_preference" value="call" <?= ($profile['offline_alert_preference'] ?? '') === 'call' ? 'checked' : '' ?>>
                            Voice Call Only
                        </label>
                        <label style="font-size: 0.85rem; display: flex; align-items: center; gap: 0.4rem; cursor: pointer;">
                            <input type="radio" name="offline_alert_preference" value="none" <?= ($profile['offline_alert_preference'] ?? '') === 'none' ? 'checked' : '' ?>>
                            None (In-app only)
                        </label>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" style="padding: 0.75rem 1.5rem; font-weight: 600;">
                    Save Profile Changes
                </button>
            </form>
        </div>
    <?php endif; ?>

    <!-- TAB 2: ADDRESSES -->
    <?php if ($activeTab === 'addresses'): ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 2rem;">
            <!-- Address List -->
            <div>
                <h2 style="font-size: 1.25rem; font-weight: 700; color: #0f172a; margin-top: 0; margin-bottom: 1rem;">
                    Saved Delivery Locations
                </h2>
                <?php if (empty($savedAddresses)): ?>
                    <div style="background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 8px; padding: 2rem; text-align: center; color: #64748b;">
                        <p style="margin: 0 0 0.5rem; font-weight: 600;">No saved delivery addresses yet.</p>
                        <p style="margin: 0; font-size: 0.85rem;">Add your home, hostel, or workplace address to speed up checkout.</p>
                    </div>
                <?php else: ?>
                    <div style="display: flex; flex-direction: column; gap: 1rem;">
                        <?php foreach ($savedAddresses as $addr): ?>
                            <div style="background: white; border: 1px solid <?= $addr['is_default'] ? '#16a34a' : '#e2e8f0' ?>; border-radius: 8px; padding: 1.25rem; position: relative;">
                                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.5rem;">
                                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                                        <strong style="font-size: 0.95rem; color: #0f172a;"><?= e($addr['label']) ?></strong>
                                        <?php if ($addr['is_default']): ?>
                                            <span style="background: #dcfce7; color: #15803d; font-size: 0.7rem; font-weight: 700; padding: 0.2rem 0.5rem; border-radius: 9999px;">
                                                DEFAULT
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="display: flex; gap: 0.5rem;">
                                        <?php if (!$addr['is_default']): ?>
                                            <form method="POST" action="" style="margin: 0;">
                                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="set_default_address">
                                                <input type="hidden" name="address_id" value="<?= (int) $addr['id'] ?>">
                                                <button type="submit" class="btn btn-outline btn-sm" style="font-size: 0.75rem; padding: 0.2rem 0.5rem;">
                                                    Make Default
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="POST" action="" onsubmit="return confirm('Remove this delivery address?');" style="margin: 0;">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="delete_address">
                                            <input type="hidden" name="address_id" value="<?= (int) $addr['id'] ?>">
                                            <button type="submit" style="background: none; border: none; color: #ef4444; cursor: pointer; font-size: 0.85rem;" title="Delete">
                                                🗑️
                                            </button>
                                        </form>
                                    </div>
                                </div>
                                <div style="font-size: 0.85rem; color: #334155; line-height: 1.4;">
                                    <div><strong>Town:</strong> <?= e($addr['town']) ?></div>
                                    <div><strong>Address:</strong> <?= e($addr['address_line']) ?></div>
                                    <?php if (!empty($addr['landmark'])): ?>
                                        <div style="color: #64748b;"><strong>Landmark:</strong> <?= e($addr['landmark']) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($addr['phone'])): ?>
                                        <div style="color: #64748b;"><strong>Contact Phone:</strong> <?= e($addr['phone']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Add Address Form -->
            <div style="background: white; border: 1px solid #e2e8f0; border-radius: 12px; padding: 1.75rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05); height: fit-content;">
                <h3 style="font-size: 1.1rem; font-weight: 700; color: #0f172a; margin-top: 0; margin-bottom: 1.25rem;">
                    ➕ Add New Address
                </h3>
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="add_address">

                    <div style="margin-bottom: 1rem;">
                        <label style="display: block; font-weight: 600; font-size: 0.85rem; color: #334155; margin-bottom: 0.25rem;">
                            Label (e.g. Home, Office, Hostel)
                        </label>
                        <input type="text" name="label" placeholder="e.g. Home / Kirinyaga University Hostel" class="form-control" required style="width: 100%;">
                    </div>

                    <div style="margin-bottom: 1rem;">
                        <label style="display: block; font-weight: 600; font-size: 0.85rem; color: #334155; margin-bottom: 0.25rem;">
                            Town / Delivery Zone
                        </label>
                        <select name="town" class="form-control" required style="width: 100%;">
                            <option value="Kutus">Kutus (Main Stage &amp; University)</option>
                            <option value="Kerugoya">Kerugoya Town</option>
                            <option value="Kagio">Kagio Market</option>
                            <option value="Karatina">Karatina Junction</option>
                            <option value="Sagana">Sagana River Stage</option>
                            <option value="Embu">Embu Central</option>
                        </select>
                    </div>

                    <div style="margin-bottom: 1rem;">
                        <label style="display: block; font-weight: 600; font-size: 0.85rem; color: #334155; margin-bottom: 0.25rem;">
                            Street Address / Building
                        </label>
                        <input type="text" name="address_line" placeholder="e.g. Joy Apartments, Room 4B" class="form-control" required style="width: 100%;">
                    </div>

                    <div style="margin-bottom: 1rem;">
                        <label style="display: block; font-weight: 600; font-size: 0.85rem; color: #334155; margin-bottom: 0.25rem;">
                            Nearby Landmark
                        </label>
                        <input type="text" name="landmark" placeholder="e.g. Near Equity Bank ATM" class="form-control" style="width: 100%;">
                    </div>

                    <div style="margin-bottom: 1rem;">
                        <label style="display: block; font-weight: 600; font-size: 0.85rem; color: #334155; margin-bottom: 0.25rem;">
                            Recipient Contact Phone
                        </label>
                        <input type="text" name="phone" placeholder="07XXXXXXXX" value="<?= e($profile['phone']) ?>" class="form-control" style="width: 100%;">
                    </div>

                    <div style="margin-bottom: 1.25rem;">
                        <label style="display: flex; align-items: center; gap: 0.4rem; font-size: 0.85rem; cursor: pointer;">
                            <input type="checkbox" name="is_default" value="1" <?= empty($savedAddresses) ? 'checked' : '' ?>>
                            Set as primary default delivery address
                        </label>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%; padding: 0.65rem; font-weight: 600;">
                        Save Address
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <!-- TAB 3: SECURITY -->
    <?php if ($activeTab === 'security'): ?>
        <div style="max-width: 540px; background: white; border: 1px solid #e2e8f0; border-radius: 12px; padding: 2rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <h2 style="font-size: 1.25rem; font-weight: 700; color: #0f172a; margin-top: 0; margin-bottom: 0.5rem;">
                Change Account Password
            </h2>
            <p style="font-size: 0.85rem; color: #64748b; margin-top: 0; margin-bottom: 1.5rem;">
                Enter your existing password to confirm your identity, followed by your new password.
            </p>

            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="change_password">

                <div style="margin-bottom: 1.25rem;">
                    <label style="display: block; font-weight: 600; font-size: 0.85rem; color: #334155; margin-bottom: 0.35rem;">
                        Current Password
                    </label>
                    <input type="password" name="current_password" class="form-control" required style="width: 100%;">
                </div>

                <div style="margin-bottom: 1.25rem;">
                    <label style="display: block; font-weight: 600; font-size: 0.85rem; color: #334155; margin-bottom: 0.35rem;">
                        New Password (minimum 8 characters)
                    </label>
                    <input type="password" name="new_password" minlength="8" class="form-control" required style="width: 100%;">
                </div>

                <div style="margin-bottom: 1.5rem;">
                    <label style="display: block; font-weight: 600; font-size: 0.85rem; color: #334155; margin-bottom: 0.35rem;">
                        Confirm New Password
                    </label>
                    <input type="password" name="confirm_password" minlength="8" class="form-control" required style="width: 100%;">
                </div>

                <button type="submit" class="btn btn-primary" style="padding: 0.75rem 1.5rem; font-weight: 600;">
                    Update Password
                </button>
            </form>
        </div>
    <?php endif; ?>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
