<?php

require_once __DIR__ . '/../Libraries/Core/Dotenv.php';

Dotenv::load(__DIR__ . '/../.env');

$baseUrl = getenv('BASE_URL') ?: 'http://localhost/AmazonMarket';

define('BASE_URL', $baseUrl);
define('API_URL', $baseUrl . '/api');

define('CONNECTION', false);
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'amazon_market');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_CHARSET', getenv('DB_CHARSET') ?: 'utf8');

define('MAIL_HOST', getenv('MAIL_HOST') ?: 'smtp.gmail.com');
define('MAIL_PORT', intval(getenv('MAIL_PORT') ?: '587'));
define('MAIL_USER', getenv('MAIL_USER') ?: '');
define('MAIL_PASS', getenv('MAIL_PASS') ?: '');
define('MAIL_FROM', getenv('MAIL_FROM') ?: '');
define('MAIL_FROM_NAME', getenv('MAIL_FROM_NAME') ?: 'AmazonMarket');
define('MAIL_ENCRYPTION', getenv('MAIL_ENCRYPTION') ?: 'tls');

define('STORAGE_PATH', getenv('STORAGE_PATH') ?: 'C:/xampp/storage/amazon_market/productos');

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.gc_maxlifetime', 3600);
    ini_set('session.use_strict_mode', 1);
    session_start();
}
