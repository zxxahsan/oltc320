<?php
/**
 * Helper Functions
 */

// Global settings cache
$global_settings_cache = null;
$site_settings_cache = null;

// Get setting from database with fallback to config constant
function getSetting($key, $default = '') {
    global $global_settings_cache;
    
    if ($global_settings_cache === null) {
        $global_settings_cache = [];
        $data = fetchAll("SELECT setting_key, setting_value FROM settings");
        foreach ($data as $row) {
            $global_settings_cache[$row['setting_key']] = $row['setting_value'];
        }
    }
    
    if (isset($global_settings_cache[$key]) && $global_settings_cache[$key] !== '') {
        return $global_settings_cache[$key];
    }
    
    if (defined($key)) {
        return constant($key);
    }
    
    return $default;
}

// Get site setting from site_settings table
function getSiteSetting($key, $default = '') {
    global $site_settings_cache;
    
    if ($site_settings_cache === null) {
        $site_settings_cache = [];
        try {
            $data = fetchAll("SELECT setting_key, setting_value FROM site_settings");
            if (is_array($data)) {
                foreach ($data as $row) {
                    $site_settings_cache[$row['setting_key']] = $row['setting_value'];
                }
            }
        } catch (Exception $e) {
            // Table might not exist yet
        }
    }
    
    return $site_settings_cache[$key] ?? $default;
}

// Get Mikrotik settings from database (supports multi-router)
require_once __DIR__ . '/mikrotik_api.php';

// Format currency
function formatCurrency($amount)
{
    $amount = is_numeric($amount) ? $amount : 0;
    $symbol = getSetting('CURRENCY_SYMBOL', 'Rp');
    return $symbol . ' ' . number_format((float) $amount, 0, ',', '.');
}

// Format date
function formatDate($date, $format = 'd M Y')
{
    if (!$date)
        return '-';
    $time = strtotime($date);
    return $time ? date($format, $time) : '-';
}

// Format Day and Indonesian Month Name (e.g. 1 Maret)
function formatDayMonthIndo($date)
{
    if (!$date) return '-';
    $time = strtotime($date);
    $months = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus', 
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];
    $m = (int)date('n', $time);
    return date('j', $time) . ' ' . $months[$m];
}

function generateInvoiceNumber()
{
    $prefix = INVOICE_PREFIX;
    $count = fetchOne("SELECT COUNT(id) as total FROM invoices")['total'] + 1;
    $newNum = $count;
    
    // Ensure absolute uniqueness bypassing legacy string parsing exceptions
    $invoiceNum = $prefix . str_pad($newNum, 6, '0', STR_PAD_LEFT);
    while (fetchOne("SELECT id FROM invoices WHERE invoice_number = ?", [$invoiceNum])) {
        $newNum++;
        $invoiceNum = $prefix . str_pad($newNum, 6, '0', STR_PAD_LEFT);
    }
    return $invoiceNum;
}

function sendWhatsApp($phone, $message)
{
    require_once 'whatsapp.php';

    // Format phone number (62 format) is already covered in the new engine, but double ensuring doesn't hurt.
    if (substr($phone, 0, 2) === '08') {
        $phone = '62' . substr($phone, 1);
    }

    // Send through the unified Node JS Engine natively
    return sendWhatsAppMessage($phone, $message);
}

/**
 * Send Welcome WhatsApp message to new customer
 * @param array $customer Customer data
 * @param string $plainPassword The unhashed password
 * @return array Result of sendWhatsAppMessage
 */
function sendCustomerWelcomeWA($customer, $plainPassword) {
    require_once 'whatsapp.php';
    
    // Get universal variables
    $vars = getUniversalWaVariables($customer);
    
    // Add sensitive/specific ones
    $vars['portal_password'] = $plainPassword; // Pass plain password for notification
    $vars['portal_url'] = rtrim(APP_URL, '/') . '/portal/login.php';
    
    $message = buildWhatsAppMessage('new_customer', $vars);
    
    if (empty($message)) {
        // Fallback message if template is empty/disabled
        $appName = getSetting('app_name', 'GEMBOK');
        $portalUrl = rtrim(APP_URL, '/') . '/portal/login.php';
        $message = "Terimakasih telah memilih layanan kami {$appName}.\n\nBerikut detail akses Dashboard Anda:\nURL: {$portalUrl}\nPassword: {$plainPassword}";
    }
    
    return sendWhatsAppMessage($customer['phone'], $message);
}

function getCustomerDueDate($customer, $baseDate = null)
{
    $baseTimestamp = $baseDate ? strtotime($baseDate) : time();
    $year = date('Y', $baseTimestamp);
    $month = date('m', $baseTimestamp);
    $day = isset($customer['isolation_date']) ? (int) $customer['isolation_date'] : 20;
    if ($day < 1) {
        $day = 1;
    }
    if ($day > 28) {
        $day = 28;
    }
    $lastDay = (int) date('t', strtotime($year . '-' . $month . '-01'));
    if ($day > $lastDay) {
        $day = $lastDay;
    }
    return date('Y-m-d', strtotime($year . '-' . $month . '-' . str_pad($day, 2, '0', STR_PAD_LEFT)));
}

function logError($message)
{
    $logFile = __DIR__ . '/../logs/error.log';
    $logDir = dirname($logFile);

    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true);
    }

    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] ERROR: {$message}\n";

    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

// Log activity
function logActivity($action, $details = '')
{
    $logFile = __DIR__ . '/../logs/activity.log';
    $logDir = dirname($logFile);

    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true);
    }

    $timestamp = date('Y-m-d H:i:s');
    $user = $_SESSION['admin']['username'] ?? 'guest';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    $logMessage = "[{$timestamp}] [{$user}] [{$ip}] {$action} - {$details}\n";

    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

// Redirect
function redirect($url)
{
    header("Location: {$url}");
    exit;
}

// Flash message
function setFlash($type, $message)
{
    $_SESSION['flash'][$type] = $message;
}

function getFlash($type)
{
    $message = $_SESSION['flash'][$type] ?? null;
    unset($_SESSION['flash'][$type]);
    return $message;
}

function hasFlash($type)
{
    return isset($_SESSION['flash'][$type]);
}

// Sanitize input
function sanitize($input)
{
    if (is_array($input)) {
        return array_map('sanitize', $input);
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

// Validate email
function isValidEmail($email)
{
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

// Generate random string with charset options
function generateRandomString($length = 10, $type = 'mixed')
{
    switch ($type) {
        case 'numeric':
        case 'num':
            $x = '0123456789';
            break;
        case 'alpha':
        case 'low':
            $x = 'abcdefghijklmnopqrstuvwxyz';
            break;
        case 'up':
            $x = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
            break;
        case 'mixed':
            $x = '23456789abcdefghijkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ';
            break; // Avoid ambiguous chars
        case 'alphanumeric':
        default:
            $x = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
            break;
    }
    
    $str = '';
    for ($i = 0; $i < $length; $i++) {
        $str .= $x[mt_rand(0, strlen($x) - 1)];
    }
    return $str;
}

// Mikhmon Metadata Helpers
function formatMikhmonComment($price, $validity, $profile)
{
    // Format: vc-user-dd-mm-yy (Price: Rp 5.000, Validity: 1d)
    // Note: Mikhmon often uses specific patterns like uct-ddmmyy-price
    $date = date('d/m/y');
    return "price:{$price},validity:{$validity},profile:{$profile},date:{$date}";
}

function parseMikhmonComment($comment)
{
    $data = [
        'price' => 0,
        'validity' => '-',
        'profile' => '-',
        'date' => '-',
        'raw' => $comment
    ];

    if (empty($comment))
        return $data;

    // 1. Try existing key:value format (e.g. price:5000,validity:1d,date=...)
    // Note: Mikhmon uses both : and =
    if (strpos($comment, 'price:') !== false || strpos($comment, 'price=') !== false) {
        $parts = preg_split('/[, ]+/', $comment);
        foreach ($parts as $part) {
            $kv = preg_split('/[:=]/', $part, 2);
            if (count($kv) === 2) {
                $itemKey = trim($kv[0]);
                $itemVal = trim($kv[1]);
                if (isset($data[$itemKey])) {
                    $data[$itemKey] = $itemVal;
                }
            }
        }
        return $data;
    }

    // 2. Try Standard Mikhmon Format: Date - Code - Price - Profile - Validity
    $parts = array_map('trim', explode('-', $comment));
    if (count($parts) >= 5) {
        $data['date'] = $parts[0];
        $data['price'] = preg_replace('/[^0-9]/', '', $parts[2]);
        $data['profile'] = $parts[3];
        $data['validity'] = $parts[4];
        return $data;
    }

    // 3. Fallback search using Regex - BE STRICTER
    // Prioritize Rp or price: prefixes. If none, only accept numeric strings if they are reasonable (< 1,000,000)
    // and not too long (vouchers rarely cost billions)

    $foundPrice = 0;

    // Pattern A: Explicit Price Prefix (Rp, price:, parent:)
    if (preg_match('/(?:price[:=]|Rp\.?\s?|rp\.?\s?|parent[:=])\s?(\d{1,3}(?:\.\d{3})*|\d{3,})/i', $comment, $matches)) {
        $foundPrice = str_replace('.', '', $matches[1]);
    }
    // Pattern B: Bare number at the end or surrounded by spaces (only if Pattern A failed)
    elseif (preg_match('/(?:\s|^)(\d{3,7})(?:\s|$|,)/', $comment, $matches)) {
        $tempPrice = $matches[1];
        // Sanity check: Mikhmon voucher prices are usually under 1,000,000
        if ((int) $tempPrice < 1000000) {
            $foundPrice = $tempPrice;
        }
    }

    if ($foundPrice) {
        $data['price'] = (int) $foundPrice;
    }

    // Date - Be careful not to pick up the same big number
    if (preg_match('/(?:date[:=]|^|\s)([a-z]{3}\/\d{2}\/\d{4}\s\d{2}:\d{2}:\d{2})/i', $comment, $matches)) {
        $data['date'] = $matches[1];
    } elseif (preg_match('/(\d{2}[-\/\.]\d{2}[-\/\.]\d{2,4})/', $comment, $matches)) {
        $data['date'] = $matches[1];
    }

    return $data;
}

function parseHotspotProfileComment($comment)
{
    $price = 0;

    if (empty($comment)) {
        return 0;
    }

    // 1. Try 'parent:PRICE' format (used by this app)
    if (strpos($comment, 'parent:') !== false) {
        // Extract everything after parent:
        $parts = explode('parent:', $comment);
        if (isset($parts[1])) {
            // Take the number immediately following parent:
            $val = trim($parts[1]);
            // If comma separated like parent:5000,other:value
            $valParts = explode(',', $val);
            $price = preg_replace('/[^0-9]/', '', $valParts[0]);
            return (int) $price;
        }
    }

    // 2. Try explicit 'price:' format
    if (preg_match('/price[:=]\s?(\d+)/i', $comment, $matches)) {
        return (int) $matches[1];
    }

    // 3. Try formatted currency format (Rp 5.000)
    if (preg_match('/Rp\.?\s?(\d{1,3}(?:\.\d{3})*|\d{3,})/i', $comment, $matches)) {
        $clean = str_replace('.', '', $matches[1]);
        return (int) $clean;
    }

    // 4. Try bare numeric price (with sanity check)
    // Mikhmon sometimes just puts the price. But we must ignore timestamps (YYYYMMDD...)
    if (preg_match('/(?:\s|^)(\d{3,7})(?:\s|$|,)/', $comment, $matches)) {
        $val = (int) $matches[1];
        // Sanity check: if it looks like a date/timestamp 
        // (e.g. starts with 202, 201 or has 8+ digits), ignore it
        if ($val < 1000000 && strlen($matches[1]) <= 7) {
            return $val;
        }
    }

    return 0;
}

// Check if customer is isolated
function isCustomerIsolated($customerId)
{
    $customer = fetchOne("SELECT status FROM customers WHERE id = ?", [$customerId]);
    return $customer && $customer['status'] === 'isolated';
}

// Isolate customer
function isolateCustomer($customerId)
{
    $customer = fetchOne("SELECT * FROM customers WHERE id = ?", [$customerId]);
    if (!$customer) {
        return false;
    }

    // Update status
    update('customers', ['status' => 'isolated'], 'id = ?', [$customerId]);

    // Update MikroTik profile
    $package = fetchOne("SELECT * FROM packages WHERE id = ?", [$customer['package_id']]);
    if ($package && !empty($customer['pppoe_username'])) {
        // Call MikroTik API to change profile on assigned router
        mikrotikSetProfile($customer['pppoe_username'], $package['profile_isolir'], $customer['router_id']);
        
        // Kick active session so they get the new isolated profile immediately
        mikrotikRemoveActivePppoe($customer['pppoe_username'], $customer['router_id']);

        // Send WhatsApp notification
        $message = "Halo {$customer['name']},\n\nPembayaran internet Anda sudah melewati tanggal jatuh tempo.\n\nMohon segera lakukan pembayaran untuk mengaktifkan kembali koneksi internet Anda.\n\nTerima kasih.";
        sendWhatsApp($customer['phone'], $message);
    }

    logActivity('ISOLATE_CUSTOMER', "Customer ID: {$customerId}");

    return true;
}

// Unisolate customer
function unisolateCustomer($customerId)
{
    $customer = fetchOne("SELECT * FROM customers WHERE id = ?", [$customerId]);
    if (!$customer) {
        return false;
    }

    // Flexible Billing Cycle: If they were isolated, shift their billing cycle forwards to today
    $newIsolationDate = $customer['isolation_date'];
    if ($customer['status'] === 'isolated') {
        $today = (int)date('d');
        if ($today > 28) $today = 28; // Cap at 28 to avoid February leap issues
        $newIsolationDate = $today;
    }

    // Update status
    update('customers', [
        'status' => 'active',
        'isolation_date' => $newIsolationDate
    ], 'id = ?', [$customerId]);

    // Update MikroTik profile
    $package = fetchOne("SELECT * FROM packages WHERE id = ?", [$customer['package_id']]);
    if ($package && !empty($customer['pppoe_username'])) {
        // Call MikroTik API to change profile on assigned router
        mikrotikSetProfile($customer['pppoe_username'], $package['profile_normal'], $customer['router_id']);
        
        // Kick active session so they instantly get reconnect and regain normal internet access
        mikrotikRemoveActivePppoe($customer['pppoe_username'], $customer['router_id']);
    }

    logActivity('UNISOLATE_CUSTOMER', "Customer ID: {$customerId}");

    return true;
}

// Get GenieACS settings from database (override config.php)
function getGenieacsSettings()
{
    static $settings = null;
    if ($settings === null) {
        $settings = [
            'url' => defined('GENIEACS_URL') ? GENIEACS_URL : '',
            'username' => defined('GENIEACS_USERNAME') ? GENIEACS_USERNAME : '',
            'password' => defined('GENIEACS_PASSWORD') ? GENIEACS_PASSWORD : ''
        ];

        // Try to get from database
        $dbSettings = fetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('GENIEACS_URL', 'GENIEACS_USERNAME', 'GENIEACS_PASSWORD')");
        foreach ($dbSettings as $s) {
            switch ($s['setting_key']) {
                case 'GENIEACS_URL':
                    $settings['url'] = $s['setting_value'];
                    break;
                case 'GENIEACS_USERNAME':
                    $settings['username'] = $s['setting_value'];
                    break;
                case 'GENIEACS_PASSWORD':
                    $settings['password'] = $s['setting_value'];
                    break;
            }
        }
    }
    return $settings;
}

// GenieACS functions
function genieacsGetDevices()
{
    $genieacs = getGenieacsSettings();
    if (empty($genieacs['url'])) {
        return [];
    }

    $projection = [
        '_id',
        '_lastInform',
        '_deviceId',
        '_tags',
        'DeviceID',
        'VirtualParameters.pppoeUsername',
        'VirtualParameters.pppoeUsername2',
        'VirtualParameters.gettemp',
        'VirtualParameters.RXPower',
        'VirtualParameters.pppoeIP',
        'VirtualParameters.IPTR069',
        'VirtualParameters.pppoeMac',
        'VirtualParameters.getponmode',
        'VirtualParameters.PonMac',
        'VirtualParameters.getSerialNumber',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.KeyPassphrase',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.TotalAssociations',
        'VirtualParameters.activedevices',
        'VirtualParameters.getdeviceuptime'
    ];

    $query = json_encode(['_id' => ['$regex' => '']]);
    $projectionStr = implode(',', $projection);
    
    $url = rtrim($genieacs['url'], '/') . '/devices/?query=' . urlencode($query) . '&projection=' . $projectionStr;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Increased timeout for larger datasets

    // Add authentication if credentials are set
    if (!empty($genieacs['username']) && !empty($genieacs['password'])) {
        curl_setopt($ch, CURLOPT_USERPWD, $genieacs['username'] . ':' . $genieacs['password']);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    // curl_close($ch); // Deprecated in PHP 8.0+

    if ($httpCode === 200) {
        $devices = json_decode($response, true);
        return is_array($devices) ? $devices : [];
    }

    return [];
}

// Helper to sort multiple devices and pick the most recent
function _genieacsPickBestDevice($devices) {
    if (empty($devices) || !is_array($devices)) return null;
    usort($devices, function($a, $b) {
        $timeA = isset($a['_lastInform']) ? strtotime($a['_lastInform']) : 0;
        $timeB = isset($b['_lastInform']) ? strtotime($b['_lastInform']) : 0;
        return $timeB <=> $timeA;
    });
    return $devices[0];
}

function genieacsGetDevice($serial)
{
    $genieacs = getGenieacsSettings();
    if (empty($genieacs['url'])) {
        return null;
    }

    // Attempt 1: Search by Serial Number
    $query1 = json_encode(['_deviceId._SerialNumber' => $serial]);
    $url1 = rtrim($genieacs['url'], '/') . '/devices/?query=' . urlencode($query1);

    $ch1 = curl_init($url1);
    curl_setopt($ch1, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch1, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch1, CURLOPT_TIMEOUT, 10);
    if (!empty($genieacs['username']) && !empty($genieacs['password'])) {
        curl_setopt($ch1, CURLOPT_USERPWD, $genieacs['username'] . ':' . $genieacs['password']);
    }

    $response1 = curl_exec($ch1);
    $httpCode1 = curl_getinfo($ch1, CURLINFO_HTTP_CODE);

    if ($httpCode1 === 200) {
        $devices = json_decode($response1, true);
        if (is_array($devices) && count($devices) > 0) {
            return _genieacsPickBestDevice($devices);
        }
    }

    // Attempt 2: Search by _id (Exact match)
    // Using query parameter is safer than direct URL access for special chars
    $query2 = json_encode(['_id' => $serial]);
    $url2 = rtrim($genieacs['url'], '/') . '/devices/?query=' . urlencode($query2);

    $ch2 = curl_init($url2);
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch2, CURLOPT_TIMEOUT, 10);
    if (!empty($genieacs['username']) && !empty($genieacs['password'])) {
        curl_setopt($ch2, CURLOPT_USERPWD, $genieacs['username'] . ':' . $genieacs['password']);
    }

    $response2 = curl_exec($ch2);
    $httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);

    if ($httpCode2 === 200) {
        $devices = json_decode($response2, true);
        if (is_array($devices) && count($devices) > 0) {
            return _genieacsPickBestDevice($devices);
        }
    }

    // Attempt 3: Search by _id (Decoded)
    // Handles cases where ID was passed encoded (e.g. %2D instead of -)
    $decodedSerial = urldecode($serial);
    if ($decodedSerial !== $serial) {
        $query3 = json_encode(['_id' => $decodedSerial]);
        $url3 = rtrim($genieacs['url'], '/') . '/devices/?query=' . urlencode($query3);

        $ch3 = curl_init($url3);
        curl_setopt($ch3, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch3, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch3, CURLOPT_TIMEOUT, 10);
        if (!empty($genieacs['username']) && !empty($genieacs['password'])) {
            curl_setopt($ch3, CURLOPT_USERPWD, $genieacs['username'] . ':' . $genieacs['password']);
        }

        $response3 = curl_exec($ch3);
        $httpCode3 = curl_getinfo($ch3, CURLINFO_HTTP_CODE);

        if ($httpCode3 === 200) {
            $devices = json_decode($response3, true);
            if (is_array($devices) && count($devices) > 0) {
                return _genieacsPickBestDevice($devices);
            }
        }
    }

    // Attempt 4: Search by PPPoE Username (VirtualParameters.pppoeUsername)
    // Since `customers.php` maps PPPoE Username to the `serial_number` column in the database,
    // this acts as a vital fallback for finding online status on the map.
    $query4 = json_encode(['VirtualParameters.pppoeUsername' => $serial]);
    $url4 = rtrim($genieacs['url'], '/') . '/devices/?query=' . urlencode($query4);

    $ch4 = curl_init($url4);
    curl_setopt($ch4, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch4, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch4, CURLOPT_TIMEOUT, 10);
    if (!empty($genieacs['username']) && !empty($genieacs['password'])) {
        curl_setopt($ch4, CURLOPT_USERPWD, $genieacs['username'] . ':' . $genieacs['password']);
    }

    $response4 = curl_exec($ch4);
    $httpCode4 = curl_getinfo($ch4, CURLINFO_HTTP_CODE);

    if ($httpCode4 === 200) {
        $devices = json_decode($response4, true);
        if (is_array($devices) && count($devices) > 0) {
            return _genieacsPickBestDevice($devices);
        }
    }

    // Attempt 5: Search by Tag (critical when the map passes a Customer WhatsApp/Phone number instead of a hardware serial)
    $query5 = json_encode(['_tags' => $serial]);
    $url5 = rtrim($genieacs['url'], '/') . '/devices/?query=' . urlencode($query5);

    $ch5 = curl_init($url5);
    curl_setopt($ch5, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch5, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch5, CURLOPT_TIMEOUT, 10);
    if (!empty($genieacs['username']) && !empty($genieacs['password'])) {
        curl_setopt($ch5, CURLOPT_USERPWD, $genieacs['username'] . ':' . $genieacs['password']);
    }

    $response5 = curl_exec($ch5);
    $httpCode5 = curl_getinfo($ch5, CURLINFO_HTTP_CODE);

    if ($httpCode5 === 200) {
        $devices = json_decode($response5, true);
        if (is_array($devices) && count($devices) > 0) {
            return _genieacsPickBestDevice($devices);
        }
    }

    return null;
}

// Helper function to extract value from GenieACS parameter structure
function genieacsGetValue($device, $path)
{
    // Navigate through nested structure
    $keys = explode('.', $path);
    $current = $device;

    foreach ($keys as $key) {
        if (!is_array($current)) {
            return null;
        }

        // Try direct key access
        if (isset($current[$key])) {
            $current = $current[$key];
        } else {
            // Try numeric index pattern (e.g., LANDevice.1 -> LANDevice["1"])
            $found = false;
            foreach ($current as $k => $v) {
                if (strpos($k, $key) === 0) {
                    $current = $v;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return null;
            }
        }
    }

    // Extract value - GenieACS stores values in different formats
    if (is_array($current)) {
        // Try common value keys
        if (isset($current['_value'])) {
            return $current['_value'];
        }
        if (isset($current['value'])) {
            return $current['value'];
        }
        if (isset($current[0]) && is_string($current[0])) {
            return $current[0];
        }
        
        // If it lacks a specific value leaf, it is a structural tree (like Hosts.Host)
        // Return the array intact for the caller to parse!
        return $current;
    }

    return $current;
}

// Get device info summary from GenieACS
function genieacsGetDeviceInfo($serial)
{
    $device = genieacsGetDevice($serial);

    if (!$device) {
        return null;
    }

    $info = [
        'id' => $device['_id'] ?? $serial,
        'serial_number' => $serial,
        'last_inform' => $device['_lastInform'] ?? null,
        'status' => 'unknown',
        'uptime' => null,
        'manufacturer' => null,
        'model' => null,
        'software_version' => null,
        'rx_power' => null,
        'tx_power' => null,
        'ssid' => null,
        'wifi_password' => null,
        'ip_address' => null,
        'mac_address' => null,
        'total_associations' => null
    ];

    // Determine online status (last inform within 5 minutes)
    if ($info['last_inform']) {
        $lastInform = strtotime($info['last_inform']);
        $info['status'] = (time() - $lastInform) < 300 ? 'online' : 'offline';
    }

    // Extract common parameters using different possible paths
    // Device Manufacturer
    $info['manufacturer'] =
        genieacsGetValue($device, 'InternetGatewayDevice.DeviceInfo.Manufacturer') ??
        genieacsGetValue($device, 'Device.DeviceInfo.Manufacturer') ??
        genieacsGetValue($device, 'DeviceID.Manufacturer');

    // Device Model
    $info['model'] =
        genieacsGetValue($device, 'InternetGatewayDevice.DeviceInfo.ModelName') ??
        genieacsGetValue($device, 'Device.DeviceInfo.ModelName') ??
        genieacsGetValue($device, 'DeviceID.ProductClass');

    // Software Version
    $info['software_version'] =
        genieacsGetValue($device, 'InternetGatewayDevice.DeviceInfo.SoftwareVersion') ??
        genieacsGetValue($device, 'Device.DeviceInfo.SoftwareVersion');

    // Uptime
    $info['uptime'] =
        genieacsGetValue($device, 'InternetGatewayDevice.DeviceInfo.UpTime') ??
        genieacsGetValue($device, 'Device.DeviceInfo.UpTime');

    // WAN IP Address
    $info['ip_address'] =
        genieacsGetValue($device, 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress') ??
        genieacsGetValue($device, 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.ExternalIPAddress');

    // MAC Address
    $info['mac_address'] =
        genieacsGetValue($device, 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.MACAddress') ??
        genieacsGetValue($device, 'Device.Ethernet.Interface.1.MACAddress');

    // WiFi SSID - try multiple paths
    $info['ssid'] =
        genieacsGetValue($device, 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID') ??
        genieacsGetValue($device, 'InternetGatewayDevice.LANDevice.1.WiFi.Radio.1.SSID') ??
        genieacsGetValue($device, 'Device.WiFi.SSID.1.SSID');

    // WiFi Password
    $info['wifi_password'] =
        genieacsGetValue($device, 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.KeyPassphrase') ??
        genieacsGetValue($device, 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase') ??
        genieacsGetValue($device, 'Device.WiFi.AccessPoint.1.Security.KeyPassphrase');

    // PON Optical Power (for GPON/EPON ONUs)
    $info['rx_power'] =
        genieacsGetValue($device, 'VirtualParameters.RXPower') ??
        genieacsGetValue($device, 'InternetGatewayDevice.WANDevice.1.X_GponInterafceConfig.RxPower') ??
        genieacsGetValue($device, 'Device.Optical.Interface.1.RXPower');

    $info['tx_power'] =
        genieacsGetValue($device, 'VirtualParameters.TXPower') ??
        genieacsGetValue($device, 'InternetGatewayDevice.WANDevice.1.X_GponInterafceConfig.TxPower') ??
        genieacsGetValue($device, 'Device.Optical.Interface.1.TXPower');

    // Connected Devices / Total Associations (SSID 1 Only)
    $info['total_associations'] = genieacsGetValue($device, 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.TotalAssociations');

    return $info;
}

// Extract dynamically hosted LAN/WiFi associated hosts cleanly (Fixes Portal Blank Screen Bug)
function genieacsGetLanHosts($serial)
{
    $device = genieacsGetDevice($serial);
    if (!$device) {
        return [];
    }
    
    // Look for Hosts.Host arrays across all standard nodes robustly
    $hosts = [];
    $hostNode = genieacsGetValue($device, 'InternetGatewayDevice.LANDevice.1.Hosts.Host') ?? 
                genieacsGetValue($device, 'Device.Hosts.Host');
                
    if (is_array($hostNode)) {
        foreach ($hostNode as $key => $node) {
            if (is_numeric($key)) {
                $hosts[] = $node;
            }
        }
    }
    return $hosts;
}

function genieacsSetParameter($serial, $parameter, $value)
{
    $genieacs = getGenieacsSettings();
    if (empty($genieacs['url'])) {
        return ['success' => false, 'message' => 'GenieACS URL not configured'];
    }

    // Get device first to find the actual device ID
    $device = genieacsGetDevice($serial);
    if (!$device) {
        // If device lookup fails, return specific error
        return ['success' => false, 'message' => "Device lookup failed for: $serial"];
    }

    $deviceId = $device['_id'] ?? $serial;
    // Use rawurlencode and add timeout parameter (3000ms) to avoid hanging
    // This matches GACS implementation reference
    $encodedId = rawurlencode($deviceId);
    $url = rtrim($genieacs['url'], '/') . "/devices/{$encodedId}/tasks?timeout=3000&connection_request";

    $data = [
        'name' => 'setParameterValues', // Note: GACS uses setParameterValues, check if different from setParameter
        'parameterValues' => [
            [$parameter, (string)$value, 'xsd:string']
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10); // 10s > 3s GenieACS timeout

    // Add authentication if credentials are set
    if (!empty($genieacs['username']) && !empty($genieacs['password'])) {
        curl_setopt($ch, CURLOPT_USERPWD, $genieacs['username'] . ':' . $genieacs['password']);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    // curl_close() is deprecated in PHP 8.0+ - CurlHandle auto-destroys

    if ($httpCode === 200 || $httpCode === 201 || $httpCode === 202) {
        return ['success' => true, 'message' => 'Task created successfully'];
    }

    if ($curlError) {
        return ['success' => false, 'message' => "Curl Error: $curlError"];
    }

    return ['success' => false, 'message' => "GenieACS Error ($httpCode): " . ($response ?: 'Unknown error')];
}

/**
 * Force GenieACS to query the router and refresh multiple objects simultaneously via a bulk POST to minimize ConnectionRequest overheads.
 * @param string $serial Device ID or Phone tag
 * @param array $objectNames Array of parameter trees to refresh
 */
function genieacsRefreshObjects($serial, $objectNames)
{
    $genieacs = getGenieacsSettings();
    if (empty($genieacs['url']) || empty($objectNames)) return false;

    $device = genieacsGetDevice($serial);
    if (!$device) return false;

    $deviceId = $device['_id'] ?? $serial;
    $encodedId = rawurlencode($deviceId);
    
    // Batch all targets into a single queue block
    $tasks = [];
    foreach ($objectNames as $t) {
        $tasks[] = [
            'name' => 'refreshObject',
            'objectName' => $t
        ];
    }
    
    $url = rtrim($genieacs['url'], '/') . "/devices/{$encodedId}/tasks?timeout=5000&connection_request";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($tasks));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8); // Allow up to 8 secs for big bulk trees

    if (!empty($genieacs['username']) && !empty($genieacs['password'])) {
        curl_setopt($ch, CURLOPT_USERPWD, $genieacs['username'] . ':' . $genieacs['password']);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    return ($httpCode === 200 || $httpCode === 202);
}

// Kept for backward compatibility
function genieacsRefreshObject($serial, $objectName) {
    return genieacsRefreshObjects($serial, [$objectName]);
}

function genieacsSetParameterValues($serial, $params)
{
    $genieacs = getGenieacsSettings();
    if (empty($genieacs['url'])) {
        return false;
    }

    // Get device first to find the actual device ID
    $device = genieacsGetDevice($serial);
    if (!$device) {
        return false;
    }

    $deviceId = $device['_id'] ?? $serial;
    $encodedId = rawurlencode($deviceId);
    $url = rtrim($genieacs['url'], '/') . "/devices/{$encodedId}/tasks?timeout=3000&connection_request";

    $parameterValues = [];
    foreach ($params as $key => $value) {
        $parameterValues[] = [$key, (string)$value, 'xsd:string'];
    }

    $data = [
        'name' => 'setParameterValues',
        'parameterValues' => $parameterValues
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    if (!empty($genieacs['username']) && !empty($genieacs['password'])) {
        curl_setopt($ch, CURLOPT_USERPWD, $genieacs['username'] . ':' . $genieacs['password']);
    }

    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    return $httpCode === 200 || $httpCode === 201 || $httpCode === 202;
}

// Find device by PPPoE username in GenieACS
function genieacsFindDeviceByPppoe($pppoeUsername)
{
    $genieacs = getGenieacsSettings();
    if (empty($genieacs['url'])) {
        return null;
    }

    // First, try to find device using VirtualParameters.pppoeUsername which is the most reliable approach
    $query = json_encode(['VirtualParameters.pppoeUsername' => $pppoeUsername]);
    $url = rtrim($genieacs['url'], '/') . '/devices/?query=' . urlencode($query);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    // Add authentication if credentials are set
    if (!empty($genieacs['username']) && !empty($genieacs['password'])) {
        curl_setopt($ch, CURLOPT_USERPWD, $genieacs['username'] . ':' . $genieacs['password']);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    // curl_close() is deprecated in PHP 8.0+ - CurlHandle auto-destroys

    if ($httpCode === 200) {
        $devices = json_decode($response, true);
        if (is_array($devices) && count($devices) > 0) {
            return $devices[0]; // Return first matching device
        }
    }

    // If not found via VirtualParameters, try alternative approaches
    // Try searching for devices with PPPoE username in various possible locations
    $possibleQueries = [
        // Alternative VirtualParameters that might contain the username
        ['VirtualParameters.pppoeUsername2' => $pppoeUsername],
        // Common paths where username might be stored in standard parameters
        ['InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.Username' => $pppoeUsername],
        ['InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Username' => $pppoeUsername],
        ['Device.PPP.Interface.1.Credentials.Username' => $pppoeUsername],
        ['InternetGatewayDevice.PPPPEngine.PPPoE.UnicastDiscovery.Username' => $pppoeUsername],
        // If PPPoE username is stored as part of device name or description
        ['Device.DeviceInfo.Description' => $pppoeUsername],
        ['Device.DeviceInfo.FriendlyName' => $pppoeUsername]
    ];

    foreach ($possibleQueries as $query) {
        $encodedQuery = json_encode($query);
        $url = rtrim($genieacs['url'], '/') . '/devices/?query=' . urlencode($encodedQuery);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        // Add authentication if credentials are set
        if (!empty($genieacs['username']) && !empty($genieacs['password'])) {
            curl_setopt($ch, CURLOPT_USERPWD, $genieacs['username'] . ':' . $genieacs['password']);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // curl_close() is deprecated in PHP 8.0+ - CurlHandle auto-destroys

        if ($httpCode === 200) {
            $devices = json_decode($response, true);
            if (is_array($devices) && count($devices) > 0) {
                return $devices[0]; // Return first matching device
            }
        }
    }

    // If no device found by searching parameters, try a more general search
    // Sometimes the PPPoE username might be stored in custom fields
    $generalQuery = urlencode('"' . $pppoeUsername . '"');
    $url = rtrim($genieacs['url'], '/') . '/devices/?query=' . $generalQuery;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    // Add authentication if credentials are set
    if (!empty($genieacs['username']) && !empty($genieacs['password'])) {
        curl_setopt($ch, CURLOPT_USERPWD, $genieacs['username'] . ':' . $genieacs['password']);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    // curl_close() is deprecated in PHP 8.0+ - CurlHandle auto-destroys

    if ($httpCode === 200) {
        $devices = json_decode($response, true);
        if (is_array($devices) && count($devices) > 0) {
            return $devices[0]; // Return first matching device
        }
    }

    return null;
}

// Reboot device via GenieACS
function genieacsReboot($serial)
{
    $genieacs = getGenieacsSettings();
    if (empty($genieacs['url'])) {
        return false;
    }

    // Get device first to find the actual device ID
    $device = genieacsGetDevice($serial);
    if (!$device) {
        return false;
    }

    $deviceId = $device['_id'] ?? $serial;
    $url = rtrim($genieacs['url'], '/') . '/devices/' . urlencode($deviceId) . '/tasks?connection_request';

    $data = [
        'name' => 'reboot'
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    // Add authentication if credentials are set
    if (!empty($genieacs['username']) && !empty($genieacs['password'])) {
        curl_setopt($ch, CURLOPT_USERPWD, $genieacs['username'] . ':' . $genieacs['password']);
    }

    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    // curl_close() is deprecated in PHP 8.0+ - CurlHandle auto-destroys

    return $httpCode === 200 || $httpCode === 201;
}
// Pagination
function paginate($table, $page = 1, $perPage = ITEMS_PER_PAGE, $where = '', $params = [])
{
    $offset = ($page - 1) * $perPage;

    // Get total
    $countSql = "SELECT COUNT(*) as total FROM {$table}";
    if ($where) {
        $countSql .= " WHERE {$where}";
    }
    $totalResult = fetchOne($countSql, $params);
    $total = $totalResult['total'] ?? 0;

    // Get data
    $dataSql = "SELECT * FROM {$table}";
    if ($where) {
        $dataSql .= " WHERE {$where}";
    }
    $perPage = (int) $perPage;
    $offset = (int) $offset;
    $dataSql .= " ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}";

    $data = fetchAll($dataSql, $params);

    return [
        'data' => $data,
        'total' => $total,
        'page' => $page,
        'perPage' => $perPage,
        'totalPages' => ceil($total / $perPage)
    ];
}

// Generate CSRF token
function generateCsrfToken()
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Verify CSRF token
function verifyCsrfToken($token)
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Check if admin is logged in
function isAdminLoggedIn()
{
    return isset($_SESSION['admin']['logged_in']) && $_SESSION['admin']['logged_in'] === true;
}

// Check if customer is logged in
function isCustomerLoggedIn()
{
    return isset($_SESSION['customer']['logged_in']) && $_SESSION['customer']['logged_in'] === true;
}

// Get current admin
function getCurrentAdmin()
{
    return $_SESSION['admin'] ?? null;
}

// Get current customer
function getCurrentCustomer()
{
    return $_SESSION['customer'] ?? null;
}

// JSON response
function jsonResponse($data, $statusCode = 200)
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// Sync Hotspot Voucher Status against RouterOS (Simplified 3-Rule Version)
function syncHotspotSalesStatus()
{
    $pdo = getDB();
    
    // 1. Ensure schema is correct
    $existingCols = $pdo->query("SHOW COLUMNS FROM hotspot_sales")->fetchAll(PDO::FETCH_COLUMN);
    $neededCols = [
        'status'      => "ENUM('inactive', 'active', 'expired') DEFAULT 'inactive'",
        'used_at'     => "DATETIME NULL",
        'mac_address' => "VARCHAR(20) DEFAULT NULL",
        'uptime'      => "VARCHAR(20) DEFAULT '0s'",
        'expired_at'  => "DATETIME DEFAULT NULL"
    ];

    foreach ($neededCols as $col => $definition) {
        if (!in_array($col, $existingCols)) {
            $pdo->exec("ALTER TABLE hotspot_sales ADD COLUMN $col $definition");
        }
    }

    // 2. Process Sync from Router
    require_once __DIR__ . '/mikrotik_api.php';
    if (!function_exists('mikrotikGetHotspotUsers')) return;

    $routers = getAllRouters();
    $routerIds = array_column($routers, 'id');
    if (!in_array(0, $routerIds)) $routerIds[] = 0;

    foreach ($routerIds as $routerId) {
        $routerUsers = mikrotikGetHotspotUsers($routerId);
        if (!is_array($routerUsers)) continue;
        
        $pdo->beginTransaction();
        try {
            $profiles = mikrotikGetHotspotProfiles($routerId);
            $profValidity = [];
            if (is_array($profiles)) {
                foreach ($profiles as $p) {
                    $pData = parseMikhmonOnLogin($p['on-login'] ?? '');
                    if (!empty($pData['validity']) && $pData['validity'] !== '-') {
                        $profValidity[$p['name']] = $pData['validity'];
                    }
                }
            }

            foreach ($routerUsers as $u) {
                $uname = $u['name'] ?? '';
                if (empty($uname)) continue;

                $comment = $u['comment'] ?? '';
                $disabled = (isset($u['disabled']) && ($u['disabled'] === 'true' || $u['disabled'] === 'yes' || $u['disabled'] === true));
                $hasTimestamp = (strpos($comment, ' / ') !== false);
                
                $newStatus = 'inactive';
                $usedAt = null;
                $expiryAt = null;

                if ($hasTimestamp) {
                    $newStatus = ($disabled) ? 'expired' : 'active';
                    $cParts = explode(' / ', $comment);
                    $tsStr = trim(end($cParts));
                    if (strtotime($tsStr)) {
                        $usedAt = date('Y-m-d H:i:s', strtotime($tsStr));
                        
                        if ($newStatus === 'active' && isset($profValidity[$u['profile'] ?? 'default'])) {
                            $sec = parseValidityToSeconds($profValidity[$u['profile'] ?? 'default']);
                            if ($sec > 0) $expiryAt = date('Y-m-d H:i:s', strtotime($usedAt) + $sec);
                        }
                    }
                }

                $updateData = [
                    'status' => $newStatus,
                    'mac_address' => $u['mac-address'] ?? null,
                    'uptime' => $u['uptime'] ?? '0s'
                ];
                if ($usedAt) $updateData['used_at'] = $usedAt;
                if ($expiryAt) $updateData['expired_at'] = $expiryAt;

                update('hotspot_sales', $updateData, 'username = ? AND (router_id = ? OR router_id IS NULL)', [$uname, $routerId]);
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            logError("Sync error on router $routerId: " . $e->getMessage());
        }
    }
}

/**
 * Parse Mikhmon-style on-login script for validity metadata
 */
function parseMikhmonOnLogin($script) {
    $data = ['validity' => '-', 'price' => 0];
    if (empty($script)) return $data;
    
    // 1. Handle Comma-Separated Mikhmon v3 format (e.g. ,up,5000,1d,5000,,disable)
    if (strpos($script, ',') !== false) {
        $parts = explode(',', $script);
        // Indices: [1]=mode, [2]=price, [3]=validity
        if (isset($parts[2]) && is_numeric($parts[2])) {
            $data['price'] = (int)$parts[2];
        }
        if (isset($parts[3]) && !empty($parts[3])) {
            $data['validity'] = $parts[3];
        }
        if ($data['price'] > 0 || ($data['validity'] !== '-' && !empty($data['validity']))) {
            return $data;
        }
    }

    // 2. Handle Key=Value format (e.g. set validity=1d; set price=5000;)
    if (preg_match('/validity=([^;|\s,]+)/', $script, $matches)) {
        $data['validity'] = trim($matches[1], '"\'');
    }
    if (preg_match('/price=([^;|\s,]+)/', $script, $matches)) {
        $data['price'] = (int)trim($matches[1], '"\'');
    }
    return $data;
}

/**
 * Convert MikroTik validity string (1d, 1h, etc) to seconds
 */
function parseValidityToSeconds($validity) {
    if (empty($validity) || $validity === '-') return 0;
    
    $seconds = 0;
    // Handle 1d 1h 12m format
    if (preg_match_all('/(\d+)([dhms])/', strtolower($validity), $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $val = (int)$match[1];
            $unit = $match[2];
            switch ($unit) {
                case 'd': $seconds += $val * 86400; break;
                case 'h': $seconds += $val * 3600; break;
                case 'm': $seconds += $val * 60; break;
                case 's': $seconds += $val; break;
            }
        }
    } else if (is_numeric($validity)) {
        return (int)$validity;
    }
    
    return $seconds;
}

// Check if request is AJAX
function isAjax()
{
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
}

// Get current URL
function getCurrentUrl()
{
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    return $protocol . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
}

// Format bytes to human readable format
function formatBytes($bytes, $precision = 2)
{
    $bytes = is_numeric($bytes) ? (float) $bytes : 0;
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Compress and save an uploaded image
 * @param string $source Path to source file
 * @param string $destination Path to destination file
 * @param int $quality Compression quality (1-100)
 * @return bool Success status
 */
function compressImage($source, $destination, $quality = 60)
{
    $info = getimagesize($source);
    if ($info['mime'] == 'image/jpeg') {
        $image = imagecreatefromjpeg($source);
        // Fix orientation if EXIF data exists
        if (function_exists('exif_read_data')) {
            $exif = @exif_read_data($source);
            if ($exif && isset($exif['Orientation'])) {
                switch ($exif['Orientation']) {
                    case 3: $image = imagerotate($image, 180, 0); break;
                    case 6: $image = imagerotate($image, -90, 0); break;
                    case 8: $image = imagerotate($image, 90, 0); break;
                }
            }
        }
        imagejpeg($image, $destination, $quality);
    } elseif ($info['mime'] == 'image/png') {
        $image = imagecreatefrompng($source);
        // PNG quality is 0-9 (0 = no compression, 9 = max)
        // Convert 1-100 to 0-9
        $png_quality = floor((100 - $quality) / 10);
        imagepng($image, $destination, $png_quality);
    } else {
        return move_uploaded_file($source, $destination);
    }
    
    imagedestroy($image);
    return true;
}

/**
 * GenieACS: Push Phone Number (or any descriptor) as a Device Tag immediately
 * This maps physical serials up to customer identifiers natively!
 */
function genieacsAddTag($serial, $tag) {
    if (empty($serial) || empty($tag)) return false;
    
    $settings = getGenieacsSettings();
    if (empty($settings['url'])) return false;

    // Fetch the raw _id representation from ACS first (we need URL-encoded ID format)
    $deviceInfo = genieacsGetDevice($serial);
    if (!$deviceInfo || empty($deviceInfo['_id'])) return false;

    $deviceId = $deviceInfo['_id'];
    $encodedId = rawurlencode($deviceId);
    $encodedTag = rawurlencode(trim((string)$tag));
    
    // Using POST /devices/{id}/tags/{tag}
    $url = rtrim($settings['url'], '/') . "/devices/{$encodedId}/tags/{$encodedTag}";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, ''); // Empty body for tags
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    // Ignore SSL verification if running privately
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    if (!empty($settings['username'])) {
        curl_setopt($ch, CURLOPT_USERPWD, $settings['username'] . ':' . $settings['password']);
    }
    
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    return ($httpCode === 200 || $httpCode === 201 || $httpCode === 202);
}
