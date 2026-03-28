<?php
/**
 * API: ONU WiFi Settings
 */

header('Content-Type: application/json');

require_once '../includes/auth.php';
require_once '../includes/functions.php';

// Allow admin, customer, and technician
if (!isCustomerLoggedIn() && !isAdminLoggedIn() && !isTechnicianLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $pppoeUsername = $_GET['pppoe_username'] ?? '';
        
        if (empty($pppoeUsername)) {
            echo json_encode(['success' => false, 'message' => 'PPPoE Username required']);
            exit;
        }

        // Get device info from GenieACS
        $device = null;
        
        // Find customer to see if a phone tag applies
        $custDb = fetchOne("SELECT phone FROM customers WHERE pppoe_username = ?", [$pppoeUsername]);
        if ($custDb && !empty($custDb['phone'])) {
            $device = genieacsGetDevice($custDb['phone']);
        }
        
        // Fallback to PPPoE
        if (!$device) {
            $device = genieacsFindDeviceByPppoe($pppoeUsername);
        }
        
        if ($device) {
            $deviceId = $device['_id'];
            $deviceData = genieacsGetDeviceInfo($deviceId);
            
            if ($deviceData) {
                echo json_encode(['success' => true, 'data' => $deviceData]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to retrieve device info']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Device not found or offline']);
        }
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);

    $pppoeUsername = $input['pppoe_username'] ?? '';
    $serial = $input['serial'] ?? '';  // Keep for backward compatibility
    $ssid = $input['ssid'] ?? '';
    $password = $input['password'] ?? '';

    // If customer is logged in, enforce ownership
    if (isCustomerLoggedIn()) {
        $customer = getCurrentCustomer();
        // If pppoe_username is provided, it MUST match the customer's
        if (!empty($pppoeUsername) && $pppoeUsername !== $customer['pppoe_username']) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized access to this device']);
            exit;
        }
        // If only serial is provided, we still need to verify ownership (more complex, so enforce pppoe_username for customers)
        if (empty($pppoeUsername)) {
            // Force use of customer's pppoe_username
            $pppoeUsername = $customer['pppoe_username'];
        }
    }

    // Use either pppoe_username or serial
    if (empty($pppoeUsername) && empty($serial)) {
        echo json_encode(['success' => false, 'message' => 'PPPoE username or serial number is required']);
        exit;
    }

    // Find the device properly prioritizing Phone Tag overrides
    if (!empty($pppoeUsername)) {
        $device = null;
        $custDb = fetchOne("SELECT phone FROM customers WHERE pppoe_username = ?", [$pppoeUsername]);
        if ($custDb && !empty($custDb['phone'])) {
            $device = genieacsGetDevice($custDb['phone']);
        }
        
        if (!$device) {
            $device = genieacsFindDeviceByPppoe($pppoeUsername);
        }
        
        if (!$device) {
            echo json_encode(['success' => false, 'message' => 'Device not found for PPPoE username: ' . $pppoeUsername]);
            exit;
        }
        $serial = $device['_id'] ?? $device['DeviceID']['_SerialNumber'] ?? $pppoeUsername;
    }

    // Validate SSID
    if (!empty($ssid) && strlen($ssid) < 3) {
        echo json_encode(['success' => false, 'message' => 'SSID minimal 3 karakter']);
        exit;
    }

    // Validate password
    if (!empty($password) && strlen($password) < 8) {
        echo json_encode(['success' => false, 'message' => 'Password minimal 8 karakter']);
        exit;
    }

    // Update WiFi settings via GenieACS
    if (!empty($ssid)) {
        $ssidPath = 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID';
        $possiblePaths = [
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
            'InternetGatewayDevice.LANDevice.1.WiFi.Radio.1.SSID',
            'Device.WiFi.SSID.1.SSID'
        ];
        foreach ($possiblePaths as $path) {
            if (genieacsGetValue($device, $path) !== null) {
                $ssidPath = $path;
                break;
            }
        }

        $result = genieacsSetParameter($serial, $ssidPath, $ssid);

        if (!$result['success']) {
            echo json_encode(['success' => false, 'message' => 'Failed to update SSID: ' . ($result['message'] ?? 'Unknown error')]);
            exit;
        }
    }

    if (!empty($password)) {
        $passPath = 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.KeyPassphrase';
        $possiblePaths = [
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.KeyPassphrase',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.PreSharedKey',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase',
            'Device.WiFi.AccessPoint.1.Security.KeyPassphrase'
        ];
        foreach ($possiblePaths as $path) {
            if (genieacsGetValue($device, $path) !== null) {
                $passPath = $path;
                break;
            }
        }

        $result = genieacsSetParameter($serial, $passPath, $password);

        if (!$result['success']) {
            echo json_encode(['success' => false, 'message' => 'Failed to update password: ' . ($result['message'] ?? 'Unknown error')]);
            exit;
        }
    }

    // --- SEND WHATSAPP ALERT TO CUSTOMER ---
    $customerQuery = fetchOne("SELECT * FROM customers WHERE pppoe_username = ?", [$serial]);
    if ($customerQuery && !empty($customerQuery['phone'])) {
        require_once '../includes/whatsapp.php';
        $msg = "🔧 *UPDATE PENGATURAN WIFI*\n\n";
        $msg .= "Yth. " . $customerQuery['name'] . ",\n";
        $msg .= "Pengaturan WiFi Anda baru saja diperbarui oleh sistem:\n\n";
        if (!empty($ssid)) $msg .= "📶 *SSID (Nama WiFi):* $ssid\n";
        if (!empty($password)) $msg .= "🔑 *Password WiFi:* $password\n";
        $msg .= "\nMohon sambungkan perangkat Anda dengan informasi WiFi terbaru tersebut.";
        
        sendWhatsAppMessage($customerQuery['phone'], $msg);
    }

    echo json_encode(['success' => true, 'message' => 'WiFi settings updated successfully']);

} catch (Exception $e) {
    logError("API Error (onu_wifi.php): " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
