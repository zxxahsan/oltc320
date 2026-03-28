<?php
/**
 * Webhook Handler - Midtrans Payment Gateway
 */

require_once '../includes/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

try {
    // Get raw POST data
    $json = file_get_contents('php://input');
    
    logActivity('MIDTRANS_WEBHOOK', "Received webhook");
    
    // Parse JSON data
    $data = json_decode($json, true);
    
    if (!$data) {
        logError('Midtrans webhook: Invalid JSON');
        echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
        exit;
    }
    
    $orderId = $data['order_id'] ?? '';
    $transactionStatus = $data['transaction_status'] ?? '';
    $paymentType = $data['payment_type'] ?? '';
    $transactionTime = $data['transaction_time'] ?? '';
    $grossAmount = $data['gross_amount'] ?? '';
    $signatureKey = $data['signature_key'] ?? '';
    $statusCode = $data['status_code'] ?? '';

    // Verify signature
    if (!defined('MIDTRANS_API_KEY')) {
        logError('Midtrans webhook: API Key not configured');
        echo json_encode(['success' => false, 'message' => 'Configuration error']);
        exit;
    }

    $expectedSignature = hash('sha512', $orderId . $statusCode . $grossAmount . MIDTRANS_API_KEY);
    
    if ($signatureKey !== $expectedSignature) {
        logError('Midtrans webhook: Invalid signature');
        echo json_encode(['success' => false, 'message' => 'Invalid signature']);
        exit;
    }
    
    // Log webhook
    $pdo = getDB();
    $stmt = $pdo->prepare("INSERT INTO webhook_logs (source, payload, status_code, response, created_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->execute(['midtrans', $json, 200, 'Received']);
    
    // Handle payment status
    if ($transactionStatus === 'settlement' || $transactionStatus === 'capture') {
        handlePaidInvoice($orderId, $data);
    } elseif ($transactionStatus === 'expire' || $transactionStatus === 'cancel' || $transactionStatus === 'deny') {
        handleFailedInvoice($orderId, $transactionStatus);
    }
    
    echo json_encode(['success' => true, 'message' => 'Webhook processed']);
    
} catch (Exception $e) {
    logError("Midtrans webhook error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}

function handlePaidInvoice($invoiceNumber, $paymentData) {
    $invoice = fetchOne("SELECT * FROM invoices WHERE invoice_number = ?", [$invoiceNumber]);
    
    if (!$invoice) {
        logError("Invoice not found: {$invoiceNumber}");
        return;
    }
    
    // Update invoice status
    update('invoices', [
        'status' => 'paid',
        'paid_at' => date('Y-m-d H:i:s'),
        'payment_method' => $paymentData['payment_type'] ?? 'Midtrans',
        'payment_ref' => $paymentData['transaction_id'] ?? ''
    ], 'invoice_number = ?', [$invoiceNumber]);
    
    logActivity('INVOICE_PAID', "Invoice: {$invoiceNumber}");
    
    // Check if customer should be unisolated
    $customer = fetchOne("SELECT * FROM customers WHERE id = ?", [$invoice['customer_id']]);
    
    if ($customer && $customer['status'] === 'isolated') {
        // Check if all invoices are paid
        $unpaidCount = fetchOne("
            SELECT COUNT(*) as total 
            FROM invoices 
            WHERE customer_id = ? 
            AND status = 'unpaid' 
            AND due_date < CURDATE()
        ", [$customer['id']])['total'] ?? 0;
        
        if ($unpaidCount === 0) {
            // Unisolate customer
            if (unisolateCustomer($invoice['customer_id'])) {
                logActivity('AUTO_UNISOLATE', "Customer ID: {$invoice['customer_id']}");
                if (!empty($customer['pppoe_username'])) {
                    mikrotikRemoveActivePppoe($customer['pppoe_username'], $customer['router_id']);
                }
            }
        }
    }
}

function handleFailedInvoice($invoiceNumber, $status) {
    logActivity('INVOICE_FAILED', "Invoice: {$invoiceNumber}, Status: {$status}");
}
