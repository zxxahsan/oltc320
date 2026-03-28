<?php
/**
 * API: GenieACS
 */

header('Content-Type: application/json');

require_once '../includes/auth.php';
require_once '../includes/functions.php';

// Allow admin and technician
if (!isAdminLoggedIn() && !isTechnicianLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';

    if ($method === 'GET') {
        if ($action === 'devices') {
            // Get all devices
            $devices = genieacsGetDevices();

            echo json_encode([
                'success' => true,
                'data' => [
                    'devices' => $devices,
                    'total' => count($devices)
                ]
            ]);
        } elseif ($action === 'device' || $action === 'get_device') {
            $serial = $_GET['serial'] ?? $_GET['id'] ?? '';

            if (empty($serial)) {
                echo json_encode(['success' => false, 'message' => 'Serial number required']);
                exit;
            }

            // Resolve Phone to PPPoE Username and onto True Hardware Serial ID securely 
            $customer = fetchOne("SELECT pppoe_username FROM customers WHERE pppoe_username = ? OR phone = ?", [$serial, $serial]);
            if ($customer && !empty($customer['pppoe_username'])) {
                $dev = genieacsFindDeviceByPppoe($customer['pppoe_username']);
                if ($dev && !empty($dev['_id'])) {
                    $serial = $dev['_id'];
                }
            } else {
                $dev = genieacsFindDeviceByPppoe($serial);
                if ($dev && !empty($dev['_id'])) {
                    $serial = $dev['_id'];
                }
            }

            $deviceInfo = genieacsGetDeviceInfo($serial);

            if ($deviceInfo) {
                echo json_encode([
                    'success' => true,
                    'data' => $deviceInfo
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Device not found']);
            }
        }
    } elseif ($method === 'POST') {
        $action = $_GET['action'] ?? $_POST['action'] ?? '';
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        
        if ($action === 'reboot') {
            $deviceId = $_POST['device_id'] ?? $input['serial'] ?? $input['device_id'] ?? '';
            
            // If device_id is username, we need to find serial number first
            // But genieacsReboot usually takes device ID (serial or _id)
            // Let's try to map it if it's not a serial
            
            if (empty($deviceId)) {
                echo json_encode(['success' => false, 'message' => 'Device ID required']);
                exit;
            }
            
            // Check if it's a PPPoE username or Phone and get True Serial
            $customer = fetchOne("SELECT * FROM customers WHERE pppoe_username = ? OR phone = ?", [$deviceId, $deviceId]);
            if ($customer && !empty($customer['pppoe_username'])) {
                // Find device by username in GenieACS
                $device = genieacsFindDeviceByPppoe($customer['pppoe_username']);
                if ($device && !empty($device['_id'])) {
                    $deviceId = $device['_id'];
                }
            }

            if (genieacsReboot($deviceId)) {
                echo json_encode(['success' => true, 'message' => 'Perintah reboot berhasil dikirim']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Gagal mengirim perintah reboot']);
            }
        } elseif ($action === 'ping') {
            $deviceId = $_POST['device_id'] ?? '';
            $host = $_POST['host'] ?? '8.8.8.8';
            
            if (empty($deviceId)) {
                echo json_encode(['success' => false, 'message' => 'Device ID required']);
                exit;
            }
            
            // Resolve ID if needed (same logic as above)
            $customer = fetchOne("SELECT * FROM customers WHERE pppoe_username = ? OR phone = ?", [$deviceId, $deviceId]);
            if ($customer && !empty($customer['pppoe_username'])) {
                // Find device by username in GenieACS
                $device = genieacsFindDeviceByPppoe($customer['pppoe_username']);
                if ($device && !empty($device['_id'])) {
                    $deviceId = $device['_id'];
                }
            }
            
            // Send Diagnostics Request
            // IPPingDiagnostics
            $params = [
                'InternetGatewayDevice.IPPingDiagnostics.Host' => $host,
                'InternetGatewayDevice.IPPingDiagnostics.NumberOfRepetitions' => 3,
                'InternetGatewayDevice.IPPingDiagnostics.Timeout' => 1000,
                'InternetGatewayDevice.IPPingDiagnostics.DiagnosticsState' => 'Requested'
            ];
            
            if (genieacsSetParameterValues($deviceId, $params)) {
                echo json_encode(['success' => true, 'message' => 'Ping request sent to ' . $host]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Gagal mengirim request ping']);
            }
        } elseif ($action === 'get_ping_result') {
            $deviceId = $_POST['device_id'] ?? '';
            
            if (empty($deviceId)) {
                echo json_encode(['success' => false, 'message' => 'Device ID required']);
                exit;
            }
            
            // Resolve ID
            $customer = fetchOne("SELECT * FROM customers WHERE pppoe_username = ? OR phone = ?", [$deviceId, $deviceId]);
            if ($customer && !empty($customer['pppoe_username'])) {
                // Find device by username in GenieACS
                $device = genieacsFindDeviceByPppoe($customer['pppoe_username']);
                if ($device && !empty($device['_id'])) {
                    $deviceId = $device['_id'];
                }
            }
            
            // Fetch device data
            $device = genieacsGetDevice($deviceId);
            if ($device) {
                $diagState = genieacsGetValue($device, 'InternetGatewayDevice.IPPingDiagnostics.DiagnosticsState') ?? 'None';
                $success = genieacsGetValue($device, 'InternetGatewayDevice.IPPingDiagnostics.SuccessCount') ?? 0;
                $failure = genieacsGetValue($device, 'InternetGatewayDevice.IPPingDiagnostics.FailureCount') ?? 0;
                $avgTime = genieacsGetValue($device, 'InternetGatewayDevice.IPPingDiagnostics.AverageResponseTime') ?? 0;
                
                echo json_encode([
                    'success' => true, 
                    'data' => [
                        'state' => $diagState,
                        'success_count' => $success,
                        'failure_count' => $failure,
                        'avg_time' => $avgTime
                    ]
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Device not found']);
            }
        }
    }

} catch (Exception $e) {
    logError("API Error (genieacs.php): " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
