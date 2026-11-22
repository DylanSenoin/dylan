<?php
declare(strict_types=1);

define('APP_NAME',    'Water Bill Management System');
define('APP_VERSION', '1.0.0');
define('BASE_URL',    '/WaterBillSystem');
define('APP_TIMEZONE','Asia/Manila');
date_default_timezone_set(APP_TIMEZONE);

define('DB_HOST',    '127.0.0.1');
define('DB_NAME',    'waterbill_db');
define('DB_USER',    'root');
define('DB_PASS',    '');
define('DB_CHARSET', 'utf8mb4');

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (($_SERVER['HTTPS'] ?? '') === 'on'),
    ]);
    session_name('WBMSSESSID');
    session_start();
}