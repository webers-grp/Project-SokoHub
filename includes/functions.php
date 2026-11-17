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
 * Render a Lucide vector icon with data-lucide attribute, compact sizing,
 * and optional semantic color classes.
 */
function icon(string $name, string $class = '', string $color = '', string $extraAttrs = ''): string
{
    $classes = 'lucide';
    if ($class !== '') {
        $classes .= ' ' . $class;
    }
    if ($color !== '') {
        $classes .= ' ' . (str_starts_with($color, 'icon-') ? $color : 'icon-' . $color);
    }
    $attrs = $extraAttrs !== '' ? ' ' . $extraAttrs : '';
    return '<i data-lucide="' . e($name) . '" class="' . e($classes) . '"' . $attrs . '></i>';
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
 * Calculate final selling price and original price from regular price and optional discount percentage.
 * Returns ['price' => float, 'original_price' => ?float, 'discount_percent' => int].
 */
function calculate_discount_pricing(float $regularPrice, ?float $discountPercent): array
{
    if ($discountPercent === null || $discountPercent <= 0) {
        return [
            'price'            => round($regularPrice, 2),
            'original_price'   => null,
            'discount_percent' => 0,
        ];
    }

    $discountPercent = min(99.0, max(0.0, (float) $discountPercent));
    $salePrice = round($regularPrice * (1.0 - ($discountPercent / 100.0)), 2);

    return [
        'price'            => $salePrice,
        'original_price'   => round($regularPrice, 2),
        'discount_percent' => (int) round($discountPercent),
    ];
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
 * Render a visual 5-stage Order Progress Timeline component.
 * Tracks Order Confirmed -> Rider Assigned -> Out for Delivery -> Arrival & M-Pesa STK -> Package Delivered.
 */
function render_order_progress_timeline(array $order): string
{
    $orderStatus = (string) ($order['order_status'] ?? 'pending');
    $delStatus   = (string) ($order['delivery_status'] ?? 'pending');
    $payStatus   = (string) ($order['payment_status'] ?? 'unpaid');
    $hasRider    = !empty($order['delivery_person_id']);
    $isPrompted  = (($order['payment_prompt_status'] ?? '') === 'prompted');
    $isPaid      = ($payStatus === 'paid');
    $isDelivered = ($delStatus === 'delivered' && $isPaid);
    $isCancelled = ($orderStatus === 'cancelled' || $delStatus === 'failed');

    if ($isCancelled) {
        $cancelHtml = '
        <div class="order-timeline-wrap" style="border-left: 5px solid var(--danger); background: var(--danger-bg);">
            <div style="display: flex; align-items: center; gap: 0.75rem;">
                <span style="color: var(--danger);">' . icon('x-circle', 'lucide-sm') . '</span>
                <div>
                    <strong style="color: var(--danger); font-size: 0.95rem;">Order Cancelled</strong>
                    <p class="small muted" style="margin: 0.2rem 0 0 0;">This order was cancelled. Reserved items were returned to merchant inventory.</p>
                </div>
            </div>
        </div>';
        return $cancelHtml;
    }

    // Determine state for each of the 5 stages ('completed', 'active', 'pending')
    // Stage 1: Order Confirmed
    $s1State = 'completed';
    $s1Sub = !empty($order['created_at']) ? date('d M, H:i', strtotime($order['created_at'])) : 'Placed';

    // Stage 2: Rider Assigned
    if ($hasRider) {
        if ($delStatus === 'assigned' && !$isPrompted && !$isPaid) {
            $s2State = 'active';
        } else {
            $s2State = 'completed';
        }
        $s2Rider = htmlspecialchars((string) ($order['rider_name'] ?? 'Rider'));
        $s2Plate = !empty($order['vehicle_plate_number']) ? ' [' . htmlspecialchars((string) $order['vehicle_plate_number']) . ']' : '';
        $s2Sub = $s2Rider . $s2Plate;
    } else {
        $s2State = 'active';
        $s2Sub = 'Awaiting rider allocation';
    }

    // Stage 3: Out for Delivery
    $inTransit = in_array($delStatus, ['picked_up', 'in_transit', 'delivered'], true) || in_array($orderStatus, ['shipped', 'delivered'], true);
    if ($isDelivered || $isPaid) {
        $s3State = 'completed';
        $s3Sub = 'In transit to dropoff';
    } elseif ($inTransit) {
        $s3State = 'active';
        $s3Sub = 'Rider en route';
    } else {
        $s3State = 'pending';
        $s3Sub = 'Heading to pickup';
    }

    // Stage 4: Arrived & M-Pesa STK Prompt
    if ($isPaid) {
        $s4State = 'completed';
        $s4Receipt = !empty($order['mpesa_receipt']) ? ' (' . htmlspecialchars((string) $order['mpesa_receipt']) . ')' : '';
        $s4Sub = 'Paid via M-Pesa' . $s4Receipt;
    } elseif ($isPrompted) {
        $s4State = 'active';
        $promptPhone = htmlspecialchars((string) ($order['payment_phone'] ?? $order['buyer_phone'] ?? ''));
        $s4Sub = 'STK prompt sent to ' . $promptPhone;
    } else {
        $s4State = 'pending';
        $s4Sub = 'Prompt on arrival (No Cash)';
    }

    // Stage 5: Package Delivered & Completed
    if ($isDelivered) {
        $s5State = 'completed';
        $s5Sub = !empty($order['delivered_at']) ? 'Delivered ' . date('d M, H:i', strtotime($order['delivered_at'])) : 'Handoff complete';
    } else {
        $s5State = 'pending';
        $s5Sub = 'Final handoff';
    }

    // Build timeline HTML
    $html = '
    <div class="order-timeline-wrap">
        <div class="order-timeline-header">
            <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                <span class="badge badge-primary" style="font-size: 0.82rem;">' . icon('navigation', 'lucide-xs') . ' Live Dispatch Tracking</span>
                <strong>Order #' . htmlspecialchars((string) $order['order_code']) . '</strong>
            </div>
            <div class="small muted">
                Destination: <strong>' . htmlspecialchars((string) ($order['delivery_town'] ?? 'Kirinyaga')) . '</strong> &mdash; ' . htmlspecialchars((string) ($order['delivery_address'] ?? 'Dropoff')) . '
            </div>
        </div>

        <div class="timeline-steps">
            <!-- Step 1 -->
            <div class="timeline-step ' . $s1State . '">
                <div class="timeline-step-connector"></div>
                <div class="timeline-icon-box">' . icon('receipt', 'lucide-xs') . '</div>
                <div class="timeline-step-content">
                    <div class="timeline-step-label">Order Confirmed</div>
                    <div class="timeline-step-sub">' . $s1Sub . '</div>
                </div>
            </div>

            <!-- Step 2 -->
            <div class="timeline-step ' . $s2State . '">
                <div class="timeline-step-connector"></div>
                <div class="timeline-icon-box">' . icon('user-check', 'lucide-xs') . '</div>
                <div class="timeline-step-content">
                    <div class="timeline-step-label">Rider Assigned</div>
                    <div class="timeline-step-sub">' . $s2Sub . '</div>
                </div>
            </div>

            <!-- Step 3 -->
            <div class="timeline-step ' . $s3State . '">
                <div class="timeline-step-connector"></div>
                <div class="timeline-icon-box">' . icon('truck', 'lucide-xs') . '</div>
                <div class="timeline-step-content">
                    <div class="timeline-step-label">Out for Delivery</div>
                    <div class="timeline-step-sub">' . $s3Sub . '</div>
                </div>
            </div>

            <!-- Step 4 -->
            <div class="timeline-step ' . $s4State . '">
                <div class="timeline-step-connector"></div>
                <div class="timeline-icon-box">' . icon('smartphone', 'lucide-xs') . '</div>
                <div class="timeline-step-content">
                    <div class="timeline-step-label">M-Pesa Payment</div>
                    <div class="timeline-step-sub">' . $s4Sub . '</div>
                </div>
            </div>

            <!-- Step 5 -->
            <div class="timeline-step ' . $s5State . '">
                <div class="timeline-step-connector"></div>
                <div class="timeline-icon-box">' . icon('package-check', 'lucide-xs') . '</div>
                <div class="timeline-step-content">
                    <div class="timeline-step-label">Delivered</div>
                    <div class="timeline-step-sub">' . $s5Sub . '</div>
                </div>
            </div>
        </div>

        <!-- Dispatch Intelligence Strip -->
        <div class="timeline-intel-box">';

    if ($isDelivered) {
        $html .= '
            <div style="display: flex; align-items: center; gap: 0.5rem; color: #065f46;">
                ' . icon('check-circle', 'lucide-xs', 'emerald') . '
                <span><strong>Fulfillment Completed</strong>: Paid via Safaricom M-Pesa (' . htmlspecialchars((string) ($order['mpesa_receipt'] ?? 'Verified')) . ') and package received.</span>
            </div>';
    } elseif ($isPrompted && !$isPaid) {
        $html .= '
            <div style="display: flex; align-items: center; gap: 0.5rem; color: #b45309;">
                ' . icon('bell', 'lucide-xs', 'amber') . '
                <span><strong>Action Required on Handset</strong>: Your delivery rider has initiated an M-Pesa STK push prompt. Please enter your M-Pesa PIN on your phone to complete payment.</span>
            </div>';
    } elseif ($hasRider) {
        $riderName = htmlspecialchars((string) ($order['rider_name'] ?? 'Assigned Rider'));
        $riderPhone = htmlspecialchars((string) ($order['rider_phone'] ?? ''));
        $riderVehicle = htmlspecialchars((string) ($order['transport_method'] ?? 'Motorbike'));
        $riderPlate = !empty($order['vehicle_plate_number']) ? ' [' . htmlspecialchars((string) $order['vehicle_plate_number']) . ']' : '';

        $html .= '
            <div style="display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap;">
                <span class="badge badge-outline" style="font-family: monospace; font-size: 0.82rem;">
                    ' . icon('car', 'lucide-xs') . ' ' . $riderVehicle . $riderPlate . '
                </span>
                <span>Assigned to <strong>' . $riderName . '</strong></span>
                ' . (!empty($riderPhone) ? '<a href="tel:' . $riderPhone . '" class="btn btn-outline btn-sm timeline-call-btn" style="padding: 0.2rem 0.5rem; font-size: 0.78rem;">' . icon('phone', 'lucide-xs') . ' Call Rider (' . $riderPhone . ')</a>' : '') . '
            </div>
            <div class="small muted">
                Strictly Cashless Delivery &mdash; Pay via M-Pesa STK upon arrival.
            </div>';
    } else {
        $html .= '
            <div style="display: flex; align-items: center; gap: 0.5rem; color: #1e40af;">
                ' . icon('clock', 'lucide-xs', 'blue') . '
                <span><strong>Merchant Allocation Pending</strong>: Merchant is selecting an available verified rider on standby in Kirinyaga County.</span>
            </div>';
    }

    $html .= '
        </div>
    </div>';

    return $html;
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
        ['label' => 'Home', 'href' => 'index.php', 'match' => ['index.php'], 'icon' => 'home'],
        ['label' => 'Products', 'href' => 'pages/public/products.php', 'match' => ['products.php', 'product_details.php'], 'icon' => 'shopping-bag'],
        ['label' => 'About Us', 'href' => 'pages/public/about.php', 'match' => ['about.php'], 'icon' => 'info'],
        ['label' => 'Contact Us', 'href' => 'pages/public/contact.php', 'match' => ['contact.php'], 'icon' => 'mail'],
    ];

    if ($user !== null) {
        $items[] = ['label' => 'Dashboard', 'href' => 'pages/dashboard/dashboard.php', 'match' => ['dashboard.php'], 'icon' => 'layout-dashboard'];

        if (in_array($user['role'], ['seller', 'admin'], true)) {
            $items[] = ['label' => 'My Products', 'href' => 'pages/dashboard/manage_products.php', 'match' => ['manage_products.php'], 'icon' => 'package'];
            $items[] = ['label' => 'Bulk Import', 'href' => 'pages/dashboard/bulk_upload.php', 'match' => ['bulk_upload.php'], 'icon' => 'file-spreadsheet'];
        }

        if (in_array($user['role'], ['seller', 'delivery'], true)) {
            $items[] = ['label' => 'KYC Verification', 'href' => 'pages/dashboard/kyc_upload.php', 'match' => ['kyc_upload.php'], 'icon' => 'shield-check'];
        }

        $items[] = ['label' => 'Orders', 'href' => 'pages/buyer/orders.php', 'match' => ['orders.php', 'checkout.php'], 'icon' => 'receipt'];

        if ($user['role'] === 'buyer') {
            $items[] = ['label' => 'Alerts', 'href' => 'pages/buyer/notifications.php', 'match' => ['notifications.php'], 'icon' => 'bell'];
        }

        if (in_array($user['role'], ['seller', 'delivery', 'admin', 'buyer'], true)) {
            $items[] = ['label' => 'Deliveries', 'href' => 'pages/dashboard/deliveries.php', 'match' => ['deliveries.php'], 'icon' => 'truck'];
        }

        if ($user['role'] === 'admin') {
            $items[] = ['label' => 'KYC Queue', 'href' => 'pages/dashboard/seller_verification.php', 'match' => ['seller_verification.php'], 'icon' => 'shield-alert'];
            $items[] = ['label' => 'Audit Logs', 'href' => 'pages/dashboard/audit_logs.php', 'match' => ['audit_logs.php'], 'icon' => 'file-text'];
        }

        if (in_array($user['role'], ['seller', 'delivery', 'admin'], true)) {
            $items[] = ['label' => 'Reports', 'href' => 'pages/dashboard/reports.php', 'match' => ['reports.php'], 'icon' => 'bar-chart-3'];
        }

        if (in_array($user['role'], ['seller', 'admin'], true)) {
            $items[] = ['label' => 'Payouts', 'href' => 'pages/dashboard/payouts.php', 'match' => ['payouts.php'], 'icon' => 'wallet'];
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
    $discountPercent = $input['discount_percent'] ?? ($input['discount'] ?? '');
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

    if ($discountPercent !== '' && $discountPercent !== null) {
        if (!is_numeric($discountPercent) || (float) $discountPercent < 0 || (float) $discountPercent >= 100) {
            $errors[] = 'Discount percentage must be a number between 0% and 99%.';
        }
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
