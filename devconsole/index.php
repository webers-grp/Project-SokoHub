<?php
/**
 * Developer Console Entry Point
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';

dev_require_auth();
header('Location: ' . dev_url('dashboard.php'));
exit;
