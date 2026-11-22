<?php
require_once __DIR__ . '/includes/init.php';

if (is_logged_in()) {
    log_activity('LOGOUT', 'Authentication', 'User logged out');
}
logout_user();
session_start();
flash('success', 'You have been signed out.');
redirect('/login.php');