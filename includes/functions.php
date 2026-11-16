<?php
/**
 * SokoHub Core Helper Functions
 * Explicitly utilizes PHP arrays, control structures, validation helpers,
 * CSRF tokens, flash alerts, and formatting utilities.
 */

/**
 * HTML-escape output safely to prevent XSS.
 */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Redirect cleanly and terminate execution.
 */
function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

/**
 * Format numeric amounts in Kenyan Shillings.
 */
function format_ksh(float|int|string $amount): string
{
    return APP_CURRENCY . ' ' . number_format((float) $amount, 2);
}

/**
 * Calculate integer percentage discount when original_price > price.
 */
function discount_percent(float $price, ?float $originalPrice): int
{
    if ($originalPrice === null || $originalPrice <= $price || $originalPrice <= 0) {
        return 0;
    }

    return (int) round((($originalPrice - $price) / $originalPrice) * 100);
}

/**
 * Generate or return the active CSRF token stored in the PHP session.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Verify submitted CSRF token from POST requests.
 */
function verify_csrf(?string $token): bool
{
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Store a one-time flash message in session.
 */
function set_flash(string $type, string $message): void
{
    $_SESSION['flash'] = [
        'type'    => $type, // 'success', 'error', 'warning', 'info'
        'message' => $message,
    ];
}

/**
 * Retrieve and clear the current flash message.
 */
function get_flash(): ?array
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

/**
 * Associative array of user role labels.
 */
function get_role_labels(): array
{
    return [
        'buyer'    => 'Buyer / Customer',
        'seller'   => 'Seller / Vendor',
        'delivery' => 'Delivery Personnel',
        'admin'    => 'Administrator',
    ];
}

/**
 * Associative array of seller business categories (matches prototype select options).
 */
function get_business_types(): array
{
    return [
        'retail'        => 'Retail / General Goods',
        'food_beverage' => 'Food & Beverage',
        'crafts'        => 'Handmade / Crafts',
        'services'      => 'Service Provider',
        'other'         => 'Other',
    ];
}

/**
 * Associative array of delivery transport methods (matches prototype select options).
 */
function get_transport_methods(): array
{
    return [
        'motorbike' => 'Motorbike / Scooter',
        'bicycle'   => 'Bicycle',
        'car'       => 'Car / Van',
        'foot'      => 'On Foot',
    ];
}

/**
 * Return associative metadata (label + CSS badge class) for order or delivery statuses.
 * Demonstrates switch/match control structures.
 */
function get_status_badge(string $status): array
{
    return match ($status) {
        'pending'    => ['label' => 'Pending', 'class' => 'badge-warning'],
        'confirmed'  => ['label' => 'Confirmed', 'class' => 'badge-info'],
        'processing' => ['label' => 'Processing', 'class' => 'badge-info'],
        'assigned'   => ['label' => 'Assigned', 'class' => 'badge-info'],
        'picked_up'  => ['label' => 'Picked Up', 'class' => 'badge-primary'],
        'in_transit' => ['label' => 'In Transit', 'class' => 'badge-primary'],
        'shipped'    => ['label' => 'Shipped', 'class' => 'badge-primary'],
        'delivered'  => ['label' => 'Delivered', 'class' => 'badge-success'],
        'paid'       => ['label' => 'Paid', 'class' => 'badge-success'],
        'active'     => ['label' => 'Active', 'class' => 'badge-success'],
        'resolved'   => ['label' => 'Resolved', 'class' => 'badge-success'],
        'read'       => ['label' => 'Read', 'class' => 'badge-info'],
        'new'        => ['label' => 'New', 'class' => 'badge-warning'],
        'unpaid'     => ['label' => 'Unpaid', 'class' => 'badge-warning'],
        'cancelled',
        'failed',
        'archived',
        'suspended'  => ['label' => ucfirst($status), 'class' => 'badge-danger'],
        default      => ['label' => ucfirst(str_replace('_', ' ', $status)), 'class' => 'badge-neutral'],
    };
}

/**
 * Curated list of built-in marketplace product images from SokoHub assets.
 */
function get_preset_product_images(): array
{
    return [
        'assets/img/gas-tanks.jpg'      => 'Cooking Gas Cylinders',
        'assets/img/gas-mascot.jpg'     => 'Gas Safety Kit',
        'assets/img/electronics.jpg'    => 'Home Electronics & Appliances',
        'assets/img/router.jpeg'        => 'Wi-Fi Router & Networking',
        'assets/img/clothes.jpg'        => 'Classic Apparel Rack',
        'assets/img/organic-food.jpg'   => 'Organic Grocery Cart',
        'assets/img/fresh-produce.jpg'  => 'Fresh Farm Produce',
        'assets/img/indoor-market.jpg'  => 'Artisan Market Goods',
        'assets/img/cart-icon.jpg'      => 'Marketplace Bundle',
    ];
}

/**
 * Dynamic navigation array built according to user authentication & role.
 */
function get_nav_items(?array $user): array
{
    $items = [
        ['label' => 'Home', 'href' => 'index.php', 'match' => ['index.php']],
        ['label' => 'Products', 'href' => 'pages/public/products.php', 'match' => ['products.php', 'product_details.php']],
        ['label' => 'About Us', 'href' => 'pages/public/about.php', 'match' => ['about.php']],
        ['label' => 'Contact Us', 'href' => 'pages/public/contact.php', 'match' => ['contact.php']],
    ];

    if ($user !== null) {
        $items[] = ['label' => 'Dashboard', 'href' => 'pages/dashboard/dashboard.php', 'match' => ['dashboard.php']];

        if (in_array($user['role'], ['seller', 'admin'], true)) {
            $items[] = ['label' => 'My Products', 'href' => 'pages/dashboard/manage_products.php', 'match' => ['manage_products.php']];
        }

        if (in_array($user['role'], ['seller', 'delivery'], true)) {
            $items[] = ['label' => 'KYC Verification', 'href' => 'pages/dashboard/kyc_upload.php', 'match' => ['kyc_upload.php']];
        }

        $items[] = ['label' => 'Orders', 'href' => 'pages/buyer/orders.php', 'match' => ['orders.php', 'checkout.php']];

        if ($user['role'] === 'buyer') {
            $items[] = ['label' => 'Alerts', 'href' => 'pages/buyer/notifications.php', 'match' => ['notifications.php']];
        }

        if (in_array($user['role'], ['delivery', 'admin', 'buyer'], true)) {
            $items[] = ['label' => 'Deliveries', 'href' => 'pages/dashboard/deliveries.php', 'match' => ['deliveries.php']];
        }

        if ($user['role'] === 'admin') {
            $items[] = ['label' => 'KYC Queue', 'href' => 'pages/dashboard/seller_verification.php', 'match' => ['seller_verification.php']];
            $items[] = ['label' => 'Audit Logs', 'href' => 'pages/dashboard/audit_logs.php', 'match' => ['audit_logs.php']];
        }

        if (in_array($user['role'], ['seller', 'delivery', 'admin'], true)) {
            $items[] = ['label' => 'Reports', 'href' => 'pages/dashboard/reports.php', 'match' => ['reports.php']];
        }

        if (in_array($user['role'], ['seller', 'admin'], true)) {
            $items[] = ['label' => 'Payouts', 'href' => 'pages/dashboard/payouts.php', 'match' => ['payouts.php']];
        }
    }

    return $items;
}

/**
 * Total item quantity currently in the shopping cart session array.
 */
function cart_count(): int
{
    if (empty($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
        return 0;
    }

    $count = 0;
    foreach ($_SESSION['cart'] as $qty) {
        $count += max(0, (int) $qty);
    }

    return $count;
}

/**
 * Server-side validation helper for user registration fields.
 * Returns an indexed array of human-readable error strings.
 */
function validate_registration_fields(array $input): array
{
    $errors = [];

    $fullName = trim((string) ($input['full_name'] ?? ''));
    $email = trim((string) ($input['email'] ?? ''));
    $phone = trim((string) ($input['phone'] ?? ''));
    $password = (string) ($input['password'] ?? '');
    $confirm = (string) ($input['confirm_password'] ?? '');

    if (mb_strlen($fullName) < 3) {
        $errors[] = 'Full name must be at least 3 characters long.';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please provide a valid email address.';
    }

    if (!preg_match('/^\+?[0-9\s\-]{9,20}$/', $phone)) {
        $errors[] = 'Phone number must contain 9 to 20 digits (e.g. +254 712 345 678).';
    }

    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    }

    if ($password !== $confirm) {
        $errors[] = 'Password and confirmation password do not match.';
    }

    return $errors;
}

/**
 * Server-side validation for Product Create/Update forms.
 */
function validate_product_fields(array $input): array
{
    $errors = [];

    $name = trim((string) ($input['name'] ?? ''));
    $description = trim((string) ($input['description'] ?? ''));
    $categoryId = (int) ($input['category_id'] ?? 0);
    $price = $input['price'] ?? '';
    $originalPrice = $input['original_price'] ?? '';
    $stockQty = $input['stock_qty'] ?? '';
    $condition = (string) ($input['condition_type'] ?? '');

    if (mb_strlen($name) < 3 || mb_strlen($name) > 150) {
        $errors[] = 'Product name must be between 3 and 150 characters.';
    }

    if (mb_strlen($description) < 10) {
        $errors[] = 'Product description must be at least 10 characters.';
    }

    if ($categoryId <= 0) {
        $errors[] = 'Please select a valid product category.';
    }

    if (!is_numeric($price) || (float) $price <= 0) {
        $errors[] = 'Selling price must be a positive number.';
    }

    if ($originalPrice !== '' && (!is_numeric($originalPrice) || (float) $originalPrice < (float) $price)) {
        $errors[] = 'Original price (if set) must be greater than or equal to the selling price.';
    }

    if (!is_numeric($stockQty) || (int) $stockQty < 0) {
        $errors[] = 'Stock quantity cannot be negative.';
    }

    $threshold = $input['low_stock_threshold'] ?? '';
    if ($threshold !== '' && (!is_numeric($threshold) || (int) $threshold < 1)) {
        $errors[] = 'Low-stock alert threshold must be a number of at least 1 unit.';
    }

    if (!in_array($condition, ['New', 'Second Hand'], true)) {
        $errors[] = 'Product condition must be New or Second Hand.';
    }

    return $errors;
}

/**
 * Handle optional product image upload safely.
 * Returns [relativePath, errorMessage].
 */
function handle_product_image_upload(array $file, string $fallbackImage): array
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return [$fallbackImage, null];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [$fallbackImage, 'Image upload failed with code ' . (int) $file['error'] . '.'];
    }

    if ($file['size'] > MAX_UPLOAD_BYTES) {
        return [$fallbackImage, 'Uploaded image exceeds the 2 MB limit.'];
    }

    $allowedMime = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!isset($allowedMime[$mime])) {
        return [$fallbackImage, 'Only JPG, PNG, and WEBP images are allowed.'];
    }

    if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0755, true) && !is_dir(UPLOAD_DIR)) {
        return [$fallbackImage, 'Unable to initialize upload directory.'];
    }

    $filename = 'prod_' . bin2hex(random_bytes(8)) . '.' . $allowedMime[$mime];
    $destination = UPLOAD_DIR . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        return [$fallbackImage, 'Could not save uploaded image file.'];
    }

    return ['assets/img/uploads/' . $filename, null];
}
