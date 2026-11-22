<?php
require_once __DIR__ . '/includes/init.php';

if (is_logged_in()) {
    redirect('/pages/dashboard.php');
}
redirect('/login.php');