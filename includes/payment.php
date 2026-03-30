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
    $apiKey       = getSetting('TRIPAY_API_KEY');
    $privateKey   = getSetting('TRIPAY_PRIVATE_KEY');
    $merchantCode = getSetting('TRIPAY_MERCHANT_CODE');

    if (empty($apiKey) || empty($merchantCode) || empty($privateKey)) {
        return [
            'success' => false,
            'message' => 'Tripay API/Merchant/Private Key belum dikonfigurasi di Pengaturan Admin',
            'link' => null
        ];
    }
    
    // Default to QRIS if method is not specified, but for 'Portal' effect, we use a generic method if possible.
    // In Tripay Closed Payment, you MUST specify a method. If not specified, we can't create it.
    // However, Tripay also has "Open Payment". If the user wants a "Portal", they might want to see all.
    // If they want to Choose, we should probably redirect them to our own 'choose-method.php' or use Tripay redirect.
    
    // To satisfy "Tampil Portal", we will use QRIS as default if empty, OR if they want the portal,
    // they should be able to see the list. Tripay Closed Payment requires a CODE (e.g., QRIS, BRIVA).
    $method = !empty($paymentMethod) ? $paymentMethod : 'QRIS';

    $amountInt = (int)$amount;
    $signature = hash_hmac('sha256', $merchantCode.$merchantRef.$amountInt, $privateKey);

    $data = [
        'method'         => $method,
        'merchant_ref'   => $merchantRef,
        'amount'         => $amountInt,
        'customer_name'  => $customerName,
        'customer_email' => 'customer@mail.com',
        'customer_phone' => $customerPhone,
        'order_items'    => [
            [
                'sku'         => 'TOPUP',
                'name'        => 'Topup Saldo',
                'price'       => $amountInt,
                'quantity'    => 1,
                'product_url' => APP_URL,
                'image_url'   => APP_URL . '/assets/img/logo.png',
            ]
        ],
        'return_url'   => APP_URL . '/sales/topup.php',
        'expired_time' => (int)strtotime($dueDate),
        'signature'    => $signature
    ];

    $isSandbox = getSetting('TRIPAY_SANDBOX', '0') === '1';
    $apiUrl = $isSandbox ? 'https://tripay.co.id/api-sandbox/transaction/create' : 'https://tripay.co.id/api/transaction/create';

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_FRESH_CONNECT  => true,
        CURLOPT_URL            => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => false,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ],
        CURLOPT_FAILONERROR    => false,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
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
    $apiKey = getSetting('TRIPAY_API_KEY');
    if (empty($apiKey)) {
        return ['success' => false, 'message' => 'Tripay API Key belum dikonfigurasi'];
    }
    
    $isSandbox = getSetting('TRIPAY_SANDBOX', '0') === '1';
    $baseUrl = $isSandbox ? 'https://tripay.co.id/api-sandbox/' : 'https://tripay.co.id/api/';
    $url = $baseUrl . "transaction/detail?merchant_ref={$merchantRef}";
    
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

// Get active payment channels from Tripay
function getTripayChannels() {
    $apiKey = getSetting('TRIPAY_API_KEY');
    $merchantCode = getSetting('TRIPAY_MERCHANT_CODE');
    if (empty($apiKey) || empty($merchantCode)) return [];

    $isSandbox = getSetting('TRIPAY_SANDBOX', '0') === '1';
    $baseUrl = $isSandbox ? 'https://tripay.co.id/api-sandbox/merchant/payment-channel' : 'https://tripay.co.id/api/merchant/payment-channel';
    $url = $baseUrl . '?merchant_code=' . $merchantCode;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Accept: application/json'
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($httpCode === 200) {
        $res = json_decode($response, true);
        if ($res && isset($res['success']) && $res['success']) {
            $channels = $res['data'] ?? [];
            $_SESSION['tripay_channels_cache'] = $channels;
            $_SESSION['tripay_channels_time'] = time();
            return ['success' => true, 'data' => $channels];
        } else {
            return ['success' => false, 'message' => $res['message'] ?? 'Tripay Error: Gagal memproses data channel'];
        }
    }

    $errorMsg = !empty($curlError) ? $curlError : "HTTP Code: $httpCode";
    return ['success' => false, 'message' => "Gagal terhubung ke Tripay ($errorMsg). Pastikan API Key & Merchant Code benar."];
}
