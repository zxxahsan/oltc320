<?php
/**
 * GEMBOK Configuration Sample File
 * Rename this file to config.php and fill in your details.
 */

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'gembok_simple2');
define('DB_USER', 'root');
define('DB_PASS', '');

// MikroTik Configuration
define('MIKROTIK_HOST', '192.168.88.1');
define('MIKROTIK_USER', 'admin');
define('MIKROTIK_PASS', 'password');
define('MIKROTIK_PORT', 8728);

// Application Configuration
define('APP_NAME', 'GEMBOK');
// Automatically detect the App URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
define('APP_URL', $protocol . '://' . $host . '/gembokcontainer');

define('APP_VERSION', '3.0.1');
define('GEMBOK_UPDATE_VERSION_URL', 'https://raw.githubusercontent.com/zxxahsan/bill/main/version.txt');

// Pagination and currency
define('ITEMS_PER_PAGE', 20);
define('CURRENCY', 'IDR');
define('CURRENCY_SYMBOL', 'Rp');
define('INVOICE_PREFIX', 'INV');
define('INVOICE_START', 1);

// Security
define('ENCRYPTION_KEY', 'your-secret-key-here-32-chars-long');

// WhatsApp Configuration (Optional)
define('WHATSAPP_API_URL', '');
define('WHATSAPP_TOKEN', '');

// Tripay Configuration (Optional)
define('TRIPAY_API_KEY', '');
define('TRIPAY_PRIVATE_KEY', '');
define('TRIPAY_MERCHANT_CODE', '');

// Telegram Configuration (Optional)
define('TELEGRAM_BOT_TOKEN', '');

// GenieACS Configuration (Optional)
define('GENIEACS_URL', 'http://localhost:7557');
define('GENIEACS_USERNAME', '');
define('GENIEACS_PASSWORD', '');
