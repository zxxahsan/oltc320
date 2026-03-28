<?php
/**
 * Realtime Payment Checker API
 */
header('Content-Type: application/json');
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

if (!isAdminLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Start checking from 8 seconds ago, or use the exact last check time
$lastCheck = $_SESSION['last_payment_check'] ?? date('Y-m-d H:i:s', strtotime('-8 seconds'));

$pdo = getDB();
$newPayments = [];

try {
    // Find invoices marked as paid since last check
    $newPayments = fetchAll("
        SELECT i.invoice_number, i.amount, c.name as customer_name 
        FROM invoices i 
        JOIN customers c ON i.customer_id = c.id 
        WHERE i.status = 'paid' 
        AND i.paid_at > ?
    ", [$lastCheck]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}

// Update the last checked time so we don't alert the same payment twice
$_SESSION['last_payment_check'] = date('Y-m-d H:i:s');

echo json_encode([
    'success' => true,
    'payments' => $newPayments,
    'checked_since' => $lastCheck
]);
