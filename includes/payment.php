<?php
/**
 * Payment Gateway Integration
 */

require_once 'config.php';

// Generate payment link based on gateway
function generatePaymentLink($invoiceNumber, $amount, $customerName, $customerPhone, $dueDate, $gateway = 'tripay', $paymentMethod = '') {
    // Only Tripay is supported now
    if ($gateway === 'tripay') {
        return generateTripayPaymentLink($invoiceNumber, $amount, $customerName, $customerPhone, $dueDate, $paymentMethod);
    }
    
    return [
        'success' => false,
        'message' => 'Payment gateway not supported',
        'link' => null
    ];
}

// Tripay Payment Link Generator
function generateTripayPaymentLink($invoiceNumber, $amount, $customerName, $customerPhone, $dueDate, $paymentMethod = '') {
    if (empty(TRIPAY_API_KEY) || empty(TRIPAY_MERCHANT_CODE)) {
        return [
            'success' => false,
            'message' => 'Payment gateway not configured',
            'link' => null
        ];
    }
    
    $merchantRef = $invoiceNumber;
    $paymentLink = "https://tripay.co.id/checkout?merchant_code=" . TRIPAY_MERCHANT_CODE . "&amount={$amount}&merchant_ref={$merchantRef}&customer_name=" . urlencode($customerName) . "&customer_phone=" . urlencode($customerPhone);
    
    if (!empty($paymentMethod)) {
        $paymentLink .= "&payment_method={$paymentMethod}";
    }
    
    return [
        'success' => true,
        'link' => $paymentLink
    ];
}

// DEPRECATED: Midtrans Payment Link Generator removed

// Get supported payment gateways
function getPaymentGateways() {
    return [
        [
            'id' => 'tripay',
            'name' => 'Tripay',
            'icon' => 'fa-credit-card',
            'color' => '#00f5ff',
            'description' => 'Payment gateway populer Indonesia',
            'features' => ['QRIS', 'Virtual Account', 'VA'],
            'supported_channels' => ['QRIS', 'VA', 'Bank Transfer']
        ]
    ];
}

// Send payment reminder via WhatsApp
function sendPaymentReminder($invoiceNumber, $amount, $customerName, $customerPhone, $dueDate) {
    $message = "Halo {$customerName},\n\n";
    $message .= "Tagihan internet Anda akan jatuh tempo pada " . formatDate($dueDate) . "\n\n";
    $message .= "Nominal: " . formatCurrency($amount) . "\n\n";
    $message .= "Mohon segera lakukan pembayaran untuk mengaktifkan kembali koneksi internet Anda.\n\n";
    $message .= "Terima kasih.";
    
    return sendWhatsApp($customerPhone, $message);
}

// Get payment status from Tripay
function getTripayPaymentStatus($merchantRef) {
    if (empty(TRIPAY_API_KEY)) {
        return ['success' => false, 'message' => 'API Key not configured'];
    }
    
    $url = "https://tripay.co.id/transaction/detail?merchant_ref={$merchantRef}";
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . TRIPAY_API_KEY
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        return ['success' => false, 'message' => 'Failed to get payment status'];
    }
    
    return ['success' => true, 'data' => json_decode($response, true)];
}
