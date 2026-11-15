<?php
/**
 * SokoHub Logout Endpoint
 * Destroys active session and resets session cookie safely.
 */
require_once __DIR__ . '/../../includes/auth.php';
init_session();

logout_user();
init_session();
set_flash('info', 'You have been signed out safely.');
redirect('pages/auth/login.php');
