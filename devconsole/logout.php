<?php
/**
 * Developer Console Logout
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';

dev_session_init();
dev_logout();
