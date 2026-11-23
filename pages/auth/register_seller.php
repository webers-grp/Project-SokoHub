<?php
/**
 * SokoHub Seller Registration (Upgraded from Main/sellers.page.html)
 */
require_once __DIR__ . '/../../includes/auth.php';
init_session();

if (is_logged_in()) {
    redirect('pages/dashboard/dashboard.php');
}

$businessTypes = get_business_types();
$errors = [];
$old = [
    'full_name'     => '',
    'business_name' => '',
    'till_number'   => '',
    'business_type' => '',
    'town'          => 'Kutus',
    'email'         => '',
    'phone'         => '',
    'description'   => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid security token. Please refresh and try again.';
    } else {
        $old['full_name'] = trim((string) ($_POST['full_name'] ?? ''));
        $old['business_name'] = trim((string) ($_POST['business_name'] ?? ''));
        $old['till_number'] = trim((string) ($_POST['till_number'] ?? ''));
        $old['business_type'] = (string) ($_POST['business_type'] ?? '');
        $old['town'] = trim((string) ($_POST['town'] ?? 'Kutus'));
        $old['email'] = strtolower(trim((string) ($_POST['email'] ?? '')));
        $old['phone'] = trim((string) ($_POST['phone'] ?? ''));
        $old['description'] = trim((string) ($_POST['description'] ?? ''));

        $errors = validate_registration_fields($_POST);

        if (mb_strlen($old['business_name']) < 3) {
            $errors[] = 'Business name is strictly required and must be at least 3 characters long.';
        }
        if (empty($old['till_number']) || !preg_match('/^[0-9]{5,8}$/', $old['till_number'])) {
            $errors[] = 'M-Pesa Buy Goods Till Number is strictly required and must be between 5 and 8 digits.';
        }
        if (!array_key_exists($old['business_type'], $businessTypes)) {
            $errors[] = 'Please choose a valid business category.';
        }
        if (empty($_POST['terms'])) {
            $errors[] = 'You must agree to the SokoHub Terms of Service and Privacy Policy.';
        }

        if (empty($errors)) {
            $pdo = get_db_connection();
            require_once __DIR__ . '/../../includes/orders.php';
            ensure_order_schema_tables($pdo);
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
                         VALUES (:full_name, :email, :phone, :hash, 'seller', 'active')"
                    );
                    $uStmt->execute([
                        ':full_name' => $old['full_name'],
                        ':email'     => $old['email'],
                        ':phone'     => $old['phone'],
                        ':hash'      => $hash,
                    ]);
                    $userId = (int) $pdo->lastInsertId();

                    $sStmt = $pdo->prepare(
                        'INSERT INTO seller_profiles (user_id, business_name, till_number, business_type, town, description)
                         VALUES (:user_id, :business_name, :till_number, :business_type, :town, :description)'
                    );
                    $sStmt->execute([
                        ':user_id'       => $userId,
                        ':business_name' => $old['business_name'],
                        ':till_number'   => $old['till_number'],
                        ':business_type' => $old['business_type'],
                        ':town'          => $old['town'] !== '' ? $old['town'] : 'Kutus',
                        ':description'   => $old['description'],
                    ]);
                    $pdo->commit();

                    login_user($pdo, $old['email'], (string) $_POST['password'], false);
                    set_flash('success', 'Vendor account created! Welcome to SokoHub, ' . $old['business_name'] . '.');
                    redirect('pages/dashboard/manage_products.php');
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    $errors[] = 'Registration failed due to a database error. Please try again.';
                }
            }
        }
    }
}

$pageTitle = 'SokoHub | Seller Registration';
require_once __DIR__ . '/../../includes/header.php';
?>

<main class="container section-space">
    <div class="auth-split">
        <div class="auth-aside">
            <img src="<?= e(url('assets/img/sokohub-logo.jpg')) ?>" alt="SokoHub Logo">
            <h2>Become a SokoHub Vendor</h2>
            <p style="margin-top: 0.75rem;">Showcase products to thousands of local shoppers, track inventory levels, and monitor sales performance.</p>
        </div>

        <div class="auth-body">
            <h1>Create Seller Account</h1>
            <p class="muted" style="margin-bottom: 1.25rem;">Tell us about your business to start listing on SokoHub.</p>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <div>
                        <?php foreach ($errors as $err): ?>
                            <div><?= e($err) ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <form action="<?= e(url('pages/auth/register_seller.php')) ?>" method="POST" data-validate="true">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                <div class="form-grid-2">
                    <div class="form-group">
                        <label for="full_name">Owner Full Name <span style="color:var(--danger,#c62828);">*</span></label>
                        <input type="text" id="full_name" name="full_name" class="form-control" value="<?= e($old['full_name']) ?>" required data-minlength="3" placeholder="e.g. Ian Mwangi">
                    </div>

                    <div class="form-group">
                        <label for="business_name">Business Name <span style="color:var(--danger,#c62828);">*</span></label>
                        <input type="text" id="business_name" name="business_name" class="form-control" value="<?= e($old['business_name']) ?>" required data-minlength="3" placeholder="e.g. Ian's Fresh Produce">
                    </div>
                </div>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label for="till_number">M-Pesa Buy Goods Till Number <span style="color:var(--danger,#c62828);">*</span></label>
                        <input type="text" id="till_number" name="till_number" class="form-control" value="<?= e($old['till_number']) ?>" required pattern="[0-9]{5,8}" placeholder="e.g. 1648753 or 882910">
                        <span class="small muted" style="display:block; margin-top:0.25rem;">Strictly required for your 98% sales payout (2% SokoHub platform fee deducted).</span>
                    </div>

                    <div class="form-group">
                        <label for="town">Primary Town / Market <span style="color:var(--danger,#c62828);">*</span></label>
                        <input type="text" id="town" name="town" class="form-control" value="<?= e($old['town']) ?>" required placeholder="e.g. Kutus, Kerugoya">
                    </div>
                </div>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label for="business_type">Type of Business <span style="color:var(--danger,#c62828);">*</span></label>
                        <select id="business_type" name="business_type" class="form-control" required>
                            <option value="">Select a category</option>
                            <?php foreach ($businessTypes as $key => $label): ?>
                                <option value="<?= e($key) ?>" <?= $old['business_type'] === $key ? 'selected' : '' ?>>
                                    <?= e($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="phone">Phone Number <span style="color:var(--danger,#c62828);">*</span></label>
                        <input type="tel" id="phone" name="phone" class="form-control" value="<?= e($old['phone']) ?>" required placeholder="+254 712 345 678">
                    </div>
                </div>

                <div class="form-group">
                    <label for="email">Official Email Address <span style="color:var(--danger,#c62828);">*</span></label>
                    <input type="email" id="email" name="email" class="form-control" value="<?= e($old['email']) ?>" required placeholder="business@example.com">
                </div>

                <div class="form-group">
                    <label for="description">Short Business Description (Optional)</label>
                    <input type="text" id="description" name="description" class="form-control" value="<?= e($old['description']) ?>" placeholder="What do you sell?">
                </div>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label for="password">Create Password</label>
                        <input type="password" id="password" name="password" class="form-control" required data-minlength="8" placeholder="Minimum 8 characters">
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirm Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control" required data-match="password" placeholder="Re-enter password">
                    </div>
                </div>

                <div class="checkbox-row">
                    <input id="terms" name="terms" type="checkbox" value="1" required>
                    <label for="terms" style="margin: 0;">I agree to the SokoHub Terms of Service and Seller Guidelines.</label>
                </div>

                <button type="submit" class="btn btn-amber btn-block">Register &amp; Start Selling</button>
            </form>

            <p class="small muted" style="margin-top: 1rem;">
                Already have a seller account? <a href="<?= e(url('pages/auth/login.php')) ?>">Sign In Here</a>
            </p>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
