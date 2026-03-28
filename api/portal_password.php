<?php
/**
 * API: Change Portal Password
 */

header('Content-Type: application/json');

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

try {
    // Check if customer is logged in
    if (!isCustomerLoggedIn()) {
        echo json_encode(['success' => false, 'message' => 'Not authenticated']);
        exit;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $password = $input['password'] ?? '';
    
    // Validate password
    if (strlen($password) < 6) {
        echo json_encode(['success' => false, 'message' => 'Password minimal 6 karakter']);
        exit;
    }
    
    $customer = getCurrentCustomer();
    
    if (setCustomerPortalPassword($customer['id'], $password)) {
        echo json_encode(['success' => true, 'message' => 'Password berhasil diubah']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal mengubah password']);
    }
    
} catch (Exception $e) {
    logError("API Error (portal_password.php): " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
