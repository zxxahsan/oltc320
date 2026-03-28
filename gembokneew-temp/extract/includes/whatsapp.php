<?php
/**
 * WhatsApp Gateway Integration
 */

require_once 'config.php';

// Helper to get settings from database if constant is empty
function getWhatsAppSetting($key, $constantValue) {
    if (!empty($constantValue)) {
        return $constantValue;
    }
    
    // Attempt to fetch from database
    try {
        $row = fetchOne("SELECT setting_value FROM settings WHERE setting_key = ?", [$key]);
        return $row ? $row['setting_value'] : '';
    } catch (Exception $e) {
        return '';
    }
}

// Fonnte WhatsApp Sender
function sendFonnteWhatsApp($phone, $message) {
    $token = getWhatsAppSetting('FONNTE_API_TOKEN', defined('FONNTE_API_TOKEN') ? FONNTE_API_TOKEN : '');
    
    if (empty($token)) {
        return ['success' => false, 'message' => 'Fonnte API token not configured'];
    }
    
    $url = 'https://api.fonnte.com/send';
    
    $data = [
        'target' => $phone,
        'message' => $message,
        'countryCode' => '62'
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: ' . $token
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        return ['success' => true, 'data' => json_decode($response, true)];
    } else {
        return ['success' => false, 'message' => 'Failed to send WhatsApp via Fonnte (HTTP ' . $httpCode . ')'];
    }
}

// Wablas WhatsApp Sender
function sendWablasWhatsApp($phone, $message) {
    $token = getWhatsAppSetting('WABLAS_API_TOKEN', defined('WABLAS_API_TOKEN') ? WABLAS_API_TOKEN : '');
    
    if (empty($token)) {
        return ['success' => false, 'message' => 'Wablas API token not configured'];
    }
    
    $url = 'https://solo.wablas.com/api/send-message';
    
    $data = [
        'phone' => $phone,
        'message' => $message,
        'secret' => $token
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        return ['success' => true, 'data' => json_decode($response, true)];
    } else {
        return ['success' => false, 'message' => 'Failed to send WhatsApp via Wablas (HTTP ' . $httpCode . ')'];
    }
}

// MPWA WhatsApp Sender
function sendMpwaWhatsApp($phone, $message) {
    $token = getWhatsAppSetting('MPWA_API_KEY', defined('MPWA_API_KEY') ? MPWA_API_KEY : '');
    
    if (empty($token)) {
        return ['success' => false, 'message' => 'MPWA API key not configured'];
    }
    
    $url = 'https://mpwa.official.id/api/send';
    
    $data = [
        'phone' => $phone,
        'message' => $message,
        'api_key' => $token
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        return ['success' => true, 'data' => json_decode($response, true)];
    } else {
        return ['success' => false, 'message' => 'Failed to send WhatsApp via MPWA (HTTP ' . $httpCode . ')'];
    }
}


// Get supported WhatsApp gateways
function getWhatsAppGateways() {
    return [
        [
            'id' => 'fonnte',
            'name' => 'Fonnte',
            'icon' => 'fa-whatsapp',
            'color' => '#25d366',
            'description' => 'WhatsApp API service'
        ],
        [
            'id' => 'wablas',
            'name' => 'Wablas',
            'icon' => 'fa-whatsapp',
            'color' => '#25d366',
            'description' => 'WhatsApp API service'
        ],
        [
            'id' => 'mpwa',
            'name' => 'MPWA',
            'icon' => 'fa-whatsapp',
            'color' => '#25d366',
            'description' => 'WhatsApp API service'
        ]
    ];
}

// Send WhatsApp message based on gateway
function sendWhatsAppMessage($phone, $message, $gateway = 'fonnte') {
    switch ($gateway) {
        case 'fonnte':
            return sendFonnteWhatsApp($phone, $message);
            
        case 'wablas':
            return sendWablasWhatsApp($phone, $message);
            
        case 'mpwa':
            return sendMpwaWhatsApp($phone, $message);
            
        default:
            return [
                'success' => false,
                'message' => 'WhatsApp gateway not supported'
            ];
    }
}
