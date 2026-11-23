<?php
/**
 * Test Harness: Loads every page in devconsole with an active developer session
 * and checks for PHP notices, warnings, and PDO exceptions.
 */

// Prevent dev_session_init from blowing up headers in CLI
session_id('testdevconsoleharness123456');
session_start();

$_SESSION['dev_user'] = [
    'id' => 1,
    'username' => 'devowner',
    'email' => 'devowner@sokohub.test',
    'role' => 'owner',
    'totp_enabled' => 1,
];
$_SESSION['dev_2fa_passed'] = true;
$_SESSION['dev_reauth_until'] = time() + 3600;
$_SESSION['dev_csrf_token'] = 'test_token_12345';
$_SESSION['dev_write_mode'] = true;

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../devconsole/config.php';
require_once __DIR__ . '/../devconsole/includes/auth.php';

$pdo = get_db_connection();

// Create sample records if needed so pages that query data have something to display
// Check if orders has at least 1 record
$orderCount = (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
if ($orderCount === 0) {
    $pdo->exec("INSERT INTO orders (order_code, buyer_id, total_amount, payment_method, payment_status, order_status, created_at) VALUES ('TEST-001', 1, 1000.00, 'mpesa', 'pending', 'pending', NOW())");
}

$page = $argv[1] ?? '';
if (!$page) {
    echo "No page specified.\n";
    exit(1);
}

// Intercept output
ob_start();

// Custom error handler to catch warnings and notices
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

try {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = [];
    $_POST = [];

    // Provide query parameters where helpful
    if (str_contains($page, 'browser.php')) {
        $_GET['table'] = 'orders';
    }
    if (str_contains($page, 'inspector.php')) {
        $firstPay = $pdo->query('SELECT checkout_request_id FROM payments LIMIT 1')->fetchColumn();
        if ($firstPay) {
            $_GET['q'] = $firstPay;
        }
    }

    require __DIR__ . '/../devconsole/' . $page;
    $output = ob_get_clean();
    echo "SUCCESS (output length: " . strlen($output) . " bytes)\n";
    exit(0);
} catch (Throwable $e) {
    ob_end_clean();
    echo "EXCEPTION: " . $e->getMessage() . "\n";
    echo "IN: " . $e->getFile() . " LINE: " . $e->getLine() . "\n";
    exit(1);
}
