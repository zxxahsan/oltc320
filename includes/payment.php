<?php
/**
 * Payment Gateway Integration
 */

require_once 'config.php';

// Generate payment link based on gateway
function generatePaymentLink($reference, $amount, $customerName, $customerPhone, $dueDate, $gateway = 'tripay', $paymentMethod = '') {
    // Only Tripay is supported now
    if ($gateway === 'tripay') {
        return generateTripayPaymentLink($reference, $amount, $customerName, $customerPhone, $dueDate, $paymentMethod);
    }
    
    return [
        'success' => false,
        'message' => 'Payment gateway not supported',
        'link' => null
    ];
}

// Tripay Payment Link Generator (Closed Payment API)
function generateTripayPaymentLink($merchantRef, $amount, $customerName, $customerPhone, $dueDate, $paymentMethod = '') {
    if (empty(TRIPAY_API_KEY) || empty(TRIPAY_MERCHANT_CODE) || empty(TRIPAY_PRIVATE_KEY)) {
        return [
            'success' => false,
            'message' => 'Tripay API/Merchant/Private Key belum dikonfigurasi',
            'link' => null
        ];
    }
    
    $apiKey       = TRIPAY_API_KEY;
    $privateKey   = TRIPAY_PRIVATE_KEY;
    $merchantCode = TRIPAY_MERCHANT_CODE;
    
    // Default to QRIS if method is not specified, but for 'Portal' effect, we use a generic method if possible.
    // In Tripay Closed Payment, you MUST specify a method. If not specified, we can't create it.
    // However, Tripay also has "Open Payment". If the user wants a "Portal", they might want to see all.
    // If they want to Choose, we should probably redirect them to our own 'choose-method.php' or use Tripay redirect.
    
    // To satisfy "Tampil Portal", we will use QRIS as default if empty, OR if they want the portal,
    // they should be able to see the list. Tripay Closed Payment requires a CODE (e.g., QRIS, BRIVA).
    $method = !empty($paymentMethod) ? $paymentMethod : 'QRIS';

    $signature = hash_hmac('sha256', $merchantCode.$merchantRef.$amount, $privateKey);

    $data = [
        'method'         => $method,
        'merchant_ref'   => $merchantRef,
        'amount'         => $amount,
        'customer_name'  => $customerName,
        'customer_email' => 'customer@mail.com',
        'customer_phone' => $customerPhone,
        'order_items'    => [
            [
                'sku'         => 'TOPUP',
                'name'        => 'Topup Saldo',
                'price'       => $amount,
                'quantity'    => 1,
                'product_url' => APP_URL,
                'image_url'   => APP_URL . '/assets/img/logo.png',
            ]
        ],
        'return_url'   => APP_URL . '/sales/topup.php',
        'expired_time' => strtotime($dueDate),
        'signature'    => $signature
    ];

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_FRESH_CONNECT  => true,
        CURLOPT_URL            => 'https://tripay.co.id/api/transaction/create',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => false,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey],
        CURLOPT_FAILONERROR    => false,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($data),
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4
    ]);

    $response = curl_exec($curl);
    $error = curl_error($curl);
    curl_close($curl);

    $res = json_decode($response);
    
    if ($res && isset($res->success) && $res->success) {
        return [
            'success' => true,
            'link'    => $res->data->checkout_url
        ];
    } else {
        return [
            'success' => false,
            'message' => $res->message ?? $error ?? 'Gagal membuat transaksi ke Tripay'
        ];
    }
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
