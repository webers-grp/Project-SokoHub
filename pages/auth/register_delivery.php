<?php
/**
 * SokoHub Delivery Personnel Registration (Upgraded from Main/delivery.html)
 */
require_once __DIR__ . '/../../includes/auth.php';
init_session();

if (is_logged_in()) {
    redirect('pages/dashboard/dashboard.php');
}

$transportMethods = get_transport_methods();
$errors = [];
$old = [
    'full_name'        => '',
    'email'            => '',
    'phone'            => '',
    'transport_method' => '',
    'license_number'   => '',
    'service_area'     => 'Kutus & Kerugoya',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid security token. Please refresh and try again.';
    } else {
        $old['full_name'] = trim((string) ($_POST['full_name'] ?? ''));
        $old['email'] = strtolower(trim((string) ($_POST['email'] ?? '')));
        $old['phone'] = trim((string) ($_POST['phone'] ?? ''));
        $old['transport_method'] = (string) ($_POST['transport_method'] ?? '');
        $old['license_number'] = trim((string) ($_POST['license_number'] ?? ''));
        $old['service_area'] = trim((string) ($_POST['service_area'] ?? 'Kutus & Kerugoya'));

        $errors = validate_registration_fields($_POST);

        if (!array_key_exists($old['transport_method'], $transportMethods)) {
            $errors[] = 'Please select a valid transport method.';
        }
        if (mb_strlen($old['license_number']) < 5) {
            $errors[] = "Driver's License or National ID number must be at least 5 characters.";
        }

        if (empty($errors)) {
            $pdo = get_db_connection();
            $exists = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $exists->execute([':email' => $old['email']]);

            if ($exists->fetch()) {
                $errors[] = 'An account with this email address already exists.';
            } else {
                $pdo->beginTransaction();
                try {
                    $hash = password_hash((string) $_POST['password'], PASSWORD_BCRYPT, ['cost' => 10]);
                    $uStmt = $pdo->prepare(
                        "INSERT INTO users (full_name, email, phone, password_hash, role, status)
                         VALUES (:full_name, :email, :phone, :hash, 'delivery', 'active')"
                    );
                    $uStmt->execute([
                        ':full_name' => $old['full_name'],
                        ':email'     => $old['email'],
                        ':phone'     => $old['phone'],
                        ':hash'      => $hash,
                    ]);
                    $userId = (int) $pdo->lastInsertId();

                    $dStmt = $pdo->prepare(
                        'INSERT INTO delivery_profiles (user_id, transport_method, license_number, service_area, is_available)
                         VALUES (:user_id, :transport, :license, :area, 1)'
                    );
                    $dStmt->execute([
                        ':user_id'   => $userId,
                        ':transport' => $old['transport_method'],
                        ':license'   => $old['license_number'],
                        ':area'      => $old['service_area'] !== '' ? $old['service_area'] : 'Kutus & Kerugoya',
                    ]);
                    $pdo->commit();

                    login_user($pdo, $old['email'], (string) $_POST['password'], false);
                    set_flash('success', 'Delivery partner account activated! You can now claim pending deliveries.');
                    redirect('pages/dashboard/deliveries.php');
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    $errors[] = 'Registration failed due to a database error. Please try again.';
                }
            }
        }
    }
}

$pageTitle = 'SokoHub | Delivery Partner Registration';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="container section-space">
    <div class="auth-split">
        <div class="auth-aside">
            <img src="<?= e(url('assets/img/delivery-rider.jpg')) ?>" alt="Delivery Partner">
            <h2>Deliver with SokoHub</h2>
            <p style="margin-top: 0.75rem;">Connect local vendors and customers across Kirinyaga with reliable last-mile fulfillment.</p>
        </div>

        <div class="auth-body">
            <h1>Delivery Personnel Sign-Up</h1>
            <p class="muted" style="margin-bottom: 1.25rem;">Register your transport details to start receiving delivery tasks.</p>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <div>
                        <?php foreach ($errors as $err): ?>
                            <div><?= e($err) ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <form action="<?= e(url('pages/auth/register_delivery.php')) ?>" method="POST" data-validate="true">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                <div class="form-group">
                    <label for="full_name">Full Name</label>
                    <input type="text" id="full_name" name="full_name" class="form-control" value="<?= e($old['full_name']) ?>" required data-minlength="3" placeholder="e.g. Brian Kiprop">
                </div>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" class="form-control" value="<?= e($old['email']) ?>" required placeholder="rider@example.com">
                    </div>

                    <div class="form-group">
                        <label for="phone">Phone Number</label>
                        <input type="tel" id="phone" name="phone" class="form-control" value="<?= e($old['phone']) ?>" required placeholder="+254 712 345 678">
                    </div>
                </div>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label for="transport_method">Primary Transport Method</label>
                        <select id="transport_method" name="transport_method" class="form-control" required>
                            <option value="">Select transport type</option>
                            <?php foreach ($transportMethods as $key => $label): ?>
                                <option value="<?= e($key) ?>" <?= $old['transport_method'] === $key ? 'selected' : '' ?>>
                                    <?= e($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="license_number">Driver's License / ID Number</label>
                        <input type="text" id="license_number" name="license_number" class="form-control" value="<?= e($old['license_number']) ?>" required data-minlength="5" placeholder="DL-KE-88421">
                    </div>
                </div>

                <div class="form-group">
                    <label for="service_area">Preferred Service Area</label>
                    <input type="text" id="service_area" name="service_area" class="form-control" value="<?= e($old['service_area']) ?>" required placeholder="Kutus - Kagio - Kerugoya">
                </div>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" class="form-control" required data-minlength="8" placeholder="Minimum 8 characters">
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirm Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control" required data-match="password" placeholder="Re-enter password">
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-block">
                    <?= icon('truck') ?>
                    <span>Register as Delivery Personnel</span>
                </button>
            </form>

            <p class="small muted" style="margin-top: 1rem;">
                Already registered? <a href="<?= e(url('pages/auth/login.php')) ?>">Sign In here</a>
            </p>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
