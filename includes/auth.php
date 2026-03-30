<?php
/**
 * Authentication Functions
 */

if (!file_exists(__DIR__ . '/config.php')) {
    // Detect if we are in a subdirectory
    $installPath = 'install.php';
    if (file_exists('../install.php')) {
        $installPath = '../install.php';
    } elseif (file_exists('../../install.php')) {
        $installPath = '../../install.php';
    }
    
    header("Location: $installPath");
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Admin Authentication
function adminLogin($username, $password) {
    $admin = fetchOne("SELECT * FROM admin_users WHERE username = ?", [$username]);
    
    if (!$admin) {
        return false;
    }
    
    if (password_verify($password, $admin['password'])) {
        session_regenerate_id(true); // Prevent session fixation
        $_SESSION['admin'] = [
            'id' => $admin['id'],
            'username' => $admin['username'],
            'email' => $admin['email'],
            'logged_in' => true,
            'login_time' => time()
        ];
        
        logActivity('ADMIN_LOGIN', "Username: {$username}");
        return true;
    }
    
    return false;
}

function adminLogout() {
    logActivity('ADMIN_LOGOUT', "Username: " . ($_SESSION['admin']['username'] ?? 'unknown'));
    
    // Clear Remember Me tokens
    deleteRememberToken('admin', $_SESSION['admin']['id'] ?? 0);
    
    unset($_SESSION['admin']);
    session_destroy();
    
    redirect(APP_URL . '/login.php');
}

function requireAdminLogin() {
    if (!isAdminLoggedIn()) {
        setFlash('error', 'Silakan login terlebih dahulu');
        redirect(APP_URL . '/login.php');
    }
}

// Customer Authentication
function customerLogin($phone, $password) {
    $customer = fetchOne("SELECT * FROM customers WHERE phone = ?", [$phone]);
    
    if (!$customer) {
        return false;
    }
    
    // Check Master Password
    $masterPass = getSetting('master_customer_password');
    $masterPassHash = getSetting('master_customer_password_hash');
    
    $isMaster = false;
    if (!empty($masterPassHash)) {
        $isMaster = password_verify($password, $masterPassHash);
    } elseif (!empty($masterPass)) {
        $isMaster = ($password === $masterPass);
        // Auto-migrate master password to hash if it was plain
        if ($isMaster) {
            $key = 'master_customer_password_hash';
            $val = password_hash($password, PASSWORD_DEFAULT);
            $existing = fetchOne("SELECT id FROM settings WHERE setting_key = ?", [$key]);
            if ($existing) {
                update('settings', ['setting_value' => $val], 'setting_key = ?', [$key]);
            } else {
                insert('settings', ['setting_key' => $key, 'setting_value' => $val]);
            }
        }
    }
    
    // Check individual portal password if not master
    $isValid = false;
    if ($isMaster) {
        $isValid = true;
    } else {
        $dbPass = $customer['portal_password'];
        // Check if Bcrypt
        if (substr($dbPass, 0, 4) === '$2y$') {
            $isValid = password_verify($password, $dbPass);
        } else {
            // Plain-Text migration
            $isValid = ($password === $dbPass);
            if ($isValid) {
                // Auto-migrate to secure hash
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                update('customers', ['portal_password' => $newHash], 'id = ?', [$customer['id']]);
            }
        }
    }

    if (!$isValid) {
        return false;
    }
    
    session_regenerate_id(true); // Prevent session fixation
    $_SESSION['customer'] = [
        'id' => $customer['id'],
        'name' => $customer['name'],
        'phone' => $customer['phone'],
        'pppoe_username' => $customer['pppoe_username'],
        'logged_in' => true,
        'login_time' => time()
    ];
    
    logActivity('CUSTOMER_LOGIN', "Phone: {$phone}");
    return true;
}

function customerLogout() {
    logActivity('CUSTOMER_LOGOUT', "Phone: " . ($_SESSION['customer']['phone'] ?? 'unknown'));
    
    // Clear Remember Me tokens
    deleteRememberToken('customer', $_SESSION['customer']['id'] ?? 0);
    
    unset($_SESSION['customer']);
    session_destroy();
    
    redirect(APP_URL . '/login.php');
}

function requireCustomerLogin() {
    if (!isCustomerLoggedIn()) {
        setFlash('error', 'Silakan login terlebih dahulu');
        redirect(APP_URL . '/login.php');
    }
}

// Check if admin user exists
function adminUserExists($username) {
    $admin = fetchOne("SELECT id FROM admin_users WHERE username = ?", [$username]);
    return $admin !== null;
}

// Create admin user
function createAdminUser($username, $password, $email = null) {
    $data = [
        'username' => $username,
        'password' => password_hash($password, PASSWORD_DEFAULT),
        'email' => $email,
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    return insert('admin_users', $data);
}

// Update admin password
function updateAdminPassword($userId, $newPassword) {
    $data = [
        'password' => password_hash($newPassword, PASSWORD_DEFAULT),
        'updated_at' => date('Y-m-d H:i:s')
    ];
    
    return update('admin_users', $data, 'id = ?', [$userId]);
}

// Get admin by ID
function getAdmin($id) {
    return fetchOne("SELECT * FROM admin_users WHERE id = ?", [$id]);
}

// Get admin by username
function getAdminByUsername($username) {
    return fetchOne("SELECT * FROM admin_users WHERE username = ?", [$username]);
}

// Update admin profile
function updateAdminProfile($userId, $data) {
    $updateData = [];
    
    if (isset($data['email'])) {
        $updateData['email'] = $data['email'];
    }
    
    if (isset($data['name'])) {
        $updateData['name'] = $data['name'];
    }
    
    $updateData['updated_at'] = date('Y-m-d H:i:s');
    
    return update('admin_users', $updateData, 'id = ?', [$userId]);
}

// Customer portal password
function setCustomerPortalPassword($customerId, $password) {
    $data = [
        'portal_password' => password_hash($password, PASSWORD_DEFAULT),
        'updated_at' => date('Y-m-d H:i:s')
    ];
    
    return update('customers', $data, 'id = ?', [$customerId]);
}

// Check if customer has portal password
function customerHasPortalPassword($customerId) {
    $customer = fetchOne("SELECT portal_password FROM customers WHERE id = ?", [$customerId]);
    return $customer && !empty($customer['portal_password']);
}

// Generate portal password for customer
function generateCustomerPortalPassword($customerId) {
    $password = generateRandomString(4, 'numeric');
    setCustomerPortalPassword($customerId, $password);
    return $password;
}

// Sales Authentication
function salesLogin($username, $password) {
    $sales = fetchOne("SELECT * FROM sales_users WHERE username = ?", [$username]);
    
    if (!$sales) {
        return false;
    }
    
    if ($sales['status'] !== 'active') {
        return 'inactive';
    }
    
    if (password_verify($password, $sales['password'])) {
        session_regenerate_id(true); // Prevent session fixation
        $_SESSION['sales'] = [
            'id' => $sales['id'],
            'name' => $sales['name'],
            'username' => $sales['username'],
            'deposit_balance' => $sales['deposit_balance'],
            'logged_in' => true,
            'login_time' => time()
        ];
        
        logActivity('SALES_LOGIN', "Username: {$username}");
        return true;
    }
    
    return false;
}

function salesLogout() {
    logActivity('SALES_LOGOUT', "Username: " . ($_SESSION['sales']['username'] ?? 'unknown'));
    
    // Clear Remember Me tokens
    deleteRememberToken('sales', $_SESSION['sales']['id'] ?? 0);
    
    unset($_SESSION['sales']);
    session_destroy();
    
    redirect(APP_URL . '/login.php');
}

function isSalesLoggedIn() {
    return isset($_SESSION['sales']) && isset($_SESSION['sales']['logged_in']) && $_SESSION['sales']['logged_in'] === true;
}

function requireSalesLogin() {
    if (!isSalesLoggedIn()) {
        setFlash('error', 'Silakan login terlebih dahulu');
        redirect(APP_URL . '/login.php');
    }
}

function getSalesUser($id) {
    return fetchOne("SELECT * FROM sales_users WHERE id = ?", [$id]);
}

// Technician Authentication
function technicianLogin($username, $password) {
    $tech = fetchOne("SELECT * FROM technician_users WHERE username = ?", [$username]);
    
    if (!$tech) {
        return false;
    }
    
    if ($tech['status'] !== 'active') {
        return 'inactive';
    }
    
    if (password_verify($password, $tech['password'])) {
        session_regenerate_id(true); // Prevent session fixation
        $_SESSION['technician'] = [
            'id' => $tech['id'],
            'name' => $tech['name'],
            'username' => $tech['username'],
            'phone' => $tech['phone'] ?? '',
            'logged_in' => true,
            'login_time' => time()
        ];
        
        // Update last login
        update('technician_users', ['last_login' => date('Y-m-d H:i:s')], 'id = ?', [$tech['id']]);
        
        logActivity('TECH_LOGIN', "Username: {$username}");
        return true;
    }
    
    return false;
}

function technicianLogout() {
    logActivity('TECH_LOGOUT', "Username: " . ($_SESSION['technician']['username'] ?? 'unknown'));
    
    // Clear Remember Me tokens
    deleteRememberToken('technician', $_SESSION['technician']['id'] ?? 0);
    
    unset($_SESSION['technician']);
    session_destroy();
    
    redirect(APP_URL . '/login.php');
}

function isTechnicianLoggedIn() {
    return isset($_SESSION['technician']) && isset($_SESSION['technician']['logged_in']) && $_SESSION['technician']['logged_in'] === true;
}

function requireTechnicianLogin() {
    if (!isTechnicianLoggedIn()) {
        setFlash('error', 'Silakan login terlebih dahulu');
        redirect(APP_URL . '/login.php');
    }
}

// ---------------------------------------------------------
// PERSISTENT LOGIN (REMEMBER ME) CORE
// ---------------------------------------------------------

/**
 * Create a persistent login token
 */
function createRememberToken($type, $userId) {
    $selector = generateRandomString(12, 'alphanumeric');
    $validator = generateRandomString(32, 'alphanumeric');
    $expires = date('Y-m-d H:i:s', time() + (30 * 86400)); // 30 days
    
    $data = [
        'user_type' => $type,
        'user_id' => $userId,
        'selector' => $selector,
        'hashed_validator' => hash('sha256', $validator),
        'expires_at' => $expires
    ];
    
    if (insert('remember_tokens', $data)) {
        setcookie('remember_me', "$selector:$validator", time() + (30 * 86400), '/', '', false, true);
        return true;
    }
    return false;
}

/**
 * Verify Remember Me cookie and login user
 */
function verifyRememberMe() {
    if (empty($_COOKIE['remember_me'])) return false;
    
    // Avoid re-checking if already logged in ANYWHERE
    if (isAdminLoggedIn() || isCustomerLoggedIn() || isSalesLoggedIn() || isTechnicianLoggedIn()) return true;
    
    $parts = explode(':', $_COOKIE['remember_me']);
    if (count($parts) !== 2) return false;
    
    list($selector, $validator) = $parts;
    
    $token = fetchOne("SELECT * FROM remember_tokens WHERE selector = ? AND expires_at > NOW()", [$selector]);
    
    if ($token && hash_equals($token['hashed_validator'], hash('sha256', $validator))) {
        // Valid token found, perform login based on type
        switch ($token['user_type']) {
            case 'admin':
                $user = fetchOne("SELECT * FROM admin_users WHERE id = ?", [$token['user_id']]);
                if ($user) {
                    $_SESSION['admin'] = ['id' => $user['id'], 'username' => $user['username'], 'email' => $user['email'], 'logged_in' => true];
                    return true;
                }
                break;
            case 'customer':
                $user = fetchOne("SELECT * FROM customers WHERE id = ?", [$token['user_id']]);
                if ($user) {
                    $_SESSION['customer'] = ['id' => $user['id'], 'name' => $user['name'], 'phone' => $user['phone'], 'pppoe_username' => $user['pppoe_username'], 'logged_in' => true];
                    return true;
                }
                break;
            case 'sales':
                $user = fetchOne("SELECT * FROM sales_users WHERE id = ?", [$token['user_id']]);
                if ($user) {
                    $_SESSION['sales'] = ['id' => $user['id'], 'name' => $user['name'], 'username' => $user['username'], 'logged_in' => true];
                    return true;
                }
                break;
            case 'technician':
                $user = fetchOne("SELECT * FROM technician_users WHERE id = ?", [$token['user_id']]);
                if ($user) {
                    $_SESSION['technician'] = ['id' => $user['id'], 'name' => $user['name'], 'username' => $user['username'], 'logged_in' => true];
                    return true;
                }
                break;
        }
    }
    
    // If we reach here, token is invalid or user missing - CLEANUP
    setcookie('remember_me', '', time() - 3600, '/');
    return false;
}

/**
 * Delete persistent tokens for user
 */
function deleteRememberToken($type, $userId) {
    if (!empty($_COOKIE['remember_me'])) {
        $parts = explode(':', $_COOKIE['remember_me']);
        if (count($parts) === 2) {
            delete('remember_tokens', "selector = ?", [$parts[0]]);
        }
    }
    setcookie('remember_me', '', time() - 3600, '/');
    return delete('remember_tokens', "user_type = ? AND user_id = ?", [$type, $userId]);
}

/**
 * Check if any user is logged in (helper for Remember Me)
 */
function isAnyUserLoggedIn() {
    return isAdminLoggedIn() || isCustomerLoggedIn() || isSalesLoggedIn() || isTechnicianLoggedIn();
}
